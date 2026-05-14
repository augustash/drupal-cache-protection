<?php

declare(strict_types=1);

namespace Drupal\drupal_cache_protection\Warmer;

use Drupal\Core\PreWarm\CachePreWarmerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\drupal_cache_protection\Warmer\Strategy\DetachedStrategy;
use Drupal\drupal_cache_protection\Warmer\Strategy\DisabledStrategy;
use Drupal\drupal_cache_protection\Warmer\Strategy\PostResponseStrategy;
use Drupal\drupal_cache_protection\Warmer\Strategy\StrategyInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Orchestrates warmer strategy selection and dispatches the warm.
 *
 * Selection ladder (high to low):
 *   detached       — only after the survival probe has returned success
 *   post_response  — the default floor; works on any PHP-FPM host
 *   disabled       — no cache_prewarmer service available (D<11.2)
 *
 * Selection logic:
 *   - If the cache_prewarmer service is unavailable → disabled.
 *   - Otherwise, post_response is the default.
 *   - Promote to detached if and only if the survival probe last
 *     succeeded within REVERIFY_AFTER_SECONDS.
 *   - Demote back to post_response on probe failure.
 */
class CacheWarmer {

  private const STATE_STRATEGY = 'drupal_cache_protection.warmer.strategy';
  private const STATE_CAPABILITIES = 'drupal_cache_protection.warmer.capabilities';

  public function __construct(
    private readonly ?CachePreWarmerInterface $prewarmer,
    private readonly StateInterface $state,
    private readonly CapabilityDetector $capabilities,
    private readonly SurvivalProbe $probe,
    private readonly RequestStack $requestStack,
    private readonly string $appRoot,
  ) {}

  /**
   * Called from hook_rebuild() after a cache flush.
   */
  public function warm(): void {
    $this->getCurrentStrategy()->warm();
  }

  /**
   * Called from hook_cron(). Drives the survival probe lifecycle and
   * updates the persisted strategy selection based on its result.
   */
  public function maintainProbe(): void {
    if (!$this->prewarmer) {
      return;
    }

    $this->probe->check();

    if ($this->probe->shouldSpawn()) {
      $this->probe->spawn();
    }

    $this->refreshStrategy();

    $this->state->set(self::STATE_CAPABILITIES, $this->capabilities->detect());
  }

  /**
   * Reset all warmer state and re-pick the default. Called from
   * hook_install. The probe will run on next cron.
   */
  public function reset(): void {
    $this->state->set(self::STATE_CAPABILITIES, $this->capabilities->detect());
    $this->probe->reset();
    $this->state->set(self::STATE_STRATEGY, $this->defaultStrategyName());
  }

  public function getStrategyName(): string {
    // Honor the absence of cache_prewarmer regardless of what state
    // says — a stale 'post_response' lingering from a prior install
    // shouldn't lie about the active strategy when the service has
    // gone away (downgrade, container without prewarming, etc.).
    if (!$this->prewarmer) {
      return 'disabled';
    }
    $name = $this->state->get(self::STATE_STRATEGY);
    return is_string($name) ? $name : $this->defaultStrategyName();
  }

  public function getCurrentStrategy(): StrategyInterface {
    return $this->buildStrategy($this->getStrategyName());
  }

  public function getRequirements(): array {
    $name = $this->getStrategyName();
    $probe = $this->probe->getRecord();
    $caps = $this->state->get(self::STATE_CAPABILITIES) ?? $this->capabilities->detect();

    // Severity is returned as a string; hook_requirements() translates
    // to the procedural REQUIREMENT_* constants which aren't available
    // inside a namespaced service class.
    $severity = match (TRUE) {
      $name === 'disabled' => 'info',
      $name === 'detached' && $probe['status'] === 'success' => 'ok',
      $name === 'post_response' && $probe['status'] === 'failed' => 'warning',
      default => 'info',
    };

    $valueLines = [
      sprintf('Active strategy: <code>%s</code>', $name),
      sprintf('Probe status: <code>%s</code>%s', $probe['status'], $probe['notes'] ? ' — ' . $probe['notes'] : ''),
    ];
    if (!empty($probe['last_success_at'])) {
      $valueLines[] = 'Last successful probe: ' . date('c', $probe['last_success_at']);
    }
    $valueLines[] = sprintf(
      'Capabilities: exec=%s, setsid=%s, drush=%s, fastcgi_finish_request=%s, prewarmer_service=%s',
      $caps['exec'] ? 'yes' : 'no',
      $caps['setsid'] ? 'yes' : 'no',
      $caps['drush_path'] ?: 'not found',
      $caps['fastcgi_finish_request'] ? 'yes' : 'no',
      $caps['prewarmer_service'] ? 'yes' : 'no',
    );

    return [
      'drupal_cache_protection_warmer' => [
        'title' => 'Cache Protection — Warmer',
        'value' => implode('<br>', $valueLines),
        'severity' => $severity,
      ],
    ];
  }

  private function refreshStrategy(): void {
    $current = $this->getStrategyName();
    $next = $this->selectStrategyName();
    if ($current !== $next) {
      $this->state->set(self::STATE_STRATEGY, $next);
    }
  }

  private function defaultStrategyName(): string {
    return $this->prewarmer ? 'post_response' : 'disabled';
  }

  private function selectStrategyName(): string {
    if (!$this->prewarmer) {
      return 'disabled';
    }

    $caps = $this->capabilities->detect();
    $probe = $this->probe->getRecord();

    $detachedEligible = $caps['exec']
      && $caps['setsid']
      && $caps['drush_path'] !== NULL
      && $probe['status'] === 'success'
      && !empty($probe['last_success_at'])
      && (time() - $probe['last_success_at']) <= SurvivalProbe::REVERIFY_AFTER_SECONDS;

    return $detachedEligible ? 'detached' : 'post_response';
  }

  private function buildStrategy(string $name): StrategyInterface {
    if (!$this->prewarmer) {
      return new DisabledStrategy();
    }

    return match ($name) {
      'detached' => $this->buildDetachedOrFallback(),
      'disabled' => new DisabledStrategy(),
      default => new PostResponseStrategy($this->prewarmer),
    };
  }

  private function buildDetachedOrFallback(): StrategyInterface {
    $drush = $this->capabilities->findDrush();
    if (!$drush) {
      return new PostResponseStrategy($this->prewarmer);
    }
    return new DetachedStrategy(
      $this->prewarmer,
      $this->requestStack,
      $this->appRoot,
      $drush,
    );
  }

}

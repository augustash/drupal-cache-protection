<?php

declare(strict_types=1);

namespace Drupal\drupal_cache_protection\Warmer\Strategy;

use Drupal\Core\PreWarm\CachePreWarmerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Shells out to `drush cache:warm` as a fully-detached child process,
 * so the originating web request returns immediately and no PHP-FPM
 * worker is held during the warm.
 *
 * Only safe on hosts where detached children survive past the request —
 * verified by SurvivalProbe before this strategy is promoted to active.
 *
 * CLI context (already inside `drush deploy` etc.) runs inline instead
 * of spawning a new drush process.
 */
class DetachedStrategy implements StrategyInterface {

  public function __construct(
    private readonly CachePreWarmerInterface $prewarmer,
    private readonly RequestStack $requestStack,
    private readonly string $appRoot,
    private readonly string $drushPath,
  ) {}

  public function getName(): string {
    return 'detached';
  }

  public function warm(): void {
    if (PHP_SAPI === 'cli') {
      $this->prewarmer->preWarmAllCaches();
      return;
    }

    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return;
    }

    $cmd = sprintf(
      'setsid %s --root=%s --uri=%s cache:warm </dev/null >/dev/null 2>&1 &',
      escapeshellarg($this->drushPath),
      escapeshellarg($this->appRoot),
      escapeshellarg($request->getSchemeAndHttpHost()),
    );

    @exec($cmd);
  }

}

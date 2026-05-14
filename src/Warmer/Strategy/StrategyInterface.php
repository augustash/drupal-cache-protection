<?php

declare(strict_types=1);

namespace Drupal\drupal_cache_protection\Warmer\Strategy;

/**
 * Strategy for running cache prewarming after a flush.
 *
 * Implementations are selected by CacheWarmer based on host capabilities
 * and the survival-probe result. Each implementation is responsible for
 * invoking the cache_prewarmer service in whichever execution context
 * (inline, post-response, detached) is appropriate.
 */
interface StrategyInterface {

  /**
   * Machine name, persisted in state and shown on the status report.
   */
  public function getName(): string;

  /**
   * Run the warm. Called from hook_rebuild() after a flush.
   *
   * Must return promptly — long work belongs in a deferred context.
   */
  public function warm(): void;

}

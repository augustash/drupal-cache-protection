<?php

declare(strict_types=1);

namespace Drupal\drupal_cache_protection\Warmer\Strategy;

/**
 * No-op strategy. Selected when the cache_prewarmer service is
 * unavailable (Drupal core < 11.2) or when the warmer is explicitly
 * disabled.
 */
class DisabledStrategy implements StrategyInterface {

  public function getName(): string {
    return 'disabled';
  }

  public function warm(): void {
  }

}

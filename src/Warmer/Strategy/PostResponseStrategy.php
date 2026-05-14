<?php

declare(strict_types=1);

namespace Drupal\drupal_cache_protection\Warmer\Strategy;

use Drupal\Core\PreWarm\CachePreWarmerInterface;

/**
 * Runs prewarming after the response has been flushed to the client.
 *
 * Under PHP-FPM / FastCGI: calls fastcgi_finish_request() so the user
 * sees an immediate response, then prewarms in the same PHP process.
 * Without FastCGI (CLI, mod_php), falls through to inline prewarming.
 *
 * Cost: holds one PHP worker for the duration of the warm. Acceptable
 * on the assumption that cache flushes are infrequent relative to
 * normal traffic.
 */
class PostResponseStrategy implements StrategyInterface {

  public function __construct(
    private readonly CachePreWarmerInterface $prewarmer,
  ) {}

  public function getName(): string {
    return 'post_response';
  }

  public function warm(): void {
    if (PHP_SAPI === 'cli' || !function_exists('fastcgi_finish_request')) {
      $this->prewarmer->preWarmAllCaches();
      return;
    }

    drupal_register_shutdown_function(function (): void {
      while (ob_get_level() > 0) {
        @ob_end_flush();
      }
      @fastcgi_finish_request();
      $this->prewarmer->preWarmAllCaches();
    });
  }

}

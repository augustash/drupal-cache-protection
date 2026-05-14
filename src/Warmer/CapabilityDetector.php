<?php

declare(strict_types=1);

namespace Drupal\drupal_cache_protection\Warmer;

/**
 * Synchronous capability checks that determine which warmer strategies
 * are eligible for selection. None of these touch the filesystem
 * heavily or block — running them at install time and on demand is
 * cheap.
 *
 * The "child process survives the request" capability is intentionally
 * NOT detected here — that's the survival probe's job, since it cannot
 * be answered synchronously.
 */
class CapabilityDetector {

  /**
   * Common locations to look for drush relative to the Drupal app root.
   * Tried in order; the first executable hit wins.
   */
  private const DRUSH_CANDIDATES = [
    '/../vendor/bin/drush',
    '/../bin/drush',
    '/vendor/bin/drush',
  ];

  public function __construct(
    private readonly string $appRoot,
  ) {}

  public function detect(): array {
    $disabled = $this->disabledFunctions();
    $execAvailable = function_exists('exec') && !in_array('exec', $disabled, TRUE);

    return [
      'detected_at' => time(),
      'sapi' => PHP_SAPI,
      'is_pantheon' => !empty($_ENV['PANTHEON_ENVIRONMENT']),
      'exec' => $execAvailable,
      'proc_open' => function_exists('proc_open') && !in_array('proc_open', $disabled, TRUE),
      'fastcgi_finish_request' => function_exists('fastcgi_finish_request'),
      'setsid' => $execAvailable && $this->commandExists('setsid'),
      'drush_path' => $this->findDrush(),
      'prewarmer_service' => \Drupal::hasService('cache_prewarmer'),
    ];
  }

  public function findDrush(): ?string {
    foreach (self::DRUSH_CANDIDATES as $rel) {
      $path = realpath($this->appRoot . $rel);
      if ($path && is_executable($path)) {
        return $path;
      }
    }

    if (function_exists('exec') && !in_array('exec', $this->disabledFunctions(), TRUE)) {
      $output = [];
      @exec('command -v drush 2>/dev/null', $output);
      $candidate = trim($output[0] ?? '');
      if ($candidate && is_executable($candidate)) {
        return $candidate;
      }
    }

    return NULL;
  }

  private function commandExists(string $cmd): bool {
    $output = [];
    $return = 0;
    @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $output, $return);
    return $return === 0 && !empty($output[0]);
  }

  private function disabledFunctions(): array {
    static $disabled;
    if ($disabled === NULL) {
      $list = ini_get('disable_functions') ?: '';
      $disabled = array_filter(array_map('trim', explode(',', $list)));
    }
    return $disabled;
  }

}

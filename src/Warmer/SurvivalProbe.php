<?php

declare(strict_types=1);

namespace Drupal\drupal_cache_protection\Warmer;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;

/**
 * Verifies whether a detached child process survives past the originating
 * request on this host. The test runs across two cron ticks:
 *
 *   Tick N:   spawn a detached child that sleeps PROBE_SLEEP_SECONDS,
 *             then writes a marker file. Record "pending" in state.
 *   Tick N+1: if the marker exists and is timestamped after the spawn,
 *             record "success"; otherwise record "failed".
 *
 * The two-tick design is what makes this asynchronous-from-install:
 * we never block install or cron on the sleep itself.
 */
class SurvivalProbe {

  /**
   * Sleep duration for the probe child. Long enough that the originating
   * cron tick has long finished, short enough that the result is
   * available within one default cron interval.
   */
  private const PROBE_SLEEP_SECONDS = 10;

  /**
   * Re-verify the result this many seconds after the last success.
   * Catches platform behavior drift over time.
   */
  public const REVERIFY_AFTER_SECONDS = 2592000; // 30 days

  /**
   * Grace window after expected_finish_at before a missing marker is
   * declared a failure. Default cron tick is ~3 minutes; this gives
   * the next-tick check ample room.
   */
  private const PENDING_TIMEOUT_SECONDS = 3600; // 1 hour

  private const STATE_KEY = 'drupal_cache_protection.warmer.probe';
  private const MARKER_DIR = 'public://drupal_cache_protection';

  public function __construct(
    private readonly StateInterface $state,
    private readonly FileSystemInterface $fileSystem,
    private readonly CapabilityDetector $capabilities,
  ) {}

  /**
   * Returns the current probe record from state.
   *
   * Shape:
   *   - status: 'never' | 'pending' | 'success' | 'failed' | 'unsupported'
   *   - last_attempt_at: int|null
   *   - last_success_at: int|null
   *   - spawned_at: int|null
   *   - expected_finish_at: int|null
   *   - notes: string
   */
  public function getRecord(): array {
    return $this->state->get(self::STATE_KEY) ?? [
      'status' => 'never',
      'last_attempt_at' => NULL,
      'last_success_at' => NULL,
      'spawned_at' => NULL,
      'expected_finish_at' => NULL,
      'notes' => '',
    ];
  }

  public function reset(): void {
    $this->state->delete(self::STATE_KEY);
  }

  /**
   * Whether the host is capable of running the probe at all.
   */
  public function isRunnable(array $caps): bool {
    return $caps['exec'] && $caps['setsid'] && $caps['drush_path'] !== NULL;
  }

  /**
   * Should the probe spawn now? Yes if never-run-successfully, or if
   * the last success was longer ago than REVERIFY_AFTER_SECONDS.
   */
  public function shouldSpawn(): bool {
    $record = $this->getRecord();
    if ($record['status'] === 'pending' || $record['status'] === 'unsupported') {
      return FALSE;
    }
    if (!$record['last_success_at']) {
      return TRUE;
    }
    return (time() - $record['last_success_at']) > self::REVERIFY_AFTER_SECONDS;
  }

  /**
   * Spawn a probe child. Returns TRUE if the spawn succeeded.
   */
  public function spawn(): bool {
    $caps = $this->capabilities->detect();
    if (!$this->isRunnable($caps)) {
      $this->state->set(self::STATE_KEY, [
        'status' => 'unsupported',
        'last_attempt_at' => time(),
        'last_success_at' => $this->getRecord()['last_success_at'] ?? NULL,
        'spawned_at' => NULL,
        'expected_finish_at' => NULL,
        'notes' => 'Host lacks exec / setsid / drush.',
      ]);
      return FALSE;
    }

    $dir = $this->ensureDir();
    if (!$dir) {
      return FALSE;
    }

    @unlink($dir . '/probe_finish.json');

    $script = sprintf(
      'sleep %d; printf \'{"timestamp":"%%s","pid":%%d,"hostname":"%%s"}\' '
        . '"$(date -Iseconds 2>/dev/null || date)" $$ "$(hostname)" '
        . '> %s/probe_finish.json',
      self::PROBE_SLEEP_SECONDS,
      escapeshellarg($dir),
    );

    $cmd = sprintf(
      'setsid bash -c %s </dev/null >/dev/null 2>&1 & echo $!',
      escapeshellarg($script),
    );

    $output = [];
    $return = 0;
    @exec($cmd, $output, $return);

    if ($return !== 0) {
      return FALSE;
    }

    $now = time();
    $this->state->set(self::STATE_KEY, [
      'status' => 'pending',
      'last_attempt_at' => $now,
      'last_success_at' => $this->getRecord()['last_success_at'] ?? NULL,
      'spawned_at' => $now,
      'expected_finish_at' => $now + self::PROBE_SLEEP_SECONDS,
      'notes' => 'Probe spawned, awaiting next cron tick for verification.',
    ]);

    return TRUE;
  }

  /**
   * If a probe is pending, check whether it finished. Updates state.
   * Returns the resolved status.
   */
  public function check(): string {
    $record = $this->getRecord();
    if ($record['status'] !== 'pending') {
      return $record['status'];
    }

    $expected = (int) $record['expected_finish_at'];
    $now = time();

    if ($now < $expected) {
      return 'pending';
    }

    $dir = $this->fileSystem->realpath(self::MARKER_DIR);
    $markerPath = $dir ? $dir . '/probe_finish.json' : NULL;
    $markerExists = $markerPath && is_file($markerPath);

    if ($markerExists) {
      $contents = @file_get_contents($markerPath);
      $data = $contents ? @json_decode($contents, TRUE) : NULL;
      $this->state->set(self::STATE_KEY, [
        'status' => 'success',
        'last_attempt_at' => $record['last_attempt_at'],
        'last_success_at' => $now,
        'spawned_at' => $record['spawned_at'],
        'expected_finish_at' => $record['expected_finish_at'],
        'notes' => is_array($data)
          ? sprintf('Child finished at %s (pid %d, host %s).', $data['timestamp'] ?? '?', (int) ($data['pid'] ?? 0), $data['hostname'] ?? '?')
          : 'Finish marker present.',
      ]);
      return 'success';
    }

    if (($now - $expected) < self::PENDING_TIMEOUT_SECONDS) {
      return 'pending';
    }

    $this->state->set(self::STATE_KEY, [
      'status' => 'failed',
      'last_attempt_at' => $record['last_attempt_at'],
      'last_success_at' => $record['last_success_at'] ?? NULL,
      'spawned_at' => $record['spawned_at'],
      'expected_finish_at' => $record['expected_finish_at'],
      'notes' => 'Finish marker absent after the expected window. Detached child did not survive.',
    ]);
    return 'failed';
  }

  private function ensureDir(): ?string {
    $dir = self::MARKER_DIR;
    $prepared = $this->fileSystem->prepareDirectory(
      $dir,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );
    return $prepared ? $this->fileSystem->realpath(self::MARKER_DIR) : NULL;
  }

}

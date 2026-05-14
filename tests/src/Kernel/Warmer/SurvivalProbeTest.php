<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_cache_protection\Kernel\Warmer;

use Drupal\Core\File\FileSystemInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_cache_protection\Warmer\SurvivalProbe;

/**
 * Tests the SurvivalProbe state machine — the logic that decides when
 * to spawn a probe and how to interpret the result.
 *
 * We deliberately do NOT test SurvivalProbe::spawn() end-to-end. That
 * would mean firing a real detached subprocess, which is environment-
 * dependent and was already verified manually on dev + live. Here we
 * test the pieces that are pure logic over state + filesystem:
 *
 *   - shouldSpawn() — when the probe wants to run again
 *   - check()       — how a pending probe transitions to success/failed
 *
 * To simulate "a child finished" or "a child never wrote its marker"
 * we manually populate state and (optionally) write the marker file.
 *
 * @group aai
 * @group drupal_cache_protection
 */
class SurvivalProbeTest extends KernelTestBase {

  protected static $modules = ['system', 'drupal_cache_protection'];

  private SurvivalProbe $probe;
  private string $markerDir;

  protected function setUp(): void {
    parent::setUp();
    $this->probe = $this->container->get('drupal_cache_protection.warmer.probe');

    // Prepare the marker dir so we can write fake child markers into it.
    $fs = $this->container->get('file_system');
    $dir = 'public://drupal_cache_protection';
    $fs->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
    $this->markerDir = $fs->realpath($dir);
  }

  /**
   * @covers ::shouldSpawn
   */
  public function testShouldSpawnWhenNeverRun(): void {
    // Fresh install state — no record at all.
    $this->setProbeState(NULL);
    $this->assertTrue($this->probe->shouldSpawn());
  }

  /**
   * @covers ::shouldSpawn
   */
  public function testShouldNotSpawnWhileAnotherProbeIsPending(): void {
    // A probe is already in flight; don't queue another.
    $this->setProbeState([
      'status' => 'pending',
      'last_attempt_at' => time(),
      'last_success_at' => NULL,
      'spawned_at' => time(),
      'expected_finish_at' => time() + 10,
      'notes' => '',
    ]);
    $this->assertFalse($this->probe->shouldSpawn());
  }

  /**
   * @covers ::shouldSpawn
   */
  public function testShouldNotSpawnAfterRecentSuccess(): void {
    // Re-verification interval hasn't elapsed yet.
    $this->setProbeState([
      'status' => 'success',
      'last_attempt_at' => time() - 60,
      'last_success_at' => time() - 60,
      'spawned_at' => time() - 90,
      'expected_finish_at' => time() - 80,
      'notes' => '',
    ]);
    $this->assertFalse($this->probe->shouldSpawn());
  }

  /**
   * @covers ::shouldSpawn
   */
  public function testShouldSpawnAfterReverificationWindow(): void {
    // Last success was longer ago than REVERIFY_AFTER_SECONDS — time to
    // re-check that the platform still supports detached survival.
    $stale = time() - (SurvivalProbe::REVERIFY_AFTER_SECONDS + 60);
    $this->setProbeState([
      'status' => 'success',
      'last_attempt_at' => $stale,
      'last_success_at' => $stale,
      'spawned_at' => $stale - 30,
      'expected_finish_at' => $stale - 20,
      'notes' => '',
    ]);
    $this->assertTrue($this->probe->shouldSpawn());
  }

  /**
   * @covers ::shouldSpawn
   */
  public function testShouldNotSpawnWhenUnsupported(): void {
    // If a previous spawn attempt found exec/setsid/drush missing, the
    // probe is parked at 'unsupported' and we don't keep retrying.
    $this->setProbeState([
      'status' => 'unsupported',
      'last_attempt_at' => time() - 60,
      'last_success_at' => NULL,
      'spawned_at' => NULL,
      'expected_finish_at' => NULL,
      'notes' => 'Host lacks exec / setsid / drush.',
    ]);
    $this->assertFalse($this->probe->shouldSpawn());
  }

  /**
   * @covers ::check
   *
   * The happy path: a child was spawned, its sleep has elapsed, and the
   * finish marker is on disk. check() should flip pending → success.
   */
  public function testCheckResolvesToSuccessWhenMarkerExists(): void {
    $spawned = time() - 60;
    $this->setProbeState([
      'status' => 'pending',
      'last_attempt_at' => $spawned,
      'last_success_at' => NULL,
      'spawned_at' => $spawned,
      'expected_finish_at' => $spawned + 10,
      'notes' => '',
    ]);

    file_put_contents(
      $this->markerDir . '/probe_finish.json',
      json_encode([
        'timestamp' => '2026-05-14T12:57:38-05:00',
        'pid' => 12345,
        'hostname' => 'test-host',
      ]),
    );

    $this->assertSame('success', $this->probe->check());

    $record = $this->probe->getRecord();
    $this->assertSame('success', $record['status']);
    $this->assertNotNull($record['last_success_at']);
    $this->assertStringContainsString('pid 12345', $record['notes']);
  }

  /**
   * @covers ::check
   *
   * The expected_finish_at has not yet elapsed; check() should keep the
   * record in pending state and not write a verdict.
   */
  public function testCheckStaysPendingBeforeExpectedFinish(): void {
    $this->setProbeState([
      'status' => 'pending',
      'last_attempt_at' => time(),
      'last_success_at' => NULL,
      'spawned_at' => time(),
      // Expected finish is in the future — too early to judge.
      'expected_finish_at' => time() + 60,
      'notes' => '',
    ]);

    $this->assertSame('pending', $this->probe->check());
    $this->assertSame('pending', $this->probe->getRecord()['status']);
  }

  /**
   * @covers ::check
   *
   * Expected finish + grace timeout have both elapsed and no marker
   * landed — the child was reaped or never ran. Verdict: failed.
   */
  public function testCheckResolvesToFailedAfterTimeoutWithoutMarker(): void {
    // PENDING_TIMEOUT_SECONDS is 1 hour; pretend the spawn was 2h ago.
    $longAgo = time() - 7200;
    $this->setProbeState([
      'status' => 'pending',
      'last_attempt_at' => $longAgo,
      'last_success_at' => NULL,
      'spawned_at' => $longAgo,
      'expected_finish_at' => $longAgo + 10,
      'notes' => '',
    ]);

    // No marker file present — simulating "child never finished."

    $this->assertSame('failed', $this->probe->check());
    $this->assertSame('failed', $this->probe->getRecord()['status']);
  }

  /**
   * @covers ::check
   *
   * If expected_finish_at has elapsed but only by a small margin (within
   * the grace window) and the marker hasn't arrived, the probe stays
   * pending rather than prematurely declaring failure. This protects
   * against tiny clock skew or a child that's seconds slow.
   */
  public function testCheckGracesAShortDelayBeforeFailing(): void {
    // Expected finish was 30 seconds ago — well inside the 1-hour grace.
    $now = time();
    $this->setProbeState([
      'status' => 'pending',
      'last_attempt_at' => $now - 60,
      'last_success_at' => NULL,
      'spawned_at' => $now - 60,
      'expected_finish_at' => $now - 30,
      'notes' => '',
    ]);

    $this->assertSame('pending', $this->probe->check());
  }

  /**
   * @covers ::check
   *
   * check() is a no-op (idempotent) for any non-pending status: success
   * stays success, failed stays failed, etc. This matters because cron
   * will call check() on every tick.
   */
  public function testCheckIsIdempotentForResolvedStates(): void {
    foreach (['success', 'failed', 'unsupported', 'never'] as $status) {
      $this->setProbeState([
        'status' => $status,
        'last_attempt_at' => time() - 60,
        'last_success_at' => $status === 'success' ? time() - 60 : NULL,
        'spawned_at' => NULL,
        'expected_finish_at' => NULL,
        'notes' => '',
      ]);
      $this->assertSame($status, $this->probe->check(), "Expected check() to leave $status untouched.");
    }
  }

  private function setProbeState(?array $record): void {
    $state = $this->container->get('state');
    if ($record === NULL) {
      $state->delete('drupal_cache_protection.warmer.probe');
    }
    else {
      $state->set('drupal_cache_protection.warmer.probe', $record);
    }
  }

}

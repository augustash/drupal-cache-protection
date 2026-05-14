<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_cache_protection\Kernel\Warmer;

use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_cache_protection\Warmer\CacheWarmer;
use Drupal\drupal_cache_protection\Warmer\CapabilityDetector;
use Drupal\drupal_cache_protection\Warmer\SurvivalProbe;

/**
 * Tests the strategy-selection state machine inside CacheWarmer.
 *
 * The interesting logic lives in:
 *   - selectStrategyName() — given prewarmer / caps / probe, picks a name
 *   - refreshStrategy()    — persists the picked name when it changes
 *   - reset()              — establishes install-time defaults
 *
 * Two design choices in these tests:
 *
 *   1. We use the REAL CapabilityDetector against the test environment.
 *      In DDEV (and most Linux CI images), exec/setsid/drush are all
 *      available — so the detector returns capabilities that make the
 *      detached strategy eligible. If the tests run on an image lacking
 *      those, the "promotes to detached" tests would fall through to
 *      post_response. Swap in a stub detector if that becomes a problem.
 *
 *   2. We use a test-double SurvivalProbe that no-ops the spawn() call.
 *      Otherwise maintainProbe() would fire a real `setsid bash` child
 *      every time, polluting the test environment with subprocesses we
 *      can't easily clean up. Probe RESOLUTION is tested separately in
 *      SurvivalProbeTest with real state + filesystem.
 *
 * @group aai
 * @group drupal_cache_protection
 */
class CacheWarmerTest extends KernelTestBase {

  protected static $modules = ['system', 'drupal_cache_protection'];

  private CapabilityDetector $capabilities;
  private NoopSpawnProbe $probe;

  protected function setUp(): void {
    parent::setUp();
    $this->capabilities = $this->container->get('drupal_cache_protection.warmer.capabilities');
    $this->probe = new NoopSpawnProbe(
      $this->container->get('state'),
      $this->container->get('file_system'),
      $this->capabilities,
    );
  }

  /**
   * Without cache_prewarmer (D < 11.2), the warmer parks at 'disabled'
   * regardless of any other inputs. There's nothing to warm.
   */
  public function testDisabledStrategyWhenPrewarmerUnavailable(): void {
    $warmer = $this->buildWarmerWithoutPrewarmer();
    $this->assertSame('disabled', $warmer->getStrategyName());

    $warmer->reset();
    $this->assertSame('disabled', $warmer->getStrategyName());
  }

  /**
   * On fresh install with prewarmer available, the warmer picks
   * post_response as the floor. Detached is the upgrade you earn by
   * passing a survival probe — never the default at install time.
   */
  public function testResetSetsDefaultStrategyToPostResponse(): void {
    $warmer = $this->buildWarmer();
    $warmer->reset();

    $this->assertSame('post_response', $warmer->getStrategyName());

    // reset() also clears any prior probe record and writes a fresh
    // capabilities snapshot.
    $state = $this->container->get('state');
    $this->assertNull($state->get('drupal_cache_protection.warmer.probe'));
    $this->assertIsArray($state->get('drupal_cache_protection.warmer.capabilities'));
  }

  /**
   * The whole reason the module exists: when the survival probe has
   * recently succeeded and the host can actually run detached children
   * (exec + setsid + drush), the warmer should promote to detached.
   *
   * Environment requirement: this test assumes the test runner has
   * exec/setsid/drush available. In DDEV that's the case.
   */
  public function testPromotesToDetachedAfterRecentProbeSuccess(): void {
    if (!$this->hostSupportsDetached()) {
      $this->markTestSkipped('Test environment lacks exec/setsid/drush.');
    }

    $this->seedProbeRecord([
      'status' => 'success',
      'last_attempt_at' => time() - 60,
      'last_success_at' => time() - 60,
      'spawned_at' => time() - 90,
      'expected_finish_at' => time() - 80,
      'notes' => '',
    ]);

    $warmer = $this->buildWarmer();
    $warmer->maintainProbe();

    $this->assertSame('detached', $warmer->getStrategyName());
  }

  /**
   * Inverse of the above: if the probe is recorded as failed, the
   * warmer must NOT promote to detached, even if the host caps look
   * fine. We hold at post_response.
   */
  public function testStaysAtPostResponseWhenProbeFailed(): void {
    $this->seedProbeRecord([
      'status' => 'failed',
      'last_attempt_at' => time() - 60,
      // The probe ran recently enough that shouldSpawn() returns false,
      // so our no-op spawn doesn't get a chance to mask this.
      'last_success_at' => time() - 30,
      'spawned_at' => time() - 90,
      'expected_finish_at' => time() - 80,
      'notes' => 'simulated failure',
    ]);

    $warmer = $this->buildWarmer();
    $warmer->reset();
    $warmer->maintainProbe();

    $this->assertSame('post_response', $warmer->getStrategyName());
  }

  /**
   * After 30 days, even a previously-successful probe is treated as
   * stale: shouldSpawn() returns true and the strategy demotes to
   * post_response until a fresh probe confirms the host still works.
   *
   * This is the safety net for platforms that change behavior over time
   * (e.g. a Pantheon container model update).
   */
  public function testStaleSuccessDemotesPendingReverification(): void {
    $stale = time() - (SurvivalProbe::REVERIFY_AFTER_SECONDS + 60);
    $this->seedProbeRecord([
      'status' => 'success',
      'last_attempt_at' => $stale,
      'last_success_at' => $stale,
      'spawned_at' => $stale - 30,
      'expected_finish_at' => $stale - 20,
      'notes' => '',
    ]);

    $warmer = $this->buildWarmer();
    $warmer->maintainProbe();

    // Reverification was requested (shouldSpawn=true), our test double
    // recorded the call without firing a real subprocess.
    $this->assertTrue($this->probe->spawnWasCalled, 'maintainProbe should have requested a fresh probe.');

    // While reverification is in flight, the strategy should sit at
    // post_response — we haven't earned 'detached' on the new probe yet.
    $this->assertSame('post_response', $warmer->getStrategyName());
  }

  /**
   * Requirements output is what ops sees on the status report. Verify
   * it includes the active strategy name, probe status, and a sensible
   * severity.
   */
  public function testRequirementsReflectActiveStrategy(): void {
    $warmer = $this->buildWarmer();
    $warmer->reset();

    $reqs = $warmer->getRequirements();
    $this->assertArrayHasKey('drupal_cache_protection_warmer', $reqs);

    $req = $reqs['drupal_cache_protection_warmer'];
    $this->assertSame('Cache Protection — Warmer', $req['title']);
    $this->assertStringContainsString('post_response', $req['value']);
    $this->assertSame('info', $req['severity']);
  }

  private function buildWarmer(): CacheWarmer {
    $prewarmer = $this->container->has('cache_prewarmer')
      ? $this->container->get('cache_prewarmer')
      : NULL;
    return $this->constructWarmer($prewarmer);
  }

  private function buildWarmerWithoutPrewarmer(): CacheWarmer {
    return $this->constructWarmer(NULL);
  }

  private function constructWarmer(?object $prewarmer): CacheWarmer {
    return new CacheWarmer(
      $prewarmer,
      $this->container->get('state'),
      $this->capabilities,
      $this->probe,
      $this->container->get('request_stack'),
      $this->container->getParameter('app.root'),
    );
  }

  private function seedProbeRecord(array $record): void {
    $this->container->get('state')->set('drupal_cache_protection.warmer.probe', $record);
  }

  private function hostSupportsDetached(): bool {
    $caps = $this->capabilities->detect();
    return $caps['exec'] && $caps['setsid'] && $caps['drush_path'] !== NULL;
  }

}

/**
 * Test double for SurvivalProbe that records spawn() invocations
 * without firing a real detached subprocess.
 */
class NoopSpawnProbe extends SurvivalProbe {

  public bool $spawnWasCalled = FALSE;

  public function spawn(): bool {
    $this->spawnWasCalled = TRUE;
    return TRUE;
  }

}

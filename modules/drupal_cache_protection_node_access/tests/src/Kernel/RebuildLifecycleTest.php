<?php

namespace Drupal\Tests\drupal_cache_protection_node_access\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\drupal_cache_protection_node_access\NodeAccess\GrantStorageDecorator;
use Drupal\drupal_cache_protection_node_access\RebuildTracker;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the grants-rebuild window is bracketed by marker and purge.
 *
 * @group aai
 * @group drupal_cache_protection_node_access
 */
class RebuildLifecycleTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'drupal_cache_protection',
    'drupal_cache_protection_node_access',
  ];

  /**
   * The tracker under test.
   */
  protected RebuildTracker $tracker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('node', ['node_access']);
    $this->tracker = $this->container->get('drupal_cache_protection_node_access.rebuild_tracker');
  }

  /**
   * The decorator must be in front of core's grant storage.
   */
  public function testGrantStorageIsDecorated(): void {
    $this->assertInstanceOf(GrantStorageDecorator::class, $this->container->get('node.grant_storage'));
  }

  /**
   * Delegation must be intact — a broken decorator silently breaks node access.
   */
  public function testDelegatesToCoreStorage(): void {
    $storage = $this->container->get('node.grant_storage');
    $storage->writeDefault();
    $this->assertSame(1, (int) $storage->count(), 'writeDefault() reached core storage.');

    $storage->delete();
    $this->assertSame(0, (int) $storage->count(), 'delete() truncated the real table.');
  }

  /**
   * The window opens when grants are deleted and closes when core says so.
   *
   * This is the reported bug in miniature: something rendered while the table
   * was empty must not survive the rebuild.
   */
  public function testRebuildWindowPurgesOnCompletion(): void {
    $state = $this->container->get('state');

    $this->seedRenderedEntry('pre_rebuild');
    $this->container->get('entity_type.manager')
      ->getAccessControlHandler('node')
      ->deleteGrants();

    $this->assertNotNull(
      $this->getRenderedEntry('pre_rebuild'),
      'Caches built under the old grants are kept — they are the best thing to serve while the table is empty, and purging here would dedupe away the purge that matters.'
    );
    $this->assertNotNull($state->get(RebuildTracker::STATE_KEY), 'The rebuild was marked.');
    $this->assertTrue((bool) $state->get(RebuildTracker::CORE_STATE_KEY), 'The completion signal was armed.');
    $this->assertTrue($this->tracker->isRebuilding());

    // A response rendered mid-rebuild — empty listings, cached permanently.
    $this->seedRenderedEntry('poisoned');

    $this->tracker->settle();
    $this->assertNotNull($this->getRenderedEntry('poisoned'), 'Nothing is purged while the rebuild is still in flight.');
    $this->assertTrue($this->tracker->isRebuilding(), 'The batch path spans requests; the marker must survive them.');

    // Core clears its flag as the last step of node_access_rebuild().
    $state->delete(RebuildTracker::CORE_STATE_KEY);
    $this->tracker->settle();

    $this->assertNull($this->getRenderedEntry('poisoned'), 'The empty render was purged once grants were back.');
    $this->assertNull($this->getRenderedEntry('pre_rebuild'), 'The single end-of-rebuild purge covers pre-window entries too.');
    $this->assertNull($state->get(RebuildTracker::STATE_KEY), 'The marker was cleared.');
    $this->assertFalse($this->tracker->isRebuilding(), 'Caching resumes after a completed rebuild.');
  }

  /**
   * Settling is idempotent and cheap when no rebuild has run.
   */
  public function testSettleNoopsWhenIdle(): void {
    $this->seedRenderedEntry('untouched');
    $this->tracker->settle();
    $this->assertNotNull($this->getRenderedEntry('untouched'));
  }

  /**
   * An abandoned rebuild must give the cache back rather than hold it forever.
   */
  public function testAbandonedRebuildStopsSuppressingCache(): void {
    $state = $this->container->get('state');
    $state->set(RebuildTracker::CORE_STATE_KEY, TRUE);
    $state->set(
      RebuildTracker::STATE_KEY,
      $this->container->get('datetime.time')->getRequestTime() - RebuildTracker::STALE_AFTER - 1
    );

    $this->assertFalse($this->tracker->isRebuilding(), 'A stale marker stops suppressing the page cache.');
    $this->assertNull($state->get(RebuildTracker::STATE_KEY), 'The stale marker was cleared.');
  }

  /**
   * Stores a cache entry tagged the way every rendered response is.
   */
  protected function seedRenderedEntry(string $cid): void {
    $this->container->get('cache.render')->set($cid, 'markup', Cache::PERMANENT, ['rendered']);
  }

  /**
   * Reads back a seeded entry, or NULL once invalidated.
   */
  protected function getRenderedEntry(string $cid): ?object {
    return $this->container->get('cache.render')->get($cid) ?: NULL;
  }

}

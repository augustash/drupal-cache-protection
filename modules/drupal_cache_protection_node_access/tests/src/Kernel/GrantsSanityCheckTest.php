<?php

namespace Drupal\Tests\drupal_cache_protection_node_access\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_cache_protection_node_access\RebuildTracker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests detection of a grants table emptied outside the decorator.
 *
 * @group aai
 * @group drupal_cache_protection_node_access
 */
class GrantsSanityCheckTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'dcpna_grants_test',
    'drupal_cache_protection',
    'drupal_cache_protection_node_access',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * A table emptied by raw SQL is caught and the guard opened.
   *
   * This is the gap the decorator cannot see: no ::delete() call, so no
   * marker, so nothing stops the site caching empty listings.
   */
  public function testEmptyGrantsWithPublishedNodesIsCaught(): void {
    $this->createPublishedNode();
    $this->truncateGrantsBehindTheDecorator();
    $this->seedRenderedEntry('poisoned');

    $this->runCheck();

    $this->assertNotNull(
      $this->container->get('state')->get(RebuildTracker::STATE_KEY),
      'The guard was opened, so nothing further gets cached empty.'
    );
    $this->assertTrue(
      (bool) $this->container->get('state')->get(RebuildTracker::CORE_STATE_KEY),
      'A rebuild was flagged for the status report.'
    );
    $this->assertNull($this->getRenderedEntry('poisoned'), 'Already-poisoned renders were dropped.');
    $this->assertTrue($this->container->get('drupal_cache_protection_node_access.rebuild_tracker')->isRebuilding());
  }

  /**
   * A healthy grants table is left completely alone.
   */
  public function testPopulatedGrantsAreNotTouched(): void {
    $this->createPublishedNode();
    $this->seedRenderedEntry('healthy');

    $this->runCheck();

    $this->assertNull($this->container->get('state')->get(RebuildTracker::STATE_KEY));
    $this->assertNotNull($this->getRenderedEntry('healthy'));
  }

  /**
   * An empty site empties the grants table legitimately — not an anomaly.
   */
  public function testEmptySiteIsNotAnAnomaly(): void {
    $this->truncateGrantsBehindTheDecorator();
    $this->seedRenderedEntry('empty_site');

    $this->runCheck();

    $this->assertNull($this->container->get('state')->get(RebuildTracker::STATE_KEY));
    $this->assertNotNull($this->getRenderedEntry('empty_site'), 'A site with no published nodes is not purged.');
  }

  /**
   * A rebuild we already know about must not be re-reported as an anomaly.
   */
  public function testInFlightRebuildIsNotTreatedAsAnomaly(): void {
    $this->createPublishedNode();
    $this->container->get('entity_type.manager')
      ->getAccessControlHandler('node')
      ->deleteGrants();
    $startedAt = $this->container->get('state')->get(RebuildTracker::STATE_KEY);

    $this->seedRenderedEntry('mid_rebuild');
    $this->runCheck();

    $this->assertSame(
      $startedAt,
      $this->container->get('state')->get(RebuildTracker::STATE_KEY),
      'The existing window was left as it was, not restarted.'
    );
    $this->assertNotNull($this->getRenderedEntry('mid_rebuild'), 'No extra purge during a known rebuild.');
  }

  /**
   * Runs the check the way cron does.
   */
  protected function runCheck(): void {
    $this->container->get('drupal_cache_protection_node_access.grants_sanity_check')->run();
  }

  /**
   * Creates a published node, which acquires its default grant on save.
   */
  protected function createPublishedNode(): void {
    Node::create(['type' => 'page', 'title' => 'Published', 'status' => 1])->save();
  }

  /**
   * Empties the table without going through the decorated service.
   */
  protected function truncateGrantsBehindTheDecorator(): void {
    $this->container->get('database')->truncate('node_access')->execute();
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

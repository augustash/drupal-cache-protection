<?php

namespace Drupal\Tests\drupal_cache_protection_node_access\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_cache_protection_node_access\GrantsSanityCheck;
use Drupal\drupal_cache_protection_node_access\RebuildTracker;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

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
    $this->installConfig(['user']);
    // Grants are checked against a real account, so uid 0 has to exist.
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
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

    $this->assertGreaterThan(
      0,
      (int) $this->container->get('node.grant_storage')->count(),
      'Grants were rebuilt without waiting for a human.'
    );
    $this->assertNull(
      $this->container->get('state')->get(RebuildTracker::STATE_KEY),
      'The window closed in the same run, so caching resumed immediately.'
    );
    $this->assertFalse($this->container->get('drupal_cache_protection_node_access.rebuild_tracker')->isRebuilding());
    $this->assertNull(
      $this->getRenderedEntry('poisoned'),
      'Anything cached while grants were missing was purged, now that purging re-renders correctly.'
    );
  }

  /**
   * The published node is visible again afterwards — the point of the exercise.
   */
  public function testRepairRestoresAnonymousAccess(): void {
    $node = $this->createPublishedNode();
    $this->truncateGrantsBehindTheDecorator();

    $anonymous = $this->container->get('entity_type.manager')->getStorage('user')->load(0);
    $this->assertFalse(
      $this->container->get('node.grant_storage')->access($node, 'view', $anonymous)->isAllowed(),
      'Precondition: with grants gone, anonymous cannot see the node.'
    );

    $this->runCheck();

    $this->assertTrue(
      $this->container->get('node.grant_storage')->access($node, 'view', $anonymous)->isAllowed(),
      'After the automatic repair the node is visible to anonymous again.'
    );
  }

  /**
   * A rebuild that never completes is retried, then abandoned loudly.
   *
   * Without the ceiling a site too large to finish inside a cron run would
   * truncate and half-refill on every tick, forever.
   */
  public function testRepairGivesUpAfterRepeatedFailures(): void {
    $this->createPublishedNode();
    $this->truncateGrantsBehindTheDecorator();
    $this->container->get('state')->set(GrantsSanityCheck::ATTEMPTS_KEY, GrantsSanityCheck::MAX_ATTEMPTS);

    $this->runCheck();

    $this->assertSame(
      0,
      (int) $this->container->get('node.grant_storage')->count(),
      'No further rebuild was attempted.'
    );
    $this->assertNotNull(
      $this->container->get('state')->get(RebuildTracker::STATE_KEY),
      'The guard keeps holding, so the site still never caches an empty listing.'
    );
  }

  /**
   * A half-filled table is repaired, not mistaken for a healthy one.
   *
   * Row count alone can't tell "populated" from "partially populated", so a
   * rebuild that died midway would otherwise look fine and the site would
   * settle into serving a subset of its content.
   */
  public function testPartiallyPopulatedTableIsRebuilt(): void {
    $visible = $this->createPublishedNode();
    $orphaned = $this->createPublishedNode();
    $this->container->get('node.grant_storage')->deleteNodeRecords([$orphaned->id()]);

    // The fingerprint of a repair that started and never reported completion.
    $this->container->get('state')->set(GrantsSanityCheck::ATTEMPTS_KEY, 1);
    $this->container->get('state')->set(RebuildTracker::CORE_STATE_KEY, TRUE);

    $anonymous = $this->container->get('entity_type.manager')->getStorage('user')->load(0);
    $this->assertFalse(
      $this->container->get('node.grant_storage')->access($orphaned, 'view', $anonymous)->isAllowed(),
      'Precondition: the orphaned node is invisible while its grant row is missing.'
    );

    $this->runCheck();

    $this->assertTrue(
      $this->container->get('node.grant_storage')->access($orphaned, 'view', $anonymous)->isAllowed(),
      'The missing grant was restored.'
    );
    $this->assertTrue(
      $this->container->get('node.grant_storage')->access($visible, 'view', $anonymous)->isAllowed(),
      'The node that was already fine stayed fine.'
    );
    $this->assertNull($this->container->get('state')->get(GrantsSanityCheck::ATTEMPTS_KEY));
  }

  /**
   * A human who rebuilt in the meantime is not second-guessed.
   *
   * Core clears its flag on a completed rebuild, so an outstanding attempt
   * without that flag means somebody already fixed it — no redundant rebuild,
   * and no redundant cache purge.
   */
  public function testManualRebuildIsRecognisedAndNotRedone(): void {
    $this->createPublishedNode();
    $orphaned = $this->createPublishedNode();
    $this->container->get('node.grant_storage')->deleteNodeRecords([$orphaned->id()]);
    $this->container->get('state')->set(GrantsSanityCheck::ATTEMPTS_KEY, 1);
    $this->container->get('state')->delete(RebuildTracker::CORE_STATE_KEY);

    $this->runCheck();

    $anonymous = $this->container->get('entity_type.manager')->getStorage('user')->load(0);
    $this->assertFalse(
      $this->container->get('node.grant_storage')->access($orphaned, 'view', $anonymous)->isAllowed(),
      'No rebuild ran — the deliberately missing row is still missing.'
    );
    $this->assertNull($this->container->get('state')->get(GrantsSanityCheck::ATTEMPTS_KEY));
  }

  /**
   * A healthy table resets the failure streak.
   */
  public function testHealthyGrantsClearTheAttemptCounter(): void {
    $this->createPublishedNode();
    $this->container->get('state')->set(GrantsSanityCheck::ATTEMPTS_KEY, 2);

    $this->runCheck();

    $this->assertNull(
      $this->container->get('state')->get(GrantsSanityCheck::ATTEMPTS_KEY),
      'A past streak must not count against the next genuine incident.'
    );
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
  protected function createPublishedNode(): Node {
    $node = Node::create(['type' => 'page', 'title' => 'Published', 'status' => 1]);
    $node->save();
    return $node;
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

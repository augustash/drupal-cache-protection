<?php

namespace Drupal\Tests\drupal_cache_protection_listing\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_cache_protection_listing\ListingSanityCheck;
use Drupal\exo_list_builder\Entity\EntityList;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Tests detection of a list that renders empty while content exists.
 *
 * @group aai
 * @group drupal_cache_protection_listing
 */
class ListingSanityCheckTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'dblog',
    'exo',
    'exo_list_builder',
    'dcpl_grants_test',
    'drupal_cache_protection',
    'drupal_cache_protection_listing',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['user', 'node', 'filter']);
    // Access is resolved against a real account, so uid 0 has to exist.
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->createList();
  }

  /**
   * Content that exists but is invisible to everyone is caught.
   *
   * This is the shape of the incident the module was written for: the rows are
   * there, the query is right, and the visitor is shown nothing.
   */
  public function testHiddenContentIsCaught(): void {
    $this->createPublishedNode();
    $this->emptyGrantsTable();
    $this->seedRenderedEntry('poisoned');

    $this->loadList();

    $this->assertTrue(
      $this->check()->isAnomalous(),
      'The response is marked so nothing may cache it.'
    );
    $this->assertCount(1, $this->logRecords(), 'The anomaly was reported once.');

    $this->check()->destruct();
    $this->assertNull(
      $this->getRenderedEntry('poisoned'),
      'What was cached while the list was broken has been dropped.'
    );
  }

  /**
   * A list with genuinely nothing to show is left completely alone.
   *
   * The common case by far — most empty lists are empty because the site has
   * no matching content — and it must cost nothing and say nothing.
   */
  public function testGenuinelyEmptyListIsIgnored(): void {
    $this->emptyGrantsTable();
    $this->seedRenderedEntry('healthy');

    $this->loadList();

    $this->assertFalse($this->check()->isAnomalous());
    $this->assertSame([], $this->logRecords(), 'Nothing was reported.');

    $this->check()->destruct();
    $this->assertNotNull(
      $this->getRenderedEntry('healthy'),
      'Nothing was purged, so an ordinary empty list stays cacheable.'
    );
  }

  /**
   * Unpublished content is hidden on purpose, so it is not an anomaly.
   *
   * Without this the module would report every list that happens to match only
   * unpublished rows, on every render — noise, not a finding.
   */
  public function testUnpublishedContentIsNotAnAnomaly(): void {
    $node = $this->createPublishedNode();
    $node->setUnpublished();
    $node->save();

    $this->loadList();

    $this->assertFalse($this->check()->isAnomalous());
    $this->assertSame([], $this->logRecords());
  }

  /**
   * Protection is unconditional; only the expensive half is rate limited.
   *
   * A persistent anomaly must keep every response uncacheable, while logging
   * and purging back off — otherwise the site spends itself re-rendering a
   * page it is about to throw away, and floods the log doing it.
   */
  public function testCooldownLimitsLoggingButNotProtection(): void {
    $this->createPublishedNode();
    $this->emptyGrantsTable();

    $this->loadList();
    $this->assertTrue($this->check()->isAnomalous());
    $this->assertCount(1, $this->logRecords());

    // A fresh instance is what the next request would get.
    $next = $this->freshCheck();
    $next->check([], EntityList::load('dcpl_test_list'));

    $this->assertTrue(
      $next->isAnomalous(),
      'The second request is still kept out of every cache.'
    );
    $this->assertCount(1, $this->logRecords(), 'It was not reported a second time.');
  }

  /**
   * The report says which list, how much is hidden, and what the grants are.
   *
   * The log is the only artefact left for whoever has to find the cause, so
   * its content is part of the contract.
   */
  public function testReportCarriesDiagnosticContext(): void {
    $this->createPublishedNode();
    $this->createPublishedNode();
    $this->emptyGrantsTable();

    $this->loadList();

    $variables = unserialize(
      $this->logRecords()[0]->variables,
      ['allowed_classes' => FALSE]
    );
    $this->assertSame('dcpl_test_list', $variables['@list']);
    $this->assertSame(2, $variables['@count'], 'Says how much content is hidden.');
    $this->assertStringContainsString('{node_access} rows: 0', $variables['@grants']);
  }

  /**
   * Builds a minimal list over the page bundle.
   */
  protected function createList(): void {
    EntityList::create([
      'id' => 'dcpl_test_list',
      'label' => 'Test list',
      'target_entity_type' => 'node',
      'target_bundles_include' => ['page' => 'page'],
      'target_bundles_exclude' => [],
      'format' => 'list',
      'key' => 'q',
      'limit' => 10,
      'fields' => [],
      'sorts' => [],
      'actions' => [],
      'references' => [],
    ])->save();
  }

  /**
   * Runs the list through the seam the module hooks into.
   */
  protected function loadList(): void {
    EntityList::load('dcpl_test_list')->getHandler()->load();
  }

  /**
   * The service under test, as the container holds it for this request.
   *
   * @return \Drupal\drupal_cache_protection_listing\ListingSanityCheck
   *   The shared instance.
   */
  protected function check(): ListingSanityCheck {
    return $this->container->get('drupal_cache_protection_listing.sanity_check');
  }

  /**
   * An instance with no request state, standing in for the next request.
   *
   * @return \Drupal\drupal_cache_protection_listing\ListingSanityCheck
   *   A new instance wired to the same services.
   */
  protected function freshCheck(): ListingSanityCheck {
    return new ListingSanityCheck(
      $this->container->get('current_route_match'),
      $this->container->get('router.admin_context'),
      $this->container->get('state'),
      $this->container->get('cache_tags.invalidator'),
      $this->container->get('datetime.time'),
      $this->container->get('current_user'),
      $this->container->get('logger.channel.drupal_cache_protection_listing'),
      $this->container->get('node.grant_storage'),
    );
  }

  /**
   * Creates a published node of the bundle the list targets.
   *
   * @return \Drupal\node\Entity\Node
   *   The saved node.
   */
  protected function createPublishedNode(): Node {
    $node = Node::create(['type' => 'page', 'title' => 'Published', 'status' => 1]);
    $node->save();
    return $node;
  }

  /**
   * Empties {node_access} without going through the grant storage service.
   */
  protected function emptyGrantsTable(): void {
    $this->container->get('database')->truncate('node_access')->execute();
  }

  /**
   * Stands in for a page cached while the list was rendering wrongly.
   */
  protected function seedRenderedEntry(string $cid): void {
    $tags = EntityList::load('dcpl_test_list')->getHandler()->getCacheTags();
    $this->container->get('cache.render')->set($cid, 'cached', Cache::PERMANENT, $tags);
  }

  /**
   * Reads back a seeded render cache entry.
   *
   * @return object|null
   *   The cache item, or NULL once it has been invalidated.
   */
  protected function getRenderedEntry(string $cid) {
    $cache = $this->container->get('cache.render')->get($cid);
    return $cache === FALSE ? NULL : $cache;
  }

  /**
   * The anomalies reported so far.
   *
   * @return object[]
   *   Watchdog rows for this module's error channel, oldest first.
   */
  protected function logRecords(): array {
    return $this->container->get('database')->select('watchdog', 'w')
      ->fields('w')
      ->condition('type', 'drupal_cache_protection_listing')
      ->condition('severity', RfcLogLevel::ERROR)
      ->orderBy('wid')
      ->execute()
      ->fetchAll();
  }

}

<?php

declare(strict_types=1);

namespace Drupal\drupal_cache_protection_node_access\NodeAccess;

use Drupal\Core\Session\AccountInterface;
use Drupal\drupal_cache_protection_node_access\RebuildTracker;
use Drupal\node\NodeGrantDatabaseStorageInterface;
use Drupal\node\NodeInterface;

/**
 * Notices when the node access grants table is wiped.
 *
 * ::delete() truncates {node_access}. It is called by
 * NodeAccessControlHandler::deleteGrants(), which in core has exactly one
 * non-test caller: node_access_rebuild(). So it is the precise start of the
 * window in which every node listing renders empty — the one moment we need
 * to know about, and there is no hook for it.
 *
 * Every other method delegates untouched. ::access() and ::alterQuery() run on
 * effectively every node query, so nothing may be added to them.
 */
final class GrantStorageDecorator implements NodeGrantDatabaseStorageInterface {

  public function __construct(
    protected NodeGrantDatabaseStorageInterface $inner,
    protected RebuildTracker $tracker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function delete() {
    $this->tracker->start();
    return $this->inner->delete();
  }

  /**
   * {@inheritdoc}
   */
  public function checkAll(AccountInterface $account) {
    return $this->inner->checkAll($account);
  }

  /**
   * {@inheritdoc}
   */
  public function alterQuery($query, array $tables, $operation, AccountInterface $account, $base_table) {
    return $this->inner->alterQuery($query, $tables, $operation, $account, $base_table);
  }

  /**
   * {@inheritdoc}
   */
  public function write(NodeInterface $node, array $grants, $realm = NULL, $delete = TRUE) {
    return $this->inner->write($node, $grants, $realm, $delete);
  }

  /**
   * {@inheritdoc}
   */
  public function writeDefault() {
    return $this->inner->writeDefault();
  }

  /**
   * {@inheritdoc}
   */
  public function access(NodeInterface $node, $operation, AccountInterface $account) {
    return $this->inner->access($node, $operation, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function count() {
    return $this->inner->count();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteNodeRecords(array $nids) {
    return $this->inner->deleteNodeRecords($nids);
  }

}

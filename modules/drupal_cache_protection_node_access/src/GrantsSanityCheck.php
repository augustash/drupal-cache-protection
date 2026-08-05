<?php

declare(strict_types=1);

namespace Drupal\drupal_cache_protection_node_access;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\node\NodeGrantDatabaseStorageInterface;
use Psr\Log\LoggerInterface;

/**
 * Catches an empty grants table that never passed through the decorator.
 *
 * RebuildTracker learns about a rebuild from
 * NodeGrantDatabaseStorage::delete(). Anything that empties {node_access}
 * without going through that service is invisible to it — a hand-run
 * "TRUNCATE node_access", or a database import carrying a table that was empty
 * (or mid-rebuild) when it was dumped. Pantheon's env clone makes the second
 * one plausible enough to guard.
 *
 * With no marker set, nothing suppresses caching, so the site quietly caches
 * empty listings forever exactly as it did before this module existed. This
 * check turns that from "stuck until a human notices" into "self-heals within
 * a cron cycle".
 */
final class GrantsSanityCheck {

  public function __construct(
    protected NodeGrantDatabaseStorageInterface $grantStorage,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected RebuildTracker $tracker,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Looks for an impossible state and opens the guard if it finds one.
   */
  public function run(): void {
    // Without a hook_node_grants() implementation core short-circuits
    // node_query_node_access_alter() before it ever joins {node_access}, so an
    // empty table is harmless and this check has nothing to say.
    if (!$this->moduleHandler->hasImplementations('node_grants')) {
      return;
    }

    // A rebuild we already know about is not an anomaly — it is the very thing
    // the tracker exists to sit through.
    if ($this->tracker->isRebuilding()) {
      return;
    }

    if ((int) $this->grantStorage->count() > 0) {
      return;
    }

    // A site with no published nodes has an empty grants table legitimately.
    if (!$this->hasPublishedNodes()) {
      return;
    }

    $this->logger->error('The node access grants table is empty while published nodes exist. Listings will render empty for anonymous visitors. Page caching has been suspended and a grants rebuild flagged — run "drush php:eval \'node_access_rebuild();\'" to restore it.');

    // Open the window: this is exactly the state the guard was written for,
    // and it also arms core's flag so the rebuild is surfaced on the status
    // report. ::start() deliberately does not purge, so the invalidation below
    // is not deduped away from the purge that ::settle() runs later.
    $this->tracker->start();
    $this->cacheTagsInvalidator->invalidateTags(['rendered']);
  }

  /**
   * Whether any published node exists.
   */
  protected function hasPublishedNodes(): bool {
    $result = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->range(0, 1)
      ->execute();
    return !empty($result);
  }

}

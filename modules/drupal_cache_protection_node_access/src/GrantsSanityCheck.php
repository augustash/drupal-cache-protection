<?php

declare(strict_types=1);

namespace Drupal\drupal_cache_protection_node_access;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
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

  /**
   * State key counting consecutive incomplete automatic rebuilds.
   */
  public const ATTEMPTS_KEY = 'drupal_cache_protection_node_access.repair_attempts';

  /**
   * How many times to attempt an automatic rebuild before giving up.
   */
  public const MAX_ATTEMPTS = 3;

  public function __construct(
    protected NodeGrantDatabaseStorageInterface $grantStorage,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected RebuildTracker $tracker,
    protected StateInterface $state,
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

    $count = (int) $this->grantStorage->count();

    // A repair we started but never confirmed leaves the table *partially*
    // filled, which reads as healthy on row count alone — so the check would
    // never retry, and the site would quietly settle into serving a subset of
    // its content. An outstanding attempt with core's flag still set is that
    // state: our rebuild never reached the line that clears it. A human who
    // rebuilt in the meantime does clear it, so their fix is recognised and
    // no redundant rebuild runs.
    $incomplete = (int) $this->state->get(self::ATTEMPTS_KEY, 0) > 0
      && (bool) $this->state->get(RebuildTracker::CORE_STATE_KEY, FALSE);

    if ($count > 0 && !$incomplete) {
      // Healthy — including after a manual rebuild, so a past failure streak
      // must not count against the next genuine incident.
      $this->state->delete(self::ATTEMPTS_KEY);
      return;
    }

    // A site with no published nodes has an empty grants table legitimately.
    if (!$this->hasPublishedNodes()) {
      $this->state->delete(self::ATTEMPTS_KEY);
      return;
    }

    $this->logger->error($count === 0
      ? 'The node access grants table is empty while published nodes exist — listings would render empty for anonymous visitors. Suspending page caching and rebuilding grants now.'
      : 'A previous automatic grants rebuild never confirmed completion, so {node_access} may be partially populated (@count records) and some content invisible. Suspending page caching and rebuilding grants now.',
      ['@count' => $count]);

    // Open the window first, so that if the rebuild below dies partway the
    // guard is already holding and nothing caches empty in the meantime.
    //
    // Nothing is purged here. The cache currently holds a mix of pages
    // rendered before the table was emptied (correct, and the only thing still
    // serving properly) and after (empty). Purging cannot tell them apart, and
    // re-rendering a poisoned one just reproduces it while grants are still
    // missing — so it gains nothing and costs the good ones. Cache *hits* keep
    // serving throughout; the kill switch only prevents storing.
    $this->tracker->start();

    $this->repair();
  }

  /**
   * Rebuilds the grants, which is the whole point of noticing.
   *
   * Logging and waiting for a human means the site serves empty listings until
   * someone happens to look. A rebuild costs a cold cache for a few minutes;
   * that is plainly the better trade, and it needs nobody to show up.
   *
   * Bounded because it is the one operation that could fail the same way every
   * time: a site large enough that the rebuild cannot finish inside a cron run
   * would otherwise truncate and half-refill the table on every tick forever.
   * After MAX_ATTEMPTS it stops and says so, which is the only case that
   * degrades to needing a human.
   */
  protected function repair(): void {
    $attempts = (int) $this->state->get(self::ATTEMPTS_KEY, 0);
    if ($attempts >= self::MAX_ATTEMPTS) {
      $this->logger->error('Automatic grants rebuild has failed to complete @count times and will not be retried. Page caching stays suspended. Run "drush php:eval \'node_access_rebuild();\'" from the command line, where it is not bound by a cron run.', [
        '@count' => $attempts,
      ]);
      return;
    }
    $this->state->set(self::ATTEMPTS_KEY, $attempts + 1);

    // Core raises its own time limit and resets entity storage per node, so
    // this is bounded in memory if not in wall clock. It truncates first,
    // which is a no-op on the already-empty table that got us here.
    node_access_rebuild();

    if ((int) $this->grantStorage->count() === 0) {
      // Did not finish. The marker is still set, so the guard keeps holding
      // and the next tick retries once the tracker's staleness window lapses.
      $this->logger->error('Automatic grants rebuild did not populate the table. Page caching stays suspended; will retry.');
      return;
    }

    $this->state->delete(self::ATTEMPTS_KEY);
    // Grants are back, so now the purge is both correct and necessary — it is
    // what clears anything cached empty before the guard went up. Settling
    // here rather than waiting for kernel.terminate means the site is serving
    // correctly by the time cron returns.
    $this->tracker->settle();
    $this->logger->notice('Rebuilt node access grants automatically (@count records). Page caching resumed.', [
      '@count' => $this->grantStorage->count(),
    ]);
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

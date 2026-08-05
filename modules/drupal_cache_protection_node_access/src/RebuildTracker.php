<?php

declare(strict_types=1);

namespace Drupal\drupal_cache_protection_node_access;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Tracks whether a node access grants rebuild is in flight.
 *
 * Why this exists: node_access_rebuild() truncates {node_access} before it
 * refills it row by row (node.module). While the table is empty, every node
 * listing query returns zero rows for anyone without "bypass node access" —
 * so anonymous requests render empty listings. Those responses land in the
 * Internal Page Cache with CACHE_PERMANENT, and nothing ever evicts them:
 * grants are exposed to the cache as a *context* ("user.node_grants:view"),
 * never as a tag, and core's rebuild invalidates nothing at all. The empty
 * page outlives the rebuild indefinitely.
 *
 * So we bracket the rebuild: suppress caching while it runs, and purge the
 * render cache when it finishes.
 *
 * Detecting "finished" is the hard half. The only thing core does at the end
 * of a rebuild — in both the batch and non-batch paths — is clear the
 * "node.node_access_needs_rebuild" state flag. That flag is not guaranteed to
 * have been set when the rebuild started, so ::start() sets it, making its
 * removal an unambiguous completion signal we can watch from any process.
 */
final class RebuildTracker implements DestructableInterface {

  /**
   * State key holding the request time at which the rebuild started.
   */
  public const STATE_KEY = 'drupal_cache_protection_node_access.rebuild_started';

  /**
   * Core's own "grants are stale" flag, which core clears when a rebuild ends.
   */
  public const CORE_STATE_KEY = 'node.node_access_needs_rebuild';

  /**
   * Seconds after which an unfinished rebuild is treated as abandoned.
   *
   * A rebuild killed by a PHP timeout would otherwise leave the page cache
   * suppressed forever. Losing the cache is a worse failure than the one we
   * are guarding against, so we give up and log instead.
   */
  public const STALE_AFTER = 3600;

  public function __construct(
    protected StateInterface $state,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Records that a rebuild has begun.
   *
   * Deliberately does *not* purge. Caches built under the previous grants are
   * the best thing the site has to serve right now — dropping them here would
   * push visitors onto the render path at the one moment it produces empty
   * listings, which is the failure we are preventing. Purging once, at the
   * end, is also what keeps the end purge effective: core dedupes repeated
   * invalidations of a tag within a process (DatabaseCacheTagsChecksum), so a
   * purge here would silently cancel ::settle()'s purge for any rebuild that
   * starts and finishes in one process — drush, or the non-batch call.
   */
  public function start(): void {
    $this->state->set(self::STATE_KEY, $this->time->getRequestTime());
    // Guarantee the completion signal exists. If the rebuild was triggered
    // without this flag set, core still clears it on the way out, so setting
    // it here is what makes ::settle() reliable.
    $this->state->set(self::CORE_STATE_KEY, TRUE);
  }

  /**
   * Whether a rebuild is currently in flight.
   */
  public function isRebuilding(): bool {
    $startedAt = $this->state->get(self::STATE_KEY);
    if ($startedAt === NULL) {
      return FALSE;
    }

    if ($this->time->getRequestTime() - (int) $startedAt > self::STALE_AFTER) {
      $this->logger->warning('A node access grants rebuild started @seconds seconds ago and never reported completion. Page caching is being re-enabled; run "drush php:eval \'node_access_rebuild();\'" and verify {node_access} is populated.', [
        '@seconds' => $this->time->getRequestTime() - (int) $startedAt,
      ]);
      $this->clear();
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Closes out a completed rebuild, purging anything cached while it ran.
   *
   * Safe and cheap to call from anywhere: it no-ops unless a rebuild is
   * marked and core has since cleared its flag.
   */
  public function settle(): void {
    if ($this->state->get(self::STATE_KEY) === NULL) {
      return;
    }
    if ($this->state->get(self::CORE_STATE_KEY, FALSE)) {
      // Still in flight. The batch path spans several requests, so an
      // unfinished rebuild here is normal, not an error.
      return;
    }
    $this->clear();
    $this->purge();
  }

  /**
   * {@inheritdoc}
   *
   * Catches the common case — a rebuild run start-to-finish inside one
   * process (drush, or the non-batch call) — without waiting for the next
   * request to notice.
   */
  public function destruct(): void {
    $this->settle();
  }

  /**
   * Drops the rebuild marker.
   */
  protected function clear(): void {
    $this->state->delete(self::STATE_KEY);
  }

  /**
   * Invalidates every rendered response.
   *
   * "rendered" is deliberately broad. A grants change alters what any user may
   * see anywhere, and the poisoned pages carry no tag that identifies them —
   * an empty listing has no entity tags on it precisely because it rendered
   * no entities. On Pantheon this also reaches the edge, since
   * pantheon_advanced_page_cache surfaces cache tags as surrogate keys.
   */
  protected function purge(): void {
    $this->cacheTagsInvalidator->invalidateTags(['rendered']);
  }

}

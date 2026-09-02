<?php

declare(strict_types=1);

namespace Drupal\drupal_cache_protection_listing;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\exo_list_builder\EntityListInterface;
use Drupal\exo_list_builder\ExoListBuilderInterface;
use Drupal\node\NodeGrantDatabaseStorageInterface;
use Psr\Log\LoggerInterface;

/**
 * Catches a list that renders nothing while matching content exists.
 *
 * Why this exists: an entity list is cached under the contexts it declares and
 * evicted by the tags it carries — and an empty listing carries neither the
 * entities it failed to show nor anything naming the reason it failed. So the
 * moment a list renders empty for a reason that is *not* "there is nothing to
 * show", that response is stored with nothing to evict it. Observed on MSP: two
 * category pages served "There are no content items yet." from the internal
 * page cache for six days, and a third from the render cache, while the query
 * behind them returned 40, 20 and 14 rows respectively.
 *
 * The check is deliberately narrow. It runs only when a list came back empty,
 * and asks one question: would the same query return rows if access were not
 * checked? A genuinely empty list answers no and nothing happens — the common
 * case costs one early return. A list whose rows exist but are all invisible
 * answers yes, and that is not a state worth caching for anybody.
 *
 * It cannot see every way a list goes wrong. A render that returns the *wrong*
 * rows rather than none — a route-derived filter that failed to resolve, say —
 * looks healthy from here. Bounding max-age on the pages that matter is the
 * companion to this, not something it replaces.
 */
final class ListingSanityCheck implements DestructableInterface {

  /**
   * State key holding recent detections, keyed by list + route signature.
   */
  public const COOLDOWN_KEY = 'drupal_cache_protection_listing.last_seen';

  /**
   * Seconds before the same list/route is worth logging and purging again.
   *
   * A persistent anomaly would otherwise log on every request and invalidate
   * on every request — and each invalidation forces a re-render that lands in
   * the same state, so the site would spend itself rebuilding a page it is
   * about to throw away. Suppressing the *response* from being cached is free
   * and stays unconditional; only the expensive half is rate limited.
   */
  public const COOLDOWN = 900;

  /**
   * Whether this request rendered a list that should not be cached.
   */
  protected bool $anomalous = FALSE;

  /**
   * Cache tags to invalidate once the response is out the door.
   *
   * @var string[]
   */
  protected array $purgeTags = [];

  public function __construct(
    protected RouteMatchInterface $routeMatch,
    protected AdminContext $adminContext,
    protected StateInterface $state,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected TimeInterface $time,
    protected AccountInterface $currentUser,
    protected LoggerInterface $logger,
    protected ?NodeGrantDatabaseStorageInterface $grantStorage = NULL,
  ) {}

  /**
   * Inspects a list that has just loaded its entities.
   *
   * @param array $entities
   *   The entities the list loaded. Empty is the only interesting case.
   * @param \Drupal\exo_list_builder\EntityListInterface $entity_list
   *   The list being rendered.
   */
  public function check(array $entities, EntityListInterface $entity_list): void {
    if ($entities !== []) {
      return;
    }

    // Admin listings are not shared-cached, so a poisoned render harms nobody,
    // and they legitimately show access-filtered results to every user.
    $route = $this->routeMatch->getRouteObject();
    if ($route && $this->adminContext->isAdminRoute($route)) {
      return;
    }

    $handler = $entity_list->getHandler();
    if (!$handler instanceof ExoListBuilderInterface) {
      return;
    }

    $hidden = $this->countIgnoringAccess($handler);
    if ($hidden < 1) {
      // Nothing to show. The list is right and this is not our business.
      return;
    }

    // Unconditional: whatever else we do, this response must not be stored.
    $this->anomalous = TRUE;

    $signature = $this->signature($entity_list);
    if (!$this->cooldownElapsed($signature)) {
      return;
    }
    $this->noteSeen($signature);

    $this->logger->error('The "@list" list rendered no results on @route while @count matching item(s) exist — every one of them is invisible to this request. Caching is suppressed for this response and anything already cached for it is being purged. Filters: @filters. User: @user (roles: @roles).@grants', [
      '@list' => $entity_list->id(),
      '@route' => $this->describeRoute(),
      '@count' => $hidden,
      '@filters' => $this->describeFilters($handler),
      '@user' => $this->currentUser->id(),
      '@roles' => implode(', ', $this->currentUser->getRoles()),
      '@grants' => $this->describeGrants(),
    ]);

    $this->purgeTags = $this->purgeTagsFor($handler);
  }

  /**
   * Whether this request produced a listing that must not be cached.
   */
  public function isAnomalous(): bool {
    return $this->anomalous;
  }

  /**
   * {@inheritdoc}
   *
   * Purging happens here rather than at response time because the render cache
   * entry does not exist yet when the response is built — the kill switch keeps
   * the page and dynamic caches from storing it, but the fragment underneath is
   * written on the way out regardless. Destruction runs after that, in both web
   * and CLI processes, which is the first moment the bad entry can be dropped.
   */
  public function destruct(): void {
    if ($this->purgeTags === []) {
      return;
    }
    $this->cacheTagsInvalidator->invalidateTags($this->purgeTags);
    $this->purgeTags = [];
  }

  /**
   * Counts what the same query would return with access checking disabled.
   *
   * ExoListBuilderBase::buildQuery() sets accessCheck(TRUE), so the difference
   * between this count and the empty result is exactly "content that exists but
   * nobody may see" — the shape of a grants gap, and of anything else that
   * makes access collapse.
   */
  protected function countIgnoringAccess(ExoListBuilderInterface $handler): int {
    try {
      $query = clone $handler->getQuery('all');
      // The live query may carry the pager's range; a count within a range
      // would understate the answer.
      $query->range(NULL, NULL);
      $query->accessCheck(FALSE);

      // Unpublished content is hidden on purpose. Without this, any list whose
      // filters don't already exclude it — and which happens to match only
      // unpublished rows — would be reported on every render, which is noise,
      // not a finding.
      $entity_type = $handler->getEntityList()->getTargetEntityType();
      if ($entity_type->hasKey('published')) {
        $query->condition($entity_type->getKey('published'), 1);
      }

      return (int) $query->count()->execute();
    }
    catch (\Throwable $e) {
      // This runs on every empty listing on the site. It may never be the
      // reason a page fails to render.
      $this->logger->warning('Could not probe the "@list" list for hidden results: @message', [
        '@list' => $handler->getEntityList()->id(),
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * The tags whose invalidation drops the render just cached.
   *
   * The entity the route resolved to is the tightest available handle: it is
   * what the entity view builder keyed the poisoned build under. Falling back
   * to the list's own tags is broader than we would like, but a bad render with
   * no way to reach it is worse.
   */
  protected function purgeTagsFor(ExoListBuilderInterface $handler): array {
    $tags = [];
    foreach ($this->routeMatch->getParameters()->all() as $value) {
      if ($value instanceof EntityInterface) {
        $tags = Cache::mergeTags($tags, $value->getCacheTags());
      }
    }
    return $tags ?: $handler->getCacheTags();
  }

  /**
   * Whether enough time has passed to log and purge this signature again.
   */
  protected function cooldownElapsed(string $signature): bool {
    $seen = $this->state->get(self::COOLDOWN_KEY, []);
    $last = (int) ($seen[$signature] ?? 0);
    return ($this->time->getRequestTime() - $last) > self::COOLDOWN;
  }

  /**
   * Records this signature, dropping any that have aged out.
   *
   * Pruning on write is what keeps the map bounded without a separate cron job:
   * a site cannot accumulate more entries than it saw inside one window.
   */
  protected function noteSeen(string $signature): void {
    $now = $this->time->getRequestTime();
    $seen = $this->state->get(self::COOLDOWN_KEY, []);
    $seen = array_filter($seen, fn ($timestamp) => ($now - (int) $timestamp) <= self::COOLDOWN);
    $seen[$signature] = $now;
    $this->state->set(self::COOLDOWN_KEY, $seen);
  }

  /**
   * A stable identity for "this list, on this page".
   */
  protected function signature(EntityListInterface $entity_list): string {
    $parts = [$entity_list->id(), $this->routeMatch->getRouteName() ?? '<none>'];
    foreach ($this->routeMatch->getParameters()->all() as $name => $value) {
      if ($value instanceof EntityInterface) {
        $parts[] = $name . ':' . $value->id();
      }
      elseif (is_scalar($value)) {
        $parts[] = $name . ':' . $value;
      }
    }
    return implode('|', $parts);
  }

  /**
   * Route name and parameters, for the log.
   */
  protected function describeRoute(): string {
    $name = $this->routeMatch->getRouteName() ?? '<no route>';
    $params = [];
    foreach ($this->routeMatch->getParameters()->all() as $key => $value) {
      if ($value instanceof EntityInterface) {
        $params[] = $key . '=' . $value->getEntityTypeId() . ':' . $value->id();
      }
      elseif (is_scalar($value)) {
        $params[] = $key . '=' . $value;
      }
    }
    return $params ? $name . ' (' . implode(', ', $params) . ')' : $name;
  }

  /**
   * The resolved filter values, which are usually where the answer is.
   */
  protected function describeFilters(ExoListBuilderInterface $handler): string {
    $values = [];
    foreach (array_keys($handler->getFilters()) as $field_id) {
      try {
        $value = $handler->getFilterValue($field_id);
      }
      catch (\Throwable $e) {
        $value = '<error: ' . $e->getMessage() . '>';
      }
      if ($value !== NULL && $value !== '' && $value !== []) {
        $values[$field_id] = $value;
      }
    }
    return $values ? json_encode($values) : '<none>';
  }

  /**
   * The grants table size, when this site has one worth reporting.
   *
   * Named explicitly because an empty {node_access} is the likeliest single
   * cause of "everything is invisible", and the number turns a guess into a
   * finding.
   */
  protected function describeGrants(): string {
    if (!$this->grantStorage) {
      return '';
    }
    try {
      return ' {node_access} rows: ' . $this->grantStorage->count() . '.';
    }
    catch (\Throwable) {
      return '';
    }
  }

}

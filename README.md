# Drupal Cache Protection

Protects Drupal page cache from fragmentation by tracking parameters and bot abuse.

## Install

```sh
ddev composer config --json --merge extra.drupal-scaffold.allowed-packages '["augustash/drupal_cache_protection"]' && ddev composer require augustash/drupal_cache_protection && ddev drush en -y drupal_cache_protection
```

## Submodules

The parent module always belongs on. Submodules are opt-in based on what features the site exposes — enable each only when its trigger is present.

### `drupal_cache_protection_facets`

**Enable when:** `drupal/facets` is enabled on the site.

Facet bot protection — count throttle, alias validation, per-IP rate limit on faceted requests. Skip on sites without faceted browsing; the middleware would only inspect requests that never reach it.

### `drupal_cache_protection_search`

**Enable when:** any search exposure is present — Drupal core Search, `search_api`, Solr, or a custom search route reachable from the front end.

Per-IP rate limiting and page-cache kill switch on search routes. Bots blast unique queries to fragment `cache_page` and overload Solr — this throttles them and prevents the responses from being cached.

- Two flood windows (burst + sustained), either limit triggers a 429.
- Only acts when a configured search query parameter is present (e.g. `?s=...`), so the empty search form stays cacheable.
- Configure at `/admin/config/search/cache-protection/search`.

### `drupal_cache_protection_node_access`

**Enable when:** any module implements `hook_node_grants()` — `node_unpublished`, `group`, `domain_access`, `workbench_access`, `content_access`, and friends. Check with `drush ev 'var_dump(Drupal::moduleHandler()->hasImplementations("node_grants"));'`; the module's status report entry tells you if it has nothing to do.

Stops a node access grants rebuild from permanently caching empty content listings.

`node_access_rebuild()` truncates `{node_access}` before refilling it row by row. While the table is empty every node listing query returns zero rows for anyone without *bypass node access*, so anonymous requests render **empty listings** — and the Internal Page Cache stores them with `CACHE_PERMANENT`. Nothing ever evicts them: grants reach the cache as a *context* (`user.node_grants:view`), never as a tag, and core's rebuild invalidates nothing. The empty page outlives the rebuild indefinitely, until an unrelated node save or a full cache flush happens to clear it.

It reads as a content or search-index bug, not a caching one, because the listing is simply gone.

- Marks the window open when the grants table is wiped, and arms core's own `node.node_access_needs_rebuild` flag so its removal is an unambiguous completion signal.
- While open: `page_cache_kill_switch` (covers both `page_cache` and `dynamic_page_cache`) plus `Cache-Control: no-store`, so a reverse proxy such as Pantheon's Varnish can't hold the empty page either.
- Already-cached pages are deliberately **left alone** during the window — stale-but-correct beats empty, and it keeps visitors off the render path while it's broken.
- On completion, invalidates `rendered` once. That's the only tag that reaches a poisoned page: an empty listing carries no entity tags precisely because it rendered no entities.
- An abandoned rebuild stops suppressing the cache after an hour and logs a warning; the status report flags it before that.

Nothing to configure.

## Enabling

```sh
# Always:
ddev drush en -y drupal_cache_protection

# Add when applicable:
ddev drush en -y drupal_cache_protection_facets       # only if drupal/facets is enabled
ddev drush en -y drupal_cache_protection_search       # if any search route is exposed
ddev drush en -y drupal_cache_protection_node_access  # if any module implements hook_node_grants()
```

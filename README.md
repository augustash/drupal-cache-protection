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

## Enabling

```sh
# Always:
ddev drush en -y drupal_cache_protection

# Add when applicable:
ddev drush en -y drupal_cache_protection_facets   # only if drupal/facets is enabled
ddev drush en -y drupal_cache_protection_search   # if any search route is exposed
```

# Drupal Cache Protection

Protects Drupal page cache from fragmentation by tracking parameters and bot abuse.

## Install

```sh
ddev composer config --json --merge extra.drupal-scaffold.allowed-packages '["augustash/drupal_cache_protection"]' && ddev composer require augustash/drupal_cache_protection && ddev drush en -y drupal_cache_protection
```

## Submodules

Each submodule is opt-in.

### `drupal_cache_protection_facets`

Facet bot protection. Enable only on sites that use `drupal/facets`.

### `drupal_cache_protection_search`

Per-IP rate limiting and page-cache kill switch on search routes. Bots blast unique queries to fragment `cache_page` and overload Solr — this throttles them and prevents the responses from being cached.

- Two flood windows (burst + sustained), either limit triggers a 429.
- Only acts when a configured search query parameter is present (e.g. `?s=...`), so the empty search form stays cacheable.
- Configure at `/admin/config/search/cache-protection/search`.

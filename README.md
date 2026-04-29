# Drupal Cache Protection

Protects Drupal page cache from fragmentation by tracking parameters and bot abuse.

## Install

```sh
ddev composer config --json --merge extra.drupal-scaffold.allowed-packages '["augustash/drupal_cache_protection"]' && ddev composer require augustash/drupal_cache_protection && ddev drush en -y drupal_cache_protection
```

## Submodule

`drupal_cache_protection_facets` adds facet bot protection. Enable only on sites that use `drupal/facets`. If enabled, also add `augustash/drupal_cache_protection_facets` to `allowed-packages`.

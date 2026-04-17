# Drupal Cache Protection

Protects Drupal page cache from fragmentation by tracking parameters and bot abuse.

## Install

```sh
composer require augustash/drupal_cache_protection && drush en drupal_cache_protection
```

Then add `augustash/drupal_cache_protection` to `extra.drupal-scaffold.allowed-packages` in the project's root `composer.json` and run `composer drupal:scaffold` to apply the bundled `robots.txt` rules.

## Submodule

`drupal_cache_protection_facets` adds facet bot protection. Enable only on sites that use `drupal/facets`. If enabled, also add `augustash/drupal_cache_protection_facets` to `allowed-packages`.

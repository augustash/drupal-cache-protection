<?php

namespace Drupal\drupal_cache_protection_facets\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Middleware to throttle abusive faceted search requests.
 *
 * Protects against bots that bombard faceted search pages with unique filter
 * combinations, each of which generates an expensive uncached Solr query.
 */
class FacetThrottleMiddleware implements HttpKernelInterface {

  /**
   * Cache key for valid facet aliases.
   */
  const ALIAS_CACHE_KEY = 'drupal_cache_protection_facets:valid_aliases';

  /**
   * The wrapped HTTP kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * Constructs the middleware.
   */
  public function __construct(HttpKernelInterface $http_kernel) {
    $this->httpKernel = $http_kernel;
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
    if ($type !== self::MAIN_REQUEST || $request->getMethod() !== 'GET') {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    // Read the raw value: ParameterBag::all('f') throws when f isn't an array,
    // so a hand-made ?f=abc would surface as a 500 instead of a 400.
    $facets = $request->query->all()['f'] ?? NULL;
    if ($facets === NULL || $facets === '' || $facets === []) {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    // Facets only ever writes a flat list of 'alias:value' strings. A scalar
    // or nested value is hand-made, so reject it like any other bad filter.
    if (!is_array($facets) || array_filter($facets, static fn ($facet) => !is_string($facet))) {
      return new Response('Invalid filter.', 400, [
        'Cache-Control' => 'no-store',
      ]);
    }

    $config = $this->getConfig();

    // Block requests with too many facet parameters.
    $maxFacets = $config['max_facets'] ?? 6;
    if (count($facets) > $maxFacets) {
      return new Response('Too many filters.', 429, [
        'Cache-Control' => 'no-store',
        'Retry-After' => '60',
      ]);
    }

    // Reject a value given twice. Facets never writes one — selecting an
    // active value removes it — so a repeat only comes from a crawler padding
    // its URL into a combination nothing caches. Rejected rather than
    // redirected: the de-duplicated URL is just as uncached, so a 301 would
    // still cost a render.
    if (count(array_unique($facets)) !== count($facets)) {
      return new Response('Invalid filter.', 400, [
        'Cache-Control' => 'no-store',
      ]);
    }

    $extraAliases = $config['extra_aliases'] ?? [];

    // Reject requests with invalid facet aliases.
    $validAliases = $this->getValidAliases();
    if ($validAliases !== NULL) {
      // Site-declared pseudo-facets: parameters a site writes into f[] itself
      // (a list toggle, say) that no Facets facet owns. Merged here rather than
      // into the cached set, so a settings change applies at once.
      $validAliases += array_fill_keys($extraAliases, TRUE);
      foreach ($facets as $facet) {
        $alias = strstr($facet, ':', TRUE);
        if ($alias === FALSE || !isset($validAliases[$alias])) {
          return new Response('Invalid filter.', 400, [
            'Cache-Control' => 'no-store',
          ]);
        }
      }
    }

    $rateLimit = $config['rate_limit'] ?? 30;
    $rateWindow = $config['rate_window'] ?? 60;

    try {
      $cache = \Drupal::cache('default');

      // A site-wide budget for deep combinations. The number of combinations
      // grows exponentially with depth, so a crawler walking a filter shelf's
      // links finds an endless supply of URLs nothing has cached. Spread over
      // thousands of residential IPs, it never trips the per-IP limit below.
      // Shoppers rarely go this deep, so the budget only ever bites a crawl.
      // Site-declared aliases are toggles rather than filters, so they don't
      // count toward depth.
      $deepThreshold = (int) ($config['deep_threshold'] ?? 0);
      if ($deepThreshold > 0) {
        $depth = count(array_filter($facets, static fn ($facet) => !in_array(strstr($facet, ':', TRUE), $extraAliases, TRUE)));
        if ($depth > $deepThreshold) {
          $deepKey = 'facet_throttle:deep';
          $deepEntry = $cache->get($deepKey);
          $deepCount = $deepEntry ? (int) $deepEntry->data : 0;
          if ($deepCount >= (int) ($config['deep_rate_limit'] ?? 6)) {
            return new Response('Too many filters.', 429, [
              'Cache-Control' => 'no-store',
              'Retry-After' => (string) $rateWindow,
            ]);
          }
          // The window starts at the first deep request and is not extended,
          // so the budget refills on a fixed cadence.
          $cache->set($deepKey, $deepCount + 1, $deepEntry ? $deepEntry->expire : time() + $rateWindow);
        }
      }

      // Rate limit faceted requests per IP.
      $cacheKey = 'facet_throttle:' . hash('xxh3', $request->getClientIp());
      $entry = $cache->get($cacheKey);
      $count = $entry ? (int) $entry->data : 0;

      if ($count >= $rateLimit) {
        return new Response('Rate limit exceeded.', 429, [
          'Cache-Control' => 'no-store',
          'Retry-After' => (string) $rateWindow,
        ]);
      }

      $cache->set($cacheKey, $count + 1, time() + $rateWindow);
    }
    catch (\Exception $e) {
      // If cache is unavailable, don't block the request.
    }

    return $this->httpKernel->handle($request, $type, $catch);
  }

  /**
   * Gets the module config.
   *
   * @return array
   *   The settings array.
   */
  protected function getConfig(): array {
    return \Drupal::config('drupal_cache_protection_facets.settings')->get() ?? [];
  }

  /**
   * Gets valid facet aliases from config, cached.
   *
   * @return array|null
   *   Associative array keyed by alias, or NULL if unavailable.
   */
  protected function getValidAliases(): ?array {
    try {
      $cache = \Drupal::cache('default');
      $entry = $cache->get(self::ALIAS_CACHE_KEY);
      if ($entry) {
        return $entry->data;
      }

      $aliases = [];
      $configFactory = \Drupal::configFactory();
      foreach ($configFactory->listAll('facets.facet.') as $name) {
        $alias = $configFactory->get($name)->get('url_alias');
        if ($alias) {
          $aliases[$alias] = TRUE;
        }
      }

      $cache->set(self::ALIAS_CACHE_KEY, $aliases, time() + 3600, ['config:facets.facet']);

      return $aliases;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}

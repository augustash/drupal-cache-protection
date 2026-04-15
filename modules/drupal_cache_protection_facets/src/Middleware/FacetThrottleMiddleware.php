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

    $facets = $request->query->all('f');
    if (empty($facets)) {
      return $this->httpKernel->handle($request, $type, $catch);
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

    // Reject requests with invalid facet aliases.
    $validAliases = $this->getValidAliases();
    if ($validAliases !== NULL) {
      foreach ($facets as $facet) {
        $alias = strstr($facet, ':', TRUE);
        if ($alias === FALSE || !isset($validAliases[$alias])) {
          return new Response('Invalid filter.', 400, [
            'Cache-Control' => 'no-store',
          ]);
        }
      }
    }

    // Rate limit faceted requests per IP.
    $rateLimit = $config['rate_limit'] ?? 30;
    $rateWindow = $config['rate_window'] ?? 60;
    $ip = $request->getClientIp();
    $cacheKey = 'facet_throttle:' . hash('xxh3', $ip);

    try {
      $cache = \Drupal::cache('default');
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

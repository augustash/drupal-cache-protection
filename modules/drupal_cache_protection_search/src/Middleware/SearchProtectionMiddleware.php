<?php

namespace Drupal\drupal_cache_protection_search\Middleware;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Middleware to rate-limit and uncache search requests.
 *
 * Search routes are bot magnets — abusers blast unique queries to fragment
 * the page cache and overload Solr. Caching them is pointless because each
 * query string is unique, and serving them is expensive. This middleware:
 *
 * - Rate-limits per IP across two windows (burst + sustained). 429 on excess.
 * - Triggers the page-cache kill switch so Drupal does not store the response.
 *
 * Runs at priority 100 so it sits inside page_cache (priority 200): cache
 * hits are served without consulting the flood, only misses are throttled.
 */
class SearchProtectionMiddleware implements HttpKernelInterface {

  /**
   * Flood event names — single source of truth for register/check pairs.
   */
  protected const FLOOD_BURST = 'drupal_cache_protection_search.burst';
  protected const FLOOD_SUSTAINED = 'drupal_cache_protection_search.sustained';

  public function __construct(
    protected HttpKernelInterface $httpKernel,
    protected FloodInterface $flood,
    protected KillSwitch $killSwitch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
    if ($type !== self::MAIN_REQUEST || $request->getMethod() !== 'GET') {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    $config = $this->getConfig();

    if (!$this->isProtectedRequest($request, $config)) {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    [$burstThreshold, $burstWindow] = [(int) ($config['burst_threshold'] ?? 5), (int) ($config['burst_window'] ?? 60)];
    [$sustainedThreshold, $sustainedWindow] = [(int) ($config['sustained_threshold'] ?? 30), (int) ($config['sustained_window'] ?? 3600)];

    // Middlewares run before the inner kernel pushes onto request_stack, so
    // the flood backend's default identifier lookup (RequestStack::getCurrentRequest)
    // returns null. Pass the IP explicitly from the request we already have.
    $ip = $request->getClientIp();

    $burstAllowed = $this->flood->isAllowed(self::FLOOD_BURST, $burstThreshold, $burstWindow, $ip);
    $sustainedAllowed = $this->flood->isAllowed(self::FLOOD_SUSTAINED, $sustainedThreshold, $sustainedWindow, $ip);

    if (!$burstAllowed || !$sustainedAllowed) {
      $retryAfter = !$burstAllowed ? $burstWindow : $sustainedWindow;
      return new Response('Too Many Requests', 429, [
        'Cache-Control' => 'no-store',
        'Retry-After' => (string) $retryAfter,
      ]);
    }

    $this->flood->register(self::FLOOD_BURST, $burstWindow, $ip);
    $this->flood->register(self::FLOOD_SUSTAINED, $sustainedWindow, $ip);

    // Prevent Drupal from caching this response. Each unique query string
    // would create its own cache row otherwise — that is exactly the bloat
    // we are trying to avoid.
    $this->killSwitch->trigger();

    return $this->httpKernel->handle($request, $type, $catch);
  }

  /**
   * Whether this request matches a protected path AND has a search query.
   *
   * Both must be true. /search with no query is the search form (cheap, fine
   * to cache). /search?s=... is what we throttle.
   */
  protected function isProtectedRequest(Request $request, array $config): bool {
    $protectedPaths = $config['protected_paths'] ?? [];
    if (!in_array($request->getPathInfo(), $protectedPaths, TRUE)) {
      return FALSE;
    }
    $queryParams = $config['query_params'] ?? [];
    foreach ($queryParams as $param) {
      if ($request->query->has($param)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Gets the module config.
   */
  protected function getConfig(): array {
    return \Drupal::config('drupal_cache_protection_search.settings')->get() ?? [];
  }

}

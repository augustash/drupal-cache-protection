<?php

namespace Drupal\drupal_cache_protection\Middleware;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Middleware to protect page cache from fragmentation by abusive URLs.
 *
 * Handles three classes of traffic, in priority order:
 * - Malformed encoding: URLs with double-encoded brackets (%255B/%255D) are
 *   cache-buster signatures with no legitimate use; returns 410 Gone with a
 *   long immutable cache so the CDN absorbs repeat hits from bad crawler links.
 * - Redirect params: tracking params not needed by client-side JS (srsltid,
 *   fbclid) — 301 to the clean URL so the CDN caches the redirect.
 * - Strip params: analytics params (gclid, msclkid, _kx, etc.) are removed
 *   from the internal request so Drupal's page cache keys on the clean URL,
 *   while the browser URL is unchanged for analytics JS.
 */
class CacheProtectionMiddleware implements HttpKernelInterface {

  /**
   * Malformed-encoding signatures that indicate cache-busting abuse.
   *
   * Double-encoded bracket characters (%5B/%5D → %255B/%255D) only appear when
   * a client re-encodes an already-encoded URL. No legitimate browser or
   * crawler produces these, and every unique variant creates a distinct cache
   * entry. Matched case-insensitively via stripos().
   */
  protected const BLOCK_SUBSTRINGS = [
    '%255B',
    '%255D',
  ];

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

    // Block malformed-encoding cache-buster signatures before any other work.
    $uri = $request->server->get('REQUEST_URI', '');
    foreach (self::BLOCK_SUBSTRINGS as $pattern) {
      if (stripos($uri, $pattern) !== FALSE) {
        return $this->goneResponse();
      }
    }

    $config = $this->getConfig();

    // Redirect away tracking params not needed by client-side JS.
    $redirectParams = $config['redirect_params'] ?? [];
    $redirected = FALSE;
    foreach ($redirectParams as $param) {
      if ($request->query->has($param)) {
        $request->query->remove($param);
        $redirected = TRUE;
      }
    }
    if ($redirected) {
      $qs = http_build_query($request->query->all());
      $baseUri = strtok($request->server->get('REQUEST_URI'), '?');
      $cleanUrl = $qs !== '' ? $baseUri . '?' . $qs : $baseUri;
      return new RedirectResponse($cleanUrl, 301);
    }

    // Strip analytics params from the internal request so Drupal's page cache
    // keys on the clean URL. The browser URL is unchanged, so client-side JS
    // can still read these params for conversion tracking.
    $stripParams = $config['strip_params'] ?? [];
    $stripped = FALSE;
    foreach ($stripParams as $param) {
      if ($request->query->has($param)) {
        $request->query->remove($param);
        $stripped = TRUE;
      }
    }
    if ($stripped) {
      $qs = http_build_query($request->query->all());
      $request->server->set('QUERY_STRING', $qs);
      $baseUri = strtok($request->server->get('REQUEST_URI'), '?');
      $request->server->set('REQUEST_URI', $qs !== '' ? $baseUri . '?' . $qs : $baseUri);
      $request->overrideGlobals();
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
    return \Drupal::config('drupal_cache_protection.settings')->get() ?? [];
  }

  /**
   * Builds the 410 Gone response for blocked URL patterns.
   *
   * The response is cached aggressively (1 year, immutable) because the block
   * is a hard rule on malformed encoding that will never be valid. Body is
   * minimal and headers are clean to keep the cached object shareable.
   */
  protected function goneResponse(): Response {
    $response = new Response('Gone', 410);
    $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
    $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
    return $response;
  }

}

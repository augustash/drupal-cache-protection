<?php

namespace Drupal\drupal_cache_protection\Middleware;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Middleware to protect page cache from tracking parameter fragmentation.
 *
 * Handles two classes of tracking parameters:
 * - Redirect params: stripped via 301 redirect (not needed by client-side JS).
 * - Strip params: removed from the internal request so Drupal's page cache
 *   keys on the clean URL, while the browser URL is unchanged for analytics JS.
 */
class CacheProtectionMiddleware implements HttpKernelInterface {

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

}

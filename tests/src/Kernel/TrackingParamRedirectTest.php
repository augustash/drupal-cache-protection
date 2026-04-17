<?php

namespace Drupal\Tests\drupal_cache_protection\Kernel;

use Symfony\Component\HttpFoundation\Request;

/**
 * Tests redirect-based tracking param handling (srsltid, fbclid).
 *
 * These params are captured by the ad platform at click time and not read by
 * on-site JS, so a 301 to the clean URL is safe and lets the CDN cache the
 * redirect itself.
 *
 * @group drupal_cache_protection
 */
class TrackingParamRedirectTest extends MiddlewareTestBase {

  /**
   * Tests that srsltid triggers a redirect to the clean URL.
   */
  public function testSrsltidRedirects(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'srsltid' => 'abc123',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(301, $response->getStatusCode());
    $this->assertEquals('/staff-directory', $response->headers->get('Location'));
  }

  /**
   * Tests that fbclid triggers a redirect to the clean URL.
   */
  public function testFbclidRedirects(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'fbclid' => 'def456',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(301, $response->getStatusCode());
    $this->assertEquals('/staff-directory', $response->headers->get('Location'));
  }

  /**
   * Tests that other query params are preserved when tracking params redirect.
   */
  public function testRedirectPreservesOtherParams(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'page' => '2',
      'srsltid' => 'abc123',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(301, $response->getStatusCode());
    $this->assertEquals('/staff-directory?page=2', $response->headers->get('Location'));
  }

  /**
   * Tests that redirect params take priority over strip params.
   *
   * If both are present, the 301 wins — there's no point stripping a param
   * that's about to be redirected away anyway.
   */
  public function testRedirectTakesPriorityOverStrip(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'srsltid' => 'abc',
      'gclid' => 'def',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(301, $response->getStatusCode());
  }

  /**
   * Tests that POST requests with srsltid pass through untouched.
   */
  public function testPostRequestsPassThrough(): void {
    $request = Request::create('/staff-directory', 'POST', [
      'srsltid' => 'abc123',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests that clean requests pass through.
   */
  public function testCleanRequestPassesThrough(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'page' => '2',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
  }

}

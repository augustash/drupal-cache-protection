<?php

namespace Drupal\Tests\drupal_cache_protection\Kernel;

use Drupal\drupal_cache_protection\Middleware\CacheProtectionMiddleware;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests the CacheProtectionMiddleware.
 *
 * @group drupal_cache_protection
 */
class CacheProtectionMiddlewareTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['drupal_cache_protection'];

  /**
   * The middleware under test.
   */
  protected CacheProtectionMiddleware $middleware;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $innerKernel = new class implements HttpKernelInterface {
      public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
        return new Response('OK', 200);
      }
    };

    $this->middleware = new CacheProtectionMiddleware($innerKernel);
  }

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
  public function testTrackingParamRedirectPreservesOtherParams(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'page' => '2',
      'srsltid' => 'abc123',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(301, $response->getStatusCode());
    $this->assertEquals('/staff-directory?page=2', $response->headers->get('Location'));
  }

  /**
   * Tests that gclid is stripped internally, not redirected.
   */
  public function testGclidStrippedInternally(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'gclid' => 'abc123',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertFalse($request->query->has('gclid'));
    $this->assertStringNotContainsString('gclid', $request->server->get('REQUEST_URI'));
  }

  /**
   * Tests that msclkid is stripped internally, not redirected.
   */
  public function testMsclkidStrippedInternally(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'msclkid' => 'def456',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertFalse($request->query->has('msclkid'));
    $this->assertStringNotContainsString('msclkid', $request->server->get('REQUEST_URI'));
  }

  /**
   * Tests that analytics strip params preserve other query params.
   */
  public function testAnalyticsStripPreservesOtherParams(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'color' => 'blue',
      'gclid' => 'abc123',
      'gad_source' => '1',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('blue', $request->query->get('color'));
    $this->assertFalse($request->query->has('gclid'));
    $this->assertFalse($request->query->has('gad_source'));
  }

  /**
   * Tests that redirect params take priority over strip params.
   */
  public function testRedirectParamsTakePriority(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'srsltid' => 'abc',
      'gclid' => 'def',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(301, $response->getStatusCode());
  }

  /**
   * Tests that POST requests are not affected.
   */
  public function testPostRequestsPassThrough(): void {
    $request = Request::create('/staff-directory', 'POST', [
      'srsltid' => 'abc123',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests that requests without tracking params pass through.
   */
  public function testCleanRequestPassesThrough(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'page' => '2',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
  }

}

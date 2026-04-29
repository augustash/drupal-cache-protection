<?php

namespace Drupal\Tests\drupal_cache_protection\Kernel;

use Symfony\Component\HttpFoundation\Request;

/**
 * Tests internal-strip tracking param handling (gclid, msclkid, _kx, etc.).
 *
 * These params must remain in the browser's URL so client-side analytics JS
 * can read them from window.location for conversion tracking. The middleware
 * strips them only from Drupal's internal request so page cache keys on the
 * clean URL.
 *
 * @group aai
 * @group drupal_cache_protection
 */
class TrackingParamStripTest extends MiddlewareTestBase {

  /**
   * Tests that gclid is stripped from the internal request.
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
   * Tests that msclkid is stripped from the internal request.
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
   * Tests that all UTM params are stripped from the internal request.
   */
  public function testUtmParamsStrippedInternally(): void {
    $request = Request::create('/news', 'GET', [
      'utm_source' => 'google',
      'utm_medium' => 'cpc',
      'utm_campaign' => 'spring',
      'utm_term' => 'flights',
      'utm_content' => 'cta-1',
      'utm_id' => 'abc123',
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertFalse($request->query->has('utm_source'));
    $this->assertFalse($request->query->has('utm_medium'));
    $this->assertFalse($request->query->has('utm_campaign'));
    $this->assertFalse($request->query->has('utm_term'));
    $this->assertFalse($request->query->has('utm_content'));
    $this->assertFalse($request->query->has('utm_id'));
    $this->assertStringNotContainsString('utm_', $request->server->get('REQUEST_URI'));
  }

  /**
   * Tests that strip preserves non-tracking query params.
   */
  public function testStripPreservesOtherParams(): void {
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

}

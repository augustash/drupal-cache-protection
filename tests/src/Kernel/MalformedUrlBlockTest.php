<?php

namespace Drupal\Tests\drupal_cache_protection\Kernel;

use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the malformed-URL block (double-encoded brackets → 410 Gone).
 *
 * These patterns are cache-buster signatures from abusive scrapers — no
 * legitimate client produces double-encoded brackets, so the middleware
 * returns a long-cacheable 410 before any Drupal bootstrap occurs.
 *
 * @group drupal_cache_protection
 */
class MalformedUrlBlockTest extends MiddlewareTestBase {

  /**
   * Builds a GET request whose raw REQUEST_URI contains the given query.
   *
   * Symfony's Request::create decodes the query string, so tests that need
   * the raw encoded URI (e.g. %255B) must set REQUEST_URI on the server bag
   * directly.
   */
  protected function rawUriRequest(string $path, string $rawQuery): Request {
    $request = Request::create($path, 'GET');
    $request->server->set('REQUEST_URI', $path . '?' . $rawQuery);
    return $request;
  }

  /**
   * Tests that double-encoded %255B triggers 410 Gone.
   */
  public function testDoubleEncodedOpenBracketBlocked(): void {
    $request = $this->rawUriRequest('/views/ajax', 'ajax_page_state%255Btheme%255D=foo');
    $response = $this->middleware->handle($request);
    $this->assertEquals(410, $response->getStatusCode());
  }

  /**
   * Tests that double-encoded %255D triggers 410 Gone.
   *
   * Covered separately because either bracket alone is enough to block; a
   * broken encoder might produce one without the other.
   */
  public function testDoubleEncodedCloseBracketBlocked(): void {
    $request = $this->rawUriRequest('/views/ajax', 'key%255D=value');
    $response = $this->middleware->handle($request);
    $this->assertEquals(410, $response->getStatusCode());
  }

  /**
   * Tests that lowercase hex (%255b) is also blocked.
   *
   * URL encoding is defined as case-insensitive; some clients emit lowercase.
   */
  public function testLowercaseHexBlocked(): void {
    $request = $this->rawUriRequest('/views/ajax', 'ajax_page_state%255btheme%255d=foo');
    $response = $this->middleware->handle($request);
    $this->assertEquals(410, $response->getStatusCode());
  }

  /**
   * Tests that the 410 response sets long, immutable cache headers.
   *
   * The block is a permanent signal (malformed URLs will never become valid),
   * so the response should be cached aggressively at the CDN to keep origin
   * cost low for crawlers re-hitting the same polluted links.
   */
  public function testBlockResponseIsLongCacheable(): void {
    $request = $this->rawUriRequest('/views/ajax', 'key%255B=value');
    $response = $this->middleware->handle($request);
    $this->assertEquals(410, $response->getStatusCode());
    $cacheControl = $response->headers->get('Cache-Control');
    $this->assertStringContainsString('public', $cacheControl);
    $this->assertStringContainsString('max-age=31536000', $cacheControl);
    $this->assertStringContainsString('immutable', $cacheControl);
  }

  /**
   * Tests that the 410 body is plain text with no cookies/vary pollution.
   *
   * A clean response keeps the cached object shareable across all clients.
   */
  public function testBlockResponseHasCleanHeaders(): void {
    $request = $this->rawUriRequest('/views/ajax', 'key%255B=value');
    $response = $this->middleware->handle($request);
    $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
    $this->assertEmpty($response->headers->getCookies());
  }

  /**
   * Tests that properly-encoded brackets (%5B/%5D) are NOT blocked.
   *
   * Single-encoded brackets are legitimate array notation used by facets,
   * ajax_page_state, etc. — only the double-encoding signature should trip.
   */
  public function testSingleEncodedBracketsPassThrough(): void {
    $request = $this->rawUriRequest('/views/ajax', 'ajax_page_state%5Btheme%5D=foo');
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests that "amp%3B"-prefixed params trigger 410 Gone.
   *
   * Crawlers that parse "&amp;" literally from HTML re-request URLs with
   * duplicate "amp;param=" entries alongside the real ones, fragmenting cache
   * across infinite variants. No legitimate client produces this.
   */
  public function testAmpEntityBleedBlocked(): void {
    $request = $this->rawUriRequest(
      '/flights-and-airlines/flights',
      'amp%3Border=city_airport&amp%3Bpage=3&flight_type=departure'
    );
    $response = $this->middleware->handle($request);
    $this->assertEquals(410, $response->getStatusCode());
  }

  /**
   * Tests that "amp" without an encoded semicolon passes through.
   *
   * The block pattern is "amp%3B" specifically — params like "amplitude" or
   * "ampm" must not be caught by an over-broad match.
   */
  public function testAmpWithoutSemicolonPassesThrough(): void {
    $request = $this->rawUriRequest('/page', 'amplitude=10&ampm=AM');
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests that the block takes priority over tracking-param handling.
   *
   * If both signatures appear on one request, the block is the more
   * conservative response — we don't want to issue a redirect to a URL that
   * preserves (or strips) the malformed encoding.
   */
  public function testBlockTakesPriorityOverTrackingParams(): void {
    $request = Request::create('/page', 'GET', ['srsltid' => 'abc']);
    $request->server->set('REQUEST_URI', '/page?srsltid=abc&key%255B=value');
    $response = $this->middleware->handle($request);
    $this->assertEquals(410, $response->getStatusCode());
  }

  /**
   * Tests that POST requests with malformed URIs pass through.
   *
   * The middleware's GET-only scope is intentional — form submissions from
   * legitimate users should never be blocked by URL-pattern heuristics.
   */
  public function testPostWithMalformedUriPassesThrough(): void {
    $request = Request::create('/page', 'POST');
    $request->server->set('REQUEST_URI', '/page?key%255B=value');
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
  }

}

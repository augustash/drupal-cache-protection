<?php

namespace Drupal\Tests\drupal_cache_protection_search\Kernel;

use Drupal\Core\PageCache\ResponsePolicyInterface;
use Drupal\drupal_cache_protection_search\Middleware\SearchProtectionMiddleware;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests SearchProtectionMiddleware rate limiting and cache-kill behavior.
 *
 * @group aai
 * @group drupal_cache_protection_search
 */
class SearchProtectionMiddlewareTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'drupal_cache_protection',
    'drupal_cache_protection_search',
  ];

  /**
   * The middleware under test.
   */
  protected SearchProtectionMiddleware $middleware;

  /**
   * Counter for inner-kernel passes — lets tests assert pass-through happened.
   */
  protected int $innerCalls = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['drupal_cache_protection_search']);

    $this->innerCalls = 0;
    $callsRef = &$this->innerCalls;
    $innerKernel = new class($callsRef) implements HttpKernelInterface {

      public function __construct(protected int &$calls) {}

      public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
        $this->calls++;
        return new Response('OK', 200);
      }

    };

    $this->middleware = new SearchProtectionMiddleware(
      $innerKernel,
      $this->container->get('flood'),
      $this->container->get('page_cache_kill_switch'),
    );
  }

  /**
   * Non-protected paths pass through with no flood interaction.
   */
  public function testUnprotectedPathPassesThrough(): void {
    $request = Request::create('/news', 'GET', ['s' => 'foo']);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals(1, $this->innerCalls);
  }

  /**
   * Protected path without a search query passes through.
   */
  public function testProtectedPathWithoutQueryPassesThrough(): void {
    $request = Request::create('/search', 'GET');
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals(1, $this->innerCalls);
  }

  /**
   * Search request under the limit passes through and triggers kill switch.
   */
  public function testSearchRequestPassesThroughAndKillsCache(): void {
    $request = Request::create('/search', 'GET', ['s' => 'flights']);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals(1, $this->innerCalls);
    // KillSwitch exposes its triggered state via check() returning DENY.
    $policy = $this->container->get('page_cache_kill_switch')->check($response, $request);
    $this->assertEquals(ResponsePolicyInterface::DENY, $policy);
  }

  /**
   * Sixth request from the same IP within 60s gets a 429.
   */
  public function testBurstLimitExceededReturns429(): void {
    for ($i = 0; $i < 5; $i++) {
      $request = Request::create('/search', 'GET', ['s' => "q$i"]);
      $response = $this->middleware->handle($request);
      $this->assertEquals(200, $response->getStatusCode(), "Request $i should pass");
    }

    $blocked = Request::create('/search', 'GET', ['s' => 'q6']);
    $response = $this->middleware->handle($blocked);
    $this->assertEquals(429, $response->getStatusCode());
    $this->assertNotEmpty($response->headers->get('Retry-After'));
    // Inner kernel was hit 5 times (the allowed requests), not 6.
    $this->assertEquals(5, $this->innerCalls);
  }

  /**
   * Non-search-query params do not count toward the limit.
   */
  public function testNonSearchQueryParamsDoNotCount(): void {
    // Six requests, but with a non-search param — no flood registered.
    for ($i = 0; $i < 6; $i++) {
      $request = Request::create('/search', 'GET', ['unrelated' => "x$i"]);
      $response = $this->middleware->handle($request);
      $this->assertEquals(200, $response->getStatusCode());
    }
    $this->assertEquals(6, $this->innerCalls);
  }

  /**
   * Regression: middleware must not depend on request_stack being populated.
   *
   * Drupal runs middlewares outside the inner HttpKernel — request_stack only
   * receives the request once that inner kernel starts handling. Any lazy
   * lookup of the current request from inside a middleware (e.g. the flood
   * backend's default identifier resolution) blows up on null. The middleware
   * must pass the IP through explicitly so it never depends on request_stack.
   */
  public function testHandlesEmptyRequestStack(): void {
    $stack = $this->container->get('request_stack');
    while ($stack->getCurrentRequest()) {
      $stack->pop();
    }
    $this->assertNull($stack->getCurrentRequest());

    $request = Request::create('/search', 'GET', ['s' => 'flights']);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals(1, $this->innerCalls);

    // KernelTestBase::tearDown() calls request_stack->getSession(). Restore a
    // session-bearing request so teardown does not emit Drupal's session-less
    // push deprecation.
    $cleanup = Request::create('/');
    $cleanup->setSession(new Session(new MockArraySessionStorage()));
    $stack->push($cleanup);
  }

}

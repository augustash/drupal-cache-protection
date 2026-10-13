<?php

namespace Drupal\Tests\drupal_cache_protection_facets\Kernel;

use Drupal\drupal_cache_protection_facets\Middleware\FacetThrottleMiddleware;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests the FacetThrottleMiddleware.
 *
 * @group aai
 * @group drupal_cache_protection_facets
 */
class FacetThrottleMiddlewareTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'drupal_cache_protection',
    'drupal_cache_protection_facets',
  ];

  /**
   * The middleware under test.
   */
  protected FacetThrottleMiddleware $middleware;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Pre-seed the alias cache with test aliases.
    // This avoids needing the facets module installed for tests.
    \Drupal::cache('default')->set(FacetThrottleMiddleware::ALIAS_CACHE_KEY, [
      'departments' => TRUE,
      'locations' => TRUE,
      'category' => TRUE,
    ], time() + 3600);

    $innerKernel = new class implements HttpKernelInterface {
      public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
        return new Response('OK', 200);
      }
    };

    $this->middleware = new FacetThrottleMiddleware($innerKernel);
  }

  /**
   * Tests that requests without facets pass through.
   */
  public function testNoFacetsPassThrough(): void {
    $request = Request::create('/staff-directory');
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests that valid facets under the limit pass through.
   */
  public function testValidFacetsPassThrough(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'f' => ['departments:15', 'locations:3'],
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests that too many facets returns 429.
   */
  public function testTooManyFacetsBlocked(): void {
    $facets = [];
    for ($i = 0; $i <= 6; $i++) {
      $facets[] = "departments:$i";
    }
    $request = Request::create('/staff-directory', 'GET', ['f' => $facets]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(429, $response->getStatusCode());
    $this->assertEquals('Too many filters.', $response->getContent());
  }

  /**
   * Tests that invalid facet aliases return 400.
   */
  public function testInvalidAliasBlocked(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'f' => ['fakeparam:123'],
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Invalid filter.', $response->getContent());
  }

  /**
   * Tests that a site-declared alias passes and an undeclared one still fails.
   */
  public function testExtraAliasAllowed(): void {
    $this->config('drupal_cache_protection_facets.settings')
      ->set('extra_aliases', ['show'])
      ->save();

    $request = Request::create('/staff-directory', 'GET', [
      'f' => ['show:discontinued', 'departments:15'],
    ]);
    $this->assertEquals(200, $this->middleware->handle($request)->getStatusCode());

    $request = Request::create('/staff-directory', 'GET', [
      'f' => ['hide:discontinued'],
    ]);
    $this->assertEquals(400, $this->middleware->handle($request)->getStatusCode());
  }

  /**
   * Tests that a value given twice is rejected, however few filters there are.
   */
  public function testDuplicateValueBlocked(): void {
    $request = Request::create('/staff-directory', 'GET', [
      'f' => ['departments:15', 'departments:15'],
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Invalid filter.', $response->getContent());

    // The same alias with different values is an ordinary OR selection.
    $request = Request::create('/staff-directory', 'GET', [
      'f' => ['departments:15', 'departments:16'],
    ]);
    $this->assertEquals(200, $this->middleware->handle($request)->getStatusCode());
  }

  /**
   * Tests the site-wide budget for deep combinations.
   *
   * Each request comes from a different IP, as the crawler's do, so only the
   * shared budget can stop them.
   */
  public function testDeepRequestsShareOneBudget(): void {
    $this->config('drupal_cache_protection_facets.settings')
      ->set('max_facets', 15)
      ->set('deep_threshold', 3)
      ->set('deep_rate_limit', 2)
      ->set('extra_aliases', ['show'])
      ->save();
    $deep = ['departments:1', 'departments:2', 'locations:3', 'category:4'];
    $request = fn (array $facets, int $ip) => Request::create('/staff-directory', 'GET', ['f' => $facets], [], [], ['REMOTE_ADDR' => "10.0.0.$ip"]);

    $this->assertEquals(200, $this->middleware->handle($request($deep, 1))->getStatusCode());
    $this->assertEquals(200, $this->middleware->handle($request($deep, 2))->getStatusCode());
    $response = $this->middleware->handle($request($deep, 3));
    $this->assertEquals(429, $response->getStatusCode());
    $this->assertEquals('Too many filters.', $response->getContent());

    // A shopper at or under the threshold never touches the budget, and a
    // site-declared toggle does not count toward depth.
    $shallow = ['departments:1', 'locations:3', 'category:4'];
    $this->assertEquals(200, $this->middleware->handle($request($shallow, 4))->getStatusCode());
    $toggled = array_merge(['show:discontinued'], $shallow);
    $this->assertEquals(200, $this->middleware->handle($request($toggled, 5))->getStatusCode());
  }

  /**
   * Tests that the deep budget is off unless a site sets a threshold.
   */
  public function testDeepBudgetOffByDefault(): void {
    $this->config('drupal_cache_protection_facets.settings')->set('max_facets', 15)->save();
    $deep = [
      'departments:1',
      'departments:2',
      'locations:3',
      'category:4',
      'category:5',
      'category:6',
      'category:7',
    ];
    for ($ip = 1; $ip <= 10; $ip++) {
      $request = Request::create('/staff-directory', 'GET', ['f' => $deep], [], [], ['REMOTE_ADDR' => "10.0.0.$ip"]);
      $this->assertEquals(200, $this->middleware->handle($request)->getStatusCode());
    }
  }

  /**
   * Tests that rate limiting kicks in after threshold.
   */
  public function testRateLimitExceeded(): void {
    $ip = '127.0.0.1';
    $cacheKey = 'facet_throttle:' . hash('xxh3', $ip);
    \Drupal::cache('default')->set($cacheKey, 30, time() + 60);

    $request = Request::create('/staff-directory', 'GET', [
      'f' => ['departments:15'],
    ], [], [], ['REMOTE_ADDR' => $ip]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(429, $response->getStatusCode());
    $this->assertEquals('Rate limit exceeded.', $response->getContent());
  }

  /**
   * Tests that POST requests are not affected.
   */
  public function testPostRequestsPassThrough(): void {
    $request = Request::create('/staff-directory', 'POST', [
      'f' => ['fakeparam:999', 'a:1', 'b:2', 'c:3', 'd:4', 'e:5', 'f:6', 'g:7', 'h:8', 'i:9'],
    ]);
    $response = $this->middleware->handle($request);
    $this->assertEquals(200, $response->getStatusCode());
  }

}

<?php

namespace Drupal\Tests\drupal_cache_protection\Kernel;

use Drupal\drupal_cache_protection\Middleware\CacheProtectionMiddleware;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Base class for CacheProtectionMiddleware kernel tests.
 *
 * Provides a middleware wired to a fake inner kernel that always returns 200,
 * so assertions can distinguish middleware-produced responses from pass-through.
 */
abstract class MiddlewareTestBase extends KernelTestBase {

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
    $this->installConfig(['drupal_cache_protection']);

    $innerKernel = new class implements HttpKernelInterface {
      public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
        return new Response('OK', 200);
      }
    };

    $this->middleware = new CacheProtectionMiddleware($innerKernel);
  }

}

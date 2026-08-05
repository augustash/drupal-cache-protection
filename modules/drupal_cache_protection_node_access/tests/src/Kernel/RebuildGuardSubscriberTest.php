<?php

namespace Drupal\Tests\drupal_cache_protection_node_access\Kernel;

use Drupal\Core\PageCache\ResponsePolicyInterface;
use Drupal\drupal_cache_protection_node_access\RebuildTracker;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests responses rendered during a grants rebuild are not cacheable.
 *
 * @group aai
 * @group drupal_cache_protection_node_access
 */
class RebuildGuardSubscriberTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'drupal_cache_protection',
    'drupal_cache_protection_node_access',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('node', ['node_access']);
  }

  /**
   * During the window: kill switch armed and the response marked no-store.
   *
   * The kill switch alone only stops Drupal. A reverse proxy in front (Varnish
   * on Pantheon) would still hold the empty page for the full max-age, so the
   * header matters as much as the switch.
   */
  public function testRebuildWindowIsUncacheable(): void {
    $this->startRebuild();
    $response = $this->dispatchResponse();

    $this->assertSame(
      ResponsePolicyInterface::DENY,
      $this->container->get('page_cache_kill_switch')->check($response, Request::create('/')),
    );
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
  }

  /**
   * Outside the window nothing is touched — no standing cache cost.
   */
  public function testIdleResponseIsLeftAlone(): void {
    $response = $this->dispatchResponse();

    $this->assertNull(
      $this->container->get('page_cache_kill_switch')->check($response, Request::create('/')),
    );
    $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
  }

  /**
   * A request arriving after the rebuild finished purges and stops suppressing.
   *
   * This is the path that heals a site whose rebuild ran under drush: no
   * further request from that process ever comes, so the next web request has
   * to be the one that notices.
   */
  public function testResponseAfterCompletionSettlesTheRebuild(): void {
    $this->startRebuild();
    $this->container->get('state')->delete(RebuildTracker::CORE_STATE_KEY);

    $response = $this->dispatchResponse();

    $this->assertNull($this->container->get('state')->get(RebuildTracker::STATE_KEY));
    $this->assertNull(
      $this->container->get('page_cache_kill_switch')->check($response, Request::create('/')),
      'Caching resumes on the same request that settles the rebuild.',
    );
  }

  /**
   * Opens a rebuild window the way a real truncate would.
   */
  protected function startRebuild(): void {
    $this->container->get('entity_type.manager')
      ->getAccessControlHandler('node')
      ->deleteGrants();
  }

  /**
   * Runs a main-request response through the subscriber.
   */
  protected function dispatchResponse(): Response {
    $event = new ResponseEvent(
      $this->container->get('http_kernel'),
      Request::create('/'),
      HttpKernelInterface::MAIN_REQUEST,
      new Response('body'),
    );
    $this->container->get('drupal_cache_protection_node_access.rebuild_guard')->onResponse($event);
    return $event->getResponse();
  }

}

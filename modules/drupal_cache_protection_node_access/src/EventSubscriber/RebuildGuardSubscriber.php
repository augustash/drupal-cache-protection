<?php

declare(strict_types=1);

namespace Drupal\drupal_cache_protection_node_access\EventSubscriber;

use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\drupal_cache_protection_node_access\RebuildTracker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps rebuild-window responses out of every cache that would hold them.
 */
final class RebuildGuardSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected RebuildTracker $tracker,
    protected KillSwitch $killSwitch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', 0]];
  }

  /**
   * Suppresses caching for as long as the grants table is being rebuilt.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    // Close out a rebuild that finished since the last request. Cheap when
    // idle (one state read) and this is the catch-all for rebuilds whose own
    // process never reached ::destruct().
    $this->tracker->settle();

    if (!$this->tracker->isRebuilding()) {
      return;
    }

    // The kill switch is tagged for both the page_cache and
    // dynamic_page_cache response policies, so one call covers both.
    $this->killSwitch->trigger();

    // The kill switch is internal to Drupal; a reverse proxy would still cache
    // the empty page for the full max-age. Say so explicitly.
    $response = $event->getResponse();
    $response->setPrivate();
    $response->headers->set('Cache-Control', 'no-store, must-revalidate');
  }

}

<?php

declare(strict_types=1);

namespace Drupal\drupal_cache_protection_listing\EventSubscriber;

use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\drupal_cache_protection_listing\ListingSanityCheck;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps a bad listing's response out of every cache that would hold it.
 */
final class ListingGuardSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected ListingSanityCheck $check,
    protected KillSwitch $killSwitch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', 0]];
  }

  /**
   * Suppresses caching of a response whose listing came back wrongly empty.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || !$this->check->isAnomalous()) {
      return;
    }

    // Tagged for both the page_cache and dynamic_page_cache response policies,
    // so one call covers both.
    $this->killSwitch->trigger();

    // The kill switch is internal to Drupal; a reverse proxy would still cache
    // the empty page for the full max-age. Say so explicitly.
    $response = $event->getResponse();
    $response->setPrivate();
    $response->headers->set('Cache-Control', 'no-store, must-revalidate');
  }

}

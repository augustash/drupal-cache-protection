<?php

namespace Drupal\drupal_cache_protection\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for Cache Protection.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Parameters that must never be stripped or redirected.
   *
   * Each is a Drupal-functional param where removal breaks core behavior. The
   * value is the reason shown in the validation error so site builders learn
   * why instead of guessing. Both redirect_params and strip_params are checked
   * against this list — redirecting to remove itok also breaks images.
   */
  protected const BLOCKED_PARAMS = [
    'itok' => 'Drupal image style protection token; stripping it breaks image rendering for all visitors.',
    'h' => 'Drupal image style hash (paired with itok); stripping it breaks image rendering.',
    'destination' => 'Drupal post-action redirect parameter; stripping it breaks login and form submission flows.',
    'token' => 'CSRF or one-time-link token; stripping it breaks form submissions and time-bounded URLs.',
    '_format' => 'Drupal content negotiation parameter; stripping it breaks REST and JSON API endpoints.',
    'page' => 'Pagination cursor; stripping it collapses every paginated view to the first page.',
  ];

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['drupal_cache_protection.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'drupal_cache_protection_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('drupal_cache_protection.settings');

    $blocked = '<strong>' . $this->t('Blocked params:') . '</strong> ' . implode(', ', array_keys(self::BLOCKED_PARAMS)) . '. ' . $this->t('These are required by Drupal core and will be rejected on save.');

    $form['redirect_params'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Redirect parameters'),
      '#description' => $this->t('Tracking parameters to strip via 301 redirect. These are not needed by client-side JS. One per line.') . '<br>' . $blocked,
      '#default_value' => implode("\n", $config->get('redirect_params') ?? []),
    ];

    $form['strip_params'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Strip parameters'),
      '#description' => $this->t('Tracking parameters to strip from the internal request only. The browser URL is unchanged so analytics JS can still read them, but they will not fragment the page cache. One per line.') . '<br>' . $blocked,
      '#default_value' => implode("\n", $config->get('strip_params') ?? []),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    foreach (['redirect_params', 'strip_params'] as $key) {
      $params = $this->parseLines($form_state->getValue($key));
      foreach ($params as $param) {
        if (isset(self::BLOCKED_PARAMS[$param])) {
          $form_state->setErrorByName($key, $this->t('"@param" cannot be added to %field: @reason', [
            '@param' => $param,
            '%field' => $form[$key]['#title'],
            '@reason' => self::BLOCKED_PARAMS[$param],
          ]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('drupal_cache_protection.settings')
      ->set('redirect_params', $this->parseLines($form_state->getValue('redirect_params')))
      ->set('strip_params', $this->parseLines($form_state->getValue('strip_params')))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Parses a newline-separated textarea value into a clean array.
   */
  protected function parseLines(string $value): array {
    return array_values(array_filter(array_map('trim', explode("\n", $value))));
  }

}

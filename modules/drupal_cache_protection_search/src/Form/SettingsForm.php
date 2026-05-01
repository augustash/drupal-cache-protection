<?php

namespace Drupal\drupal_cache_protection_search\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for Cache Protection: Search.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['drupal_cache_protection_search.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'drupal_cache_protection_search_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('drupal_cache_protection_search.settings');

    $form['protected_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Protected paths'),
      '#description' => $this->t('Paths to apply rate limiting and cache protection to, one per line. Exact match. Example: <code>/search</code>'),
      '#default_value' => implode("\n", $config->get('protected_paths') ?? []),
      '#required' => TRUE,
    ];

    $form['query_params'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Search query parameters'),
      '#description' => $this->t("Query parameter names that signal a search submission, one per line. The middleware only acts when one of these is present in the URL. Example defaults: <code>s</code>, <code>keys</code>, <code>search_api_fulltext</code>"),
      '#default_value' => implode("\n", $config->get('query_params') ?? []),
      '#required' => TRUE,
    ];

    $form['rate_limits'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Per-IP rate limits'),
      '#description' => $this->t('Both windows are checked on every request. Either limit triggers a 429 response.'),
    ];

    $form['rate_limits']['burst_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Burst threshold'),
      '#description' => $this->t('Maximum requests allowed within the burst window.'),
      '#default_value' => $config->get('burst_threshold') ?? 5,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['rate_limits']['burst_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Burst window (seconds)'),
      '#default_value' => $config->get('burst_window') ?? 60,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['rate_limits']['sustained_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Sustained threshold'),
      '#description' => $this->t('Maximum requests allowed within the sustained window.'),
      '#default_value' => $config->get('sustained_threshold') ?? 30,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['rate_limits']['sustained_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Sustained window (seconds)'),
      '#default_value' => $config->get('sustained_window') ?? 3600,
      '#min' => 1,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('drupal_cache_protection_search.settings')
      ->set('protected_paths', $this->parseLines($form_state->getValue('protected_paths')))
      ->set('query_params', $this->parseLines($form_state->getValue('query_params')))
      ->set('burst_threshold', (int) $form_state->getValue('burst_threshold'))
      ->set('burst_window', (int) $form_state->getValue('burst_window'))
      ->set('sustained_threshold', (int) $form_state->getValue('sustained_threshold'))
      ->set('sustained_window', (int) $form_state->getValue('sustained_window'))
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

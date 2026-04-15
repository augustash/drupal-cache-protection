<?php

namespace Drupal\drupal_cache_protection_facets\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for Facet Protection.
 */
class FacetSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['drupal_cache_protection_facets.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'drupal_cache_protection_facets_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('drupal_cache_protection_facets.settings');

    $form['max_facets'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum facet parameters'),
      '#description' => $this->t('Requests exceeding this number of facet (f[]) parameters will be blocked with a 429 response.'),
      '#default_value' => $config->get('max_facets') ?? 6,
      '#min' => 1,
      '#max' => 20,
      '#required' => TRUE,
    ];

    $form['rate_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate limit'),
      '#description' => $this->t('Maximum faceted requests per IP within the rate window.'),
      '#default_value' => $config->get('rate_limit') ?? 30,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['rate_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate window (seconds)'),
      '#description' => $this->t('Time window for the rate limit.'),
      '#default_value' => $config->get('rate_window') ?? 60,
      '#min' => 10,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('drupal_cache_protection_facets.settings')
      ->set('max_facets', (int) $form_state->getValue('max_facets'))
      ->set('rate_limit', (int) $form_state->getValue('rate_limit'))
      ->set('rate_window', (int) $form_state->getValue('rate_window'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}

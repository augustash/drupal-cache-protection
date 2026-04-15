<?php

namespace Drupal\drupal_cache_protection\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for Cache Protection.
 */
class SettingsForm extends ConfigFormBase {

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

    $form['redirect_params'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Redirect parameters'),
      '#description' => $this->t('Tracking parameters to strip via 301 redirect. These are not needed by client-side JS. One per line.'),
      '#default_value' => implode("\n", $config->get('redirect_params') ?? []),
    ];

    $form['strip_params'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Strip parameters'),
      '#description' => $this->t('Tracking parameters to strip from the internal request only. The browser URL is unchanged so analytics JS can still read them, but they will not fragment the page cache. One per line.'),
      '#default_value' => implode("\n", $config->get('strip_params') ?? []),
    ];

    return parent::buildForm($form, $form_state);
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

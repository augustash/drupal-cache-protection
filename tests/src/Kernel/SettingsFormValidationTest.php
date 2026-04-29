<?php

namespace Drupal\Tests\drupal_cache_protection\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\drupal_cache_protection\Form\SettingsForm;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests SettingsForm validation against Drupal-functional params.
 *
 * The form blocks params whose removal would break core Drupal behavior
 * (image style tokens, pagination, content negotiation, etc.). Site builders
 * should see a clear error explaining why instead of silently breaking the
 * site.
 *
 * @group aai
 * @group drupal_cache_protection
 */
class SettingsFormValidationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'drupal_cache_protection'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['drupal_cache_protection']);
  }

  /**
   * Tests each blocked param produces a validation error on strip_params.
   *
   * @dataProvider blockedParamProvider
   */
  public function testBlockedParamRejectedInStrip(string $param): void {
    $form_state = (new FormState())->setValues([
      'redirect_params' => '',
      'strip_params' => "gclid\n$param",
    ]);
    \Drupal::formBuilder()->submitForm(SettingsForm::class, $form_state);
    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('strip_params', $errors, "Expected error for blocked param '$param'");
    $this->assertStringContainsString("\"$param\"", (string) $errors['strip_params']);
  }

  /**
   * Tests each blocked param is also rejected in redirect_params.
   *
   * @dataProvider blockedParamProvider
   */
  public function testBlockedParamRejectedInRedirect(string $param): void {
    $form_state = (new FormState())->setValues([
      'redirect_params' => "fbclid\n$param",
      'strip_params' => '',
    ]);
    \Drupal::formBuilder()->submitForm(SettingsForm::class, $form_state);
    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('redirect_params', $errors, "Expected error for blocked param '$param'");
  }

  /**
   * Tests legitimate params save without error.
   */
  public function testValidParamsAccepted(): void {
    $form_state = (new FormState())->setValues([
      'redirect_params' => 'fbclid',
      'strip_params' => "gclid\nutm_source\nutm_medium",
    ]);
    \Drupal::formBuilder()->submitForm(SettingsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * Provides each blocked param defined on the form.
   */
  public static function blockedParamProvider(): array {
    return [
      'itok' => ['itok'],
      'h' => ['h'],
      'destination' => ['destination'],
      'token' => ['token'],
      '_format' => ['_format'],
      'page' => ['page'],
    ];
  }

}

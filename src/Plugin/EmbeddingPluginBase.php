<?php

namespace Drupal\ocha_ai_chat\Plugin;

use Drupal\Core\Form\FormStateInterface;

/**
 * Base embedding plugin.
 */
abstract class EmbeddingPluginBase extends PluginBase implements EmbeddingPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'embedding';
  }

  /**
   * {@inheritdoc}
   */
  public function getDimensions(): int {
    return $this->getPluginSetting('dimensions');
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $plugin_type = $this->getPluginType();
    $plugin_id = $this->getPluginId();
    $config = $this->getConfiguration() + $this->defaultConfiguration();

    $form['plugins'][$plugin_type][$plugin_id]['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#description' => $this->t('AI model.'),
      '#options' => $this->getModels(),
      '#default_value' => $config['model'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Endpoint'),
      '#description' => $this->t('Endpoint of the API.'),
      '#default_value' => $config['endpoint'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#description' => $this->t('Version of the API.'),
      '#default_value' => $config['version'] ?? NULL,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Region'),
      '#description' => $this->t('Region of the API.'),
      '#default_value' => $config['region'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#description' => $this->t('Key to access the API.'),
      '#default_value' => $config['api_key'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('batch_size'),
      '#description' => $this->t('Maximum number embedding vectors to generate at once.'),
      '#default_value' => $config['batch_size'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['dimensions'] = [
      '#type' => 'number',
      '#title' => $this->t('Dimensions'),
      '#description' => $this->t('Dimensions of the embedding vectors.'),
      '#default_value' => $config['dimensions'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Max tokens'),
      '#description' => $this->t('Maximum number of tokens accepted by the model in one request.'),
      '#default_value' => $config['max_tokens'] ?? NULL,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelName(): string {
    return $this->getPluginSetting('model', '');
  }

}

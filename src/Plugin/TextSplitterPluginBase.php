<?php

namespace Drupal\ocha_ai\Plugin;

use Drupal\Core\Form\FormStateInterface;

/**
 * Base text splitter plugin.
 */
abstract class TextSplitterPluginBase extends PluginBase implements TextSplitterPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'text_splitter';
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $plugin_type = $this->getPluginType();
    $plugin_id = $this->getPluginId();
    $config = $this->getConfiguration() + $this->defaultConfiguration();

    $form['plugins'][$plugin_type][$plugin_id]['length'] = [
      '#type' => 'number',
      '#title' => $this->t('Length'),
      '#description' => $this->t('Maximum number of characters, sentences, tokens etc. for one text passage.'),
      '#default_value' => $config['length'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['overlap'] = [
      '#type' => 'number',
      '#title' => $this->t('overlap'),
      '#description' => $this->t('Maximum number of previous characters, sentences, tokens etc. to include in the passage to preserve context.'),
      '#default_value' => $config['overlap'] ?? NULL,
      '#required' => TRUE,
    ];

    return $form;
  }

}

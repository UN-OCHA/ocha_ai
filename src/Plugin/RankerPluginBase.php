<?php

namespace Drupal\ocha_ai\Plugin;

use Drupal\Core\Form\FormStateInterface;

/**
 * Base ranker plugin.
 */
abstract class RankerPluginBase extends PluginBase implements RankerPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'ranker';
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $plugin_type = $this->getPluginType();
    $plugin_id = $this->getPluginId();
    $config = $this->getConfiguration() + $this->defaultConfiguration();

    $form['plugins'][$plugin_type][$plugin_id]['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Limit'),
      '#description' => $this->t('Maximum number of relevant texts to return after the ranking.'),
      '#default_value' => $config['limit'] ?? NULL,
      '#required' => TRUE,
    ];

    return $form;
  }

}

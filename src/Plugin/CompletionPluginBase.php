<?php

namespace Drupal\ocha_ai\Plugin;

use Drupal\Core\Form\FormStateInterface;

/**
 * Base completion plugin.
 */
abstract class CompletionPluginBase extends PluginBase implements CompletionPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'completion';
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
      '#description' => $this->t('AI Model.'),
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

    $form['plugins'][$plugin_type][$plugin_id]['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Max tokens'),
      '#description' => $this->t('Maximum number of tokens to generate.'),
      '#default_value' => $config['max_tokens'] ?? NULL,
      '#required' => TRUE,
      '#weight' => 10,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['prompt_template'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt template'),
      '#description' => $this->t('Prompte template. Available placeholders: {{ context }} and {{ question }}.'),
      '#default_value' => $config['prompt_template'] ?? NULL,
      '#required' => TRUE,
      '#weight' => 11,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getPromptTemplate(): string {
    $template = $this->getPluginSetting('prompt_template');
    if (empty($template)) {
      return '';
    }

    // Fix issue from prompt loaded from the config.
    $template = strtr($template, ["\r" => '']);

    return trim($template);
  }

  /**
   * {@inheritdoc}
   */
  public function generatePrompt(string $question, string $context): string {
    if (empty($question) || empty($context)) {
      return '';
    }

    // Remove tags and control characters to prevent some types of prompt
    // polluting or jailbreaking.
    $question = trim(preg_replace('/[^\PC\s]/u', '', strip_tags($question)));
    $context = trim(preg_replace('/[^\PC\s]/u', '', strip_tags($context)));

    if (empty($question) || empty($context)) {
      return '';
    }

    $template = $this->getPromptTemplate();
    if (empty($template)) {
      return '';
    }

    // Fix issue from prompt loaded from the config.
    $template = strtr($template, ["\r" => '']);

    // @todo review what is a good template for AWS titan model.
    $prompt = strtr($template, [
      '{{ context }}' => $context,
      '{{ question }}' => $question,
    ]);

    return $prompt;
  }

  /**
   * {@inheritdoc}
   */
  public function generateContext(string $question, array $passages): string {
    $context = [];

    foreach ($passages as $passage) {
      $context[] = trim($passage['text']);
      if (isset($passage['reference'])) {
        $context[] = 'Source: ' . $passage['reference'];
      }
    }

    return implode("\n\n", $context);
  }

}

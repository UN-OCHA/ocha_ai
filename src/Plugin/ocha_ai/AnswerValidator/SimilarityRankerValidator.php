<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\AnswerValidator;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiAnswerValidator;
use Drupal\ocha_ai\Plugin\AnswerValidatorPluginBase;
use Drupal\ocha_ai\Plugin\RankerPluginInterface;

/**
 * Validate an answer by comparing its similarity to the context or question.
 */
#[OchaAiAnswerValidator(
  id: 'similarity_ranker',
  label: new TranslatableMarkup('Similarity - Ranker'),
  description: new TranslatableMarkup('Validate an answer by comparing its similarity to the context and/or question via a ranker.')
)]
class SimilarityRankerValidator extends AnswerValidatorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function validate(string $answer, string $question, array $passages, string $language, array $plugins = []): bool {
    if (empty($answer)) {
      return FALSE;
    }

    // Search for an embedding plugin.
    $ranker_plugin = NULL;
    foreach ($plugins as $plugin) {
      if ($plugin instanceof RankerPluginInterface) {
        $ranker_plugin = $plugin;
      }
    }

    if (is_null($ranker_plugin)) {
      throw new \Exception('Pluging @id error: missing ranker plugin.');
    }

    $min_similarity = $limit ?? $this->getPluginSetting('min_similarity') ?? 0.8;

    $texts = [];
    foreach ($passages as $passage) {
      $texts[] = $passage['text'];
    }

    $ranked_texts = $ranker_plugin->rankTexts($answer, $texts, $language, count($texts));
    return max($ranked_texts) > $min_similarity;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $plugin_type = $this->getPluginType();
    $plugin_id = $this->getPluginId();
    $config = $this->getConfiguration() + $this->defaultConfiguration();

    $form['plugins'][$plugin_type][$plugin_id]['min_similarity'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum similarity'),
      '#description' => $this->t('Minimum similarity for the answer to be considered valid.'),
      '#default_value' => $config['min_similarity'] ?? 0.8,
      '#required' => TRUE,
      '#step' => '.01',
      '#min' => 0.0,
      '#max' => 1.0,
    ];

    return $form;
  }

}

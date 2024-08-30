<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\AnswerValidator;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiAnswerValidator;
use Drupal\ocha_ai\Helpers\VectorHelper;
use Drupal\ocha_ai\Plugin\AnswerValidatorPluginBase;
use Drupal\ocha_ai\Plugin\EmbeddingPluginInterface;

/**
 * Validate an answer by comparing its similarity to the context or question.
 */
#[OchaAiAnswerValidator(
  id: 'similarity_embedding',
  label: new TranslatableMarkup('Similarity - Embedding'),
  description: new TranslatableMarkup('Validate an answer by comparing its similarity to the context and/or question via embeddings.')
)]
class SimilarityEmbeddingValidator extends AnswerValidatorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function validate(string $answer, string $question, array $passages, string $language, array $plugins = []): bool {
    if (empty($answer)) {
      return FALSE;
    }

    // @todo adjust the min similarity so that it's high enough to prevent
    // invalid answers but still pass for valid answers that use only small
    // parts of the context, for which, the similarity can be low.
    $min_similarity = $limit ?? $this->getPluginSetting('min_similarity') ?? 0.2;

    // Skip directly if the model considered it was a prompt attack.
    // @todo instead of that here, have the ::answer() method of the model
    // throw an exception for example so we are not tied to the what the prompt
    // tells the model to return.
    if (mb_stripos($answer, 'Prompt Attack Detected') !== FALSE) {
      return FALSE;
    }

    // Search for an embedding plugin.
    $embedding_plugin = NULL;
    foreach ($plugins as $plugin) {
      if ($plugin instanceof EmbeddingPluginInterface) {
        $embedding_plugin = $plugin;
      }
    }

    if (is_null($embedding_plugin)) {
      throw new \Exception('Pluging @id error: missing embedding plugin.');
    }

    $answer_embedding = $embedding_plugin->generateEmbedding($answer, TRUE);
    if (empty($answer_embedding)) {
      return FALSE;
    }

    $max_similarity = 0.0;
    foreach ($passages as $passage) {
      $embedding = $passage['embedding'] ?? $embedding_plugin->generateEmbedding($passage['text']);
      if (!empty($embedding)) {
        $similarity = VectorHelper::cosineSimilarity($embedding, $answer_embedding);
        $max_similarity = max($max_similarity, $similarity);
      }
    }

    return $max_similarity > $min_similarity;
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
      '#default_value' => $config['min_similarity'] ?? 0.2,
      '#required' => TRUE,
      '#step' => '.01',
      '#min' => 0.0,
      '#max' => 1.0,
    ];

    return $form;
  }

}

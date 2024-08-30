<?php

namespace Drupal\ocha_ai\Plugin;

/**
 * Interface for the answer validator plugins.
 */
interface AnswerValidatorPluginInterface {

  /**
   * Validate an answer.
   *
   * @param string $answer
   *   Answer.
   * @param string $question
   *   Question.
   * @param string $passages
   *   Text passages passed used as context to answer the question.
   * @param string $language
   *   Language of the passages.
   * @param \Drupal\ocha_ai\Plugin\PluginInterface[] $plugins
   *   Additional plugins used in the pipeline to answer the question. For
   *   example the embedding plugin.
   *
   * @return bool
   *   TRUE if the answer is considered valid.
   *
   * @throws \Exception
   *   An exception if the plugin cannot perform its validation, for example,
   *   due to a missing plugin it depends on.
   */
  public function validate(string $answer, string $question, array $passages, string $language, array $plugins = []): bool;

}

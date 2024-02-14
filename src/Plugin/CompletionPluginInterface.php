<?php

namespace Drupal\ocha_ai\Plugin;

/**
 * Interface for the completion plugins.
 */
interface CompletionPluginInterface {

  /**
   * Generate completions for the given text.
   *
   * @param string $question
   *   Question.
   * @param string $context
   *   Context to answer the question.
   *
   * @return string
   *   Answer to the question.
   */
  public function answer(string $question, string $context): string;

  /**
   * Generate a context for the question based on a list of text passages.
   *
   * @param string $question
   *   Question.
   * @param array $passages
   *   Text passages. Each passage has a text property and a source property
   *   with title, URL, author and page.
   *
   * @return string
   *   Context.
   */
  public function generateContext(string $question, array $passages): string;

  /**
   * Get the list of available models.
   *
   * @return array
   *   List of models keyed by model ID and with labels as values.
   */
  public function getModels(): array;

}

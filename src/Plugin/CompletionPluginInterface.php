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
   * Perform a completion query.
   *
   * @param string $prompt
   *   Prompt.
   * @param string $system_prompt
   *   Optional system prompt.
   * @param array $parameters
   *   Optional parameters for the payload: max_tokens, temperature, top_p.
   * @param bool $raw
   *   Whether to return the raw output text or let the plugin do some
   *   processing if any.
   *
   * @return string|null
   *   The model output text or NULL in case of error when querying the model.
   */
  public function query(string $prompt, string $system_prompt = '', array $parameters = [], bool $raw = TRUE): ?string;

  /**
   * Get the prompt template.
   *
   * @return string
   *   The prompt template.
   */
  public function getPromptTemplate(): string;

  /**
   * Generate the prompt.
   *
   * @param string $question
   *   The question.
   * @param string $context
   *   The context.
   *
   * @return string
   *   The prompt.
   */
  public function generatePrompt(string $question, string $context): string;

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

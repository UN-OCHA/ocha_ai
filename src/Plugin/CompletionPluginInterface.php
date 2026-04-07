<?php

namespace Drupal\ocha_ai\Plugin;

use Drupal\ocha_ai\Plugin\ocha_ai\Completion\CompletionCapability;

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
   * @param array $files
   *   List files to pass to the model for analysis. Each file is an
   *   associative array with the following properties:
   *   - mimetype (string): the mime type of the file.
   *   - id (string): optional document ID, for example for reference in the
   *     prompt.
   *   - data (string): optional content of the file. If not defined, the `uri`
   *     property should be set.
   *   - uri (string): optional URI of the file. If not defined, the `data`
   *     property should be set.
   *   - base64 (bool): optional flag indicating if the data is already base64
   *     encoded.
   *
   * @return string|null
   *   The model output text or NULL in case of error when querying the model.
   */
  public function query(string $prompt, string $system_prompt = '', array $parameters = [], bool $raw = TRUE, array $files = []): ?string;

  /**
   * Perform a structured completion query.
   *
   * Implementations that do not support structured output should return NULL.
   *
   * @param string $prompt
   *   Prompt.
   * @param array $json_schema
   *   JSON schema as an associative array.
   * @param string $system_prompt
   *   Optional system prompt.
   * @param array $parameters
   *   Optional parameters for the payload.
   * @param array $files
   *   Optional list of files to pass to the model.
   *
   * @return array|null
   *   Structured output data or NULL on failure/unsupported capability.
   */
  public function queryStructured(string $prompt, array $json_schema, string $system_prompt = '', array $parameters = [], array $files = []): ?array;

  /**
   * Query the model with the given payload and return the raw response data.
   *
   * @param array $payload
   *   Payload as expected by the model.
   *
   * @return array
   *   Data as returned by the model.
   */
  public function queryModel(array $payload): array;

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

  /**
   * Get the list of files that the model supports.
   *
   * @return array
   *   List of files keyed by mime types and with the max allowed file sizes
   *   as values.
   */
  public function getSupportedFileTypes(): array;

  /**
   * Get the completion plugin capabilities.
   *
   * @return \Drupal\ocha_ai\Plugin\ocha_ai\Completion\CompletionCapability[]
   *   Capabilities keyed numerically.
   */
  public function getCapabilities(): array;

  /**
   * Check if the completion plugin has a specific capability.
   *
   * @param \Drupal\ocha_ai\Plugin\ocha_ai\Completion\CompletionCapability $capability
   *   Capability enum case.
   *
   * @return bool
   *   TRUE if the capability is available.
   */
  public function hasCapability(CompletionCapability $capability): bool;

}

<?php

namespace Drupal\ocha_ai_chat\Plugin;

/**
 * Interface for the embedding plugins.
 */
interface EmbeddingPluginInterface {

  /**
   * Generate embeddings for the given texts.
   *
   * @param array $texts
   *   List of texts.
   * @param bool $query
   *   TRUE to generate the embedding for a search query other generate the
   *   embedding for a document.
   *
   * @return array
   *   List of embeddings. Each contains a text property with the original text
   *   and an embedding property with the vector.
   *
   * @throws \Exception
   *   Throw an exception if the generation of the embedddings fails.
   */
  public function generateEmbeddings(array $texts, bool $query = FALSE): array;

  /**
   * Generate embedding for the given text.
   *
   * @param string $text
   *   Text.
   * @param bool $query
   *   TRUE to generate the embedding for a search query other generate the
   *   embedding for a document.
   *
   * @return array
   *   Embedding for the text or empty array in case of failure.
   */
  public function generateEmbedding(string $text, bool $query = FALSE): array;

  /**
   * Get the number of dimensions for the embeddings.
   *
   * @return int
   *   Dimensions.
   */
  public function getDimensions(): int;

  /**
   * Get the model name.
   *
   * @return string
   *   Model name.
   */
  public function getModelName(): string;

  /**
   * Get the list of available models.
   *
   * @return array
   *   List of models keyed by model ID and with labels as values.
   */
  public function getModels(): array;

}

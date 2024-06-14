<?php

namespace Drupal\ocha_ai\Plugin;

/**
 * Interface for the vector store plugins.
 *
 * @todo make the methods more generic as they are a bit too tied to
 * Elasticsearch terminology. Ex: collection instead of index.
 */
interface VectorStorePluginInterface {

  /**
   * Get the base index name.
   *
   * @return string
   *   Index name.
   */
  public function getBaseIndexName(): string;

  /**
   * Create an index if doen't already exists.
   *
   * @param string $index
   *   Index name.
   * @param int $dimensions
   *   Dimensions of the embeddings.
   *
   * @return bool
   *   TRUE if the index exists or was created.
   */
  public function createIndex(string $index, int $dimensions): bool;

  /**
   * Delete an index.
   *
   * @param string $index
   *   Index name.
   *
   * @return bool
   *   TRUE if the index was deleted or didn't exist.
   */
  public function deleteIndex(string $index): bool;

  /**
   * Check if an index exists.
   *
   * @param string $index
   *   Index name.
   *
   * @return bool
   *   TRUE if the index exists.
   */
  public function indexExists(string $index): bool;

  /**
   * Get the indexed documents for the given ids.
   *
   * @param string $index
   *   Index name.
   * @param array $documents
   *   Document to index with properties like id, tile, url and a contents
   *   property containing pages with passages. Each passage contains a text
   *   its embedding.
   * @param int $dimensions
   *   Dimensions of the embeddings.
   *
   * @return bool
   *   TRUE if the indexing was successful.
   */
  public function indexDocuments(string $index, array $documents, int $dimensions): bool;

  /**
   * Get the indexed documents for the given ids.
   *
   * @param string $index
   *   Index name.
   * @param array $id
   *   List of document ids.
   *
   * @return array
   *   Document.
   */
  public function getDocument(string $index, string $id): array;

  /**
   * Get the indexed documents for the given ids.
   *
   * @param string $index
   *   Index name.
   * @param array $ids
   *   List of document ids.
   * @param array $fields
   *   Document data to include in the results. Defaults to all fields.
   *
   * @return array
   *   List of documents keyed by ID with the requested fields as values.
   */
  public function getDocuments(string $index, array $ids, array $fields = ['id']): array;

  /**
   * Get the documents relevant to a query.
   *
   * @param string $index
   *   Index name.
   * @param array $ids
   *   List of document ids to query.
   * @param string $query_text
   *   Text of the query.
   * @param array $query_embedding
   *   Embedding for the text query.
   * @param int $limit
   *   Maximum number of relevant passages to return.
   *
   * @return array
   *   List of documents and their text passages relevant to the query.
   */
  public function getRelevantPassages(string $index, array $ids, string $query_text, array $query_embedding, int $limit = 5): array;

  /**
   * Get the contents relevant to a query.
   *
   * @param string $index
   *   Index name.
   * @param array $ids
   *   List of document ids to query.
   * @param string $query_text
   *   Text of the query.
   * @param array $query_embedding
   *   Embedding for the text query.
   *
   * @return array
   *   List of the IDs of the relevant contents.
   */
  public function getRelevantContents(string $index, array $ids, string $query_text, array $query_embedding): array;

}

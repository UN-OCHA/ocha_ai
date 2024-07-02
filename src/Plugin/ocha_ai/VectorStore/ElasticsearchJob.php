<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\VectorStore;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiVectorStore;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Light elasticsearch vector store.
 */
#[OchaAiVectorStore(
  id: 'elasticsearch_job',
  label: new TranslatableMarkup('Elasticsearch (Job)'),
  description: new TranslatableMarkup('Use Elasticsearch as vector store for jobs.')
)]
class ElasticsearchJob extends Elasticsearch {

  /**
   * {@inheritdoc}
   */
  public function getBaseIndexName(): string {
    return $this->getPluginSetting('base_index_name', 'ocha_ai_job');
  }

  /**
   * Get the contents relevant to a query.
   *
   * @param string $index
   *   Index name.
   * @param array $ids
   *   List of document ids to exclude.
   * @param string $query_text
   *   Text of the query.
   * @param array $query_embedding
   *   Embedding for the text query.
   *
   * @return array
   *   List of the IDs of the relevant contents.
   */
  public function getRelevantContents(string $index, array $ids, string $query_text, array $query_embedding): array {
    if (!$this->indexExists($index)) {
      return [];
    }

    $query = [
      'size' => 50,
      'query' => [
        'nested' => [
          'path' => 'contents',
          'query' => [
            'script_score' => [
              'query' => [
                'bool' => [
                  'filter' => [
                    'exists' => [
                      'field' => 'contents.embedding',
                    ],
                  ],
                ],
              ],
              'script' => [
                'source' => 'cosineSimilarity(params.queryVector, "contents.embedding") + 1.0',
                'params' => [
                  'queryVector' => $query_embedding,
                ],
              ],
              'min_score' => (float) $this->getPluginSetting('min_similarity') + 1.0,
            ],
          ],
          'inner_hits' => [
            '_source' => [
              'contents.id',
            ],
            'size' => (int) $this->getPluginSetting('topk'),
          ],
          'score_mode' => 'max',
        ],
      ],
    ];

    $query = [
      'knn' => [
        'field' => 'embedding',
        'query_vector' => $query_embedding,
        'k' => (int) $this->getPluginSetting('topk'),
        'num_candidates' => 250,
      ],
      '_source' => [
        'excludes' => [
          'embedding',
        ],
      ],
    ];

    try {
      $response = $this->request('POST', $index . '/_knn_search', json_encode($query), 'application/json');
      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (!is_null($data)) {
        // Get the list of contents and their similarity score.
        $contents = [];
        foreach ($data['hits']['hits'] ?? [] as $hit) {
          if (!in_array($hit['_id'], $ids)) {
            $contents[$hit['_id']] = $hit['_score'];
          }
        }

        if (empty($contents)) {
          return [];
        }

        // Sort by score descending.
        arsort($contents);
        // Retrieve the minimum similarity to be considered relevant.
        $cutoff = $this->getSimilarityScoreCutOff($contents, .20);

        // Exclude irrelevant contents.
        return array_filter($contents, function ($score) use ($cutoff) {
          return $score >= $cutoff;
        });
      }
    }
    catch (GuzzleException $exception) {
      $response = $exception->getMessage();
      $status_code = $exception->getCode();

      $this->getLogger()->error(strtr('Job vector request failed with @status error: @error', [
        '@status' => $status_code,
        '@error' => $exception->getMessage(),
      ]));
    }
    catch (\Throwable $exception) {

    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function createIndex(string $index, int $dimensions): bool {
    if ($this->indexExists($index)) {
      return TRUE;
    }

    // @todo add other fields, notably the sources (organizations) and
    // publication date so we can generate proper references.
    $payload = [
      'settings' => [
        'index.mapping.nested_objects.limit' => $this->getPluginSetting('nested_object_limit', 100000),
        'number_of_shards' => $this->getPluginSetting('shards', 1),
        'number_of_replicas' => $this->getPluginSetting('replicas', 0),
      ],
      'mappings' => [
        'properties' => [
          'id' => [
            'type' => 'keyword',
          ],
          'title' => [
            'type' => 'text',
          ],
          'body' => [
            'type' => 'text',
          ],
          'url' => [
            'type' => 'keyword',
          ],
          'source' => [
            'type' => 'object',
            'properties' => [
              'name' => [
                'type' => 'text',
              ],
              'shortname' => [
                'type' => 'text',
              ],
            ],
          ],
          'date' => [
            'type' => 'object',
            'properties' => [
              'changed' => [
                'type' => 'date',
              ],
              'created' => [
                'type' => 'date',
              ],
              'original' => [
                'type' => 'date',
              ],
            ],
          ],
          'embedding' => [
            'type' => 'dense_vector',
            'dims' => $dimensions,
            'index' => TRUE,
            'similarity' => 'dot_product',
          ],
          'contents' => [
            'type' => 'nested',
            'properties' => [
              'id' => [
                'type' => 'keyword',
              ],
              'type' => [
                'type' => 'text',
                'index' => FALSE,
              ],
              'url' => [
                'type' => 'text',
                'index' => FALSE,
              ],
              'title' => [
                'type' => 'text',
                'index' => FALSE,
              ],
            ],
          ],
        ],
      ],
    ];

    $response = $this->request('PUT', $index, $payload);
    if (is_null($response)) {
      $this->getLogger()->error(strtr('Unable to create elasticsearch index: @index', [
        '@index' => $index,
      ]));
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function indexDocuments(string $index, array $documents, int $dimensions): bool {
    // Skip if there is nothing to index.
    if (empty($documents)) {
      return TRUE;
    }

    // Ensure the index exist.
    if (!$this->createIndex($index, $dimensions)) {
      return FALSE;
    }

    // Bulk index the documents.
    foreach (array_chunk($documents, (int) $this->getPluginSetting('indexing_batch_size', 1), TRUE) as $chunks) {
      $payload = [];
      foreach ($chunks as $id => $document) {
        // Do not store raw data.
        unset($document['raw']);
        $payload[] = json_encode(['index' => ['_id' => $id]]);

        $document['embedding'] = $document['contents']['embedding'];
        unset($document['contents']['embedding']);

        $payload[] = json_encode($document);
        // Try to free up some memory.
        unset($documents[$id]);
      }
      $payload = implode("\n", $payload) . "\n";

      $response = $this->request('POST', $index . '/_bulk?refresh=true', $payload, 'application/x-ndjson');

      // Abort if there are issues with the indexing.
      if (is_null($response)) {
        return FALSE;
      }
    }

    return TRUE;
  }

}

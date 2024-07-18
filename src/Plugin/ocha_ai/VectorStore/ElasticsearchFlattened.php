<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\VectorStore;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiVectorStore;

/**
 * Light elasticsearch vector store with flattened structure.
 */
#[OchaAiVectorStore(
  id: 'elasticsearch_flattened',
  label: new TranslatableMarkup('Elasticsearch Flattened'),
  description: new TranslatableMarkup('Use Elasticsearch Flattened as vector store.')
)]
class ElasticsearchFlattened extends Elasticsearch {

  /**
   * {@inheritdoc}
   */
  public function getBaseIndexName(): string {
    return $this->getPluginSetting('base_index_name', 'ocha_ai');
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
          'description' => [
            'properties' => [
              'text' => [
                'type' => 'text',
              ],
              'embedding' => [
                'type' => 'dense_vector',
                'dims' => $dimensions,
                'index' => FALSE,
              ],
            ],
          ],
          'contents' => [
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
          'passages' => [
            'type' => 'nested',
            'properties' => [
              'index' => [
                'type' => 'integer',
                'index' => TRUE,
              ],
              'content' => [
                'type' => 'keyword',
                'index' => FALSE,
              ],
              'page' => [
                'type' => 'integer',
                'index' => FALSE,
              ],
              'text' => [
                'type' => 'text',
                'index' => FALSE,
              ],
              'embedding' => [
                'type' => 'dense_vector',
                'dims' => $dimensions,
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
    $documents = array_map([$this, 'flattenDocument'], $documents);
    return parent::indexDocuments($index, $documents, $dimensions);
  }

  /**
   * {@inheritdoc}
   */
  public function indexDocument(string $index, array $document, int $dimensions): bool {
    $document = $this->flattenDocument($document);
    return parent::indexDocument($index, $document, $dimensions);
  }

  /**
   * Flatten a document (contents > pages > passages to passages).
   *
   * @param array $document
   *   Document.
   *
   * @return array
   *   Flattened document.
   */
  protected function flattenDocument(array $document): array {
    if (!empty($document)) {
      $passages = [];
      $passage_index = 0;
      foreach ($document['contents'] ?? [] as $content_index => $content) {
        foreach ($content['pages'] ?? [] as $page_index => $page) {
          foreach ($page['passages'] as $passage) {
            $passages[] = [
              'index' => $passage_index++,
              'content' => $content['id'],
              'page' => $page_index + 1,
            ] + $passage;
          }
        }
        unset($document['contents'][$content_index]['pages']);
        unset($document['contents'][$content_index]['content']);
        unset($document['contents'][$content_index]['embedding']);
      }
      $document['passages'] = $passages;
    }
    return $document;
  }

  /**
   * {@inheritdoc}
   */
  public function getRelevantPassages(string $index, array $ids, string $query_text, array $query_embedding, int $limit = 5): array {
    if (!$this->indexExists($index)) {
      return [];
    }

    if (!$this->indexExists($index)) {
      return [];
    }

    $query = [
      '_source' => [
        'id',
        'url',
        'title',
        'source',
        'date',
        'contents',
      ],
      'size' => count($ids),
      'query' => [
        'nested' => [
          'path' => 'passages',
          'query' => [
            'script_score' => [
              'query' => [
                'bool' => [
                  'filter' => [
                    'exists' => [
                      'field' => 'passages.embedding',
                    ],
                  ],
                  'must' => [
                    'ids' => [
                      'values' => $ids,
                    ],
                  ],
                ],
              ],
              'script' => [
                'source' => 'cosineSimilarity(params.queryVector, "passages.embedding") + 1.0',
                'params' => [
                  'queryVector' => $query_embedding,
                ],
              ],
              'min_score' => (float) $this->getPluginSetting('min_similarity') + 1.0,
            ],
          ],
          'inner_hits' => [
            '_source' => [
              'passages.index',
              'passages.content',
              'passages.page',
              'passages.text',
              'passages.embedding',
            ],
            'size' => (int) $this->getPluginSetting('topk') * 2,
          ],
          'score_mode' => 'max',
        ],
      ],
    ];

    $response = $this->request('POST', $index . '/_search', $query);

    $data = $this->getResponseContent($response, 'POST', $index . '/_search');
    if (!is_null($data)) {

      // Get the list of passages and their similarity score.
      $passages = [];
      foreach ($data['hits']['hits'] ?? [] as $hit) {
        $document = $hit['_source'];
        unset($document['contents']);

        $contents = [];
        foreach ($hit['_source']['contents'] as $content) {
          $contents[$content['id']] = $content;
        }

        foreach ($hit['inner_hits']['passages']['hits']['hits'] ?? [] as $inner_hit) {
          $passage = $inner_hit['_source'] + [
            'score' => $inner_hit['_score'],
            'source' => $document,
          ];

          $content = $contents[$inner_hit['_source']['content']];
          if ($content['type'] === 'file') {
            $passage['source']['attachment'] = [
              'url' => $content['url'],
              'page' => $inner_hit['_source']['page'],
            ];
          }

          $passages[] = $passage;
        }
      }

      if (empty($passages)) {
        return [];
      }

      // Sort by passage score to have the most relevant first.
      usort($passages, function ($a, $b) {
        return $b['score'] <=> $a['score'];
      });

      // Retrieve the minimum similarity to be considered relevant.
      $similaries = array_map(function ($passage) {
        return $passage['score'] ?? 1.0;
      }, $passages);
      $cutoff = $this->getSimilarityScoreCutOff($similaries);

      // Exclude irrelevant contents.
      $passages = array_filter($passages, function ($passage) use ($cutoff) {
        return $passage['score'] >= $cutoff;
      });

      // Limit the number of passages.
      return array_slice($passages, 0, $limit);
    }

    return [];
  }

}

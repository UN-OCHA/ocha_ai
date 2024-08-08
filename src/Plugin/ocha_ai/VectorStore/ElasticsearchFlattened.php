<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\VectorStore;

use Drupal\Core\Form\FormStateInterface;
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
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $plugin_type = $this->getPluginType();
    $plugin_id = $this->getPluginId();
    $config = $this->getConfiguration() + $this->defaultConfiguration();

    $form['plugins'][$plugin_type][$plugin_id]['expand_passage_before'] = [
      '#type' => 'number',
      '#title' => $this->t('Expand passage - Before'),
      '#description' => $this->t('Number of adjacent text passages to prepend to the text when passed as context.'),
      '#default_value' => $config['expand_passage_before'] ?? 0,
      '#required' => FALSE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['expand_passage_after'] = [
      '#type' => 'number',
      '#title' => $this->t('Expand passage - After'),
      '#description' => $this->t('Number of adjacent text passages to append to the text when passed as context.'),
      '#default_value' => $config['expand_passage_after'] ?? 0,
      '#required' => FALSE,
    ];

    return $form;
  }

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
    if (is_null($data)) {
      return [];
    }

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

    // Expand the text of the passages with the text of the adjacent passages
    // to enrich the context.
    $passages = $this->expandPassages($index, $passages);

    // Limit the number of passages.
    return array_slice($passages, 0, $limit);
  }

  /**
   * Expand the text of the passages with the text of the adjacent passages.
   *
   * @param string $elasticsearch_index
   *   Elasticsearch index to query.
   * @param array $passages
   *   Relevant passages.
   *
   * @return array
   *   The passages with an expanded_text property with the concatenated
   *   text of the adjacent passages.
   */
  protected function expandPassages(string $elasticsearch_index, array $passages): array {
    // @todo add plugin settings for those.
    $before = $this->getPluginSetting('expand_passage_before', 0, FALSE);
    $after = $this->getPluginSetting('expand_passage_after', 0, FALSE);

    if (empty($before) && empty($after)) {
      return $passages;
    }

    $indices = array_map(function ($passage) {
      return $passage['index'];
    }, $passages);

    $adjacent_passages = $this->getAdjacentPassages($elasticsearch_index, $indices, $before, $after);

    foreach ($passages as $key => $passage) {
      $index = $passage['index'];

      $adjacent_indices = [];
      if ($before > 0) {
        for ($i = $index - 1; $i >= max($index - $before, 0); $i--) {
          $adjacent_indices[] = $i;
        }
      }
      if ($after > 0) {
        for ($i = $index + 1; $i <= $index + $after; $i++) {
          $adjacent_indices[] = $i;
        }
      }

      $expanded_text_parts = [$index => trim($passage['text'])];

      foreach ($adjacent_indices as $adjacent_index) {
        if (!isset($adjacent_passages[$adjacent_index])) {
          continue;
        }
        $adjacent_passage = $adjacent_passages[$adjacent_index];

        if ($passage['content'] !== $adjacent_passage['content']) {
          continue;
        }

        // @todo maybe calculate the similarity to the question embedding and
        // discard adjacent passages that are way too dissimilar.
        //
        // @todo if we can get more information about a passage like, the
        // paragraph/table/list index, then we could discard passages not in
        // the same "block".
        $expanded_text_parts[$adjacent_index] = trim($adjacent_passage['text']);
      }

      ksort($expanded_text_parts);

      // @todo the separator may need to be adjusted based on the language.
      $passages[$key]['expanded_text'] = implode(' ', $expanded_text_parts);
    }

    return $passages;
  }

  /**
   * Retrieve the adjacent passages.
   *
   * @param string $elasticsearch_index
   *   Elasticsearch index to query.
   * @param array<int> $indices
   *   Already retrieved passage indices.
   * @param int $before
   *   How many previous passages to retrieve.
   * @param int $after
   *   How many next passages to retrieves.
   *
   * @return array
   *   Passages with their text and embeddings keyed by their index.
   */
  protected function getAdjacentPassages(string $elasticsearch_index, array $indices, int $before, int $after): array {
    $adjacent_indices = [];
    foreach ($indices as $index) {
      if ($before > 0) {
        for ($i = $index - 1; $i >= max($index - $before, 0); $i--) {
          $adjacent_indices[] = $i;
        }
      }
      if ($after > 0) {
        for ($i = $index + 1; $i <= $index + $after; $i++) {
          $adjacent_indices[] = $i;
        }
      }
    }

    $query = [
      '_source' => FALSE,
      'size' => count($adjacent_indices),
      'query' => [
        'nested' => [
          'path' => 'passages',
          'query' => [
            'terms' => [
              'passages.index' => $adjacent_indices,
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
            'size' => count($adjacent_indices),
          ],
        ],
      ],
    ];

    $response = $this->request('POST', $elasticsearch_index . '/_search', $query);

    $data = $this->getResponseContent($response, 'POST', $elasticsearch_index . '/_search');
    if (is_null($data)) {
      return [];
    }

    // Get the list of passages and their similarity score.
    $passages = [];
    foreach ($data['hits']['hits'] ?? [] as $hit) {
      foreach ($hit['inner_hits']['passages']['hits']['hits'] ?? [] as $inner_hit) {
        $passages[$inner_hit['_source']['index']] = $inner_hit['_source'];
      }
    }

    return $passages;
  }

}

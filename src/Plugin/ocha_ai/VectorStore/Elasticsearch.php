<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\VectorStore;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiVectorStore;
use Drupal\ocha_ai\Helpers\VectorHelper;
use Drupal\ocha_ai\Plugin\VectorStorePluginBase;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Http\Message\ResponseInterface;

/**
 * Light elasticsearch vector store.
 */
#[OchaAiVectorStore(
  id: 'elasticsearch',
  label: new TranslatableMarkup('Elasticsearch'),
  description: new TranslatableMarkup('Use Elasticsearch as vector store.')
)]
class Elasticsearch extends VectorStorePluginBase {

  /**
   * URL of the elasticsearch cluster.
   *
   * @var string
   */
  protected string $url;

  /**
   * Indexing batch size.
   *
   * @var int
   */
  protected int $indexingBatchSize;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $plugin_type = $this->getPluginType();
    $plugin_id = $this->getPluginId();
    $config = $this->getConfiguration() + $this->defaultConfiguration();

    $form['plugins'][$plugin_type][$plugin_id]['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL'),
      '#description' => $this->t('URL of the Elasticsearch cluster'),
      '#default_value' => $config['url'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['indexing_batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Indexing batch size'),
      '#description' => $this->t('Number of documents to index at once.'),
      '#default_value' => $config['indexing_batch_size'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['topk'] = [
      '#type' => 'number',
      '#title' => $this->t('TopK'),
      '#description' => $this->t('Maximum number of nearest neighbours to retrieve when doing a similarity search.'),
      '#default_value' => $config['topk'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['min_similarity'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum similarity'),
      '#description' => $this->t('Minimum similarity to be considered relevant.'),
      '#default_value' => $config['min_similarity'] ?? NULL,
      '#required' => TRUE,
      '#step' => '.01',
    ];

    $form['plugins'][$plugin_type][$plugin_id]['cutoff_coefficient'] = [
      '#type' => 'number',
      '#title' => $this->t('Cut-off coefficient'),
      '#description' => $this->t('Coefficient for the standard deviation to determine the similarity cut-off for relevancy.'),
      '#default_value' => $config['cutoff_coefficient'] ?? NULL,
      '#required' => TRUE,
      '#step' => '.01',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'indexing_batch_size' => 10,
      'topk' => 5,
      'min_similarity' => 0.3,
      'cutoff_coefficient' => 0.5,
    ];
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
          'embedding' => [
            'type' => 'dense_vector',
            'dims' => $dimensions,
            'index' => FALSE,
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
              'embedding' => [
                'type' => 'dense_vector',
                'dims' => $dimensions,
                'index' => FALSE,
              ],
              'pages' => [
                'type' => 'nested',
                'properties' => [
                  'page' => [
                    'type' => 'integer',
                    'index' => FALSE,
                  ],
                  'embedding' => [
                    'type' => 'dense_vector',
                    'dims' => $dimensions,
                    'index' => FALSE,
                  ],
                  'text' => [
                    'type' => 'text',
                    'index' => FALSE,
                  ],
                  'passages' => [
                    'type' => 'nested',
                    'properties' => [
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
  public function deleteIndex(string $index): bool {
    if (!$this->indexExists($index)) {
      return TRUE;
    }
    return !is_null($this->request('DELETE', $index));
  }

  /**
   * {@inheritdoc}
   */
  public function indexExists(string $index): bool {
    return !is_null($this->request('HEAD', $index, valid_status_codes: [404]));
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

  /**
   * {@inheritdoc}
   */
  public function indexDocument(string $index, array $document, int $dimensions): bool {
    // Skip if there is nothing to index.
    if (empty($document)) {
      return TRUE;
    }

    // Do not store raw data.
    unset($document['raw']);

    // Ensure the index exist.
    if (!$this->createIndex($index, $dimensions)) {
      return FALSE;
    }

    $payload = [
      'doc' => $document,
      'doc_as_upsert' => TRUE,
    ];

    // Create or replace the document.
    $response = $this->request('POST', $index . '/_update/' . $document['id'] . '?refresh=true', $payload);
    if (is_null($response)) {
      $this->getLogger()->error(strtr('Unable to index document @id (@url)', [
        '@id' => $document['id'],
        '@url' => $document['url'] ?? '-',
      ]));
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getDocument(string $index, string $id): array {
    if (!$this->indexExists($index)) {
      return [];
    }

    try {
      $response = $this->request('GET', $index . '/_doc/' . $id);

      if (!is_null($response)) {
        return json_decode($response->getBody()->getContents(), TRUE);
      }
    }
    catch (\Exception $e) {

    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getDocuments(string $index, array $ids, array $fields = ['id']): array {
    if (!$this->indexExists($index)) {
      return [];
    }

    $query = [
      'query' => [
        'ids' => [
          'values' => $ids,
        ],
      ],
      'size' => count($ids),
      '_source' => $fields,
    ];

    $response = $this->request('POST', $index . '/_search', $query);

    if (!is_null($response)) {
      $data = json_decode($response->getBody()->getContents(), TRUE);

      $documents = [];
      foreach ($data['hits']['hits'] ?? [] as $item) {
        $documents[$item['_source']['id']] = $item['_source'];
      }
      return $documents;
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getRelevantPassages(string $index, array $ids, string $query_text, array $query_embedding, int $limit = 5): array {
    if (!$this->indexExists($index)) {
      return [];
    }

    // Retrieve the most relevant content pages.
    $pages = $this->getRelevantPages($index, $ids, $query_text, $query_embedding);
    if (empty($pages)) {
      return [];
    }

    $passages = [];
    foreach ($pages as $page) {
      // Calculate the similarity of the passage against the query.
      $similarity_scores = [];
      foreach ($page['passages'] as $key => $passage) {
        $similarity_scores[$key] = VectorHelper::cosineSimilarity($passage['embedding'], $query_embedding) + 1.0;
        $page['passages'][$key]['similarity'] = $similarity_scores[$key];
        // Remove the embedding to reduce memory usage.
        unset($page['passages'][$key]['embedding']);
      }

      // Retrieve the minimum similarity to be considered relevant.
      $cutoff = $this->getSimilarityScoreCutOff($similarity_scores);

      // Filter out irrelevant passages.
      // @todo discard passages which are too short?
      foreach ($page['passages'] as $passage) {
        if ($passage['similarity'] >= $cutoff) {
          // @todo is there a better formula?
          $passage['score'] = $page['score'] * $passage['similarity'];
          $passage['source'] = $page['source'];
          // Ensure uniqueness by using the text as key.
          $passages[mb_strtolower($passage['text'])] = $passage;
        }
      }
    }

    if (!empty($passages)) {
      $passages = array_values($passages);

      // Sort by passage score to have the most relevant first.
      usort($passages, function ($a, $b) {
        return $b['score'] <=> $a['score'];
      });

      // Limit the number of passages.
      return array_slice($passages, 0, $limit);
    }

    return $passages;
  }

  /**
   * Get the pages relevant to a query.
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
   *   List of pages and their text passages relevant to the query.
   */
  protected function getRelevantPages(string $index, array $ids, string $query_text, array $query_embedding): array {
    if (!$this->indexExists($index)) {
      return [];
    }

    // Retrieve the most relevant contents (document content or attachments).
    $contents = $this->getRelevantContents($index, $ids, $query_text, $query_embedding);
    if (empty($contents)) {
      return [];
    }

    // Query to retrieve the most relevant pages for the most relevant contents.
    $query = [
      '_source' => [
        'id',
        // @todo that may not be needed if the entire document is already
        // available to the caller.
        // @see \Drupal\ocha_ai\modules\ocha_ai_chat\Services\OchaAiChat::answer()
        'url',
        'title',
        'source',
        'date',
      ],
      'size' => count($ids) * count($contents),
      'query' => [
        'nested' => [
          'path' => 'contents',
          'query' => [
            'bool' => [
              'filter' => [
                'terms' => [
                  'contents.id' => $contents,
                ],
              ],
              'must' => [
                'nested' => [
                  'path' => 'contents.pages',
                  'query' => [
                    'script_score' => [
                      'query' => [
                        // Ensure this appears as an object when converted to
                        // JSON.
                        'match_all' => (object) [],
                      ],
                      'script' => [
                        'source' => 'cosineSimilarity(params.queryVector, "contents.pages.embedding") + 1.0',
                        'params' => [
                          'queryVector' => $query_embedding,
                        ],
                      ],
                      'min_score' => (float) $this->getPluginSetting('min_similarity') + 1.0,
                    ],
                  ],
                  'inner_hits' => [
                    '_source' => [
                      'contents.pages.page',
                      'contents.pages.passages',
                    ],
                    'size' => (int) $this->getPluginSetting('topk'),
                  ],
                  'score_mode' => 'max',
                ],
              ],
            ],
          ],
          'inner_hits' => [
            '_source' => [
              'contents.id',
              'contents.url',
              'contents.type',
            ],
            'size' => count($contents),
          ],
          'score_mode' => 'max',
        ],
      ],
    ];

    $response = $this->request('POST', $index . '/_search', $query);

    $data = $this->getResponseContent($response, 'POST', $index . '/_search');
    if (!is_null($data)) {
      $pages = [];

      foreach ($data['hits']['hits'] ?? [] as $document_hit) {
        $document = $document_hit['_source'];

        foreach ($document_hit['inner_hits']['contents']['hits']['hits'] ?? [] as $content_hit) {
          $content = $content_hit['_source'];

          foreach ($content_hit['inner_hits']['contents.pages']['hits']['hits'] ?? [] as $page_hit) {
            $source = $document;

            if ($content['type'] === 'file') {
              $source['attachment'] = [
                'url' => $content['url'],
                'page' => $page_hit['_source']['page'],
              ];
            }

            $pages[] = [
              'score' => $page_hit['_score'],
              'passages' => $page_hit['_source']['passages'],
              'source' => $source,
            ];
          }
        }
      }

      if (empty($pages)) {
        return [];
      }

      // Sort by score descending.
      usort($pages, function ($a, $b) {
        return $b['score'] <=> $a['score'];
      });

      // Get the similarity score of the pages so we can remove the most
      // irrelevant ones.
      $similarity_scores = [];
      foreach ($pages as $page) {
        $similarity_scores[] = $page['score'];
      }

      // Retrieve the minimum similarity to be considered relevant.
      $cutoff = $this->getSimilarityScoreCutOff($similarity_scores);

      // Filter out irrelevant pages.
      return array_filter($pages, function ($page) use ($cutoff) {
        return $page['score'] >= $cutoff;
      });
    }

    return [];
  }

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
  public function getRelevantContents(string $index, array $ids, string $query_text, array $query_embedding): array {
    if (!$this->indexExists($index)) {
      return [];
    }

    $query = [
      '_source' => [
        'id',
      ],
      'size' => count($ids),
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
                  'must' => [
                    'ids' => [
                      'values' => $ids,
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

    $response = $this->request('POST', $index . '/_search', $query);

    $data = $this->getResponseContent($response, 'POST', $index . '/_search');
    if (!is_null($data)) {

      // Get the list of contents and their similarity score.
      $contents = [];
      foreach ($data['hits']['hits'] ?? [] as $hit) {
        foreach ($hit['inner_hits']['contents']['hits']['hits'] ?? [] as $inner_hit) {
          $contents[$inner_hit['_source']['id']] = $inner_hit['_score'];
        }
      }
      if (empty($contents)) {
        return [];
      }

      // Sort by score descending.
      arsort($contents);

      // Retrieve the minimum similarity to be considered relevant.
      $cutoff = $this->getSimilarityScoreCutOff($contents);

      // Exclude irrelevant contents.
      return array_keys(array_filter($contents, function ($score) use ($cutoff) {
        return $score >= $cutoff;
      }));
    }

    return [];
  }

  /**
   * Perform a request against the elasticsearch cluster.
   *
   * @param string $method
   *   Request method.
   * @param string $endpoint
   *   Request endpoint.
   * @param mixed|null $payload
   *   Optional payload (will be converted to JSON if not content type is
   *   provided).
   * @param string|null $content_type
   *   Optional content type of the payload. If not defined it is assumed to be
   *   JSON.
   * @param array $valid_status_codes
   *   List of valid status codes that should not be logged as errors.
   *
   * @return \Psr\Http\Message\ResponseInterface|null
   *   The response or NULL if the request was not successful.
   */
  protected function request(string $method, string $endpoint, $payload = NULL, ?string $content_type = NULL, array $valid_status_codes = []): ?ResponseInterface {
    $url = rtrim($this->getPluginSetting('url'), '/') . '/' . ltrim($endpoint, '/');
    $options = [];

    if (isset($payload)) {
      if (empty($content_type)) {
        $options['json'] = $payload;
      }
      else {
        $options['body'] = $payload;
        $options['headers']['Content-Type'] = $content_type;
      }
    }

    try {
      /** @var \Psr\Http\Message\ResponseInterface $response */
      $response = $this->httpClient->request($method, $url, $options);
      return $response;
    }
    catch (BadResponseException $exception) {
      $response = $exception->getResponse();
      $status_code = $response->getStatusCode();
      if (!in_array($status_code, $valid_status_codes)) {
        $this->getLogger()->error(strtr('@method request to @endpoint failed with @status error: @error', [
          '@method' => $method,
          '@endpoint' => $endpoint,
          '@status' => $status_code,
          '@error' => $exception->getMessage(),
        ]));
      }
    }
    catch (\Exception $exception) {
      $this->getLogger()->error(strtr('@method request to @endpoint failed with @status error: @error', [
        '@method' => $method,
        '@endpoint' => $endpoint,
        '@status' => $exception->getCode(),
        '@error' => $exception->getMessage(),
      ]));
    }

    return NULL;
  }

  /**
   * Get the content of a response.
   *
   * @param \Psr\Http\Message\ResponseInterface|null $response
   *   The response.
   * @param string $method
   *   HTTP method.
   * @param string $endpoint
   *   Endpoint being called.
   *
   * @return array|null
   *   The decoded response content.
   */
  protected function getResponseContent(?ResponseInterface $response = NULL, string $method = 'GET', string $endpoint = ''): ?array {
    if (is_null($response)) {
      return NULL;
    }

    // Decode the response content.
    try {
      $data = json_decode($response->getBody()->getContents(), TRUE, flags: \JSON_THROW_ON_ERROR);
    }
    catch (\Exception $exception) {
      $this->getLogger()->error(strtr('Unable to decode response from @method request to @endpoint', [
        '@method' => $method,
        '@endpoint' => $endpoint,
      ]));
      return NULL;
    }
    if (!is_array($data)) {
      $this->getLogger()->error(strtr('Invalid response data from @method request to @endpoint', [
        '@method' => $method,
        '@endpoint' => $endpoint,
      ]));
      return NULL;
    }
    return $data;
  }

  /**
   * Calculate the similarity score cut-off.
   *
   * @param array $similarity_scores
   *   List of similarity scores as floats.
   * @param float|null $alpha
   *   Coefficient to adjust the cut-off value.
   *
   * @return float
   *   Similarity score cut-off.
   */
  protected function getSimilarityScoreCutOff(array $similarity_scores, ?float $alpha = NULL): float {
    $alpha = $alpha ?? (float) $this->getPluginSetting('cutoff_coefficient');

    $count = count($similarity_scores);
    if ($count === 0) {
      return 0.0;
    }
    elseif ($count === 1) {
      return reset($similarity_scores);
    }

    // Determine the average similarity score.
    $mean = array_sum($similarity_scores) / $count;

    // Determine the standard deviation.
    $sample = FALSE;
    $variance = 0.0;
    foreach ($similarity_scores as $value) {
      $variance += pow((float) $value - $mean, 2);
    };
    $deviation = (float) sqrt($variance / ($sample ? $count - 1 : $count));

    // Calculate the similarity cut-off.
    $cutoff = $mean + $alpha * $deviation;

    // The above formula can result in a cutoff higher than the highest
    // similarity. In that case we return the max similarity to avoid discarding
    // everything.
    return min($cutoff, max($similarity_scores));
  }

}

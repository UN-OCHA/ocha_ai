<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\VectorStore;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiVectorStore;

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

    $response = $this->request('POST', $index . '/_search', $query);

    $data = $this->getResponseContent($response, 'POST', $index . '/_search');
    if (!is_null($data)) {
      // Get the list of contents and their similarity score.
      $contents = [];
      foreach ($data['hits']['hits'] ?? [] as $hit) {
        foreach ($hit['inner_hits']['contents']['hits']['hits'] ?? [] as $inner_hit) {
          $contents[$inner_hit['_id']] = $inner_hit['_score'];
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

}

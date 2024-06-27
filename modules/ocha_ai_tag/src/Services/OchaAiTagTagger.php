<?php

namespace Drupal\ocha_ai_tag\Services;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ocha_ai\Helpers\VectorHelper;
use Drupal\ocha_ai\Plugin\EmbeddingPluginManagerInterface;
use Drupal\ocha_ai\Plugin\SourcePluginManagerInterface;
use Drupal\ocha_ai\Plugin\TextSplitterPluginManagerInterface;
use Drupal\ocha_ai\Plugin\VectorStorePluginManagerInterface;
use Drupal\ocha_ai_chat\Services\OchaAiChat;

/**
 * OCHA AI Tag service.
 */
class OchaAiTagTagger extends OchaAiChat {

  public const CALCULATION_METHOD_MAX_MEAN = 'max_mean';
  public const CALCULATION_METHOD_MAX = 'max';
  public const CALCULATION_METHOD_MEAN = 'mean';
  public const CALCULATION_METHOD_MEAN_WITH_CUTOFF = 'mean_with_cutoff';

  public const AVERAGE_FULL_AVERAGE = 'average';
  public const AVERAGE_FULL_FULL = 'full';

  /**
   * The default cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cacheBackend;

  /**
   * Vocabulary mapping.
   *
   * @var array
   */
  protected $vocabularyMapping = [];

  /**
   * Term cache tags.
   *
   * @var array
   */
  protected $termCacheTags = [];

  /**
   * Constructor.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
    AccountProxyInterface $current_user,
    Connection $database,
    TimeInterface $time,
    CacheBackendInterface $cache_backend,
    EmbeddingPluginManagerInterface $embedding_plugin_manager,
    SourcePluginManagerInterface $source_plugin_manager,
    TextSplitterPluginManagerInterface $text_splitter_plugin_manager,
    VectorStorePluginManagerInterface $vector_store_plugin_manager,
  ) {
    $this->config = $config_factory->get('ocha_ai_tag.settings');
    $this->logger = $logger_factory->get('ocha_ai_tag');
    $this->state = $state;
    $this->currentUser = $current_user;
    $this->database = $database;
    $this->time = $time;
    $this->cacheBackend = $cache_backend;
    $this->embeddingPluginManager = $embedding_plugin_manager;
    $this->sourcePluginManager = $source_plugin_manager;
    $this->textSplitterPluginManager = $text_splitter_plugin_manager;
    $this->vectorStorePluginManager = $vector_store_plugin_manager;
  }

  /**
   * Get the default settings for the OCHA AI Chat.
   *
   * @return array
   *   The OCHA AI Chat settings.
   */
  public function getSettings(): array {
    if (!isset($this->settings)) {
      $config_defaults = $this->config->get('defaults') ?? [];

      $state_defaults = $this->state->get('ocha_ai_tag.default_settings', []);

      $this->settings = array_replace_recursive($config_defaults, $state_defaults);
    }
    return $this->settings;
  }

  /**
   * Get vocabulary mapping.
   */
  public function getVocabularies() : array {
    return $this->vocabularyMapping;
  }

  /**
   * Get term cache tags.
   */
  public function getTermCacheTags() : array {
    return $this->termCacheTags;
  }

  /**
   * Set vocabulary mapping.
   */
  public function setVocabularies(array $mapping, array $term_cache_tags = []) : self {
    $this->vocabularyMapping = $mapping;
    $this->termCacheTags = $term_cache_tags;

    return $this;
  }

  /**
   * Tag a text.
   *
   * @param string $text
   *   Text.
   * @param array $calculation_methods
   *   One or multiple of CALCULATION_METHOD_.
   * @param array|string $average_full
   *   AVERAGE_FULL_AVERAGE and/or AVERAGE_FULL_FULL.
   */
  public function tag(string $text, $calculation_methods = [], $average_full = []): array {
    $text = trim($text);
    $embeddings = $this->getEmbeddings($text, TRUE);

    if (empty($calculation_methods)) {
      $calculation_methods = [
        self::CALCULATION_METHOD_MAX_MEAN,
        self::CALCULATION_METHOD_MAX,
        self::CALCULATION_METHOD_MEAN,
        self::CALCULATION_METHOD_MEAN_WITH_CUTOFF,
      ];
    }

    if (empty($average_full)) {
      $average_full = [
        self::AVERAGE_FULL_FULL,
        self::AVERAGE_FULL_AVERAGE,
      ];
    }
    elseif (is_string($average_full)) {
      $average_full = [$average_full];
    }

    $results = [];
    foreach ($average_full as $item) {
      $results[$item] = $this->getSimilarTerms($embeddings, $item == self::AVERAGE_FULL_AVERAGE, $calculation_methods);
    }

    return $results;
  }

  /**
   * Perform a request against the API to get the embeddings for the text.
   *
   * @param string|array $text
   *   Text.
   * @param bool $query
   *   TRUE if the text is a search query.
   *
   * @return array
   *   Embedding for the text.
   *
   * @throws \Exception
   *   Throw an exception if the generation of the embeddding fails.
   */
  protected function getEmbeddings(string|array $text, bool $query = FALSE): array {
    if (empty($text)) {
      return [];
    }

    $text_splitter_plugin = $this->getTextSplitterPlugin();

    // Split the text into chunks.
    $chunk_size = $this->getEmbeddingPlugin()->getMaxTokens();

    $texts = [];
    if (is_string($text)) {
      $texts = $text_splitter_plugin->splitText($text, $chunk_size, 0);
    }
    else {
      $texts = [];
      foreach ($text as $part) {
        $texts = array_merge($texts, $text_splitter_plugin->splitText($part, $chunk_size, 0));
      }
    }

    $embeddings = $this->requestEmbeddings($texts, $query);

    return $embeddings;
  }

  /**
   * Perform a request against the API to get the embeddings for the text.
   *
   * @param array $texts
   *   Texts to embed.
   * @param bool $query
   *   TRUE if the text is a search query.
   *
   * @return array
   *   Embedding for the text.
   *
   * @throws \Exception
   *   Throw an exception if the generation of the embeddding fails.
   */
  protected function requestEmbeddings(array $texts, bool $query = FALSE): array {
    if (empty($texts)) {
      return [];
    }

    $data = $this->getEmbeddingPlugin()->generateEmbeddings($texts, $query);

    return $data;
  }

  /**
   * Get the most probable terms for the text.
   *
   * @param array $embeddings
   *   Embeddings of a text as associative arrays with text and embedding
   *   properties.
   * @param bool $average_embeddings
   *   If TRUE, generate the mean of the embeddings and compute the similarities
   *   against it.
   * @param array $types
   *   Types of formula to use to compare the similarity scores. One of "max",
   *   "mean" or "mean_with_cutoff" or "max_mean".
   * @param float|null $alpha
   *   Coefficient to calculate the cutoff value.
   *
   * @return array
   *   Associative array with vacobularies as keys and list of terms and their
   *   similarity score as values.
   */
  protected function getSimilarTerms(array $embeddings, bool $average_embeddings = FALSE, array $types = [self::CALCULATION_METHOD_MAX], $alpha = 0.2): array {
    if ($average_embeddings) {
      $embeddings = [
        VectorHelper::mean($embeddings),
      ];
    };

    $results = [];
    foreach ($this->getTermEmbeddings() as $vocabulary => $terms) {
      // Initialize the results for the given types and vocabularies.
      foreach ($types as $type) {
        $results[$type][$vocabulary] = [];
      }

      // Compute the similarity for each term.
      foreach ($terms as $term => $term_embeddings) {
        $similarities = [];

        // Accumulate the cosine similarities of all the term embeddings
        // compared to the text embeddings.
        foreach ($term_embeddings as $term_embedding) {
          foreach ($embeddings as $embedding) {
            // @todo if the embeddings are normalized, we coul simply use
            // the VectorHelper::dotProdut() method which a bit faster.
            $similarities[] = VectorHelper::cosineSimilarity($term_embedding, $embedding);
          }
        }

        // Reduce to one similarity score with different formulas.
        foreach ($types as $type) {
          switch ($type) {
            case static::CALCULATION_METHOD_MAX:
              $results[$type][$vocabulary][$term] = max($similarities);
              break;

            case static::CALCULATION_METHOD_MEAN:
              $results[$type][$vocabulary][$term] = array_sum($similarities) / count($similarities);
              break;

            case static::CALCULATION_METHOD_MEAN_WITH_CUTOFF:
              $similarities = $this->filterSimilarities($similarities, $alpha);
              $results[$type][$vocabulary][$term] = array_sum($similarities) / count($similarities);
              break;

            case static::CALCULATION_METHOD_MAX_MEAN:
              $results[$type][$vocabulary][$term] = max($similarities) * array_sum($similarities) / count($similarities);
              break;
          }
        }
      }
    }

    // Sort by similarity score descending.
    foreach ($types as $type) {
      foreach ($results[$type] as $vocabulary => $terms) {
        if (!empty($terms)) {
          arsort($results[$type][$vocabulary]);
        }
      }
    }

    return $results;
  }

  /**
   * Get the embeddings for the taxonomy terms.
   */
  protected function getTermEmbeddings(): array {
    $cid = $this->getSetting(['plugins', 'embedding', 'plugin_id']);
    $cache = $this->cacheBackend->get($cid);

    if ($cache) {
      return $cache->data;
    }

    $embeddings = [];
    $vocabularies = $this->getVocabularies();
    foreach ($vocabularies as $vocabulary => $terms) {
      foreach ($terms as $term => $description) {
        $embeddings[$vocabulary][$term] = $this->getEmbeddings($description, FALSE);
      }
    }

    $this->cacheBackend->set($cid, $embeddings, Cache::PERMANENT, $this->getTermCacheTags());

    return $embeddings;
  }

  /**
   * Filter similarity scores with a cutoff value.
   *
   * @param array $similarities
   *   Similarity scores.
   * @param float|null $alpha
   *   Coefficient to calculate the cutoff value.
   *
   * @return array
   *   Filtered similarity scores.
   */
  protected function filterSimilarities(array $similarities, ?float $alpha = NULL): array {
    $cutoff = $this->getSimilarityScoreCutOff($similarities, $alpha);
    return array_filter($similarities, function ($score) use ($cutoff) {
      return $score >= $cutoff;
    });
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
  protected function getSimilarityScoreCutOff(array $similarity_scores, ?float $alpha = 0.5): float {
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

  /**
   * Clear the cache.
   */
  public function clearCache(): self {
    $cid = $this->getSetting(['plugins', 'embedding', 'plugin_id']);
    $this->cacheBackend->delete($cid);

    return $this;
  }

  /**
   * Clear the index.
   */
  public function clearIndex(): self {
    $this->getVectorStorePlugin()->deleteIndex('vector_jobs');

    return $this;
  }

  /**
   * Embed a document.
   */
  public function embedDocument(int $id): bool {
    $data = $this->getSourcePlugin()->getDocument('jobs', $id);
    if (empty($data)) {
      return FALSE;
    }

    $job = reset($data['jobs']);
    $job = $this->processDocument($job);
    return $this->getVectorStorePlugin()->indexDocuments('vector_jobs', [$id => $job], $this->getEmbeddingPlugin()->getDimensions());
  }

  /**
   * Get similar documents from vector store.
   */
  public function getSimilarDocuments($id, string $text = '') {
    $query_embedding = [];
    $doc = $this->getVectorStorePlugin()->getDocument('vector_jobs', $id);

    if (empty($doc)) {
      // Doc doesn't exist in vector store.
      $data = $this->getSourcePlugin()->getDocument('jobs', $id);
      if (empty($data)) {
        $query_embedding = $this->getEmbeddings($text);
      }
      else {
        $job = reset($data['jobs']);
        $job = $this->processDocument($job);
        $query_embedding = $job['embedding'];
      }
    }
    else {
      $doc = $doc['_source'];
      $query_embedding = $doc['embedding'];
    }

    $relevant = $this->getVectorStorePlugin()->getRelevantContents('vector_jobs', [$id], '', $query_embedding);

    return $relevant;
  }

  /**
   * Process (download, extract text, split) a document's contents.
   *
   * @param array $document
   *   Document.
   *
   * @return array
   *   Document with updated contents.
   */
  protected function processDocument(array $document): array {
    foreach ($document['contents'] as $content) {
      switch ($content['type']) {
        case 'markdown':
          $text = $content['content'];
          $embeddings = $this->getEmbeddings($text);
          $document['contents']['embedding'] = reset($embeddings);

          return $document;
      }
    }

    return $document;
  }

}

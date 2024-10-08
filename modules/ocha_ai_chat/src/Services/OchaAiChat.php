<?php

namespace Drupal\ocha_ai_chat\Services;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ocha_ai\Helpers\VectorHelper;
use Drupal\ocha_ai\Plugin\AnswerValidatorPluginInterface;
use Drupal\ocha_ai\Plugin\AnswerValidatorPluginManagerInterface;
use Drupal\ocha_ai\Plugin\CompletionPluginInterface;
use Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface;
use Drupal\ocha_ai\Plugin\EmbeddingPluginInterface;
use Drupal\ocha_ai\Plugin\EmbeddingPluginManagerInterface;
use Drupal\ocha_ai\Plugin\RankerPluginInterface;
use Drupal\ocha_ai\Plugin\RankerPluginManagerInterface;
use Drupal\ocha_ai\Plugin\SourcePluginInterface;
use Drupal\ocha_ai\Plugin\SourcePluginManagerInterface;
use Drupal\ocha_ai\Plugin\TextExtractorPluginInterface;
use Drupal\ocha_ai\Plugin\TextExtractorPluginManagerInterface;
use Drupal\ocha_ai\Plugin\TextSplitterPluginInterface;
use Drupal\ocha_ai\Plugin\TextSplitterPluginManagerInterface;
use Drupal\ocha_ai\Plugin\VectorStorePluginInterface;
use Drupal\ocha_ai\Plugin\VectorStorePluginManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * OCHA AI Chat service.
 */
class OchaAiChat {

  /**
   * OCHA AI Chat config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $config;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * Answer validator plugin manager.
   *
   * @var \Drupal\ocha_ai\Plugin\AnswerValidatorPluginManagerInterface
   */
  protected AnswerValidatorPluginManagerInterface $answerValidatorPluginManager;

  /**
   * Completion plugin manager.
   *
   * @var \Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface
   */
  protected CompletionPluginManagerInterface $completionPluginManager;

  /**
   * Embedding plugin manager.
   *
   * @var \Drupal\ocha_ai\Plugin\EmbeddingPluginManagerInterface
   */
  protected EmbeddingPluginManagerInterface $embeddingPluginManager;

  /**
   * Ranker plugin manager.
   *
   * @var \Drupal\ocha_ai\Plugin\RankerPluginManagerInterface
   */
  protected RankerPluginManagerInterface $rankerPluginManager;

  /**
   * Source plugin manager.
   *
   * @var \Drupal\ocha_ai\Plugin\SourcePluginManagerInterface
   */
  protected SourcePluginManagerInterface $sourcePluginManager;

  /**
   * Text extractor plugin manager.
   *
   * @var \Drupal\ocha_ai\Plugin\TextExtractorPluginManagerInterface
   */
  protected TextExtractorPluginManagerInterface $textExtractorPluginManager;

  /**
   * Text splitter plugin manager.
   *
   * @var \Drupal\ocha_ai\Plugin\TextSplitterPluginManagerInterface
   */
  protected TextSplitterPluginManagerInterface $textSplitterPluginManager;

  /**
   * Vector store manager.
   *
   * @var \Drupal\ocha_ai\Plugin\VectorStorePluginManagerInterface
   */
  protected VectorStorePluginManagerInterface $vectorStorePluginManager;

  /**
   * Static cache for the settings.
   *
   * @var array
   */
  protected array $settings;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Database\Connection $database
   *   The database.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\ocha_ai_chat\Plugin\AnswerValidatorPluginManagerInterface $answer_validator_plugin_manager
   *   The answer validator plugin manager.
   * @param \Drupal\ocha_ai_chat\Plugin\CompletionPluginManagerInterface $completion_plugin_manager
   *   The completion plugin manager.
   * @param \Drupal\ocha_ai_chat\Plugin\EmbeddingPluginManagerInterface $embedding_plugin_manager
   *   The embedding plugin manager.
   * @param \Drupal\ocha_ai_chat\Plugin\RankerPluginManagerInterface $ranker_plugin_manager
   *   The ranker plugin manager.
   * @param \Drupal\ocha_ai_chat\Plugin\SourcePluginManagerInterface $source_plugin_manager
   *   The source plugin manager.
   * @param \Drupal\ocha_ai_chat\Plugin\TextExtractorPluginManagerInterface $text_extractor_plugin_manager
   *   The text extractor plugin manager.
   * @param \Drupal\ocha_ai_chat\Plugin\TextSplitterPluginManagerInterface $text_splitter_plugin_manager
   *   The text splitter plugin manager.
   * @param \Drupal\ocha_ai_chat\Plugin\VectorStorePluginManagerInterface $vector_store_plugin_manager
   *   The vector store plugin manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
    AccountProxyInterface $current_user,
    Connection $database,
    TimeInterface $time,
    AnswerValidatorPluginManagerInterface $answer_validator_plugin_manager,
    CompletionPluginManagerInterface $completion_plugin_manager,
    EmbeddingPluginManagerInterface $embedding_plugin_manager,
    RankerPluginManagerInterface $ranker_plugin_manager,
    SourcePluginManagerInterface $source_plugin_manager,
    TextExtractorPluginManagerInterface $text_extractor_plugin_manager,
    TextSplitterPluginManagerInterface $text_splitter_plugin_manager,
    VectorStorePluginManagerInterface $vector_store_plugin_manager,
  ) {
    $this->config = $config_factory->get('ocha_ai_chat.settings');
    $this->logger = $logger_factory->get('ocha_ai_chat');
    $this->state = $state;
    $this->currentUser = $current_user;
    $this->database = $database;
    $this->time = $time;
    $this->answerValidatorPluginManager = $answer_validator_plugin_manager;
    $this->completionPluginManager = $completion_plugin_manager;
    $this->embeddingPluginManager = $embedding_plugin_manager;
    $this->rankerPluginManager = $ranker_plugin_manager;
    $this->sourcePluginManager = $source_plugin_manager;
    $this->textExtractorPluginManager = $text_extractor_plugin_manager;
    $this->textSplitterPluginManager = $text_splitter_plugin_manager;
    $this->vectorStorePluginManager = $vector_store_plugin_manager;
  }

  /**
   * Answer the question against the ReliefWeb documents from the river URL.
   *
   * 1. Retrieve the documents from the ReliefWeb API URL.
   * 2. Embed the documents if not already.
   * 3. Generate the embedding for the question
   * 4. Find the documents relevant to the question.
   * 5. Generate the prompt context.
   * 6. Answer the question.
   *
   * @param string $question
   *   Question.
   * @param array $source
   *   Data to retrieve the source documents.
   * @param int $limit
   *   Number of documents to retrieve.
   * @param \Drupal\ocha_ai_chat\Plugin\CompletionPluginInterface $completion_plugin
   *   Optional completion plugin override.
   *
   * @return array
   *   Associative array with the qestion, the answer, the source URL,
   *   the limit, the plugins, the stats and the relevant passages.
   */
  public function answer(string $question, array $source, int $limit = 10, ?CompletionPluginInterface $completion_plugin = NULL): array {
    $completion_plugin = $completion_plugin ?? $this->getCompletionPlugin();
    $embedding_plugin = $this->getEmbeddingPlugin();
    $source_plugin = $this->getSourcePlugin();
    $vector_store_plugin = $this->getVectorStorePlugin();

    // Stats to record the time of each operation.
    // @todo either store the stats elsewhere (log etc.) or remove.
    $data = [
      'completion_plugin_id' => $completion_plugin->getPluginId(),
      'embedding_plugin_id' => $embedding_plugin->getPluginId(),
      'source_plugin_id' => $source_plugin->getPluginId(),
      'source_data' => $source,
      'source_limit' => $limit,
      'source_document_ids' => [],
      'question' => $question,
      'answer' => '',
      'original_answer' => '',
      'passages' => [],
      'status' => 'error',
      'error' => '',
      'timestamp' => $this->time->getRequestTime(),
      'duration' => 0,
      'uid' => $this->currentUser->id(),
      'stats' => [
        'Get source documents' => 0,
        'Embed documents' => 0,
        'Get question embedding' => 0,
        'Get relevant passages' => 0,
        'Get answer' => 0,
      ],
    ];

    $time = microtime(TRUE);

    // Retrieve the source documents matching the document source URL.
    ['index' => $index, 'documents' => $documents] = $this->getSourceDocuments($source, $limit);
    $data['source_document_ids'] = array_keys($documents);
    $data['stats']['Get source documents'] = 0 - $time + ($time = microtime(TRUE));

    // If there are no documents to query, then no need to ask the AI.
    if (empty($documents)) {
      $data['answer'] = $this->getAnswer('no_document', 'Sorry, no source documents were found.');
      $data['error'] = 'no_document';
      return $this->logAnswerData($data);
    }

    // @todo Maybe that should be done outside of the answer pipeline or in a
    // way that can help give feedback on the progress.
    $result = $this->embedDocuments($index, $documents);
    $data['stats']['Embed documents'] = 0 - $time + ($time = microtime(TRUE));

    // Abort if we were unable to process the source documents.
    // @todo maybe still proceed if some of the document could be processed?
    if (!$result) {
      $data['answer'] = $this->getAnswer('document_embedding_error', 'Sorry, there was an error trying to retrieve the documents to the answer to your question.');
      $data['error'] = 'document_embedding_error';
      return $this->logAnswerData($data);
    }

    // Generate the embedding for the question.
    $embedding = $embedding_plugin->generateEmbedding($question, TRUE);
    $data['stats']['Get question embedding'] = 0 - $time + ($time = microtime(TRUE));

    // Abort if we were unable to generate the embedding for the question as
    // we cannot retrieve the relevant passages in that case.
    if (empty($embedding)) {
      $data['answer'] = $this->getAnswer('question_embedding_error', 'Sorry, there was an error trying to process the qestion.');
      $data['error'] = 'question_embedding_error';
      return $this->logAnswerData($data);
    }

    // Find document passages relevant to the question.
    $passages = $vector_store_plugin->getRelevantPassages($index, array_keys($documents), $question, $embedding);
    $data['stats']['Get relevant passages'] = 0 - $time + ($time = microtime(TRUE));

    // Language of the documents.
    // @todo retrieve the language of the documents. Currently we only support
    // English but the ranker, for example, supports more languages.
    $language = 'en';

    // Rerank the passages.
    $passages = $this->rerankPassages($question, $passages, $language);
    $data['stats']['Rerank passages'] = 0 - $time + ($time = microtime(TRUE));

    // If there are no passages matching the question, we inject metadata from
    // the documents. It helps for questions such as "What are those documents
    // about?".
    if (empty($passages)) {
      $data['error'] = 'no_passage';
      $passages = $this->getFallbackPassages($index, $documents);
    }
    else {
      // Generate inline references for the passages.
      foreach ($passages ?? [] as $key => $passage) {
        $source_document = $documents[$passage['source']['id']];
        $passages[$key]['reference'] = $source_plugin->generateInlineReference($source_document);
      }
    }

    $data['passages'] = $passages;

    // Generate the context to answer the question based on the relevant
    // passages.
    $context = $completion_plugin->generateContext($question, $passages);

    // @todo parse the answer and try to detect "failure" to propose
    // alternatives or instructions to clarify the question.
    $answer = trim($completion_plugin->answer($question, $context) ?? '');
    $data['stats']['Get answer'] = 0 - $time + ($time = microtime(TRUE));
    $data['original_answer'] = $answer;

    // The answer is empty for example if there was an error during the request.
    if ($answer === '') {
      $data['answer'] = $this->getAnswer('no_answer', 'Sorry, I was unable to answer your question. Please try again in a short moment.');
      $data['error'] = 'no_answer';
      return $this->logAnswerData($data);
    }
    // Validate the answer.
    elseif (!$this->validateAnswer($answer, $question, $passages, $language)) {
      $data['answer'] = $this->getAnswer('invalid_answer', 'Sorry, I was unable to answer your question.');
      $data['error'] = 'invalid_answer';
      return $this->logAnswerData($data);
    }
    else {
      $data['answer'] = $answer;
    }

    // Arrived at this point we have a valid answer so we consider the request
    // successful.
    $data['status'] = 'success';

    return $this->logAnswerData($data);
  }

  /**
   * Get a predetermined answer.
   *
   * @param string $key
   *   The answer key in the config.
   * @param string $default
   *   The default answer if none was found in the settings.
   *
   * @return string
   *   The answer.
   */
  public function getAnswer(string $key, string $default): string {
    return $this->getSetting(['form', 'answers', $key], $default, FALSE);
  }

  /**
   * Get the fallback passages for the documents.
   *
   * @param stirng $index
   *   The vector store index.
   * @param array $documents
   *   Documents.
   *
   * @return array
   *   Passages generated from the document descriptions.
   */
  public function getFallbackPassages(string $index, array $documents): array {
    // Retrieve the descriptions of the documents.
    $data = $this->getVectorStorePlugin()->getDocuments($index, array_keys($documents), [
      'id',
      'description',
    ]);

    foreach ($documents as $id => $document) {
      if (isset($data[$id]['description'])) {
        $documents[$id]['description'] = $data[$id]['description'];
      }
    }

    return $this->getSourcePlugin()->describeDocuments($documents);
  }

  /**
   * Validate the answer against the context to ensure validity.
   *
   * @param string $answer
   *   The answer.
   * @param string $question
   *   The question.
   * @param array $passages
   *   The text passages used as context for the answer.
   * @param string $language
   *   Language of the passages.
   *
   * @return bool
   *   TRUE if the answer seems valid.
   */
  public function validateAnswer(string $answer, string $question, array $passages, string $language): bool {
    $answer_validator_plugin = $this->getAnswerValidatorPlugin();
    if (empty($answer_validator_plugin)) {
      return TRUE;
    }

    return $answer_validator_plugin->validate($answer, $question, $passages, $language, [
      'completion' => $this->getCompletionPlugin(),
      'embedding' => $this->getEmbeddingPlugin(),
      'ranker' => $this->getRankerPlugin(),
      'vector_store' => $this->getVectorStorePlugin(),
    ]);
  }

  /**
   * Log the answer data.
   *
   * @param array $data
   *   Answer data.
   *
   * @return array
   *   Answer data
   *
   * @see ::answer()
   */
  protected function logAnswerData(array $data): array {
    // Remove the embedding of the passages as they are not really useful
    // to have in the result or logs.
    // Also remove unnecessary source information.
    foreach ($data['passages'] as $index => $passage) {
      unset($data['passages'][$index]['embedding']);
      unset($data['passages'][$index]['source']['contents']);
      unset($data['passages'][$index]['source']['description']);
      unset($data['passages'][$index]['source']['raw']);
    }

    // Set the duration.
    $data['duration'] = $this->time->getCurrentTime() - $data['timestamp'];

    // Encode non scalar data like passages and stats.
    $fields = array_map(function ($item) {
      return is_scalar($item) ? $item : json_encode($item);
    }, $data);

    // Insert the record and retrieve the log ID. It can be used for example
    // to set the "satisfaction score" afterwards.
    $data['id'] = $this->database
      ->insert('ocha_ai_chat_logs')
      ->fields($fields)
      ->execute();

    // Log the entry as well.
    $this->logger->info(json_encode($data));

    return $data;
  }

  /**
   * Add feedback to an answer.
   *
   * @param int $id
   *   The ID of the answer log.
   * @param int $satisfaction
   *   A satisfaction score from 0 to 5.
   * @param string $feedback
   *   Feedback comment.
   *
   * @return bool
   *   TRUE if a record was updated.
   */
  public function addAnswerFeedback(int $id, int $satisfaction, string $feedback): bool {
    $updated = $this->database
      ->update('ocha_ai_chat_logs')
      ->fields([
        'satisfaction' => $satisfaction,
        'feedback' => $feedback,
      ])
      ->condition('id', $id, '=')
      ->execute();

    return !empty($updated);
  }

  /**
   * Add thumbs up/down to an answer's log entry.
   *
   * @param int $id
   *   The ID of the answer log.
   * @param string $value
   *   Up or down.
   *
   * @return bool
   *   TRUE if a record was updated.
   */
  public function addAnswerThumbs(int $id, string $value): bool {
    $updated = $this->database
      ->update('ocha_ai_chat_logs')
      ->fields([
        'thumbs' => $value,
      ])
      ->condition('id', $id, '=')
      ->execute();

    return !empty($updated);
  }

  /**
   * Get thumbs up/down from an answer's log entry.
   *
   * @param int $id
   *   The ID of the answer log.
   *
   * @return string
   *   Blank, up or down.
   */
  public function getAnswerThumbs(int $id): string {
    $value = $this->database
      ->select('ocha_ai_chat_logs')
      ->fields('ocha_ai_chat_logs', [
        'thumbs',
      ])
      ->condition('id', $id, '=')
      ->execute()
      ->fetchField();

    return $value ?? '';
  }

  /**
   * Record that a copy-to-clipboard button was used.
   *
   * @param int $id
   *   The ID of the answer log.
   * @param string $value
   *   Copied or not.
   *
   * @return bool
   *   TRUE if a record was updated.
   */
  public function addAnswerCopy(int $id, string $value): bool {
    $updated = $this->database
      ->update('ocha_ai_chat_logs')
      ->fields([
        'copied' => $value,
      ])
      ->condition('id', $id, '=')
      ->execute();

    return !empty($updated);
  }

  /**
   * Rerank passages against the question.
   *
   * @param string $question
   *   The user question.
   * @param array $passages
   *   Relevant passages retrieved from the document.
   * @param string $language
   *   Language of the document.
   * @param ?int $limit
   *   Optional limit override.
   *
   * @return array
   *   Reranked passages.
   */
  protected function rerankPassages(string $question, array $passages, string $language, ?int $limit = NULL): array {
    $limit ??= $this->getSetting(['plugins', 'ranker', 'limit'], count($passages), FALSE);

    $ranker_plugin = $this->getRankerPlugin();
    if (empty($ranker_plugin)) {
      return array_slice($passages, 0, $limit);
    }

    $unranked_passages = [];
    foreach ($passages as $passage) {
      if (!isset($unranked_passages[$passage['text']])) {
        $unranked_passages[$passage['text']] = $passage;
      }
    }

    $texts = array_keys($unranked_passages);
    $ranked_texts = $ranker_plugin->rankTexts($question, $texts, $language, $limit);

    $ranked_passages = array_intersect_key($unranked_passages, $ranked_texts);
    return $ranked_passages;
  }

  /**
   * Get a list of source documents for the given document source URL.
   *
   * @param array $source
   *   Data to retrieve the source documents.
   * @param int $limit
   *   Number of documents to retrieve.
   *
   * @return array
   *   Associative array with the index corresponding to the type of
   *   documents and the list of source documents for the source URL.
   *
   * @todo we should store which plugins were used to generate the embeddings
   *   so that they can be regenerated if the plugins change.
   */
  protected function getSourceDocuments(array $source, int $limit): array {
    $plugin = $this->getSourcePlugin();

    $documents = $plugin->getDocuments($source, $limit);

    // @todo allow multiple indices.
    $resource = key($documents);
    $documents = $documents[$resource] ?? [];

    if (empty($documents)) {
      $this->logger->notice(strtr('No documents found for the source: @source', [
        '@source' => strtr(print_r($source, TRUE), "\n", ' '),
      ]));
    }

    // For the similarity search, we cannot compare vectors generated by an
    // embedding model with a vector from another embedding model even if they
    // have the same dimensions so we ensure that doesn't happen by prefixing
    // the index used to store the vectors with the embedding plugin id and the
    // model ID and dimensions.
    //
    // @todo review if we really need the source ID as well. If the index
    // structure is the same between documents regardless of the source then
    // we could remove it.
    //
    // @todo ensure it's below 255 characters (ex: generate a hash?)
    //
    // @todo this is not good because the text splitter, text extractor plugins
    // impact the generation of the embeddings. So we need to generate an index
    // name with all the plugin IDs or the info about the plugins in the index
    // so that we can regenerate the embeddings when the plugins change.
    $index = implode('__', [
      $this->getVectorStorePlugin()->getBaseIndexName(),
      $this->getEmbeddingPlugin()->getPluginId(),
      $this->getEmbeddingPlugin()->getModelName(),
      $this->getEmbeddingPlugin()->getDimensions(),
      $plugin->getPluginId(),
      $resource,
    ]);

    $index = preg_replace('/[^a-z0-9_-]+/', '_', strtolower($index));

    return [
      'index' => $index,
      'documents' => $documents,
    ];
  }

  /**
   * Embed the documents retrieved from a ReliefWeb river URL.
   *
   * 1. Call RW API client to retrieve API data from RW river URL.
   * 2. Extract the text from the body + attachments.
   * 3. Split the text into passages.
   * 4. Generate embeddings for the text passages.
   * 5. Store the data in the vector database.
   *
   * @param string $index
   *   Index name.
   * @param array|null $documents
   *   Optional documents to embed. If not defined it uses the given river URL.
   *
   * @return bool
   *   TRUE if the embedding succeeded.
   *
   * @todo we may want to use a queue and do that asynchronously at some point.
   */
  public function embedDocuments(string $index, array $documents): bool {
    $vector_store_plugin = $this->getVectorStorePlugin();
    $dimensions = $this->getEmbeddingPlugin()->getDimensions();

    // Retrieve indexed documents.
    $existing = $vector_store_plugin->getDocuments($index, array_keys($documents), [
      'id',
      'date.changed',
    ]);

    // Process and index new or updated documents.
    foreach ($documents as $id => $document) {
      // Skip if the document already exists and has not changed.
      if (isset($existing[$id]) && $existing[$id]['date']['changed'] === $document['date']['changed']) {
        continue;
      }

      // Process the document contents.
      try {
        $document = $this->processDocument($document);
      }
      catch (\Exception $exception) {
        $this->logger->error(strtr('Unable to process document @url', [
          '@url' => $document['url'] ?? $id,
        ]));
        // @todo instead of aborting when there is an error indexing a document
        // log the error.
        return FALSE;
      }

      // Index the document.
      if (!$vector_store_plugin->indexDocument($index, $document, $dimensions)) {
        // @todo instead of aborting when there is an error indexing a document
        // log the error.
        return FALSE;
      }
    }

    return TRUE;
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
    if (isset($document['description']['text'])) {
      if (!isset($document['description']['embedding'])) {
        $document['description']['embedding'] = $this->getEmbeddingPlugin()->generateEmbedding($document['description']['text']);
      }
    }

    foreach ($document['contents'] as $key => $content) {
      switch ($content['type']) {
        case 'markdown':
          $content['pages'] = $this->processMarkdown($content['content']);
          break;

        case 'file':
          $content['pages'] = $this->processFile($content['url'], $content['mimetype']);
          break;
      }

      // Remove empty pages.
      $content['pages'] = array_filter($content['pages'] ?? [], function ($page) {
        return !empty($page['passages']);
      });

      // Generate the embedding for the content based on the mean of its page
      // embeddings. This is cheaper than calling the embedding API though we
      // lose in accuracy.
      if (!empty($content['pages'])) {
        $content['pages'] = array_values($content['pages']);
        $content['embedding'] = $this->getMeanEmbedding($content['pages']);
      }

      $document['contents'][$key] = $content;
    }

    return $document;
  }

  /**
   * Get the mean of embeddings.
   *
   * @param array $elements
   *   List of associative arrays where earch array has an "embedding" property.
   *
   * @return array
   *   The mean of the embeddings.
   */
  protected function getMeanEmbedding(array $elements): array {
    $embeddings = [];
    foreach ($elements as $element) {
      if (!empty($element['embedding'])) {
        $embeddings[] = $element['embedding'];
      }
    }
    return !empty($embeddings) ? VectorHelper::mean($embeddings, axis: 'y') : [];
  }

  /**
   * Process a markdown text to make it easier to split.
   *
   * @param string $text
   *   Markdown text.
   *
   * @return array
   *   List of pages with their page number and list of passages. Each passage
   *   has a text and corresponding embedding.
   */
  protected function processMarkdown(string $text): array {
    $replacements = [
      // Headings.
      '/^#{1,6}\s*(.+?)\s*#*\s*$/um' => "$1\n\n",
      // Headings or horizontal lines or code blocks.
      '/^[=*`-]{2,}$/um' => "\n",
    ];

    $text = trim(preg_replace(array_keys($replacements), $replacements, $text));

    return [$this->processPage($text)];
  }

  /**
   * Process a file, returning a list of its pages with extracted passages.
   *
   * @param string $uri
   *   File URI.
   * @param string $mimetype
   *   MIME type of the file.
   *
   * @return array
   *   List of pages with their page number and list of passages. Each passage
   *   has a text and corresponding embedding.
   */
  protected function processFile(string $uri, string $mimetype): array {
    if (!$this->isSupportedFileType($mimetype)) {
      return [];
    }

    // Record time to execute the different steps.
    // @todo either store the stats elsewhere (log etc.) or remove.
    $stats = [
      'uri' => $uri,
      'mimetype' => $mimetype,
      'download' => 0,
      'extraction' => 0,
      'processing' => 0,
    ];

    $time = microtime(TRUE);

    try {
      $file = $this->getSourcePlugin()->downloadFile($uri);
      $stats['download'] = 0 - $time + ($time = microtime(TRUE));

      // Extract the content of each page.
      $path = realpath(stream_get_meta_data($file)['uri']);
      $page_texts = $this->getTextExtractorPlugin($mimetype)->getPageTexts($path);
      $stats['extraction'] = 0 - $time + ($time = microtime(TRUE));

      // Process each page.
      if (!empty($page_texts)) {
        $pages = [];
        foreach ($page_texts as $page_number => $page_text) {
          $pages[] = $this->processPage($page_text, $page_number);
        }
        $stats['processing'] = 0 - $time + ($time = microtime(TRUE));

        return $pages;
      }
    }
    finally {
      // Ensure we close the temporary file so it can be deleted.
      if (!empty($file) && is_resource($file)) {
        fclose($file);
      }

      // @todo better logging.
      $this->logger->info(strtr(print_r($stats, TRUE), "\n", ' '));
    }

    return [];
  }

  /**
   * Process the text of a document page, extracting passages.
   *
   * @param string $content
   *   Page content.
   * @param int $page
   *   Page number (0 if irrelevant).
   *
   * @return array
   *   Associative array with the page number and passages. Each passage
   *   has a text and corresponding embedding.
   */
  protected function processPage(string $content, int $page = 0): array {
    // @todo clean the page.
    $content = trim($content);
    if (empty($content)) {
      return [];
    }

    // Most models are English only and don't work well with other languages.
    // Also the text splitter plugins don't work well with languages like
    // Arabic or Chinese.
    $language = (new \Text_LanguageDetect())->detectSimple($content);
    // @todo retrieve that from the config.
    $allowed_languages = ['english'];
    if (!in_array($language, $allowed_languages)) {
      return [];
    }

    // Retrieve the text splitter setting overrides for the chat.
    $length = $this->getSetting(['plugins', 'text_splitter', 'length'], NULL, FALSE);
    $overlap = $this->getSetting(['plugins', 'text_splitter', 'overlap'], NULL, FALSE);

    // Ensure we don't have an invalid value and that we can use the default
    // settings for the plugin.
    $length = $length === '' ? NULL : $length;
    $overlap = $overlap === '' ? NULL : $overlap;

    // Split the content into passages.
    $texts = $this->getTextSplitterPlugin()->splitText($content, $length, $overlap);

    // Generate an embedding for each passage.
    $embeddings = $this->getEmbeddingPlugin()->generateEmbeddings($texts);

    $passages = [];
    foreach ($texts as $index => $text) {
      if (!empty($embeddings[$index])) {
        $passages[] = [
          'text' => $text,
          'embedding' => $embeddings[$index],
        ];
      }
    }

    if (empty($passages)) {
      return [];
    }

    // Get the embedding for the page from the mean of the passage embeddings.
    // With some overlap for the passages, this embedding is quite close to
    // the embedding we could get by calling the API. This is cheaper and
    // faster so it's a good compromise.
    // Also some embedding models like the cohere ones have really limited
    // allowed tokens and cannot process an entire page.
    $embedding = $this->getMeanEmbedding($passages);

    return [
      'page' => $page,
      'text' => $content,
      'embedding' => $embedding,
      'passages' => $passages,
    ];
  }

  /**
   * Check if a file mimetype is supported.
   *
   * @param string $mimetype
   *   MIME type of the file.
   *
   * @return bool
   *   TRUE if the file type is supported.
   */
  protected function isSupportedFileType(string $mimetype): bool {
    $plugin_id = $this->getSetting([
      'plugins',
      'text_extractor',
      $mimetype,
      'plugin_id',
    ]);
    return isset($plugin_id);
  }

  /**
   * Get the answer validator plugin manager.
   *
   * @return \Drupal\ocha_ai\Plugin\AnswerValidatorPluginManagerInterface
   *   Completion plugin manager.
   */
  public function getAnswerValidatorPluginManager(): AnswerValidatorPluginManagerInterface {
    return $this->answerValidatorPluginManager;
  }

  /**
   * Get the completion plugin manager.
   *
   * @return \Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface
   *   Completion plugin manager.
   */
  public function getCompletionPluginManager(): CompletionPluginManagerInterface {
    return $this->completionPluginManager;
  }

  /**
   * Get the embedding plugin manager.
   *
   * @return \Drupal\ocha_ai\Plugin\EmbeddingPluginManagerInterface
   *   Embedding plugin.
   */
  public function getEmbeddingPluginManager(): EmbeddingPluginManagerInterface {
    return $this->embeddingPluginManager;
  }

  /**
   * Get the ranker plugin manager.
   *
   * @return \Drupal\ocha_ai\Plugin\RankerPluginManagerInterface
   *   Ranker plugin.
   */
  public function getRankerPluginManager(): RankerPluginManagerInterface {
    return $this->rankerPluginManager;
  }

  /**
   * Get the source plugin manager.
   *
   * @return \Drupal\ocha_ai\Plugin\SourcePluginManagerInterface
   *   Source plugin.
   */
  public function getSourcePluginManager(): SourcePluginManagerInterface {
    return $this->sourcePluginManager;
  }

  /**
   * Get the text extractor plugin manager.
   *
   * @return \Drupal\ocha_ai\Plugin\TextExtractorPluginManagerInterface
   *   Text extractor plugin.
   */
  public function getTextExtractorPluginManager(): TextExtractorPluginManagerInterface {
    return $this->textExtractorPluginManager;
  }

  /**
   * Get the text splitter plugin manager.
   *
   * @return \Drupal\ocha_ai\Plugin\TextSplitterPluginManagerInterface
   *   Text splitter plugin.
   */
  public function getTextSplitterPluginManager(): TextSplitterPluginManagerInterface {
    return $this->textSplitterPluginManager;
  }

  /**
   * Get the vector store plugin manager.
   *
   * @return \Drupal\ocha_ai\Plugin\VectorStorePluginManagerInterface
   *   Vector store plugin manager.
   */
  public function getVectorStorePluginManager(): VectorStorePluginManagerInterface {
    return $this->vectorStorePluginManager;
  }

  /**
   * Get the answe validator plugin.
   *
   * @return ?\Drupal\ocha_ai\Plugin\AnswerValidatorPluginInterface
   *   Answer validator plugin.
   */
  public function getAnswerValidatorPlugin(): ?AnswerValidatorPluginInterface {
    $plugin_id = $this->getSetting(['plugins', 'answer_validator', 'plugin_id']);
    return !empty($plugin_id) ? $this->getAnswerValidatorPluginManager()->getPlugin($plugin_id) : NULL;
  }

  /**
   * Get the completion plugin.
   *
   * @return ?\Drupal\ocha_ai\Plugin\CompletionPluginInterface
   *   Completion plugin.
   */
  public function getCompletionPlugin(): ?CompletionPluginInterface {
    $plugin_id = $this->getSetting(['plugins', 'completion', 'plugin_id']);
    return !empty($plugin_id) ? $this->getCompletionPluginManager()->getPlugin($plugin_id) : NULL;
  }

  /**
   * Get the embedding plugin.
   *
   * @return ?\Drupal\ocha_ai\Plugin\EmbeddingPluginInterface
   *   Embedding plugin.
   */
  public function getEmbeddingPlugin(): ?EmbeddingPluginInterface {
    $plugin_id = $this->getSetting(['plugins', 'embedding', 'plugin_id']);
    return !empty($plugin_id) ? $this->getEmbeddingPluginManager()->getPlugin($plugin_id) : NULL;
  }

  /**
   * Get the ranker plugin.
   *
   * @return ?\Drupal\ocha_ai\Plugin\RankerPluginInterface
   *   Ranker plugin.
   */
  public function getRankerPlugin(): ?RankerPluginInterface {
    $plugin_id = $this->getSetting(['plugins', 'ranker', 'plugin_id']);
    return !empty($plugin_id) ? $this->getRankerPluginManager()->getPlugin($plugin_id) : NULL;
  }

  /**
   * Get the source plugin.
   *
   * @return ?\Drupal\ocha_ai\Plugin\SourcePluginInterface
   *   Source plugin.
   */
  public function getSourcePlugin(): ?SourcePluginInterface {
    $plugin_id = $this->getSetting(['plugins', 'source', 'plugin_id']);
    return !empty($plugin_id) ? $this->getSourcePluginManager()->getPlugin($plugin_id) : NULL;
  }

  /**
   * Get the text extractor plugin for the given file mimetype.
   *
   * @return ?\Drupal\ocha_ai\Plugin\TextExtractorPluginInterface
   *   Text extractor plugin.
   */
  public function getTextExtractorPlugin(string $mimetype): ?TextExtractorPluginInterface {
    $plugin_id = $this->getSetting([
      'plugins',
      'text_extractor',
      $mimetype,
      'plugin_id',
    ]);
    return !empty($plugin_id) ? $this->getTextExtractorPluginManager()->getPlugin($plugin_id) : NULL;
  }

  /**
   * Get the text splitter plugin.
   *
   * @return ?\Drupal\ocha_ai\Plugin\TextSplitterPluginInterface
   *   Text splitter plugin.
   */
  public function getTextSplitterPlugin(): ?TextSplitterPluginInterface {
    $plugin_id = $this->getSetting(['plugins', 'text_splitter', 'plugin_id']);
    return !empty($plugin_id) ? $this->getTextSplitterPluginManager()->getPlugin($plugin_id) : NULL;
  }

  /**
   * Get the vector store plugin.
   *
   * @return ?\Drupal\ocha_ai\Plugin\VectorStorePluginInterface
   *   Vector store plugin.
   */
  public function getVectorStorePlugin(): ?VectorStorePluginInterface {
    $plugin_id = $this->getSetting(['plugins', 'vector_store', 'plugin_id']);
    return !empty($plugin_id) ? $this->getVectorStorePluginManager()->getPlugin($plugin_id) : NULL;
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

      $state_defaults = $this->state->get('ocha_ai_chat.default_settings', []);

      $this->settings = array_replace_recursive($config_defaults, $state_defaults);
    }
    return $this->settings;
  }

  /**
   * Get a setting for the OCHA AI Chat.
   *
   * @param array $keys
   *   Setting keys.
   * @param mixed $default
   *   Default.
   * @param bool $throw_if_null
   *   If TRUE and both the setting and default are NULL then an exception
   *   is thrown. Use this for example for mandatory settings.
   *
   * @return mixed
   *   The setting value for the keys or the provided default.
   *
   * @throws \Exception
   *   Throws an exception if no setting could be found (= NULL).
   */
  protected function getSetting(array $keys, mixed $default = NULL, bool $throw_if_null = TRUE): mixed {
    $settings = $this->getSettings();
    $setting = NestedArray::getValue($settings, $keys) ?? $default;
    if (is_null($setting) && $throw_if_null) {
      throw new \Exception(strtr('Missing setting @keys', [
        '@keys' => implode('.', $keys),
      ]));
    }
    return $setting;
  }

}

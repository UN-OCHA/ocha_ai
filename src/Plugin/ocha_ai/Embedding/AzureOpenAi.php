<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\Embedding;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiEmbedding;
use Drupal\ocha_ai\Helpers\TextHelper;
use Drupal\ocha_ai\Plugin\EmbeddingPluginBase;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use OpenAI\Client;
use Psr\Http\Message\ResponseInterface;

/**
 * Azure OpenAI embedding generator.
 */
#[OchaAiEmbedding(
  id: 'azure_openai',
  label: new TranslatableMarkup('Azure OpenAI'),
  description: new TranslatableMarkup('Use Azure OpenAI as embedding generator.')
)]
class AzureOpenAi extends EmbeddingPluginBase {

  /**
   * Azure OpenAI API client.
   *
   * @var \OpenAI\Client
   */
  protected Client $apiClient;

  /**
   * {@inheritdoc}
   */
  public function generateEmbeddings(array $texts, bool $query = FALSE): array {
    if (empty($texts)) {
      return [];
    }

    // Maximum number of embeddings to request at once.
    $batch_size = $this->getPluginSetting('batch_size');
    // Maximum number of input tokens accepted by the model (with a margin).
    $max_tokens = $this->getPluginSetting('max_tokens') - 30;

    // We batch the generation by passing several texts at once as long as their
    // size doesn't exceed the max number of input tokens.
    $accumulator = [];
    $embeddings = [];
    foreach ($texts as $index => $text) {
      $token_count = TextHelper::estimateTokenCount($text);
      if (
        count($accumulator) < $batch_size &&
        array_sum($accumulator) + $token_count < $max_tokens
      ) {
        $accumulator[$index] = $token_count;
      }
      else {
        $batch = array_values(array_intersect_key($texts, $accumulator));
        $embeddings = array_merge($embeddings, $this->requestEmbeddings($batch));
        $accumulator = [$index => $token_count];
      }
    }

    // Process the leftover from the loop if any.
    if (!empty($accumulator)) {
      $batch = array_values(array_intersect_key($texts, $accumulator));
      $embeddings = array_merge($embeddings, $this->requestEmbeddings($batch));
    }

    return $embeddings;
  }

  /**
   * {@inheritdoc}
   */
  public function generateEmbedding(string $text, bool $query = FALSE): array {
    if (empty($text)) {
      return [];
    }

    try {
      $embedding = $this->requestEmbeddings([$text])[0] ?? [];
    }
    catch (\Exception $exception) {
      return [];
    }

    return $embedding;
  }

  /**
   * Perform a request against the API to get the embeddings for the texts.
   *
   * @param array $texts
   *   List of texts.
   *
   * @return array
   *   List of embeddings for the texts.
   */
  protected function requestEmbeddings(array $texts): array {
    if (empty($texts)) {
      return [];
    }

    $payload = [
      'input' => $texts,
      'model' => $this->getPluginSetting('model'),
    ];

    try {
      /** @var \OpenAI\Responses\Embeddings\CreateResponse $response */
      $response = $this->getApiClient()->embeddings()->create($payload);
    }
    catch (\Exception $exception) {
      $this->getLogger()->error(strtr('Embedding request failed with error: @error.', [
        '@error' => $exception->getMessage(),
      ]));
      throw $exception;
    }

    try {
      $data = $response->toArray();
    }
    catch (\Exception $exception) {
      $this->getLogger()->error(strtr('Unable to retrieve embedding result data: @error.', [
        '@error' => $exception->getMessage(),
      ]));
      throw $exception;
    }

    return array_map(function ($item) {
      return $item['embedding'] ?? [];
    }, $data['data'] ?? array_fill(0, count($texts), []));
  }

  /**
   * Get the Azure OpenAI API client.
   *
   * @return \OpenAI\Client
   *   API Client.
   */
  protected function getApiClient(): Client {
    if (!isset($this->apiClient)) {
      // @todo throw an error if this is not defined.
      $api_key = $this->getPluginSetting('api_key');
      $endpoint = $this->getPluginSetting('endpoint');
      $version = $this->getPluginSetting('version');
      $model = $this->getPluginSetting('model');

      // Workaround for https://github.com/openai-php/client/issues/218.
      $stack = HandlerStack::create();
      $stack->push(
        Middleware::mapResponse(function (ResponseInterface $response) use ($model) {
          if (!$response->hasHeader('openai-model')) {
            $response = $response->withHeader('openai-model', $model);
          }
          if (!$response->hasHeader('openai-processing-ms')) {
            $response = $response->withHeader('openai-processing-ms', '1');
          }
          if (!$response->hasHeader('x-request-id')) {
            $response = $response->withHeader('x-request-id', 'abc');
          }
          return $response;
        })
      );
      $guzzle = new GuzzleClient(['handler' => $stack]);

      $this->apiClient = \OpenAI::factory()
        ->withBaseUri($endpoint)
        ->withHttpHeader('api-key', $api_key)
        ->withQueryParam('api-version', $version)
        ->withHttpClient($guzzle)
        ->make();
    }
    return $this->apiClient;
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    return [
      'text-embedding-ada-002' => $this->t('ADA 2'),
    ];
  }

}

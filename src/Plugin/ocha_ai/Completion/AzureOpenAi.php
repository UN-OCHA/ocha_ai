<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\Completion;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiCompletion;
use Drupal\ocha_ai\Plugin\CompletionPluginBase;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use OpenAI\Client;
use Psr\Http\Message\ResponseInterface;

/**
 * Azure OpenAI completion generator.
 */
#[OchaAiCompletion(
  id: 'azure_openai',
  label: new TranslatableMarkup('Azure OpenAI'),
  description: new TranslatableMarkup('Use Azure OpenAI as completion generator.')
)]
class AzureOpenAi extends CompletionPluginBase {

  /**
   * Azure OpenAI API client.
   *
   * @var \OpenAI\Client
   */
  protected Client $apiClient;

  /**
   * {@inheritdoc}
   */
  public function answer(string $question, string $context): string {
    if (empty($question) || empty($context)) {
      return '';
    }

    $prompt = $this->generatePrompt($question, $context);
    if (empty($prompt)) {
      return '';
    }

    $messages = [
      [
        'role' => 'system',
        'content' => $prompt,
      ],
      [
        'role' => 'user',
        'content' => $question,
      ],
    ];

    $payload = [
      'model' => $this->getPluginSetting('model'),
      'messages' => $messages,
      'temperature' => 0.0,
      'top_p' => 0.9,
      'max_tokens' => $this->getPluginSetting('max_tokens', 512),
    ];

    try {
      /** @var \OpenAI\Responses\Chat\CreateResponse $response */
      $response = $this->getApiClient()->chat()->create($payload);
    }
    catch (\Exception $exception) {
      $this->getLogger()->error(strtr('Completion request failed with: @error.', [
        '@error' => $exception->getMessage(),
      ]));
      return '';
    }

    try {
      $data = $response->toArray();
    }
    catch (\Exception $exception) {
      $this->getLogger()->error(strtr('Unable to retrieve completion result data: @error.', [
        '@error' => $exception->getMessage(),
      ]));
      return '';
    }

    return trim($data['choices'][0]['message']['content'] ?? '');
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
      'gpt-3.5-turbo' => $this->t('GPT 3.5 turbo'),
    ];
  }

}

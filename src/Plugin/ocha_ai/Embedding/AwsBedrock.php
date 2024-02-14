<?php

namespace Drupal\ocha_ai\Plugin\ocha_ai\Embedding;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Sts\StsClient;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_ai\Plugin\EmbeddingPluginBase;

/**
 * AWS embedding generator.
 *
 * @OchaAiChatEmbedding(
 *   id = "aws_bedrock",
 *   label = @Translation("AWS Bedrock"),
 *   description = @Translation("Use AWS Bedrock as embedding generator."),
 * )
 */
class AwsBedrock extends EmbeddingPluginBase {

  /**
   * AWS Bedrock API client.
   *
   * @var \Aws\BedrockRuntime\BedrockRuntimeClient
   */
  protected BedrockRuntimeClient $apiClient;

  /**
   * {@inheritdoc}
   */
  public function generateEmbeddings(array $texts, bool $query = FALSE): array {
    if (empty($texts)) {
      return [];
    }

    // @todo the AWS Bedrock Titan embedding model doesn't support generating
    // embeddings for several texts at once so we need to call the API for each
    // request. Investigate using parallel requests (check quota, rate limits
    // etc.).
    //
    // @todo the Cohere models support several texts up to 2048 tokens which is
    // lower than the Titan model context. We could propably generate embeddings
    // for 3/4 texts at conce. Review.
    $embeddings = [];
    foreach ($texts as $text) {
      $embeddings[] = $this->requestEmbedding($text);
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
      $embedding = $this->requestEmbedding($text);
    }
    catch (\Exception $exception) {
      return [];
    }

    return $embedding;
  }

  /**
   * Perform a request against the API to get the embeddings for the text.
   *
   * @param string $text
   *   Text for which to generate the embedding.
   * @param bool $query
   *   Whether the request is for embedding for a search query or document.
   *
   * @return array
   *   Embedding for the text.
   *
   * @throws \Exception
   *   Throw an exception if the generation of the embeddding fails.
   */
  protected function requestEmbedding(string $text, bool $query = FALSE): array {
    if (empty($text)) {
      return [];
    }

    $payload = [
      'accept' => 'application/json',
      'body' => json_encode($this->generateRequestBody($text, $query)),
      'contentType' => 'application/json',
      'modelId' => $this->getPluginSetting('model'),
    ];

    try {
      /** @var \Aws\Result $response */
      $response = $this->getApiClient()->invokeModel($payload);
    }
    catch (\Exception $exception) {
      $this->getLogger()->error(strtr('Embedding request failed with error: @error.', [
        '@error' => $exception->getMessage(),
      ]));
      throw $exception;
    }

    try {
      $data = json_decode($response->get('body')->getContents(), TRUE);
    }
    catch (\Exception $exception) {
      $this->getLogger()->error('Unable to decode embedding response.');
      throw $exception;
    }

    return $this->parseResponseBody($data);
  }

  /**
   * Generate the request body for the embedding.
   *
   * @param string $text
   *   Text for which to generate the embedding.
   * @param bool $query
   *   Whether the request is for embedding for a search query or document.
   *
   * @return array
   *   Request body.
   */
  protected function generateRequestBody(string $text, bool $query = FALSE): array {
    switch ($this->getPluginSetting('model')) {
      case 'amazon.titan-embed-text-v1':
        return [
          'inputText' => $text,
        ];

      case 'cohere.embed-english-v3':
      case 'cohere.embed-multilingual-v3':
        return [
          'texts' => [$text],
          'input_type' => $query ? 'search_query' : 'search_document',
          'truncate' => 'NONE',
        ];
    }

    return [];
  }

  /**
   * Parse the reponse from the embedding API.
   *
   * @param array $data
   *   Decoded response.
   *
   * @return array
   *   Embedding.
   */
  protected function parseResponseBody(array $data): array {
    switch ($this->getPluginSetting('model')) {
      case 'amazon.titan-embed-text-v1':
        return $data['embedding'];

      case 'cohere.embed-english-v3':
      case 'cohere.embed-multilingual-v3':
        return reset($data['embeddings']);
    }

    return [];
  }

  /**
   * Get the Bedrock API Client.
   *
   * @return \Aws\BedrockRuntime\BedrockRuntimeClient
   *   API Client.
   */
  protected function getApiClient(): BedrockRuntimeClient {
    if (!isset($this->apiClient)) {
      $region = $this->getPluginSetting('region');
      $role_arn = $this->getPluginSetting('role_arn', NULL, FALSE);

      if (!empty($role_arn)) {
        $stsClient = new StsClient([
          'region' => $region,
          'version' => 'latest',
        ]);

        $result = $stsClient->AssumeRole([
          'RoleArn' => $role_arn,
          'RoleSessionName' => 'aws-bedrock-ocha-ai-chat',
        ]);

        $credentials = [
          'key'    => $result['Credentials']['AccessKeyId'],
          'secret' => $result['Credentials']['SecretAccessKey'],
          'token'  => $result['Credentials']['SessionToken'],
        ];
      }
      else {
        $credentials = [
          'key' => $this->getPluginSetting('api_key'),
          'secret' => $this->getPluginSetting('api_secret'),
        ];
      }

      $options = [
        'credentials' => $credentials,
        'region'  => $region,
      ];

      $endpoint = $this->getPluginSetting('endpoint', NULL, FALSE);
      if (!empty($endpoint)) {
        $options['endpoint'] = $endpoint;
      }

      $this->apiClient = new BedrockRuntimeClient($options);
    }
    return $this->apiClient;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $plugin_type = $this->getPluginType();
    $plugin_id = $this->getPluginId();
    $config = $this->getConfiguration() + $this->defaultConfiguration();

    // Empty endpoint is allowed in which case the SDK will generate it.
    $form['plugins'][$plugin_type][$plugin_id]['endpoint']['#required'] = FALSE;
    $form['plugins'][$plugin_type][$plugin_id]['endpoint']['#description'] = $this->t('Endpoint of the API. Leave empty to use the official one.');

    // Remove the requirement for the API key as it's possible to acces the API
    // via the Role ARN.
    $form['plugins'][$plugin_type][$plugin_id]['api_key']['#required'] = FALSE;

    // API secret. Not mandatory for the same reason as the API key.
    $form['plugins'][$plugin_type][$plugin_id]['api_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API secret'),
      '#description' => $this->t('Optional secret to access the API.'),
      '#default_value' => $config['api_secret'] ?? NULL,
    ];

    // Role ARN to access the API as an alternative to the API key.
    $form['plugins'][$plugin_type][$plugin_id]['role_arn'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Role ARN'),
      '#description' => $this->t('Role ARN to access the API.'),
      '#default_value' => $config['role_arn'] ?? NULL,
    ];

    // Move those fields lower in the form.
    $form['plugins'][$plugin_type][$plugin_id]['batch_size']['#weight'] = 2;
    $form['plugins'][$plugin_type][$plugin_id]['dimensions']['#weight'] = 3;
    $form['plugins'][$plugin_type][$plugin_id]['max_tokens']['#weight'] = 4;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    return [
      'amazon.titan-embed-text-v1' => $this->t('Amazon - Titan'),
      'cohere.embed-english-v3' => $this->t('Cohere - English'),
      'cohere.embed-multilingual-v3' => $this->t('Cohere - Multilingual'),
    ];
  }

}

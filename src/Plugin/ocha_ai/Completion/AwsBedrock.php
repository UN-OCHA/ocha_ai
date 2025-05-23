<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\Completion;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Sts\StsClient;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiCompletion;
use Drupal\ocha_ai\Plugin\CompletionPluginBase;

/**
 * AWS Bedrock completion generator.
 */
#[OchaAiCompletion(
  id: 'aws_bedrock',
  label: new TranslatableMarkup('AWS Bedrock'),
  description: new TranslatableMarkup('Use AWS Bedrock as completion generator.')
)]
class AwsBedrock extends CompletionPluginBase {

  /**
   * AWS Bedrock API client.
   *
   * @var \Aws\BedrockRuntime\BedrockRuntimeClient
   */
  protected BedrockRuntimeClient $apiClient;

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

    return $this->query($prompt, raw: FALSE) ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function query(string $prompt, string $system_prompt = '', array $parameters = [], bool $raw = TRUE, array $files = []): ?string {
    if (empty($prompt)) {
      return '';
    }

    $payload = [
      'accept' => 'application/json',
      'body' => json_encode($this->generateRequestBody($prompt, $system_prompt, $files, $parameters)),
      'contentType' => 'application/json',
      'modelId' => $this->getPluginSetting('model'),
    ];

    $data = $this->queryModel($payload);
    if (empty($data)) {
      return '';
    }

    return $this->parseResponseBody($data, $raw);
  }

  /**
   * {@inheritdoc}
   */
  public function queryModel(array $payload): array {
    try {
      /** @var \Aws\Result $response */
      $response = $this->getApiClient()->invokeModel($payload);
    }
    catch (\Exception $exception) {
      $this->getLogger()->error(strtr('Completion request failed with error: @error.', [
        '@error' => $exception->getMessage(),
      ]));
      return [];
    }

    try {
      $data = json_decode($response->get('body')->getContents(), TRUE);
    }
    catch (\Exception $exception) {
      $this->getLogger()->error('Unable to decode completion response.');
      return [];
    }
    return $data;
  }

  /**
   * Generate the request body for the completion.
   *
   * @param string $prompt
   *   Prompt.
   * @param string $system_prompt
   *   System prompt.
   * @param array $files
   *   List of URIs of files to pass to the model for analysis.
   * @param array $parameters
   *   Parameters for the payload: max_tokens, temperature, top_p.
   *
   * @return array
   *   Request body.
   */
  protected function generateRequestBody(string $prompt, string $system_prompt, array $files = [], array $parameters = []): array {
    $max_tokens = (int) ($parameters['max_tokens'] ?? $this->getPluginSetting('max_tokens', 512));
    $temperature = (float) ($parameters['temperature'] ?? 0.0);
    $top_p = (float) ($parameters['top_p'] ?? 0.9);

    switch ($this->getPluginSetting('model')) {
      case 'amazon.titan-text-express-v1':
        return [
          'inputText' => $prompt,
          'textGenerationConfig' => [
            'maxTokenCount' => $max_tokens,
            // @todo adjust based on the prompt?
            'stopSequences' => [],
            'temperature' => $temperature,
            'topP' => $top_p,
          ],
        ];

      case 'anthropic.claude-instant-v1':
        return [
          'prompt' => "\n\nHuman:$prompt\n\nAssistant:",
          'temperature' => $temperature,
          'top_p' => $top_p,
          'top_k' => 0,
          'max_tokens_to_sample' => $max_tokens,
          'stop_sequences' => ["\n\nHuman:"],
        ];

      case 'cohere.command-text-v14':
      case 'cohere.command-light-text-v14':
        return [
          'prompt' => $prompt,
          'temperature' => $temperature,
          'p' => $top_p,
          'k' => 0.0,
          'max_tokens' => $max_tokens,
          'stop_sequences' => [],
          'return_likelihoods' => 'NONE',
          'stream' => FALSE,
          'num_generations' => 1,
          'truncate' => 'NONE',
        ];
    }

    return [];
  }

  /**
   * Parse the reponse from the completion API.
   *
   * @param array $data
   *   Decoded response.
   * @param bool $raw
   *   Whether to return the raw output text or let the plugin do some
   *   processing if any.
   *
   * @return string
   *   The generated text.
   */
  protected function parseResponseBody(array $data, bool $raw = TRUE): string {
    switch ($this->getPluginSetting('model')) {
      case 'amazon.titan-text-express-v1':
        return trim($data['results'][0]['outputText'] ?? '');

      case 'anthropic.claude-instant-v1':
        return trim($data['completion'] ?? '');

      case 'cohere.command-text-v14':
      case 'cohere.command-light-text-v14':
        return trim($data['generations'][0]['text'] ?? '');
    }

    return '';
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
    $form['plugins'][$plugin_type][$plugin_id]['max_tokens']['#weight'] = 2;
    $form['plugins'][$plugin_type][$plugin_id]['prompt_template']['#weight'] = 3;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    return [
      'amazon.titan-text-express-v1' => $this->t('Amazon - Titan - Express'),
      'anthropic.claude-instant-v1' => $this->t('Anthropic - Claude - Instant'),
      'cohere.command-text-v14' => $this->t('Cohere - Command'),
      'cohere.command-light-text-v14' => $this->t('Cohere - Command - Light'),
    ];
  }

}

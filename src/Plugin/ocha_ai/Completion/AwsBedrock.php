<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\Completion;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Sts\StsClient;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiCompletion;
use Drupal\ocha_ai\Plugin\CompletionPluginBase;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * AWS Bedrock completion generator base class.
 *
 * This class implements a generic integration with the Amazon Bedrock
 * Converse API, including optional multimodal file support. Thinking modes
 * (Nova 2 extended reasoning, Claude adaptive thinking) are handled by
 * traits and applied via applyModelSpecificRequestFields().
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
   * Whether the model supports file inputs (documents, images, video).
   *
   * @var bool
   */
  protected bool $supportsFiles = TRUE;

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

    $payload = $this->generateRequestBody($prompt, $system_prompt, $files, $parameters);

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
      $response = $this->getApiClient()->converse($payload);
    }
    catch (\Exception $exception) {
      $this->getLogger()->error(strtr('Completion request failed with error: @error.', [
        '@error' => $exception->getMessage(),
      ]));
      return [];
    }

    try {
      // The response is already a structured array, no need to decode JSON.
      $data = $response->toArray();
    }
    catch (\Exception $exception) {
      $this->getLogger()->error('Unable to process converse response.');
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

    $payload = [
      'modelId' => $this->getPluginSetting('model'),
      'inferenceConfig' => [
        'maxTokens' => $max_tokens,
        'temperature' => $temperature,
        'topP' => $top_p,
      ],
    ];

    // Add the system prompt if any.
    if (!empty($system_prompt)) {
      $payload['system'] = [['text' => $system_prompt]];
    }

    // Initialize messages array.
    $payload['messages'] = [];

    // Prepare content blocks for the user message.
    $content = [];

    // Add the documents to analyze if any as document blocks.
    if ($this->supportsFiles && !empty($files)) {
      foreach ($files as $index => $file) {
        $format = $this->mimetypeToFormat($file['mimetype']);
        if (empty($format)) {
          continue;
        }

        $data = NULL;
        if (isset($file['data'])) {
          $data = $file['data'];
        }
        elseif (isset($file['uri'])) {
          $data = $this->getFileContent($file['uri']);
        }

        if (empty($data)) {
          continue;
        }

        $document = [
          'format' => $format,
          'name' => $file['id'] ?? 'document' . ($index + 1),
          'source' => [
            'bytes' => $data,
          ],
        ];
        $document = $this->alterDocumentBlock($document, $file, $index);
        $content[] = ['document' => $document];
      }
    }

    // Add the prompt as text content.
    $content[] = ['text' => $prompt];

    // Add the user message with all content blocks.
    $payload['messages'][] = [
      'role' => 'user',
      'content' => $content,
    ];

    // Allow subclasses to customize model-specific request fields.
    $this->applyModelSpecificRequestFields($payload, $files, $parameters);

    return $payload;
  }

  /**
   * Parse the response from the completion API.
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
    // Extract text from the assistant's message content.
    $response = '';
    if (isset($data['output']['message']['content'])) {
      foreach ($data['output']['message']['content'] as $content) {
        if (isset($content['text'])) {
          $response .= $content['text'];
        }
      }
    }

    $response = trim($response);
    if ($response === '') {
      return '';
    }

    if ($raw) {
      return $response;
    }

    // Extract the answer.
    $start = mb_strpos($response, '<answer>');
    $end = mb_strpos($response, '</answer>');
    if ($start === FALSE || $end === FALSE || $start > $end) {
      return '';
    }

    $start += mb_strlen('<answer>');
    $answer = mb_substr($response, $start, $end - $start);

    // Ensure the thinking section is not part of the answer.
    $answer = preg_replace('#<thinking>.*</thinking>#', '', $answer);

    return trim($answer);
  }

  /**
   * Get the content of file from its URI.
   */
  public function getFileContent(string $uri): string {
    $content = @file_get_contents($uri);
    if (empty($content)) {
      return '';
    }

    // The API doesn't support recent PDF versions, notably the ones using
    // JPEG2000 (JPXDecode). We convert them to version 1.4.
    if (preg_match('/^%PDF-(\d+\.\d+)/i', $content, $matches) === 1 && ((float) $matches[1]) > 1.4) {
      $content = $this->convertPdfVersion($uri, $content);
    }

    return $content;
  }

  /**
   * Convert a PDF version > 1.4 to 1.4.
   *
   * @param string $uri
   *   File URI.
   * @param string $content
   *   File content.
   *
   * @return string
   *   File content.
   */
  protected function convertPdfVersion(string $uri, string $content): string {
    // Build the GhostScript conversion command.
    $process = new Process([
      'gs',
      '-sDEVICE=pdfwrite',
      '-dNOPAUSE',
      '-dQUIET',
      '-dBATCH',
      '-dCompatibilityLevel=1.4',
      '-o',
      // Write to standard output.
      '-',
      // Read from the standard input.
      '-',
    ]);

    // The conversion should be quick, but just in case use 1 min timeout.
    $process->setTimeout(60);

    try {
      // Pass the file content as input to the process.
      $process->setInput($content);

      // Execute the process.
      $process->mustRun();

      // Get the output directly.
      $output = $process->getOutput();

      // Check if the conversion succeeded.
      if (empty($output) || substr($output, 0, 4) !== '%PDF') {
        throw new \Exception('Invalid PDF output.');
      }

      $this->getLogger()->info('Successfully converted PDF version for @uri', ['@uri' => $uri]);

      return $output;
    }
    catch (ProcessFailedException $exception) {
      $this->getLogger()->warning('GhostScript conversion failed for file @uri with error: @error', [
        '@uri' => $uri,
        '@error' => strtr($exception->getMessage(), "\n", ' '),
      ]);
    }
    catch (ProcessTimedOutException $exception) {
      $this->getLogger()->warning('GhostScript conversion timed out after @seconds seconds for file @uri', [
        '@seconds' => $process->getTimeout(),
        '@uri' => $uri,
      ]);
    }
    catch (\Exception $exception) {
      $this->getLogger()->warning('GhostScript conversion failed for file @uri with error: @error', [
        '@uri' => $uri,
        '@error' => strtr($exception->getMessage(), "\n", ' '),
      ]);
    }

    // Return the original content to give it a chance.
    return $content;
  }

  /**
   * Get the file format expected by the model from the file mime type.
   *
   * @param string $mimetype
   *   Mime type.
   *
   * @return string
   *   File format.
   */
  protected function mimetypeToFormat(string $mimetype): string {
    $mapping = [
      // Images.
      'image/jpeg' => 'jpeg',
      'image/jpg' => 'jpeg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/webp' => 'webp',

      // Videos.
      'video/mp4' => 'mp4',
      'video/quicktime' => 'mov',
      'video/x-matroska' => 'mkv',
      'video/webm' => 'webm',
      'video/x-flv' => 'flv',
      'video/mpeg' => 'mpeg',
      'video/mpg' => 'mpg',
      'video/x-ms-wmv' => 'wmv',
      'video/3gpp' => 'three_gp',

      // Text Documents.
      'text/plain' => 'txt',
      'text/markdown' => 'md',
      'text/html' => 'html',
      'text/csv' => 'csv',

      // Media Documents.
      'application/pdf' => 'pdf',

      // Microsoft Office Documents.
      'application/msword' => 'doc',
      'application/vnd.ms-excel' => 'xls',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    return $mapping[$mimetype] ?? '';
  }

  /**
   * Get the list of supported file types and size limits in bytes.
   */
  public function getSupportedFileTypes(): array {
    return [
      // Text-based documents (4.5MB limit).
      'text/plain' => 4718592,
      'text/csv' => 4718592,
      'text/html' => 4718592,
      'text/markdown' => 4718592,
      'application/msword' => 4718592,
      'application/vnd.ms-excel' => 4718592,
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 4718592,

      // Media-based documents (18MB limit).
      'application/pdf' => 18874368,
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 18874368,

      // Image formats (20MB limit).
      'image/jpeg' => 20971520,
      'image/png' => 20971520,
      'image/gif' => 20971520,
      'image/webp' => 20971520,

      // Video formats (25MB limit for direct upload, 1GB for S3).
      'video/x-matroska' => 26214400,
      'video/quicktime' => 26214400,
      'video/mp4' => 26214400,
      'video/webm' => 26214400,
      'video/3gpp' => 26214400,
      'video/x-flv' => 26214400,
      'video/mpeg' => 26214400,
      'video/x-ms-wmv' => 26214400,
    ];
  }

  /**
   * Allows subclasses to alter a document before it is added to the request.
   *
   * Override to add document-level options (e.g. citations for Claude Opus
   * 4.6).
   *
   * @param array $document
   *   The document block (format, name, source).
   * @param array $file
   *   The file metadata (id, mimetype, uri or data).
   * @param int $index
   *   Zero-based index of the document in the content array.
   *
   * @return array
   *   The document block to use (may be the same array with added keys).
   */
  protected function alterDocumentBlock(array $document, array $file, int $index): array {
    return $document;
  }

  /**
   * Allow subclasses to customize model-specific request fields.
   */
  protected function applyModelSpecificRequestFields(array &$payload, array $files, array $parameters): void {
    $this->applyThinkingToPayload($payload, $parameters);
  }

  /**
   * Applies model-specific thinking config to the payload.
   *
   * Override in traits (e.g. Nova2ThinkingTrait, ClaudeThinkingTrait) to add
   * reasoningConfig or adaptive thinking. Base implementation is a no-op.
   *
   * @param array $payload
   *   The Converse request payload (modified in place).
   * @param array $parameters
   *   Request parameters (may contain thinking_mode / thinking_effort
   *   overrides).
   */
  protected function applyThinkingToPayload(array &$payload, array $parameters): void {
  }

  /**
   * Builds form elements for thinking-related settings.
   *
   * Override in traits to add thinking_mode or thinking_effort selects. Base
   * returns an empty array.
   *
   * @param string $plugin_type
   *   The plugin type (e.g. "completion").
   * @param string $plugin_id
   *   The plugin instance ID.
   * @param array $config
   *   Current configuration (e.g. getConfiguration() + defaultConfiguration()).
   *
   * @return array
   *   Form elements to merge under $form['plugins'][$plugin_type][$plugin_id].
   */
  protected function buildThinkingFormElements(string $plugin_type, string $plugin_id, array $config): array {
    return [];
  }

  /**
   * Get the Bedrock API Client.
   *
   * There are 3 ways to authenticate with the API, tried in this order:
   * 1. Role ARN
   * 2. Bearer token from the environment variable AWS_BEARER_TOKEN_BEDROCK
   * 3. API key and API secret.
   *
   * The bearer token is a Bedrock API Key (short term or long term).
   *
   * @return \Aws\BedrockRuntime\BedrockRuntimeClient
   *   API Client.
   *
   * @see https://aws.amazon.com/blogs/machine-learning/accelerate-ai-development-with-amazon-bedrock-api-keys
   */
  protected function getApiClient(): BedrockRuntimeClient {
    if (!isset($this->apiClient)) {
      $region = $this->getPluginSetting('region');
      $role_arn = $this->getPluginSetting('role_arn', NULL, FALSE);

      $options = [
        'region'  => $region,
      ];

      // Use the Role ARN if provided.
      if (!empty($role_arn)) {
        $stsClient = new StsClient([
          'region' => $region,
          'version' => 'latest',
        ]);

        $result = $stsClient->AssumeRole([
          'RoleArn' => $role_arn,
          'RoleSessionName' => 'aws-bedrock-ocha-ai',
        ]);

        $options['credentials'] = [
          'key'    => $result['Credentials']['AccessKeyId'],
          'secret' => $result['Credentials']['SecretAccessKey'],
          'token'  => $result['Credentials']['SessionToken'],
        ];
      }
      // Use the API key and API secret if provided and no Bearer token is set.
      // @see https://aws.amazon.com/blogs/machine-learning/accelerate-ai-development-with-amazon-bedrock-api-keys
      elseif (getenv('AWS_BEARER_TOKEN_BEDROCK') === FALSE) {
        $options['credentials'] = [
          'key' => $this->getPluginSetting('api_key'),
          'secret' => $this->getPluginSetting('api_secret'),
        ];
      }

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

    // Add thinking-related form elements.
    $form['plugins'][$plugin_type][$plugin_id] = array_merge(
      $form['plugins'][$plugin_type][$plugin_id] ?? [],
      $this->buildThinkingFormElements($plugin_type, $plugin_id, $config)
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    // This base class is not intended to expose specific model IDs directly.
    // Concrete plugins should override this to list their supported models.
    return [];
  }

}

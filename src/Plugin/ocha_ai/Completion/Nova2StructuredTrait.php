<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\Completion;

/**
 * Trait for Nova 2 structured outputs on AWS Bedrock.
 */
trait Nova2StructuredTrait {

  /**
   * Perform a structured completion query.
   *
   * @param string $prompt
   *   Prompt.
   * @param array $json_schema
   *   JSON schema as an associative array.
   * @param string $system_prompt
   *   Optional system prompt.
   * @param array $parameters
   *   Optional parameters for the payload.
   * @param array $files
   *   Optional list of files to pass to the model.
   *
   * @return array|null
   *   Structured output data or NULL on failure.
   *
   * @see https://docs.aws.amazon.com/nova/latest/userguide/concept-chapter-servicename.html
   * @see https://aws.amazon.com/blogs/machine-learning/structured-outputs-with-amazon-nova-a-guide-for-builders/
   */
  public function queryStructured(string $prompt, array $json_schema, string $system_prompt = '', array $parameters = [], array $files = []): ?array {
    if (empty($prompt) || empty($json_schema)) {
      return NULL;
    }

    $payload = $this->generateRequestBody($prompt, $system_prompt, $files, $parameters);
    $payload['toolConfig'] = $this->buildStructuredOutputToolConfig($json_schema);

    $data = $this->queryModel($payload);
    if (empty($data)) {
      return NULL;
    }

    return $this->parseStructuredResponseBody($data);
  }

  /**
   * Build Bedrock tool configuration for structured output.
   *
   * @param array $json_schema
   *   JSON schema as an associative array.
   *
   * @return array
   *   Tool configuration as expected by the model.
   */
  protected function buildStructuredOutputToolConfig(array $json_schema): array {
    $tool_name = 'structured_output';

    return [
      'tools' => [
        [
          'toolSpec' => [
            'name' => $tool_name,
            'description' => 'Return structured output that matches the provided schema.',
            'inputSchema' => [
              'json' => $json_schema,
            ],
          ],
        ],
      ],
      'toolChoice' => [
        'tool' => [
          'name' => $tool_name,
        ],
      ],
    ];
  }

  /**
   * Parse Bedrock structured output response payload.
   *
   * @param array $data
   *   Response data from the model.
   *
   * @return array|null
   *   Structured output data or NULL on failure.
   */
  protected function parseStructuredResponseBody(array $data): ?array {
    $stop_reason = $data['stopReason'] ?? '';
    if ($stop_reason !== 'tool_use') {
      $this->getLogger()->warning('Structured output response did not stop with tool_use. stopReason was "@reason".', [
        '@reason' => is_scalar($stop_reason) ? (string) $stop_reason : 'unknown',
      ]);
      return NULL;
    }

    $content = $data['output']['message']['content'] ?? [];
    if (!is_array($content)) {
      return NULL;
    }

    foreach ($content as $block) {
      $input = $block['toolUse']['input'] ?? NULL;
      if (is_array($input)) {
        return $input;
      }
    }

    $this->getLogger()->warning('Structured output response did not include a valid toolUse input array.');
    return NULL;
  }

}

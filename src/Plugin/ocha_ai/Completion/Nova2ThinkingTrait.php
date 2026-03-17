<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\Completion;

/**
 * Trait for Nova 2 extended thinking / reasoning on AWS Bedrock.
 *
 * Nova 2 models support reasoningConfig with maxReasoningEffort (none, low,
 * medium, high). When mode is "high", inferenceConfig must omit maxTokens,
 * temperature, and topP per API requirements.
 *
 * @see https://docs.aws.amazon.com/bedrock/latest/userguide/model-parameters-nova.html
 */
trait Nova2ThinkingTrait {

  /**
   * Allowed extended thinking / reasoning effort levels for Nova 2.
   *
   * @var string[]
   */
  protected const array NOVA2_THINKING_MODES = [
    'none',
    'low',
    'medium',
    'high',
  ];

  /**
   * Applies Nova 2 reasoning config to the Converse API payload.
   *
   * @param array $payload
   *   The Converse request payload (modified in place).
   * @param array $parameters
   *   Request parameters (may contain thinking_mode / thinking_effort).
   */
  protected function applyThinkingToPayload(array &$payload, array $parameters): void {
    $mode = $this->resolveNova2ThinkingMode($parameters);

    if ($mode === 'none') {
      return;
    }

    // When mode is "high", clear inferenceConfig so maxTokens/temperature/topP
    // are omitted.
    if ($mode === 'high') {
      $payload['inferenceConfig'] = [];
    }

    if (!isset($payload['additionalModelRequestFields'])) {
      $payload['additionalModelRequestFields'] = [];
    }
    $payload['additionalModelRequestFields']['reasoningConfig'] = [
      'type' => 'enabled',
      'maxReasoningEffort' => $mode,
    ];
  }

  /**
   * Resolves the Nova 2 thinking mode from parameters and configuration.
   *
   * Accepts both thinking_mode (canonical) and thinking_effort (alias) so
   * external callers can use a single key across models.
   *
   * @param array $parameters
   *   Request parameters.
   *
   * @return string
   *   Normalized thinking mode: none, low, medium, or high.
   */
  protected function resolveNova2ThinkingMode(array $parameters): string {
    if (isset($parameters['thinking_mode'])) {
      $mode = $parameters['thinking_mode'];
    }
    elseif (isset($parameters['thinking_effort'])) {
      $mode = match ($parameters['thinking_effort']) {
        'low' => 'low',
        'medium' => 'medium',
        'high' => 'high',
        'max' => 'high',
        default => 'none',
      };
    }
    else {
      $mode = $this->getPluginSetting('thinking_mode', 'none');
    }

    if (!is_string($mode) || !in_array($mode, self::NOVA2_THINKING_MODES, TRUE)) {
      $mode = 'none';
    }

    return $mode;
  }

  /**
   * Builds form elements for Nova 2 extended thinking mode.
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
    return [
      'thinking_mode' => [
        '#type' => 'select',
        '#title' => $this->t('Extended thinking mode'),
        '#description' => $this->t('Nova 2 extended thinking controls how much the model reasons before answering. "None" uses efficient latent reasoning; low/medium/high enable explicit step-by-step reasoning. When set to "high", temperature and top_p are not sent (per API requirements).'),
        '#default_value' => $config['thinking_mode'] ?? 'none',
        '#options' => [
          'none' => $this->t('None (default)'),
          'low' => $this->t('Low'),
          'medium' => $this->t('Medium'),
          'high' => $this->t('High'),
        ],
        '#weight' => 1,
      ],
    ];
  }

}

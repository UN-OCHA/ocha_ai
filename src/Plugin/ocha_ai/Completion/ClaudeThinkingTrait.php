<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\Completion;

/**
 * Trait for Claude Opus 4.6 adaptive thinking on AWS Bedrock.
 *
 * Adaptive thinking lets Claude decide when and how much to think based on
 * request complexity. Effort levels: none (off), low, medium, high, max.
 * Passed via additionalModelRequestFields['thinking'] for the Converse API.
 *
 * @see https://docs.aws.amazon.com/bedrock/latest/userguide/claude-messages-adaptive-thinking.html
 */
trait ClaudeThinkingTrait {

  /**
   * Allowed adaptive thinking effort levels for Claude Opus 4.6.
   *
   * @var string[]
   */
  protected const array CLAUDE_EFFORT_LEVELS = [
    'none',
    'low',
    'medium',
    'high',
    'max',
  ];

  /**
   * Applies Claude adaptive thinking to the Converse API payload.
   *
   * @param array $payload
   *   The Converse request payload (modified in place).
   * @param array $parameters
   *   Request parameters (may contain thinking_effort / thinking_mode).
   */
  protected function applyThinkingToPayload(array &$payload, array $parameters): void {
    $effort = $this->resolveClaudeThinkingEffort($parameters);

    if ($effort === 'none') {
      return;
    }

    if (!isset($payload['additionalModelRequestFields'])) {
      $payload['additionalModelRequestFields'] = [];
    }
    $payload['additionalModelRequestFields']['thinking'] = [
      'type' => 'adaptive',
      'effort' => $effort,
    ];
  }

  /**
   * Resolves the Claude adaptive thinking effort from parameters/config.
   *
   * Accepts both thinking_effort (canonical) and thinking_mode (alias) so
   * external callers can use a single key across models.
   *
   * @param array $parameters
   *   Request parameters.
   *
   * @return string
   *   Normalized effort: none, low, medium, high, or max.
   */
  protected function resolveClaudeThinkingEffort(array $parameters): string {
    if (isset($parameters['thinking_effort'])) {
      $effort = $parameters['thinking_effort'];
    }
    elseif (isset($parameters['thinking_mode'])) {
      $effort = match ($parameters['thinking_mode']) {
        'low' => 'low',
        'medium' => 'medium',
        'high' => 'high',
        default => 'none',
      };
    }
    else {
      $effort = $this->getPluginSetting('thinking_effort', 'none');
    }

    if (!is_string($effort) || !in_array($effort, self::CLAUDE_EFFORT_LEVELS, TRUE)) {
      $effort = 'none';
    }

    return $effort;
  }

  /**
   * Builds form elements for Claude adaptive thinking effort.
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
      'thinking_effort' => [
        '#type' => 'select',
        '#title' => $this->t('Adaptive thinking effort'),
        '#description' => $this->t('Claude Opus 4.6 adaptive thinking lets the model decide when and how much to reason. "None" disables thinking; low/medium/high/max guide how much thinking is used. Default is "high".'),
        '#default_value' => $config['thinking_effort'] ?? 'none',
        '#options' => [
          'none' => $this->t('None (disabled, default)'),
          'low' => $this->t('Low'),
          'medium' => $this->t('Medium'),
          'high' => $this->t('High'),
          'max' => $this->t('Max'),
        ],
        '#weight' => 1,
      ],
    ];
  }

}

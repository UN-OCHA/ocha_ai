<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\Completion;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiCompletion;

/**
 * AWS Bedrock Claude Opus 4.6 completion generator.
 *
 * Uses the Amazon Bedrock Converse API to access Anthropic Claude Opus 4.6,
 * with multimodal file support similar to Nova Lite. When files are provided,
 * citations are enabled so that Claude can perform full visual PDF analysis,
 * as described in the Claude on Amazon Bedrock and PDF support documentation.
 * Supports adaptive thinking (effort: none, low, medium, high, max).
 *
 * @see https://platform.claude.com/docs/en/build-with-claude/claude-on-amazon-bedrock
 * @see https://platform.claude.com/docs/en/build-with-claude/pdf-support#amazon-bedrock-pdf-support
 * @see https://docs.aws.amazon.com/bedrock/latest/userguide/claude-messages-adaptive-thinking.html
 */
#[OchaAiCompletion(
  id: 'aws_bedrock_claude_opus_4_6_v1',
  label: new TranslatableMarkup('AWS Bedrock - Claude Opus 4.6'),
  description: new TranslatableMarkup('Use AWS Bedrock - Claude Opus 4.6 as completion generator.'),
  capabilities: [
    CompletionCapability::FileInput,
    CompletionCapability::ThinkingMode,
  ],
)]
class AwsBedrockClaudeOpus46V1 extends AwsBedrock {

  use ClaudeThinkingTrait;

  /**
   * {@inheritdoc}
   */
  protected bool $supportsFiles = TRUE;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      // Default to the global endpoint for maximum availability.
      // See: https://platform.claude.com/docs/en/build-with-claude/claude-on-amazon-bedrock
      'model' => 'global.anthropic.claude-opus-4-6-v1',
      'max_tokens' => 512,
      'thinking_effort' => 'high',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    return [
      // Global endpoint (recommended).
      'global.anthropic.claude-opus-4-6-v1' => $this->t('Anthropic - Claude Opus 4.6 - Global'),
      // Regional endpoint (for data residency / CRIS), 10% more expensive.
      'us.anthropic.claude-opus-4-6-v1' => $this->t('Anthropic - Claude Opus 4.6 - US'),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Enables citations on PDF documents only, so Claude uses full visual
   * analysis instead of text extraction. Other formats are unchanged.
   *
   * @see https://platform.claude.com/docs/en/build-with-claude/pdf-support#amazon-bedrock-pdf-support
   */
  protected function alterDocumentBlock(array $document, array $file, int $index): array {
    if (($document['format'] ?? '') === 'pdf') {
      $document['citations'] = [
        'enabled' => TRUE,
      ];
    }
    return $document;
  }

  /**
   * {@inheritdoc}
   *
   * Claude Opus 4.6 shares the same multimodal file support profile as the
   * Nova models on Bedrock, except for DOCX which uses the text-based 4.5MB
   * limit instead of the 18MB media limit.
   *
   * DOCX limit is 4.5MB (4718592 bytes) instead of 18MB (18874368 bytes).
   */
  public function getSupportedFileTypes(): array {
    // Start from the Nova v1 limits and adjust DOCX.
    $types = parent::getSupportedFileTypes();

    // PDF documents use the Claude Opus 4.6 limit of 32MB (32768000 bytes).
    $types['application/pdf'] = 32768000;

    // Text-based documents (4.5MB limit).
    $types['application/vnd.openxmlformats-officedocument.wordprocessingml.document'] = 4718592;

    return $types;
  }

}

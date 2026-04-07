<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\Completion;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiCompletion;

/**
 * AWS Bedrock Nova 2 Lite v1 completion generator.
 *
 * Amazon Nova 2 Lite is a fast, cost-effective reasoning model with multimodal
 * input (text, image, video, documents) and extended context.
 *
 * @see https://docs.aws.amazon.com/bedrock/latest/userguide/model-ids.html
 */
#[OchaAiCompletion(
  id: 'aws_bedrock_nova_2_lite_v1',
  label: new TranslatableMarkup('AWS Bedrock - Nova 2 Lite v1'),
  description: new TranslatableMarkup('Use AWS Bedrock - Nova 2 Lite v1 as completion generator.'),
  capabilities: [
    CompletionCapability::FileInput,
    CompletionCapability::ThinkingMode,
    CompletionCapability::StructuredOutput,
  ],
)]
class AwsBedrockNova2LiteV1 extends AwsBedrock {

  use Nova2ThinkingTrait;
  use Nova2StructuredTrait;

  /**
   * {@inheritdoc}
   */
  protected bool $supportsFiles = TRUE;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'model' => 'global.amazon.nova-2-lite-v1:0',
      'max_tokens' => 512,
      'thinking_mode' => 'none',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    return [
      // Global endpoint (recommended).
      'global.amazon.nova-2-lite-v1:0' => $this->t('Amazon - Nova 2 Lite v1 - Global'),
      // Regional endpoint (for data residency / CRIS), 10% more expensive.
      'us.amazon.nova-2-lite-v1:0' => $this->t('Amazon - Nova 2 Lite v1 - US'),
    ];
  }

}

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
  description: new TranslatableMarkup('Use AWS Bedrock - Nova 2 Lite v1 as completion generator.')
)]
class AwsBedrockNova2LiteV1 extends AwsBedrockNova2V1Base {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'model' => 'us.amazon.nova-2-lite-v1:0',
      'max_tokens' => 512,
      'thinking_mode' => 'none',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    return [
      'us.amazon.nova-2-lite-v1:0' => $this->t('Amazon - Nova 2 Lite v1 - US'),
      'global.amazon.nova-2-lite-v1:0' => $this->t('Amazon - Nova 2 Lite v1 - Global'),
    ];
  }

}

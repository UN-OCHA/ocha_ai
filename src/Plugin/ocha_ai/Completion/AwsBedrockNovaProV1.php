<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\Completion;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiCompletion;

/**
 * AWS Bedrock Nova pro v1 completion generator.
 */
#[OchaAiCompletion(
  id: 'aws_bedrock_nova_pro_v1',
  label: new TranslatableMarkup('AWS Bedrock - Nova pro v1'),
  description: new TranslatableMarkup('Use AWS Bedrock - Nova pro v1 as completion generator.')
)]
class AwsBedrockNovaProV1 extends AwsBedrockNovaV1Base {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'model' => 'amazon.nova-pro-v1:0',
      'max_tokens' => 512,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    return [
      'amazon.nova-pro-v1:0' => $this->t('Amazon - Nova pro v1'),
    ];
  }

}

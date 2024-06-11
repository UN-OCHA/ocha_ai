<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\Embedding;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiEmbedding;

/**
 * AWS embedding generator.
 */
#[OchaAiEmbedding(
  id: 'aws_bedrock_titan_embed_text_v2',
  label: new TranslatableMarkup('AWS Bedrock - Titan embed text v2'),
  description: new TranslatableMarkup('Use AWS Bedrock Titan embed text v2 as embedding generator.')
)]
class AwsBedrockTitanEmbedTextV2 extends AwsBedrock {

  /**
   * The parent plugin config.
   *
   * @var array
   */
  protected $parentPluginConfig;

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
    return [
      'inputText' => $text,
      'dimensions' => (int) $this->getPluginSetting('dimensions'),
      'normalize' => TRUE,
    ];
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
    return $data['embedding'];
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    return [
      'amazon.titan-embed-text-v2:0' => $this->t('Amazon - Titan embed text v2'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $plugin_type = $this->getPluginType();
    $plugin_id = $this->getPluginId();

    // Disallow changing the defaults in the UI. This can still be done via
    // the drupal settings.php file for example.
    $form['plugins'][$plugin_type][$plugin_id]['model']['#disabled'] = TRUE;
    $form['plugins'][$plugin_type][$plugin_id]['dimensions']['#disabled'] = TRUE;
    $form['plugins'][$plugin_type][$plugin_id]['max_tokens']['#disabled'] = TRUE;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginSetting(string $key, mixed $default = NULL, bool $throw_if_null = TRUE): mixed {
    // Use the default setting or the `aws_bedrock` base plugin setting as
    // default is no other default is provided.
    // This notably allows to use the parent plugin configuration for the
    // API client while still allowing overrides through the UI or config files.
    $default ??= $this->getDefaultSetting($key) ?? $this->getParentPluginSetting($key);

    return parent::getPluginSetting($key, $default, $throw_if_null);
  }

  /**
   * Retrieve the configuration for the parent plugin.
   *
   * @param string $key
   *   Config key.
   *
   * @return mixed
   *   The parent config value if defined.
   */
  protected function getParentPluginSetting(string $key): mixed {
    if (!isset($this->parentPluginConfig)) {
      $this->parentPluginConfig = $this->configFactory
        ->get('ocha_ai.settings')
        ->get('plugins.embedding.aws_bedrock') ?? [];
    }
    return $this->parentPluginConfig[$key] ?? NULL;
  }

  /**
   * Retrieve the default setting value for the given key.
   *
   * @param string $key
   *   Config key.
   *
   * @return mixed
   *   The default config value if defined.
   */
  protected function getDefaultSetting(string $key): mixed {
    return $this->defaultConfiguration()[$key] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'model' => 'amazon.titan-embed-text-v2:0',
      'dimensions' => 1024,
      'max_tokens' => 8192,
    ];
  }

}

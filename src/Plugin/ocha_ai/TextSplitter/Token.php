<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\TextSplitter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiTextSplitter;
use Drupal\ocha_ai\Helpers\TextHelper;
use Drupal\ocha_ai\Plugin\TextSplitterPluginBase;

/**
 * Split a text into passages based on their estimated token count.
 */
#[OchaAiTextSplitter(
  id: 'token',
  label: new TranslatableMarkup('Token'),
  description: new TranslatableMarkup('Split a text into passages based on their estimated token count.')
)]
class Token extends TextSplitterPluginBase {

  /**
   * {@inheritdoc}
   */
  public function splitText(string $text, ?int $length = NULL, ?int $overlap = NULL): array {
    $max_token_count = $length ?? $this->getPluginSetting('length');
    $overlap_token_count = $overlap ?? $this->getPluginSetting('overlap');

    if (TextHelper::estimateTokenCount($text) <= $max_token_count) {
      return [$text];
    }

    return TextHelper::splitInLinesOptimalLength($text, $max_token_count, TextHelper::UNIT_TOKEN, $overlap_token_count);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $plugin_type = $this->getPluginType();
    $plugin_id = $this->getPluginId();

    $form['plugins'][$plugin_type][$plugin_id]['length']['#description'] = $this->t('Maximum number of tokens for one text passage.');
    $form['plugins'][$plugin_type][$plugin_id]['overlap']['#description'] = $this->t('Maximum number of tokens from the previous passage to include in the passage to preserve context.');

    return $form;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\TextSplitter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiTextSplitter;
use Drupal\ocha_ai\Helpers\TextHelper;
use Drupal\ocha_ai\Plugin\TextSplitterPluginBase;

/**
 * Split a text in groups of sentences.
 */
#[OchaAiTextSplitter(
  id: 'sentence',
  label: new TranslatableMarkup('Sentence'),
  description: new TranslatableMarkup('Split a text in groups of sentences.')
)]
class Sentence extends TextSplitterPluginBase {

  /**
   * {@inheritdoc}
   */
  public function splitText(string $text, ?int $length = NULL, ?int $overlap = NULL): array {
    $length = $length ?? $this->getPluginSetting('length');
    $overlap = $overlap ?? $this->getPluginSetting('overlap');

    if (mb_strlen($text) <= $length) {
      return [$text];
    }

    // Split the text into paragraphs and sentences.
    return TextHelper::splitInLinesOptimalLength($text, $length, TextHelper::UNIT_CHAR, $overlap);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $plugin_type = $this->getPluginType();
    $plugin_id = $this->getPluginId();

    $form['plugins'][$plugin_type][$plugin_id]['length']['#description'] = $this->t('Maximum number of sentences for one text passage.');
    $form['plugins'][$plugin_type][$plugin_id]['overlap']['#description'] = $this->t('Maximum number of previous sentences to include in the passage to preserve context.');

    return $form;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\TextSplitter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiTextSplitter;
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
    $length = $this->getPluginSetting('length');
    $overlap = $this->getPluginSetting('overlap');

    // Split the text into paragraphs and setences.
    $paragraphs = [];

    // @todo review how to better split paragraphs.
    foreach (preg_split('/\n{2,}/u', $text, -1, \PREG_SPLIT_NO_EMPTY) as $paragraph) {
      $paragraph = preg_replace('/\s+/u', ' ', $paragraph);
      $paragraph = trim(preg_replace('/([;.!?。؟]+)\s+/u', "$1\n", $paragraph));

      $sentences = [];
      foreach (preg_split('/\n+/u', $paragraph, -1, \PREG_SPLIT_NO_EMPTY) as $sentence) {
        $sentence = trim($sentence);
        if (!empty($sentence)) {
          $sentences[] = $sentence;
        }
      }

      $paragraphs[] = $sentences;
    }

    // Generate groups of sentences.
    $groups = [];
    foreach ($paragraphs as $sentences) {
      $count = count($sentences);
      for ($i = 0; $i < $count; $i += $length) {
        $group = [];
        // Include $overlap previous sentences to the group to try to
        // preserve some context.
        // @todo Include  following sentences as well?
        for ($j = max(0, $i - $overlap); $j < $i + $length; $j++) {
          if (isset($sentences[$j])) {
            $group[] = $sentences[$j];
          }
        }
        $groups[] = implode(' ', $group);
      }
    }

    return $groups;
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

<?php

namespace Drupal\ocha_ai_chat\Plugin\ocha_ai_chat\TextSplitter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_ai_chat\Helpers\TextHelper;
use Drupal\ocha_ai_chat\Plugin\TextSplitterPluginBase;

/**
 * Split a text into passages based on their estimated token count.
 *
 * @OchaAiChatTextSplitter(
 *   id = "token",
 *   label = @Translation("Token"),
 *   description = @Translation("Split a text into passges based on their estimated token count."),
 * )
 */
class Token extends TextSplitterPluginBase {

  /**
   * {@inheritdoc}
   */
  public function splitText(string $text, ?int $length = NULL, ?int $overlap = NULL): array {
    $lines = explode('##L##', trim(preg_replace('/(\n{1,})/u', '$1##L##', $text)));

    foreach ($lines as $index => $line) {
      $lines[$index] = [
        'token_count' => TextHelper::estimateTokenCount($line),
        'text' => $line,
      ];
    }

    $max_token_count = $length ?? $this->getPluginSetting('length');
    $overlap_token_count = $overlap ?? $this->getPluginSetting('overlap');

    $total_lines = count($lines);
    $total_token_count = TextHelper::estimateTokenCount($text);

    // Determine the max number of tokens for each passage and the size of the
    // actual overlap.
    $group_max_token_count = round($total_token_count / ceil($total_token_count / $max_token_count));
    $group_overlap_token_count = floor($group_max_token_count * $overlap_token_count / $max_token_count);

    $groups = [];
    $group = [];
    $group_token_count = 0;
    foreach ($lines as $index => $line) {
      if ($line['token_count'] + $group_token_count > $group_max_token_count - $group_overlap_token_count) {
        // Add extra lines to the group to have an overlap with the next one
        // that will give more context to the text and helps with relevancy.
        for ($i = $index; $i < $total_lines; $i++) {
          $group_token_count += $lines[$i]['token_count'];
          if ($group_token_count <= $group_max_token_count) {
            $group[$i] = $lines[$i]['text'];
          }
          else {
            break;
          }
        }

        $groups[] = $group;
        $group = [];
        $group_token_count = 0;
      }

      $group[$index] = $line['text'];
      $group_token_count += $line['token_count'];
    }

    // Add the remaining lines.
    if (!empty($group)) {
      $groups[] = $group;
    }

    foreach ($groups as $index => $group) {
      $groups[$index] = trim(implode('', $group));
    }

    return array_filter($groups);
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

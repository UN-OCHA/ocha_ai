<?php

namespace Drupal\ocha_ai\Plugin;

use Drupal\Core\Form\FormStateInterface;

/**
 * Base answer validator plugin.
 */
abstract class AnswerValidatorPluginBase extends PluginBase implements AnswerValidatorPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'answer_validator';
  }

}

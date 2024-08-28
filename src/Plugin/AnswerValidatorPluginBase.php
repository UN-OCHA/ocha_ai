<?php

namespace Drupal\ocha_ai\Plugin;

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

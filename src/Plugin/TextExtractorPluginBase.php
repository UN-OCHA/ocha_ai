<?php

namespace Drupal\ocha_ai\Plugin;

/**
 * Base text extractor plugin.
 */
abstract class TextExtractorPluginBase extends PluginBase implements TextExtractorPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'text_extractor';
  }

}

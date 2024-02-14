<?php

namespace Drupal\ocha_ai\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Plugin manager for the text extractor plugins.
 */
class TextExtractorPluginManager extends PluginManagerBase implements TextExtractorPluginManagerInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler
  ) {
    parent::__construct(
      'Plugin/ocha_ai/TextExtractor',
      $namespaces,
      $module_handler,
      'Drupal\ocha_ai\Plugin\TextExtractorPluginInterface',
      'Drupal\ocha_ai\Annotation\OchaAiChatTextExtractor'
    );

    $this->setCacheBackend($cache_backend, 'ocha_ai_text_extractor_plugins');
    $this->alterInfo('ocha_ai_text_extractor_info');
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'text_extractor';
  }

}

<?php

namespace Drupal\ocha_ai_chat\Plugin;

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
      'Plugin/ocha_ai_chat/TextExtractor',
      $namespaces,
      $module_handler,
      'Drupal\ocha_ai_chat\Plugin\TextExtractorPluginInterface',
      'Drupal\ocha_ai_chat\Annotation\OchaAiChatTextExtractor'
    );

    $this->setCacheBackend($cache_backend, 'ocha_ai_chat_text_extractor_plugins');
    $this->alterInfo('ocha_ai_chat_text_extractor_info');
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'text_extractor';
  }

}

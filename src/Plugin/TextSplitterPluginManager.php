<?php

namespace Drupal\ocha_ai_chat\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Plugin manager for the text splitter plugins.
 */
class TextSplitterPluginManager extends PluginManagerBase implements TextSplitterPluginManagerInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler
  ) {
    parent::__construct(
      'Plugin/ocha_ai_chat/TextSplitter',
      $namespaces,
      $module_handler,
      'Drupal\ocha_ai_chat\Plugin\TextSplitterPluginInterface',
      'Drupal\ocha_ai_chat\Annotation\OchaAiChatTextSplitter'
    );

    $this->setCacheBackend($cache_backend, 'ocha_ai_chat_text_splitter_plugins');
    $this->alterInfo('ocha_ai_chat_text_splitter_info');
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'text_splitter';
  }

}

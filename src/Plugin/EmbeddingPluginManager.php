<?php

namespace Drupal\ocha_ai_chat\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Plugin manager for the embedding plugins.
 */
class EmbeddingPluginManager extends PluginManagerBase implements EmbeddingPluginManagerInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler
  ) {
    parent::__construct(
      'Plugin/ocha_ai_chat/Embedding',
      $namespaces,
      $module_handler,
      'Drupal\ocha_ai_chat\Plugin\EmbeddingPluginInterface',
      'Drupal\ocha_ai_chat\Annotation\OchaAiChatEmbedding'
    );

    $this->setCacheBackend($cache_backend, 'ocha_ai_chat_embedding_plugins');
    $this->alterInfo('ocha_ai_chat_embedding_info');
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'embedding';
  }

}

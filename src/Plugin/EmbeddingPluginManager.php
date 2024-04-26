<?php

namespace Drupal\ocha_ai\Plugin;

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
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/ocha_ai/Embedding',
      $namespaces,
      $module_handler,
      'Drupal\ocha_ai\Plugin\EmbeddingPluginInterface',
      'Drupal\ocha_ai\Attribute\OchaAiEmbedding'
    );

    $this->setCacheBackend($cache_backend, 'ocha_ai_embedding_plugins');
    $this->alterInfo('ocha_ai_embedding_info');
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'embedding';
  }

}

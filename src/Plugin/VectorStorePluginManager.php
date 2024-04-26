<?php

namespace Drupal\ocha_ai\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Plugin manager for the vector store plugins.
 */
class VectorStorePluginManager extends PluginManagerBase implements VectorStorePluginManagerInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/ocha_ai/VectorStore',
      $namespaces,
      $module_handler,
      'Drupal\ocha_ai\Plugin\VectorStorePluginInterface',
      'Drupal\ocha_ai\Attribute\OchaAiVectorStore'
    );

    $this->setCacheBackend($cache_backend, 'ocha_ai_vector_store_plugins');
    $this->alterInfo('ocha_ai_vector_store_info');
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'vector_store';
  }

}

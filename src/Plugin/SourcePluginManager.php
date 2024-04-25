<?php

namespace Drupal\ocha_ai\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Plugin manager for the source plugins.
 */
class SourcePluginManager extends PluginManagerBase implements SourcePluginManagerInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/ocha_ai/Source',
      $namespaces,
      $module_handler,
      'Drupal\ocha_ai\Plugin\SourcePluginInterface',
      'Drupal\ocha_ai\Attribute\OchaAiSource'
    );

    $this->setCacheBackend($cache_backend, 'ocha_ai_source_plugins');
    $this->alterInfo('ocha_ai_source_info');
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'source';
  }

}

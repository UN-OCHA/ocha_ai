<?php

namespace Drupal\ocha_ai\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Plugin manager for the ranker plugins.
 */
class RankerPluginManager extends PluginManagerBase implements RankerPluginManagerInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/ocha_ai/Ranker',
      $namespaces,
      $module_handler,
      'Drupal\ocha_ai\Plugin\RankerPluginInterface',
      'Drupal\ocha_ai\Attribute\OchaAiRanker'
    );

    $this->setCacheBackend($cache_backend, 'ocha_ai_ranker_plugins');
    $this->alterInfo('ocha_ai_ranker_info');
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'ranker';
  }

}

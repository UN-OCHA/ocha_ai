<?php

namespace Drupal\ocha_ai_chat\Plugin;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Plugin manager for the text extractor plugins.
 */
abstract class PluginManagerBase extends DefaultPluginManager implements PluginManagerInterface {

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Static cache for the plugin instances.
   *
   * @var \Drupal\ocha_ai_chat\Plugin\PluginInterface[]
   */
  protected array $instances = [];

  /**
   * {@inheritdoc}
   */
  public function getAvailablePlugins(): array {
    $plugins = [];
    foreach (array_keys($this->getDefinitions()) as $plugin_id) {
      $plugins[$plugin_id] = $this->getPlugin($plugin_id);
    }
    return $plugins;
  }

  /**
   * {@inheritdoc}
   */
  public function getPlugin(string $plugin_id): PluginInterface {
    if (!isset($this->instances[$plugin_id])) {
      $configuration = $this->getPluginConfig($plugin_id);
      $this->instances[$plugin_id] = $this->createInstance($plugin_id, $configuration);
    }
    return $this->instances[$plugin_id];
  }

  /**
   * Get the config factory.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The config factory.
   *
   * @todo check how to inject that service instead.
   */
  protected function getPluginConfig(string $plugin_id): array {
    if (!isset($this->configFactory)) {
      $this->configFactory = \Drupal::configFactory();
    }
    return $this->configFactory
      ->get('ocha_ai_chat.settings')
      ->get('plugins.' . $this->getPluginType() . '.' . $plugin_id) ?? [];
  }

}

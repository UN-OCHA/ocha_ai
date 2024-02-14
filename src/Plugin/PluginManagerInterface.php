<?php

namespace Drupal\ocha_ai\Plugin;

/**
 * Base interface for the ocha_ai plugins.
 */
interface PluginManagerInterface {

  /**
   * Get the plugin type managed by this manager.
   *
   * @return string
   *   Plugin type.
   */
  public function getPluginType(): string;

  /**
   * Get the available completion plugins.
   *
   * @return \Drupal\ocha_ai\Plugin\PluginInterface[]
   *   List of plugins.
   */
  public function getAvailablePlugins(): array;

  /**
   * Get the instance of the plugin with the given ID.
   *
   * @param string $plugin_id
   *   Plugin ID.
   *
   * @return \Drupal\ocha_ai\Plugin\PluginInterface
   *   Plugin instance.
   */
  public function getPlugin(string $plugin_id): PluginInterface;

}

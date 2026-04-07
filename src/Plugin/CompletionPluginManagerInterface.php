<?php

namespace Drupal\ocha_ai\Plugin;

use Drupal\ocha_ai\Plugin\ocha_ai\Completion\CompletionCapability;

/**
 * Interface for the completion plugin manager.
 */
interface CompletionPluginManagerInterface extends PluginManagerInterface {

  /**
   * Get available completion plugins that support a capability.
   *
   * @param \Drupal\ocha_ai\Plugin\ocha_ai\Completion\CompletionCapability $capability
   *   Capability enum case.
   *
   * @return \Drupal\ocha_ai\Plugin\CompletionPluginInterface[]
   *   Matching completion plugins keyed by plugin ID.
   */
  public function getAvailablePluginsByCapability(CompletionCapability $capability): array;

}

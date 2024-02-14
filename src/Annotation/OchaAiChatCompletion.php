<?php

namespace Drupal\ocha_ai\Annotation;

use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;

/**
 * Defines a OCHA AI Chat completion plugin annotation object.
 *
 * Plugin Namespace: Plugin\ocha_ai\Completion.
 *
 * @see \Drupal\ocha_ai\Plugin\CompletionPluginBase
 * @see \Drupal\ocha_ai\Plugin\CompletionPluginInterface
 * @see \Drupal\ocha_ai\Plugin\CompletionPluginManager
 * @see plugin_api
 *
 * @Annotation
 */
class OchaAiChatCompletion extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public string $id;

  /**
   * The human-readable name of the plugin.
   *
   * @var \Drupal\Core\Annotation\Translation
   * @ingroup plugin_translatable
   */
  public Translation $label;

  /**
   * A short description of the plugin.
   *
   * @var \Drupal\Core\Annotation\Translation
   * @ingroup plugin_translatable
   */
  public Translation $description;

}

<?php

namespace Drupal\ocha_ai_chat\Annotation;

use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;

/**
 * Defines a OCHA AI Chat document source plugin annotation object.
 *
 * Plugin Namespace: Plugin\ocha_ai_chat\Source.
 *
 * @see \Drupal\ocha_ai_chat\Plugin\SourcePluginBase
 * @see \Drupal\ocha_ai_chat\Plugin\SourcePluginInterface
 * @see \Drupal\ocha_ai_chat\Plugin\SourcePluginManager
 * @see plugin_api
 *
 * @Annotation
 */
class OchaAiChatSource extends Plugin {

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

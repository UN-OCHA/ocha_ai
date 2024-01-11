<?php

namespace Drupal\ocha_ai_chat\Annotation;

use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;

/**
 * Defines a OCHA AI Chat text splitter plugin annotation object.
 *
 * Plugin Namespace: Plugin\ocha_ai_chat\TextSplitter.
 *
 * @see \Drupal\ocha_ai_chat\Plugin\TextSplitterPluginBase
 * @see \Drupal\ocha_ai_chat\Plugin\TextSplitterPluginInterface
 * @see \Drupal\ocha_ai_chat\Plugin\TextSplitterPluginManager
 * @see plugin_api
 *
 * @Annotation
 */
class OchaAiChatTextSplitter extends Plugin {

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

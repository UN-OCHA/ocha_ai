<?php

namespace Drupal\ocha_ai\Annotation;

use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;

/**
 * Defines a OCHA AI Chat text splitter plugin annotation object.
 *
 * Plugin Namespace: Plugin\ocha_ai\TextSplitter.
 *
 * @see \Drupal\ocha_ai\Plugin\TextSplitterPluginBase
 * @see \Drupal\ocha_ai\Plugin\TextSplitterPluginInterface
 * @see \Drupal\ocha_ai\Plugin\TextSplitterPluginManager
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

<?php

namespace Drupal\ocha_ai_chat\Annotation;

use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;

/**
 * Defines a OCHA AI Chat vector store plugin annotation object.
 *
 * Plugin Namespace: Plugin\ocha_ai_chat\VectorStore.
 *
 * @see \Drupal\ocha_ai_chat\Plugin\VectorStorePluginBase
 * @see \Drupal\ocha_ai_chat\Plugin\VectorStorePluginInterface
 * @see \Drupal\ocha_ai_chat\Plugin\VectorStorePluginManager
 * @see plugin_api
 *
 * @Annotation
 */
class OchaAiChatVectorStore extends Plugin {

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

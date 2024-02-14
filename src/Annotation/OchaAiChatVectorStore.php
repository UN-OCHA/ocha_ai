<?php

namespace Drupal\ocha_ai\Annotation;

use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;

/**
 * Defines a OCHA AI Chat vector store plugin annotation object.
 *
 * Plugin Namespace: Plugin\ocha_ai\VectorStore.
 *
 * @see \Drupal\ocha_ai\Plugin\VectorStorePluginBase
 * @see \Drupal\ocha_ai\Plugin\VectorStorePluginInterface
 * @see \Drupal\ocha_ai\Plugin\VectorStorePluginManager
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

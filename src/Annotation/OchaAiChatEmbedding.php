<?php

namespace Drupal\ocha_ai\Annotation;

use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;

/**
 * Defines a OCHA AI Chat embedding client plugin annotation object.
 *
 * Plugin Namespace: Plugin\ocha_ai\Embedding.
 *
 * @see \Drupal\ocha_ai\Plugin\EmbeddingPluginBase
 * @see \Drupal\ocha_ai\Plugin\EmbeddingPluginInterface
 * @see \Drupal\ocha_ai\Plugin\EmbeddingPluginManager
 * @see plugin_api
 *
 * @Annotation
 */
class OchaAiChatEmbedding extends Plugin {

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

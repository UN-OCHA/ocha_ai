<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a OCHA AI text splitter plugin attribute object.
 *
 * Plugin Namespace: Plugin\ocha_ai\TextSplitter.
 *
 * @see \Drupal\ocha_ai\Plugin\TextSplitterPluginBase
 * @see \Drupal\ocha_ai\Plugin\TextSplitterPluginInterface
 * @see \Drupal\ocha_ai\Plugin\TextSplitterPluginManager
 * @see plugin_api
 *
 * @Attribute
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class OchaAiTextSplitter extends Plugin {

  /**
   * Constructs a OCHA AI text splitter attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The label of the plugin.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $description
   *   The description of the plugin.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly TranslatableMarkup $description
  ) {}

}

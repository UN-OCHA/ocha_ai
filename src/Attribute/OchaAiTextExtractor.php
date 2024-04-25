<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a OCHA AI text extractor plugin attribute object.
 *
 * Plugin Namespace: Plugin\ocha_ai\TextExtractor.
 *
 * @see \Drupal\ocha_ai\Plugin\TextExtractorPluginBase
 * @see \Drupal\ocha_ai\Plugin\TextExtractorPluginInterface
 * @see \Drupal\ocha_ai\Plugin\TextExtractorPluginManager
 * @see plugin_api
 *
 * @Attribute
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class OchaAiTextExtractor extends Plugin {

  /**
   * Constructs a OCHA AI text extractor attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The label of the plugin.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $description
   *   The description of the plugin.
   * @param array $mimetypes
   *   List of file mimetypes supported by the extractor.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly TranslatableMarkup $description,
    public readonly array $mimetypes,
  ) {}

}

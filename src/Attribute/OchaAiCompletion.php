<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a OCHA AI completion plugin attribute object.
 *
 * Plugin Namespace: Plugin\ocha_ai\Completion.
 *
 * @see \Drupal\ocha_ai\Plugin\CompletionPluginBase
 * @see \Drupal\ocha_ai\Plugin\CompletionPluginInterface
 * @see \Drupal\ocha_ai\Plugin\CompletionPluginManager
 * @see plugin_api
 *
 * @Attribute
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class OchaAiCompletion extends Plugin {

  /**
   * Constructs a OCHA AI completion attribute.
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

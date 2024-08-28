<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a OCHA AI answer validator plugin attribute object.
 *
 * Plugin Namespace: Plugin\ocha_ai\AnswerValidator.
 *
 * @see \Drupal\ocha_ai\Plugin\AnswerValidatorPluginBase
 * @see \Drupal\ocha_ai\Plugin\AnswerValidatorPluginInterface
 * @see \Drupal\ocha_ai\Plugin\AnswerValidatorPluginManager
 * @see plugin_api
 *
 * @Attribute
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class OchaAiAnswerValidator extends Plugin {

  /**
   * Constructs a OCHA AI answer validator attribute.
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
    public readonly TranslatableMarkup $description,
  ) {}

}

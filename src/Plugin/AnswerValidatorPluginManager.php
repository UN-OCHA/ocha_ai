<?php

namespace Drupal\ocha_ai\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Plugin manager for the answer validator plugins.
 */
class AnswerValidatorPluginManager extends PluginManagerBase implements AnswerValidatorPluginManagerInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/ocha_ai/AnswerValidator',
      $namespaces,
      $module_handler,
      'Drupal\ocha_ai\Plugin\AnswerValidatorPluginInterface',
      'Drupal\ocha_ai\Attribute\OchaAiAnswerValidator'
    );

    $this->setCacheBackend($cache_backend, 'ocha_ai_answer_validator_plugins');
    $this->alterInfo('ocha_ai_answer_validator_info');
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'answer_validator';
  }

}

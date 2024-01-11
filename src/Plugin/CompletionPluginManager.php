<?php

namespace Drupal\ocha_ai_chat\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Plugin manager for the completion plugins.
 */
class CompletionPluginManager extends PluginManagerBase implements CompletionPluginManagerInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler
  ) {
    parent::__construct(
      'Plugin/ocha_ai_chat/Completion',
      $namespaces,
      $module_handler,
      'Drupal\ocha_ai_chat\Plugin\CompletionPluginInterface',
      'Drupal\ocha_ai_chat\Annotation\OchaAiChatCompletion'
    );

    $this->setCacheBackend($cache_backend, 'ocha_ai_chat_completion_plugins');
    $this->alterInfo('ocha_ai_chat_completion_info');
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginType(): string {
    return 'completion';
  }

}

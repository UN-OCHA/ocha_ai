<?php

namespace Drupal\ocha_ai_chat\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_ai_chat\Plugin\CompletionPluginManagerInterface;
use Drupal\ocha_ai_chat\Plugin\EmbeddingPluginManagerInterface;
use Drupal\ocha_ai_chat\Plugin\SourcePluginManagerInterface;
use Drupal\ocha_ai_chat\Plugin\TextExtractorPluginManagerInterface;
use Drupal\ocha_ai_chat\Plugin\TextSplitterPluginManagerInterface;
use Drupal\ocha_ai_chat\Plugin\VectorStorePluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Config form for the Ocha AI Chat module.
 */
class OchaAiChatConfigForm extends ConfigFormBase {

  /**
   * Completion plugin manager.
   *
   * @var \Drupal\ocha_ai_chat\Plugin\CompletionPluginManagerInterface
   */
  protected CompletionPluginManagerInterface $completionPluginManager;

  /**
   * Embedding plugin manager.
   *
   * @var \Drupal\ocha_ai_chat\Plugin\EmbeddingPluginManagerInterface
   */
  protected EmbeddingPluginManagerInterface $embeddingPluginManager;

  /**
   * Source plugin manager.
   *
   * @var \Drupal\ocha_ai_chat\Plugin\SourcePluginManagerInterface
   */
  protected SourcePluginManagerInterface $sourcePluginManager;

  /**
   * Text extractor plugin manager.
   *
   * @var \Drupal\ocha_ai_chat\Plugin\TextExtractorPluginManagerInterface
   */
  protected TextExtractorPluginManagerInterface $textExtractorPluginManager;

  /**
   * Text splitter plugin manager.
   *
   * @var \Drupal\ocha_ai_chat\Plugin\TextSplitterPluginManagerInterface
   */
  protected TextSplitterPluginManagerInterface $textSplitterPluginManager;

  /**
   * Vector store manager.
   *
   * @var \Drupal\ocha_ai_chat\Plugin\VectorStorePluginManagerInterface
   */
  protected VectorStorePluginManagerInterface $vectorStorePluginManager;

  /**
   * Store the plugins.
   *
   * @var \Drupal\ocha_ai_chat\Plugin\PluginInterface[]
   */
  protected array $plugins;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\ocha_ai_chat\Plugin\CompletionPluginManagerInterface $completion_plugin_manager
   *   The completion plugin manager.
   * @param \Drupal\ocha_ai_chat\Plugin\EmbeddingPluginManagerInterface $embedding_plugin_manager
   *   The embedding plugin manager.
   * @param \Drupal\ocha_ai_chat\Plugin\SourcePluginManagerInterface $source_plugin_manager
   *   The source plugin manager.
   * @param \Drupal\ocha_ai_chat\Plugin\TextExtractorPluginManagerInterface $text_extractor_plugin_manager
   *   The text extractor plugin manager.
   * @param \Drupal\ocha_ai_chat\Plugin\TextSplitterPluginManagerInterface $text_splitter_plugin_manager
   *   The text splitter plugin manager.
   * @param \Drupal\ocha_ai_chat\Plugin\VectorStorePluginManagerInterface $vector_store_plugin_manager
   *   The vector store plugin manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    CompletionPluginManagerInterface $completion_plugin_manager,
    EmbeddingPluginManagerInterface $embedding_plugin_manager,
    SourcePluginManagerInterface $source_plugin_manager,
    TextExtractorPluginManagerInterface $text_extractor_plugin_manager,
    TextSplitterPluginManagerInterface $text_splitter_plugin_manager,
    VectorStorePluginManagerInterface $vector_store_plugin_manager
  ) {
    parent::__construct($config_factory);

    $this->completionPluginManager = $completion_plugin_manager;
    $this->embeddingPluginManager = $embedding_plugin_manager;
    $this->sourcePluginManager = $source_plugin_manager;
    $this->textExtractorPluginManager = $text_extractor_plugin_manager;
    $this->textSplitterPluginManager = $text_splitter_plugin_manager;
    $this->vectorStorePluginManager = $vector_store_plugin_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('plugin.manager.ocha_ai_chat.completion'),
      $container->get('plugin.manager.ocha_ai_chat.embedding'),
      $container->get('plugin.manager.ocha_ai_chat.source'),
      $container->get('plugin.manager.ocha_ai_chat.text_extractor'),
      $container->get('plugin.manager.ocha_ai_chat.text_splitter'),
      $container->get('plugin.manager.ocha_ai_chat.vector_store')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $plugin_managers = [
      'completion' => [
        'label' => $this->t('Completion'),
        'manager' => $this->completionPluginManager,
      ],
      'embedding' => [
        'label' => $this->t('Embedding'),
        'manager' => $this->embeddingPluginManager,
      ],
      'source' => [
        'label' => $this->t('Document source'),
        'manager' => $this->sourcePluginManager,
      ],
      'text_extractor' => [
        'label' => $this->t('Text extractor'),
        'manager' => $this->textExtractorPluginManager,
      ],
      'text_splitter' => [
        'label' => $this->t('Text splitter'),
        'manager' => $this->textSplitterPluginManager,
      ],
      'vector_store' => [
        'label' => $this->t('Vector store'),
        'manager' => $this->vectorStorePluginManager,
      ],
    ];

    $form['plugins'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Plugins'),
      '#tree' => TRUE,
    ];

    // Add the forms for the plugins.
    foreach ($plugin_managers as $plugin_type => $info) {
      $form['plugins'][$plugin_type] = [
        '#type' => 'details',
        '#title' => $info['label'],
        '#open' => FALSE,
      ];

      foreach ($info['manager']->getAvailablePlugins() as $plugin_id => $plugin) {
        $form = $plugin->buildConfigurationForm($form, $form_state);
        $this->plugins[$plugin_id] = $plugin;
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    foreach ($this->plugins as $plugin) {
      $plugin->validateConfigurationForm($form, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Save the updated configuration.
    $config = $this->config('ocha_ai_chat.settings');
    $config->set('plugins', $form_state->getValue('plugins'));
    $config->save();

    // Store the updated config so that the plugins can work with casted values.
    $form_state->setValue('plugins', $config->get('plugins'));

    // Update the plugins.
    foreach ($this->plugins as $plugin) {
      $plugin->submitConfigurationForm($form, $form_state);
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ocha_ai_chat.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ocha_ai_chat_config_form';
  }

}

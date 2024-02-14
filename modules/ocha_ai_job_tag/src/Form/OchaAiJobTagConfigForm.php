<?php

namespace Drupal\ocha_ai_job_tag\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ocha_ai\Plugin\EmbeddingPluginManagerInterface;
use Drupal\ocha_ai\Plugin\TextExtractorPluginManagerInterface;
use Drupal\ocha_ai\Plugin\TextSplitterPluginManagerInterface;
use Drupal\ocha_ai\Plugin\VectorStorePluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Default settings form for the Ocha AI Chat module.
 */
class OchaAiJobTagConfigForm extends FormBase {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Embedding plugin manager.
   *
   * @var \Drupal\ocha_ai\Plugin\EmbeddingPluginManagerInterface
   */
  protected EmbeddingPluginManagerInterface $embeddingPluginManager;

  /**
   * Text extractor plugin manager.
   *
   * @var \Drupal\ocha_ai\Plugin\TextExtractorPluginManagerInterface
   */
  protected TextExtractorPluginManagerInterface $textExtractorPluginManager;

  /**
   * Text splitter plugin manager.
   *
   * @var \Drupal\ocha_ai\Plugin\TextSplitterPluginManagerInterface
   */
  protected TextSplitterPluginManagerInterface $textSplitterPluginManager;

  /**
   * Text vector store manager.
   *
   * @var \Drupal\ocha_ai\Plugin\VectorStorePluginManagerInterface
   */
  protected VectorStorePluginManagerInterface $vectorStorePluginManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\ocha_ai\Plugin\EmbeddingPluginManagerInterface $embedding_plugin_manager
   *   The embedding plugin manager.
   * @param \Drupal\ocha_ai\Plugin\TextExtractorPluginManagerInterface $text_extractor_plugin_manager
   *   The text extractor plugin manager.
   * @param \Drupal\ocha_ai\Plugin\TextSplitterPluginManagerInterface $text_splitter_plugin_manager
   *   The text splitter plugin manager.
   * @param \Drupal\ocha_ai\Plugin\VectorStorePluginManagerInterface $vector_store_plugin_manager
   *   The vector store plugin manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    EmbeddingPluginManagerInterface $embedding_plugin_manager,
    TextExtractorPluginManagerInterface $text_extractor_plugin_manager,
    TextSplitterPluginManagerInterface $text_splitter_plugin_manager,
    VectorStorePluginManagerInterface $vector_store_plugin_manager
  ) {
    $this->setConfigFactory($config_factory);
    $this->state = $state;
    $this->embeddingPluginManager = $embedding_plugin_manager;
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
      $container->get('state'),
      $container->get('plugin.manager.ocha_ai.embedding'),
      $container->get('plugin.manager.ocha_ai.text_extractor'),
      $container->get('plugin.manager.ocha_ai.text_splitter'),
      $container->get('plugin.manager.ocha_ai.vector_store')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $defaults = $this->getDefaultSettings();

    $form['defaults'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Defaults'),
      '#tree' => TRUE,
    ];

    $form['defaults']['form'] = [
      '#type' => 'details',
      '#title' => $this->t('Form settings'),
      '#open' => TRUE,
    ];
    $form['defaults']['form']['instructions'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Instructions'),
      '#format' => $defaults['form']['instructions']['format'] ?? 'text_editor_simple',
      '#default_value' => $defaults['form']['instructions']['value'] ?? NULL,
    ];

    $form['defaults']['plugins'] = [
      '#type' => 'details',
      '#title' => $this->t('Plugins'),
      '#open' => TRUE,
    ];

    $plugin_managers = [
      'embedding' => [
        'label' => $this->t('Embedding'),
        'manager' => $this->embeddingPluginManager,
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

    foreach ($plugin_managers as $plugin_type => $info) {
      $options = [];
      foreach ($info['manager']->getAvailablePlugins() as $plugin) {
        $options[$plugin->getPluginId()] = $plugin->getPluginLabel();
      }

      $form['defaults']['plugins'][$plugin_type] = [
        '#type' => 'fieldset',
        '#title' => $info['label'],
      ];

      $form['defaults']['plugins'][$plugin_type]['plugin_id'] = [
        '#type' => 'select',
        '#title' => $this->t('Plugin'),
        '#options' => $options,
        '#default_value' => $defaults['plugins'][$plugin_type]['plugin_id'] ?? NULL,
        '#required' => TRUE,
      ];
    }

    // For text extractors, we group the plugins by mimetype.
    foreach ($this->textExtractorPluginManager->getAvailablePlugins() as $plugin) {
      foreach ($plugin->getSupportedMimetypes() as $mimetype) {
        if (!isset($form['defaults']['plugins']['text_extractor'][$mimetype])) {
          $form['defaults']['plugins']['text_extractor'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Text extractor'),
          ];
          $form['defaults']['plugins']['text_extractor'][$mimetype] = [
            '#type' => 'fieldset',
            '#title' => $mimetype,
          ];
          $form['defaults']['plugins']['text_extractor'][$mimetype]['plugin_id'] = [
            '#type' => 'select',
            '#title' => 'Plugin',
            '#options' => [],
            '#default_value' => $defaults['plugins']['text_extractor'][$mimetype]['plugin_id'] ?? NULL,
            '#required' => TRUE,
          ];
        }
        $form['defaults']['plugins']['text_extractor'][$mimetype]['plugin_id']['#options'][$plugin->getPluginId()] = $plugin->getPluginLabel();
      }
    }

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save settings'),
      '#button_type' => 'primary',
    ];
    // By default, render the form using system-config-form.html.twig.
    $form['#theme'] = 'system_config_form';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $defaults = $form_state->getValue('defaults');

    $this->state->set('ocha_ai_job_tag.default_settings', $defaults);

    $this->messenger()->addStatus($this->t('Default settings saved.'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ocha_ai_job_tag_config_form';
  }

  /**
   * Get the default settings.
   *
   * @return array
   *   Default settings.
   */
  protected function getDefaultSettings(): array {
    $config_defaults = $this->configFactory()
      ->get('ocha_ai_job_tag.settings')
      ->get('defaults') ?? [];

    $state_defaults = $this->state
      ->get('ocha_ai_job_tag.default_settings', []);

    return array_replace_recursive($config_defaults, $state_defaults);
  }

}

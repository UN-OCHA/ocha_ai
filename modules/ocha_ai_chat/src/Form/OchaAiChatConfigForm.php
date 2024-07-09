<?php

namespace Drupal\ocha_ai_chat\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface;
use Drupal\ocha_ai\Plugin\EmbeddingPluginManagerInterface;
use Drupal\ocha_ai\Plugin\SourcePluginManagerInterface;
use Drupal\ocha_ai\Plugin\TextExtractorPluginManagerInterface;
use Drupal\ocha_ai\Plugin\TextSplitterPluginManagerInterface;
use Drupal\ocha_ai\Plugin\VectorStorePluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Default settings form for the Ocha AI Chat module.
 */
class OchaAiChatConfigForm extends FormBase {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Completion plugin manager.
   *
   * @var \Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface
   */
  protected CompletionPluginManagerInterface $completionPluginManager;

  /**
   * Embedding plugin manager.
   *
   * @var \Drupal\ocha_ai\Plugin\EmbeddingPluginManagerInterface
   */
  protected EmbeddingPluginManagerInterface $embeddingPluginManager;

  /**
   * Source plugin manager.
   *
   * @var \Drupal\ocha_ai\Plugin\SourcePluginManagerInterface
   */
  protected SourcePluginManagerInterface $sourcePluginManager;

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
   * @param \Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface $completion_plugin_manager
   *   The completion plugin manager.
   * @param \Drupal\ocha_ai\Plugin\EmbeddingPluginManagerInterface $embedding_plugin_manager
   *   The embedding plugin manager.
   * @param \Drupal\ocha_ai\Plugin\SourcePluginManagerInterface $source_plugin_manager
   *   The source plugin manager.
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
    CompletionPluginManagerInterface $completion_plugin_manager,
    EmbeddingPluginManagerInterface $embedding_plugin_manager,
    SourcePluginManagerInterface $source_plugin_manager,
    TextExtractorPluginManagerInterface $text_extractor_plugin_manager,
    TextSplitterPluginManagerInterface $text_splitter_plugin_manager,
    VectorStorePluginManagerInterface $vector_store_plugin_manager,
  ) {
    $this->setConfigFactory($config_factory);
    $this->state = $state;
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
      $container->get('state'),
      $container->get('plugin.manager.ocha_ai.completion'),
      $container->get('plugin.manager.ocha_ai.embedding'),
      $container->get('plugin.manager.ocha_ai.source'),
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
    $form['defaults']['form']['form_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Form title'),
      '#default_value' => $defaults['form']['form_title'] ?? NULL,
    ];
    $form['defaults']['form']['popup_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Popup title'),
      '#default_value' => $defaults['form']['popup_title'] ?? NULL,
    ];
    $form['defaults']['form']['instructions'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Instructions'),
      '#format' => $defaults['form']['instructions']['format'] ?? 'text_editor_simple',
      '#default_value' => $defaults['form']['instructions']['value'] ?? NULL,
    ];
    $form['defaults']['form']['feedback'] = [
      '#type' => 'select',
      '#title' => $this->t('Feedback mode'),
      '#default_value' => $defaults['form']['feedback'] ?? '',
      '#options' => [
        'detailed' => $this->t('Detailed feedback'),
        'simple' => $this->t('Simple feedback'),
        'both' => $this->t('Both feedback modes'),
      ],
      '#description' => $this->t('Simple feedback displays a thumbs up/down instead of offering open comment fields on each answer.'),
    ];
    $form['defaults']['form']['formatting'] = [
      '#type' => 'select',
      '#title' => $this->t('Formatting'),
      '#default_value' => $defaults['form']['formatting'] ?? '',
      '#options' => [
        'none' => $this->t('No special formatting'),
        'basic' => $this->t('Basic formatting'),
      ],
      '#description' => $this->t('Basic formatting means that the module takes the answer from the LLM and restores line breaks within HTML.'),
    ];

    $form['defaults']['plugins'] = [
      '#type' => 'details',
      '#title' => $this->t('Plugins'),
      '#open' => TRUE,
    ];

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

    // @todo This is for the demo. Review what to do with that.
    if (isset($form['defaults']['plugins']['source']['plugin_id']['#options']['reliefweb'])) {
      $form['defaults']['plugins']['source']['reliefweb'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('ReliefWeb demo settings'),
      ];
      $form['defaults']['plugins']['source']['reliefweb']['url'] = [
        '#type' => 'url',
        '#title' => $this->t('Document source URL'),
        '#description' => $this->t('Optional document source URL, if relevant.'),
        '#default_value' => $defaults['plugins']['source']['reliefweb']['url'] ?? NULL,
        '#maxlength' => 2048,
      ];
      $form['defaults']['plugins']['source']['reliefweb']['limit'] = [
        '#type' => 'number',
        '#title' => $this->t('Document limit'),
        '#description' => $this->t('Maximum number of source documents to retrieve.'),
        '#default_value' => $defaults['plugins']['source']['reliefweb']['limit'] ?? 1,
        '#min' => 1,
        // @todo retrieve that from the configuration.
        '#max' => 10,
      ];
      $form['defaults']['plugins']['source']['reliefweb']['editable'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Allow editing the source'),
        '#default_value' => !empty($defaults['plugins']['source']['reliefweb']['editable']),
      ];
      $form['defaults']['plugins']['source']['reliefweb']['open'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Always open the widget'),
        '#default_value' => !empty($defaults['plugins']['source']['reliefweb']['open']),
      ];
      $form['defaults']['plugins']['source']['reliefweb']['display'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Display the source widget'),
        '#default_value' => !empty($defaults['plugins']['source']['reliefweb']['display']),
      ];
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

    $this->state->set('ocha_ai_chat.default_settings', $defaults);

    $this->messenger()->addStatus($this->t('Default settings saved.'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ocha_ai_chat_config_form';
  }

  /**
   * Get the default settings.
   *
   * @return array
   *   Default settings.
   */
  protected function getDefaultSettings(): array {
    $config_defaults = $this->configFactory()
      ->get('ocha_ai_chat.settings')
      ->get('defaults') ?? [];

    $state_defaults = $this->state
      ->get('ocha_ai_chat.default_settings', []);

    return array_replace_recursive($config_defaults, $state_defaults);
  }

}

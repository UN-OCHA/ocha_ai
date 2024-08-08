<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\TextSplitter;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiTextSplitter;
use Drupal\ocha_ai\Plugin\TextSplitterPluginBase;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Split a text in groups of sentences.
 */
#[OchaAiTextSplitter(
  id: 'nlp_sentence',
  label: new TranslatableMarkup('NLP sentence'),
  description: new TranslatableMarkup('Split a text into sentences using a NLP service.')
)]
class NLPSentence extends TextSplitterPluginBase {

  /**
   * The HTTP client service.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Constructs a \Drupal\Component\Plugin\PluginBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    ClientInterface $http_client,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $config_factory,
      $logger_factory
    );

    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function splitText(string $text, ?int $length = NULL, ?int $overlap = NULL, string $language = 'en'): array {
    $endpoint = $this->getPluginSetting('endpoint');

    try {
      $response = $client = $this->httpClient->post($endpoint, [
        'json' => [
          'language' => $language,
          'text' => $text,
        ],
      ]);

      $data = $response->getBody()->getContents();
      $data = json_decode($data, TRUE, flags: \JSON_THROW_ON_ERROR);

      return $data['sentences'] ?? [];
    }
    catch (\Exception $exception) {
      $this->getLogger()->error(strtr('Error while splitting text into sentences: @error', [
        '@error' => $exception->getMessage(),
      ]));

    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $plugin_type = $this->getPluginType();
    $plugin_id = $this->getPluginId();
    $config = $this->getConfiguration() + $this->defaultConfiguration();

    $form['plugins'][$plugin_type][$plugin_id]['endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Endpoint'),
      '#description' => $this->t('Endpoint of the API.'),
      '#default_value' => $config['endpoint'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['length']['#access'] = FALSE;
    $form['plugins'][$plugin_type][$plugin_id]['overlap']['#access'] = FALSE;

    return $form;
  }

}

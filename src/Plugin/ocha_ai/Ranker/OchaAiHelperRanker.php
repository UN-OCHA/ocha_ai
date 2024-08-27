<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\Ranker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_ai\Attribute\OchaAiRanker;
use Drupal\ocha_ai\Plugin\RankerPluginBase;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Split a text in groups of sentences.
 */
#[OchaAiRanker(
  id: 'ocha_ai_helper_ranker',
  label: new TranslatableMarkup('OCHA AI Helper - Ranker'),
  description: new TranslatableMarkup('Rank texts using the python FlashRank library exposed via the OCHA AI Helper API.')
)]
class OchaAiHelperRanker extends RankerPluginBase {

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
  public function rankTexts(string $text, array $texts, string $language, ?int $limit = NULL): array {
    if (empty($text) || empty($texts)) {
      return [];
    }

    $limit = $limit ?? $this->getPluginSetting('limit') ?? count($texts);
    $endpoint = $this->getPluginSetting('endpoint');

    try {
      $response = $client = $this->httpClient->post($endpoint, [
        'json' => [
          'language' => $language,
          'text' => $text,
          'texts' => $texts,
          'limit' => $limit,
        ],
      ]);

      $data = $response->getBody()->getContents();
      $data = json_decode($data, TRUE, flags: \JSON_THROW_ON_ERROR);

      $texts = $data['texts'] ?? [];
    }
    catch (\Exception $exception) {
      // Simply log the error and return the original list of texts.
      $this->getLogger()->error(strtr('Error while ranking texts: @error', [
        '@error' => $exception->getMessage(),
      ]));
    }

    return array_slice($texts, 0, $limit);
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
      '#description' => $this->t('Endpoint of the OCHA AI Helper API.'),
      '#default_value' => $config['endpoint'] ?? NULL,
      '#required' => TRUE,
    ];

    return $form;
  }

}

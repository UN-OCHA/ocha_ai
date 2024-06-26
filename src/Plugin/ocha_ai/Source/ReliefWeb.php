<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\Source;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ocha_ai\Attribute\OchaAiSource;
use Drupal\ocha_ai\Helpers\LocalizationHelper;
use Drupal\ocha_ai\Plugin\SourcePluginBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Promise\Utils;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * ReliefWeb document source.
 */
#[OchaAiSource(
  id: 'reliefweb',
  label: new TranslatableMarkup('ReliefWeb'),
  description: new TranslatableMarkup('Use ReliefWeb as document source.')
)]
class ReliefWeb extends SourcePluginBase {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * ReliefWeb API URL.
   *
   * @var string
   */
  protected string $apiUrl;

  /**
   * ReliefWeb API converter URL.
   *
   * @var string
   */
  protected string $converterUrl;

  /**
   * ReliefWeb site URL.
   *
   * @var string
   */
  protected string $siteUrl;

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
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    ClientInterface $http_client,
    CacheBackendInterface $cache_backend,
    TimeInterface $time,
    RequestStack $request_stack,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $config_factory,
      $logger_factory,
      $http_client,
      $cache_backend,
      $time
    );

    $this->requestStack = $request_stack;
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
      $container->get('http_client'),
      $container->get('cache.ocha_ai_cache'),
      $container->get('datetime.time'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $plugin_type = $this->getPluginType();
    $plugin_id = $this->getPluginId();
    $config = $this->getConfiguration() + $this->defaultConfiguration();

    $form['plugins'][$plugin_type][$plugin_id]['api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API URL'),
      '#description' => $this->t('ReliefWeb API URL.'),
      '#default_value' => $config['api_url'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['converter_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API converter URL'),
      '#description' => $this->t('ReliefWeb search converter.'),
      '#default_value' => $config['converter_url'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['site_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ReliefWeb site URL'),
      '#description' => $this->t('ReliefWeb site URL.'),
      '#default_value' => $config['site_url'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['appname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API appname'),
      '#description' => $this->t('ReliefWeb API appname.'),
      '#default_value' => $config['appname'] ?? NULL,
      '#required' => TRUE,
    ];

    $form['plugins'][$plugin_type][$plugin_id]['cache_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cache enabled'),
      '#description' => $this->t('Flag to indicate if API results should be cached.'),
      '#default_value' => !empty($config['cache_enabled']),
    ];

    $form['plugins'][$plugin_type][$plugin_id]['cache_lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache lifetime'),
      '#description' => $this->t('Number of seconds to keep the results of the API in cache.'),
      '#default_value' => $config['cache_lifetime'] ?? NULL,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'appname' => 'ocha-ai-chat',
      'cache_enabled' => TRUE,
      'cache_lifetime' => 300,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceWidget(array $form, FormStateInterface $form_state, array $defaults): array {
    $plugin_defaults = $defaults['plugins']['source']['reliefweb'] ?? [];
    $plugin_defaults += ['url' => '', 'limit' => 0];

    $editable = !empty($plugin_defaults['editable']);
    $display = $editable || !empty($plugin_defaults['display']);
    $open = !empty($plugin_defaults['open']);

    $query = $this->requestStack->getCurrentRequest()?->query;

    $source_url = $form_state->getValue(['source', 'url'], $plugin_defaults['url']) ?: $query?->get('url') ?? '';
    $source_limit = $form_state->getValue(['source', 'limit'], $plugin_defaults['limit']) ?: $query?->get('limit') ?? 1;

    $site_url = $this->getSiteUrl();

    // Default to the main update river.
    if (empty($source_url)) {
      $source_url = $site_url . '/updates?view=reports';
    }
    else {
      $source_url = str_replace('https://reliefweb.int', $site_url, $source_url);
      // If the URL is not a river URL, assume it's a report URL and generate a
      // river URL to find the report with that URL alias.
      if ($this->checkReportUrl($source_url)) {
        $source_url = $site_url . '/updates?search=url_alias:"' . $source_url . '"';
      }
      elseif (!$this->checkRiverUrl($source_url, FALSE)) {
        $source_url = $site_url . '/updates?view=reports';
      }
    }

    // Source of documents.
    $form['source'] = [
      '#type' => $display ? 'details' : 'container',
      '#title' => $this->t('Source documents'),
      '#tree' => TRUE,
      '#open' => $open,
    ];

    if ($editable) {
      // ReliefWeb river URL.
      $form['source']['url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('ReliefWeb river URL'),
        '#description' => $this->t('Filtered list of ReliefWeb content from <a href="@site/updates?view=reports" target="_blank" rel="noreferrer noopener">@site/updates</a> to chat against.', [
          '@site' => $site_url,
        ]),
        '#default_value' => $source_url,
        '#required' => TRUE,
        '#maxlength' => 2048,
        // @todo this is for the demo.
        '#disabled' => !$editable,
      ];

      // Limit the number of allowed documents.
      $form['source']['limit'] = [
        '#type' => 'number',
        '#title' => $this->t('Document limit'),
        '#description' => $this->t('Maximum number of documents to chat against.'),
        '#default_value' => $source_limit,
        '#required' => TRUE,
        '#min' => 1,
        // @todo retrieve that from the configuration.
        '#max' => 10,
        // @todo this is for the demo.
        '#disabled' => !$editable,
      ];
    }
    else {
      $form['source']['url'] = [
        '#type' => 'hidden',
        '#value' => $source_url,
      ];
      $form['source']['limit'] = [
        '#type' => 'hidden',
        '#value' => $source_limit,
      ];

      if ($display) {
        $source_url = $this->prepareRiverUrl($source_url);
        $source_link = Link::fromTextAndUrl(rawurldecode($source_url), Url::fromUri($source_url, [
          'attributes' => [
            'rel' => 'noreferrer noopener',
            'target' => '_blank',
          ],
        ]))->toString();
        $form['source']['link']['#markup'] = $this->formatPlural(
          $source_limit,
          'The most recent document from @link',
          'The @count most recent documents from @link',
          ['@link' => $source_link]
        );
      }
    }

    // Display the source open if some required element of the source are
    // empty.
    if (!$open) {
      foreach (Element::children($form['source']) as $key) {
        $child = $form['source'][$key];
        if (!empty($child['#required']) && empty($child['#default_value'])) {
          $form['source']['#open'] = TRUE;
          break;
        }
      }
    }

    // Add the library to display a river with filters and search.
    if ($form['source']['url']['#type'] !== 'hidden') {
      // Settings for the javascript widget.
      $settings = [
        // @todo retrieve the current language.
        'language' => 'en',
        // @todo ensure it is unique.
        'baseId' => 'oaic-rw',
        'baseClass' => 'oaic-rw',
        'baseUrl' => $site_url,
        'riverUrl' => $source_url ?: $site_url . '/updates?view=reports',
        'apiUrl' => $this->buildApiUrl('reports', [], FALSE),
        // Base payload.
        'apiPayload' => [
          'preset' => 'minimal',
          'profile' => 'minimal',
          // @todo retrieve that from the limit form field in the plugin script.
          'limit' => $source_limit,
          'fields' => [
            'include' => [
              'id',
              'url_alias',
              'date.created',
              'date.original',
              'country.id',
              'country.iso3',
              'country.name',
              'country.shortname',
              'country.primary',
              'primary_country.name',
              'primary_country.shortname',
              'primary_country.iso3',
              'source.id',
              'source.name',
              'source.shortname',
              'language.id',
              'language.name',
              'language.code',
              'format.id',
              'format.name',
              'file',
              'title',
              'url_alias',
            ],
          ],
          // @todo change that if we find a model that handles multilingual.
          'filter' => [
            'field' => 'language.code',
            'value' => 'en',
          ],
          'sort' => [
            // @todo this is to be similar to https://reliefweb.int/updates but
            // maybe we want to sort by original publication date.
            'date.created:desc',
            'id:desc',
          ],
        ],
        'headingLevel' => 2,
        'openWrapper' => FALSE,
        'labels' => [
          'wrapperTitle' => $this->t('Select documents with filters and keywords'),
          'filterSectionTitle' => $this->t('Refine the list with filters'),
          'riverSectionTitle' => $this->t('List of documents'),
          'riverResults' => $this->t('Showing _start_ - _end_ of _total_ documents'),
          'riverNoResults' => $this->t('No results found. Please modify your search or filter selection.'),
          'searchHelp' => $this->t('Search help'),
          'filterSelectionTitle' => $this->t('Selected filters'),
          'filterListTitle' => $this->t('Add filters'),
          'searchLabel' => $this->t('Search with keywords'),
          'searchButton' => $this->t('Search'),
          'add' => $this->t('Add'),
          'select' => $this->t('Select'),
          'apply' => $this->t('Apply filters'),
          'cancel' => $this->t('Cancel'),
          'clear' => $this->t('Clear all'),
          'remove' => $this->t('Remove filter'),
          'formActions' => $this->t('Apply or clear filters'),
          'filterSelector' => $this->t('Add filter'),
          'fieldSelector' => $this->t('Select field'),
          'operatorSelector' => $this->t('Select operator'),
          'emptyOption' => $this->t('- Select -'),
          'dateFrom' => $this->t('From (YYYY/MM/DD)'),
          'dateTo' => $this->t('To (YYYY/MM/DD)'),
          'addFilter' => $this->t('Add filter'),
          'chooseDate' => $this->t('Choose date'),
          'changeDate' => $this->t('Change date, _date_'),
          'addFilterSuffix' => $this->t('(Country, organization...)'),
          'filter' => $this->t('_filter_ filter'),
          'switchOperator' => $this->t('Change operator. Selected operator is _operator_.'),
          'simplifiedFilter' => $this->t('Add _filter_'),
          'on' => $this->t('On'),
          'off' => $this->t('Off'),
          'advancedMode' => $this->t('Advanced mode'),
          'changeMode' => $this->t('Disabling the advanced mode will clear your selection. Please confirm.'),
          'dates' => [
            'on' => $this->t('on _start_'),
            'before' => $this->t('before _end_'),
            'after' => $this->t('after _start_'),
            'range' => $this->t('_start_ to _end_'),
          ],
          'operators' => [
            'all' => $this->t('ALL OF'),
            'any' => $this->t('ANY OF'),
            'with' => $this->t('WITH'),
            'without' => $this->t('WITHOUT'),
            'and-with' => $this->t('AND WITH'),
            'and-without' => $this->t('AND WITHOUT'),
            'or-with' => $this->t('OR WITH'),
            'or-without' => $this->t('OR WITHOUT'),
            'and' => $this->t('AND'),
            'or' => $this->t('OR'),
          ],
          'article' => [
            'more' => $this->t('_count_ more'),
            'format' => $this->t('Format'),
            'source' => $this->t('Source'),
            'sources' => $this->t('Sources'),
            'posted' => $this->t('Posted'),
            'published' => $this->t('Published'),
          ],
        ],
        'placeholders' => [
          'autocomplete' => $this->t('Type and select...'),
          'keyword' => $this->t('Enter a keyword'),
          'dateFrom' => $this->t('e.g. 2019/10/03'),
          'dateTo' => $this->t('e.g. 2019/11/07'),
        ],
        'announcements' => [
          'changeFilter' => $this->t('Filter changed to _name_.'),
          'addFilter' => $this->t('Added _field_ _label_. Your are now looking for documents _selection_. Go to the "Apply or clear filters" section to apply the filters and update the list.'),
          'removeFilter' => $this->t('Removed _field_ _label_. Your are now looking for documents _selection_. Go to the "Apply or clear filters" section to apply the filters and update the list.'),
          'removeFilterEmpty' => $this->t('Removed _field_ _label_. Your selection is now empty. Go to the "Apply or clear filters" section to apply the filters and update the list.'),
        ],
        'operators' => [
          [
            'label' => $this->t('To start the query'),
            'options' => [
              'with',
              'without',
            ],
          ],
          [
            'label' => $this->t('To use inside a group'),
            'options' => [
              'and',
              'or',
            ],
          ],
          [
            'label' => $this->t('To start a new group'),
            'options' => [
              'and-with',
              'and-without',
              'or-with',
              'or-without',
            ],
          ],
        ],
        // Convert to simple array so that we can preserve the order when
        // iterating over it in javascript.
        'filters' => array_values($this->getFilters()),
        'views' => [
          [
            'id' => 'all',
            'label' => $this->t('All Updates'),
          ],
          [
            'id' => 'headlines',
            'label' => $this->t('Headlines'),
            'filter' => [
              'field' => 'headline',
            ],
          ],
          [
            'id' => 'maps',
            'label' => $this->t('Maps / Infographics'),
            'filter' => [
              'field' => 'format',
              // Map, Infographic, Interactive.
              'value' => [12, 12570, 38974],
            ],
          ],
          [
            'id' => 'reports',
            'label' => $this->t('Reports only'),
            'filter' => [
              'field' => 'format',
              // Map, Infographic, Interactive.
              'value' => [12, 12570, 38974],
              'negate' => TRUE,
            ],
          ],
        ],
        'search' => '',
        'limit' => 10,
        'searchHelp' => 'https://reliefweb.int/search-help',
      ];

      // @todo create a form element that can be transformed into the widget and
      // is otherwise a URL field.
      $form['source']['url']['#attributes']['data-ocha-ai-chat-plugin-source-reliefweb'] = json_encode($settings);
      $form['source']['url']['#attached']['library'][] = 'ocha_ai/plugin.source.reliefweb';
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceData(array $form, FormStateInterface $form_state): array {
    return [
      'url' => $form_state->getValue(['source', 'url']),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function renderSourceData(array $data): array {
    if (isset($data['url'])) {
      $url = Url::fromUri($data['url'], [
        'attributes' => [
          'rel' => 'noreferrer noopener',
          'target' => '_blank',
        ],
      ]);
      return Link::fromTextAndUrl($this->t('Link'), $url)->toRenderable();
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getDocument(string $resource, int $id): array {
    $timeout = 5;
    $url = $this->getApiUrl() . '/' . trim($resource, '/') . '/' . $id;
    try {
      $response = $this->httpClient->get($url, [
        'query' => [
          'appname' => $this->getAppName(),
        ],
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
      ]);
    }
    catch (BadResponseException $exception) {
      // @todo handle timeouts and skip caching the result in that case?
      $this->getLogger()->error(strtr('Error @code while requesting the ReliefWeb API with @url: @exception', [
        '@code' => $exception->getResponse()?->getStatusCode(),
        '@url' => $url,
        '@exception' => $exception->getMessage(),
      ]));

      return [];
    }

    $body = (string) $response->getBody()?->getContents();
    if (!empty($body)) {
      try {
        // Decode the JSON response.
        $data = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);

        // Prepare the documents.
        return $this->parseApiData($resource, $data);
      }
      catch (\Exception $exception) {
        $this->getLogger()->error(strtr('Unable to decode ReliefWeb API data for @url', [
          '@url' => $url,
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDocuments(array $data, int $limit = 10): array {
    if (!isset($data['url'])) {
      return [];
    }
    $url = $data['url'];

    // 1. Retrieve the API resource and payload for the river URL.
    // 2. Retrieve the API data.
    // 3. Convert the API data.
    if (!$this->checkRiverUrl($url)) {
      return [];
    }

    $url = $this->prepareRiverUrl($url);
    $cache_id = $this->getCacheId($url, $limit);

    // Attempt to retrieve the cached data for the query.
    $documents = $this->getCachedDocuments($cache_id);
    if (isset($documents)) {
      return $documents;
    }

    // Get the API resource and payload from the river URL.
    $request = $this->getApiRequest($url);
    if (empty($request)) {
      $this->getLogger()->error('Unable to retrieve API request for the ReliefWeb river URL: @url.', [
        '@url' => $url,
      ]);
      return $this->cacheDocuments($cache_id, []);
    }

    // Extract the API resource from the API url.
    $resource = basename(parse_url($request['url'], \PHP_URL_PATH));

    // Adjust the payload with limit, order etc.
    $payload = $this->adjustApiPayload($request['payload'], $limit);

    // Get the data from the API.
    $data = $this->getApiData($resource, $payload);

    // Prepare the documents.
    $documents = $this->parseApiData($resource, $data);

    return $this->cacheDocuments($cache_id, $documents);
  }

  /**
   * {@inheritdoc}
   */
  public function describeDocuments(array $documents): array {
    $descriptions = [];
    foreach ($documents as $document) {
      $raw = $document['raw'];

      $description = '"' . $raw['title'] . '"';

      // Description in the form:
      //
      // "Type in Language on Country about Tags; published on Date by Sources"
      //
      // Note: for countries we don't use 'reliefweb_meta_description_term_list'
      // to ensure the primary country is the first in the list.
      //
      // Content format.
      $description .= $this->getDescriptionTermList($raw, [
        'format' => 1,
      ], "; a ", lowercase: TRUE);
      // Language.
      $description .= $this->getDescriptionTermList($raw, [
        'language' => -1,
      ], ' in ');
      // Countries.
      $description .= $this->getDescriptionTermList($raw, [
        'country' => 3,
      ], ' on ', '', '1 other country', '@count other countries');
      // Tags.
      $description .= $this->getDescriptionTermList($raw, [
        'theme' => 2,
        'disaster_type' => 2,
      ], ' about ', '', 'more', lowercase: TRUE);
      // Date.
      $description .= $this->getDescriptionDate($raw, 'original', '; published on ');
      // Sources.
      $description .= $this->getDescriptionTermList($raw, [
        'source' => 2,
      ], ' by ', '', '1 other organization', '@count other organizations');

      $descriptions[] = [
        'text' => $description,
        'source' => $document,
      ];
    }
    return $descriptions;
  }

  /**
   * Get the description component for the given term fields.
   *
   * Return a formatted list of terms like "about term1, term2, term3 and more".
   *
   * @param array $document
   *   Document data.
   * @param array $fields
   *   Array with term field as key and number of terms to use as value.
   * @param string $prefix
   *   Prefix to the formatted list of terms.
   * @param string $suffix
   *   Suffix to the formatted list of terms.
   * @param string $singular
   *   More text in case of a single extra term.
   * @param string $plural
   *   More text in case of multiple extra terms.
   * @param bool $lowercase
   *   Convert the terms to lower case.
   *
   * @return string
   *   Formatted list of terms.
   */
  protected function getDescriptionTermList(array $document, array $fields, string $prefix = ' ', string $suffix = '', string $singular = '', string $plural = '', bool $lowercase = FALSE): string {
    $list = [];
    $more = 0;

    // Retrieve and sort the terms for each field.
    foreach ($fields as $field => $count) {
      if (empty($document[$field])) {
        continue;
      }
      $data = $document[$field];

      // Retrieve and sort the terms.
      $names = [];
      $items = array_is_list($data) ? $data : [$data];
      foreach ($items as $item) {
        if (isset($item['id'], $item['name'])) {
          // We use the shortname to keep the description short.
          $name = $item['shortname'] ?? $item['name'];
          if ($lowercase) {
            $name = mb_strtolower($name);
          }
          // Ensure the primary term is first.
          $key = !empty($item['primary']) ? '_' . $name : $name;
          $names[$key] = $name;
        }
      }
      if (empty($names)) {
        continue;
      }
      elseif (count($names) > 1) {
        LocalizationHelper::collatedKsort($names);

        // Extract the subset of terms to use in the list.
        if ($count > 0) {
          foreach (array_slice($names, 0, $count) as $name) {
            $list[] = $name;
          }
        }
        else {
          $list = array_values($names);
        }
        // Keep track of the number of items non used in the list.
        $more += count($names) > 2 ? count($names) - 1 : 0;
      }
      else {
        $list[] = reset($names);
      }
    }

    if (!empty($list)) {
      if ($more > 0 && !empty($singular)) {
        if (!empty($plural) && $more > 1) {
          $list[] = str_replace('@count', (string) $more, $plural);
        }
        else {
          $list[] = str_replace('@count', (string) $more, $singular);
        }
      }

      // Format the list of terms in the form "term1, term2, term3 and more".
      $last = array_pop($list);
      $text = !empty($list) ? implode(', ', $list) . ' and ' . $last : $last;

      return $prefix . $text . $suffix;
    }
    return '';
  }

  /**
   * Get the description component for the given date field.
   *
   * @param array $document
   *   Document data.
   * @param string $field
   *   Date field.
   * @param string $prefix
   *   Prefix to prepend to the date.
   * @param string $suffix
   *   Suffix to append to the date.
   *
   * @return string
   *   Formatted date (with prefix if provided).
   */
  protected function getDescriptionDate(array $document, string $field, string $prefix = '', string $suffix = ''): string {
    if (!isset($document['date'][$field])) {
      return '';
    }
    $date = date_create($document['date'][$field])->format('j F Y');
    return !empty($date) ? $prefix . $date . $suffix : '';
  }

  /**
   * {@inheritdoc}
   */
  public function generateInlineReference(array $document): string {
    // Sources.
    $reference[] = implode(', ', array_filter(array_map(function ($source) {
      return $source['shortname'] ?? $source['name'] ?? '';
    }, $document['source'])));
    // Title.
    $reference[] = '"' . $document['title'] . '"';
    // Publication date.
    $reference[] = date_create($document['date']['original'])->format('j F Y');

    return implode(', ', $reference);
  }

  /**
   * Validate a ReliefWeb river URL.
   *
   * @param string $url
   *   River URL.
   * @param bool $log_missing
   *   If TRUE, adds a log if the url is empty.
   *
   * @return bool
   *   TRUE if the URL is valid.
   */
  protected function checkRiverUrl(string $url, bool $log_missing = TRUE): bool {
    if (empty($url)) {
      if ($log_missing) {
        $this->getLogger()->error('Missing ReliefWeb river URL.');
      }
      return FALSE;
    }

    // Ensure the river URL is for reports.
    // @todo Handle other rivers at some point?
    $site_url = preg_quote($this->getSiteUrl());
    if (preg_match('@^' . $site_url . '/updates([?#]|$)@', $url) !== 1) {
      $this->getLogger()->error('URL not a ReliefWeb updates river.');
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Validate a ReliefWeb report URL.
   *
   * @param string $url
   *   Report URL.
   *
   * @return bool
   *   TRUE if the URL is valid.
   */
  protected function checkReportUrl(string $url): bool {
    $site_url = preg_quote($this->getSiteUrl());
    return !empty($url) && preg_match('@^' . $site_url . '/report/[^/]+/[^/]+$@', $url) === 1;
  }

  /**
   * Prepare the River URL to pass to the converter.
   *
   * @param string $url
   *   River URL.
   *
   * @return string
   *   River URL.
   */
  protected function prepareRiverUrl(string $url): string {
    // Add a the "report_only" view parameter to exclude Maps, Infographics and
    // interactive content since they don't really contain content to chat with.
    $url = preg_replace('/([?&])view=[^?&]*/u', '$1view=reports', $url);
    if (strpos($url, 'view=reports') !== FALSE) {
      return $url;
    }
    else {
      return $url . (strpos($url, '?') !== FALSE ? '&' : '?') . 'view=reports';
    }
  }

  /**
   * Adjust the API payload.
   *
   * @param array $payload
   *   API payload.
   * @param int $limit
   *   Maximum number of documents.
   *
   * @return array
   *   Adjusted API payload.
   */
  protected function adjustApiPayload(array $payload, int $limit): array {
    $payload['limit'] = $limit;
    $payload['sort'] = ['date.created:desc', 'id:desc'];

    // @todo Review which fields could be useful for filtering (ex: country).
    $payload['fields']['include'] = [
      'id',
      'url',
      'url_alias',
      'title',
      'language',
      'body',
      'format',
      'country',
      'theme',
      'disaster',
      'disaster_type',
      'file.url',
      'file.mimetype',
      'source',
      'date',
    ];

    return $payload;
  }

  /**
   * Get the API request (url + payload) for the given ReliefWeb river URL.
   *
   * @param string $url
   *   ReliefWeb River URL.
   * @param int $timeout
   *   Request timeout.
   *
   * @return array
   *   ReliefWeb API request data (url + payload) as an associative array.
   */
  public function getApiRequest(string $url, int $timeout = 5): array {
    try {
      $response = $this->httpClient->get($this->getConverterUrl(), [
        'query' => [
          'appname' => $this->getAppName(),
          'search-url' => $url,
        ],
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
      ]);
    }
    catch (BadResponseException $exception) {
      // @todo handle timeouts and skip caching the result in that case?
      $this->getLogger()->error(strtr('Error @code while requesting the ReliefWeb API converter with @url: @exception', [
        '@code' => $exception->getResponse()?->getStatusCode(),
        '@url' => $url,
        '@exception' => $exception->getMessage(),
      ]));
      return [];
    }

    $body = (string) $response->getBody()?->getContents();
    if (!empty($body)) {
      try {
        // Decode the JSON response.
        $data = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);

        // Get the request data (API url + payload).
        return $data['output']['requests']['post'] ?? [];
      }
      catch (\Exception $exception) {
        $this->getLogger()->error(strtr('Unable to decode ReliefWeb API conversion data for @url', [
          '@url' => $url,
        ]));
      }
    }

    return [];
  }

  /**
   * Get the API data for the given resource and payload.
   *
   * @param string $resource
   *   API resource.
   * @param array $payload
   *   Request payload.
   * @param int $timeout
   *   Request timeout.
   *
   * @return array
   *   ReliefWeb API request data (url + payload) as an associative array.
   */
  public function getApiData(string $resource, array $payload, int $timeout = 5): array {
    $url = $this->getApiUrl() . '/' . trim($resource, '/');
    try {
      $response = $this->httpClient->post($url, [
        'query' => [
          'appname' => $this->getAppName(),
        ],
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
        'json' => $payload,
      ]);
    }
    catch (BadResponseException $exception) {
      // @todo handle timeouts and skip caching the result in that case?
      $this->getLogger()->error(strtr('Error @code while requesting the ReliefWeb API with @url: @exception', [
        '@code' => $exception->getResponse()?->getStatusCode(),
        '@url' => $url,
        '@exception' => $exception->getMessage(),
      ]));
      return [];
    }

    $body = (string) $response->getBody()?->getContents();
    if (!empty($body)) {
      try {
        // Decode the JSON response.
        $data = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);

        // Get the API data.
        return $data ?? [];
      }
      catch (\Exception $exception) {
        $this->getLogger()->error(strtr('Unable to decode ReliefWeb API data for @url', [
          '@url' => $url,
        ]));
      }
    }

    return [];
  }

  /**
   * Parse the API data and return documents.
   *
   * @param string $resource
   *   API resource.
   * @param array $data
   *   API data.
   *
   * @return array
   *   Associative array with the resource as key and associative arrays of
   *   documents with their IDs as keys and with id, title, url,
   *   source and contents (associative array with type, title, url and optional
   *   content property dependending on the type) as values.
   */
  protected function parseApiData(string $resource, array $data): array {
    if (empty($data['data'])) {
      return [];
    }

    $documents = [];
    foreach ($data['data'] as $items) {
      $fields = $items['fields'];

      // Use the UUID from the document canonical URL as ID to avoid collisions.
      $id = $this->getUuidFromUrl($fields['url']);

      // @todo add additional metadata like country, organization, notably
      // to generate references and to help filtering the content and possibly
      // extend the research to othe documents than the ones returned by
      // the API query for example as "related documents" etc.
      $document = [
        'id' => $id,
        'title' => $fields['title'],
        'url' => $fields['url'],
        'source' => $fields['source'],
        'date' => $fields['date'],
        'contents' => [],
        'raw' => $fields,
      ];

      $title = trim($fields['title']);
      $body = trim($fields['body'] ?? '');

      $document['contents'][] = [
        // @todo might not be so great to use the same id as the parent document
        // maybe use a prefix.
        'id' => $id,
        'url' => $fields['url_alias'] ?? $fields['url'],
        'type' => 'markdown',
        'content' => "# $title\n\n$body",
      ];

      // Attachments with their URL so they can be downloaded.
      foreach ($fields['file'] ?? [] as $file) {
        $document['contents'][] = [
          'id' => $this->getUuidFromUrl($file['url']),
          'url' => $file['url'],
          'type' => 'file',
          'mimetype' => $file['mimetype'],
        ];
      }

      $documents[$resource][$id] = $document;
    }
    return $documents;
  }

  /**
   * {@inheritdoc}
   */
  public function downloadFile(string $uri): mixed {
    // Create a temporary file to download to.
    $file = tmpfile();
    if ($file === FALSE) {
      $this->getLogger()->error('Unable to create temporary file.');
      return NULL;
    }

    try {
      // Try the original URI or the one from the production site.
      // This is to work with local/dev environments without the files.
      $uris = array_unique([
        $uri,
        preg_replace('@^https://[^/]+/@', 'https://reliefweb.int/', $uri),
      ]);
      foreach ($uris as $uri) {
        $source = @fopen($uri, 'r');
        if ($source !== FALSE) {
          break;
        }
      }
      if ($source === FALSE) {
        throw new \Exception(strtr('Unable to open the file @uri.', [
          'uri' => $uri,
        ]));
      }

      $copy = stream_copy_to_stream($source, $file);

      if ($copy === FALSE) {
        throw new \Exception(strtr('Unable to download the file @uri.', [
          'uri' => $uri,
        ]));
      }
      else {
        return $file;
      }
    }
    catch (\Exception $exception) {
      if ($file !== FALSE) {
        fclose($file);
      }
      throw $exception;
    }
    return NULL;
  }

  /**
   * Get the ReliefWeb API URL.
   *
   * @return string
   *   ReliefWeb API URL.
   */
  protected function getApiUrl(): string {
    if (!isset($this->apiUrl)) {
      // This is to simplify testing on local/dev environments when
      // the module is integrated into a ReliefWeb site instance.
      $api_url = $this->configFactory->get('reliefweb_api.settings')?->get('api_url');
      $api_url = $api_url ?? $this->getPluginSetting('api_url');
      if (empty($api_url) && !is_string($api_url)) {
        throw new \Exception('Missing or invalid ReliefWeb API URL');
      }
      $this->apiUrl = rtrim($api_url, '/');
    }
    return $this->apiUrl;
  }

  /**
   * Get the appname parameter to use in the API queries.
   *
   * @return string
   *   Appname.
   */
  protected function getAppName(): string {
    return $this->getPluginSetting('appname', 'ocha-ai-chat');
  }

  /**
   * Get the ReliefWeb API converter URL.
   *
   * @return string
   *   ReliefWeb API converter URL.
   */
  protected function getConverterUrl(): string {
    if (!isset($this->converterUrl)) {
      $converter_url = $this->getPluginSetting('converter_url');
      if (empty($converter_url) && !is_string($converter_url)) {
        throw new \Exception('Missing or invalid ReliefWeb API converter URL');
      }
      $this->converterUrl = rtrim($converter_url, '/');
    }
    return $this->converterUrl;
  }

  /**
   * Get the ReliefWeb Site URL.
   *
   * @return string
   *   The ReliefWeb site URL.
   */
  protected function getSiteUrl(): string {
    if (!isset($this->siteUrl)) {
      // This is to simplify testing on local/dev environments when
      // the module is integrated into a ReliefWeb site instance.
      $site_url = $this->configFactory->get('reliefweb_api.settings')?->get('website');
      $site_url = $site_url ?? $this->getPluginSetting('site_url', 'https://reliefweb.int');
      if (empty($site_url) && !is_string($site_url)) {
        throw new \Exception('Missing or invalid ReliefWeb site URL');
      }
      $this->siteUrl = rtrim($site_url, '/');
    }
    return $this->siteUrl;
  }

  /**
   * Get whether caching is enabled or not.
   *
   * @var bool
   *   TRUE if caching is enabled.
   */
  protected function isCacheEnabled(): bool {
    return $this->getPluginSetting('cache_enabled');
  }

  /**
   * Get the cache lifetime in seconds.
   *
   * @var int
   *   Cache lifetime.
   */
  protected function getCacheLifetime(): int {
    return $this->getPluginSetting('cache_lifetime');
  }

  /**
   * Get the cache lifetime in seconds.
   *
   * @var int
   *   Cache lifetime.
   */
  protected function getCacheExpiration(): int {
    return $this->time->getRequestTime() + $this->getCacheLifetime();
  }

  /**
   * Get the cache ID based on the given URL and limit.
   *
   * @param string $url
   *   URL.
   * @param int $limit
   *   Maximum number of documents.
   *
   * @return string
   *   Cache ID.
   */
  protected function getCacheId(string $url, int $limit): string {
    return 'reliefweb_api:url:' . $this->getUuidFromUrl($url) . ':' . $limit;
  }

  /**
   * Get cached documents.
   *
   * @param string $cache_id
   *   Cache ID.
   *
   * @return array|null
   *   Cached documents.
   */
  protected function getCachedDocuments(string $cache_id): ?array {
    if ($this->isCacheEnabled()) {
      $cache = $this->cacheBackend->get($cache_id);
      if (isset($cache->data)) {
        return $cache->data;
      }
    }
    return NULL;
  }

  /**
   * Cache documents.
   *
   * @param string $cache_id
   *   Cache ID.
   * @param array $documents
   *   Documents.
   *
   * @return array
   *   Cached documents.
   */
  protected function cacheDocuments(string $cache_id, array $documents): array {
    if ($this->isCacheEnabled()) {
      $tags = ['reliefweb:documents'];
      $this->cacheBackend->set($cache_id, $documents, $this->getCacheExpiration(), $tags);
    }
    return $documents;
  }

  /**
   * Get the UUID for to the URL.
   *
   * @param string $url
   *   URL.
   *
   * @return string
   *   UUID.
   */
  protected function getUuidFromUrl(string $url): string {
    return Uuid::v3(Uuid::fromString(Uuid::NAMESPACE_URL), $url)->toRfc4122();
  }

  /**
   * Convert the active filter selection to a human readable representation.
   *
   * @param array $selection
   *   Filter selection.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   Human readable filter selection.
   */
  protected function getHumanReadableSelection(array $selection): MarkupInterface {
    if (empty($selection)) {
      return '';
    }

    $operators = [
      'with' => $this->t('with'),
      'without' => $this->t('without'),
      'and-with' => $this->t('and with'),
      'and-without' => $this->t('and without'),
      'or-with' => $this->t('or with'),
      'or-without' => $this->t('or without'),
      'or' => $this->t('or'),
      'and' => $this->t('and'),
    ];

    $parts = [];
    foreach ($selection as $item) {
      $parts[] = $this->t('@operator @field: @label', [
        '@operator' => $operators[$item['operator']],
        '@field' => Markup::create(mb_strtolower($item['field'])),
        '@label' => $item['label'],
      ]);
    }

    return Markup::create(implode(' ', $parts));
  }

  /**
   * Get the API suggest URL for the resource.
   *
   * @param string $resource
   *   API resource.
   * @param array $parameters
   *   Extra parameters to pass to the API.
   *
   * @return string
   *   API suggest URL.
   */
  protected function getApiSuggestUrl(string $resource, array $parameters = []) {
    $parameters['sort'] = ['score:desc', 'name.collation_en:asc'];
    return $this->buildApiUrl($resource, $parameters);
  }

  /**
   * Build an API URL.
   *
   * This is mostly used to build a suggestion API URL.
   *
   * @param string $resource
   *   API resource.
   * @param array $parameters
   *   Query parameters.
   * @param bool $suggest_url
   *   TRUE to create a suggestion URL (for example to use in the UI filters).
   *
   * @return string
   *   API URL.
   */
  protected function buildApiUrl($resource, array $parameters = [], $suggest_url = TRUE): string {
    // We use a potentially different api url for the facets because it's
    // called from javascript. This is notably useful for dev/stage as the
    // reliefweb_api_url points to an interal url that cannot be used
    // client-side.
    $api_url = $this->getApiUrl();

    // Defaults.
    if (empty($parameters['appname'])) {
      $parameters['appname'] = $this->getAppName();
    }
    if (empty($parameters['preset'])) {
      $parameters['preset'] = $suggest_url ? 'suggest' : 'latest';
    }
    if (empty($parameters['profile'])) {
      $parameters['profile'] = $suggest_url ? 'suggest' : 'list';
    }

    // Remove the search query. We will add it back with `%QUERY` as value
    // later so that is can be replaced with the appropriate query string
    // in the river filter UI for example.
    if ($suggest_url) {
      unset($parameters['query']['value']);
    }

    // No need to return extra data.
    $parameters['slim'] = 1;

    // Generate the API URL parameters.
    $api_query = '?' . http_build_query($parameters);

    // '%QUERY' is added after the http_build_query so that it's not encoded
    // and appears as the query string value.
    if ($suggest_url) {
      $api_query .= '&' . rawurlencode('query[value]') . '=%QUERY';
    }

    return $api_url . '/' . $resource . $api_query;
  }

  /**
   * Load the references used by some filters.
   *
   * @param array $filters
   *   Associative array keyed by filter code and with the filter definition as
   *   value.
   *
   * @return array
   *   Associative with the filter codes as keys and the list of references for
   *   the filter as values.
   */
  protected function loadReferences(array $filters): array {
    $references = [];
    $queries = [];
    foreach ($filters as $code => $filter) {
      if ($filter['widget']['type'] === 'options') {
        $queries[$code] = [
          'resource' => $filter['widget']['resource'],
          'method' => 'GET',
          'payload' => [
            'fields' => [
              'exclude' => [
                'description',
              ],
            ],
          ],
        ];
      }
    }

    // Load all the references at once.
    // The cache is done in the requestMultiple() method.
    $results = $this->requestMultiple($queries);
    foreach ($results as $code => $data) {
      $filter = $filters[$code];

      $terms = [];
      foreach ($data['data'] ?? [] as $item) {
        $fields = $item['fields'] ?? [];
        if (isset($fields['id'], $fields['name'])) {
          $terms[$fields['id']] = $fields;
        }
      }

      // Exclude terms.
      if (!empty($filter['exclude'])) {
        $terms = array_diff_key($terms, array_flip($filter['exclude']));
      }
      // Include only some terms.
      elseif (!empty($filter['include'])) {
        $terms = array_intersect_key($terms, array_flip($filter['exclude']));
      }

      // Sort the terms by id or property like name.
      $sort = $filter[$code]['sort'] ?? 'name';
      if ($sort === 'id') {
        ksort($terms);
      }
      else {
        LocalizationHelper::collatedAsort($terms, $sort);
      }

      $references[$code] = $terms;
    }

    return $references;
  }

  /**
   * Perform parallel queries to the API.
   *
   * This only deals with POST requests.
   *
   * @param array $queries
   *   List of queries to perform in parallel. Each item is an associative
   *   array with the resource and the query payload.
   * @param bool $decode
   *   Whether to decode (json) the output or not.
   * @param int $timeout
   *   Request timeout.
   * @param bool $cache_enabled
   *   Whether to cache the queries or not.
   *
   * @return array
   *   Return array where each item contains the response to the corresponding
   *   query to the API.
   *
   * @see https://docs.guzzlephp.org/en/stable/quickstart.html#concurrent-requests
   */
  protected function requestMultiple(array $queries, bool $decode = TRUE, int $timeout = 5, bool $cache_enabled = TRUE): array {
    $results = [];
    $api_url = $this->getApiUrl();
    $appname = $this->getAppName();
    $cache_enabled = $cache_enabled && $this->isCacheEnabled();

    // Initialize the result array and retrieve the data for the cached queries.
    $cache_ids = [];
    foreach ($queries as $index => $query) {
      $method = $query['method'] ?? 'POST';
      $payload = $query['payload'] ?? [];

      // Sanitize the query payload.
      if (is_array($payload)) {
        $payload = $this->sanitizePayload($payload);
      }

      // Update the query payload.
      $queries[$index]['payload'] = $payload;

      // Attempt to get the data from the cache.
      $results[$index] = NULL;
      if ($cache_enabled) {
        // Retrieve the cache id for the query.
        $cache_id = $this->getCacheIdFromPayload($query['resource'], $method, $payload);
        $cache_ids[$index] = $cache_id;
        // Attempt to retrieve the cached data for the query.
        $cache = $this->cacheBackend->get($cache_id);
        if (isset($cache->data)) {
          $results[$index] = $cache->data;
        }
      }
    }

    // Prepare the requests.
    $promises = [];
    foreach ($queries as $index => $query) {
      // Skip queries with cached data.
      if (isset($results[$index])) {
        continue;
      }

      $method = $query['method'] ?? 'POST';
      $payload = $query['payload'] ?? [];

      $parameters = [
        'appname' => $appname,
      ];

      if ($method === 'GET') {
        // If the payload is a string, we assume, it is a query string.
        if (!is_array($payload)) {
          parse_str($payload, $parameters);
        }
        else {
          $parameters += $payload;
        }
      }
      else {
        // Encode the payload if it's not already.
        if (is_array($payload)) {
          $payload = json_encode($payload);

          // Skip the request if something is wrong with the payload.
          if ($payload === FALSE) {
            $results[$index] = NULL;
            $this->getLogger()->error('Could not encode payload when requesting @url: @payload', [
              '@url' => $api_url . '/' . $query['resource'],
              '@payload' => print_r($query['payload'], TRUE),
            ]);
            continue;
          }
        }
      }

      $url = $api_url . '/' . $query['resource'] . '?' . http_build_query($parameters);

      try {
        $options = [
          'timeout' => $timeout,
          'connect_timeout' => $timeout,
        ];
        if ($method === 'POST') {
          $options['headers'] = ['Content-Type: application/json'];
          $options['body'] = $payload;
        }
        $promises[$index] = $this->httpClient->requestAsync($method, $url, $options);
      }
      catch (\Exception $exception) {
        $this->getLogger()->error('Exception while querying @url: @exception', [
          '@url' => $api_url . '/' . $query['resource'],
          '@exception' => $exception->getMessage(),
        ]);
      }
    }

    // Execute the requests in parallel and retrieve and cache the response's
    // data.
    $promise_results = Utils::settle($promises)->wait();
    foreach ($promise_results as $index => $result) {
      $data = NULL;

      // Parse the response in case of success.
      if ($result['state'] === 'fulfilled') {
        $response = $result['value'];

        // Retrieve the raw response's data.
        if ($response->getStatusCode() === 200) {
          $data = (string) $response->getBody();
        }
        else {
          $this->getLogger()->notice('Unable to retrieve API data (code: @code) when requesting @url with payload @payload', [
            '@code' => $response->getStatusCode(),
            '@url' => $api_url . '/' . $queries[$index]['resource'],
            '@payload' => print_r($queries[$index]['payload'], TRUE),
          ]);
          $data = '';
        }
      }
      // Otherwise log the error.
      else {
        $this->getLogger()->notice('Unable to retrieve API data (code: @code) when requesting @url with payload @payload: @reason', [
          '@code' => $result['reason']->getCode(),
          '@url' => $api_url . '/' . $queries[$index]['resource'],
          '@payload' => print_r($queries[$index]['payload'], TRUE),
          '@reason' => $result['reason']->getMessage(),
        ]);
      }

      // Cache the data unless cache is disabled or there was an issue with the
      // request in which case $data is NULL.
      if (isset($cache, $cache_ids[$index], $queries[$index]['resource'])) {
        $tags = $this->getCacheTags($queries[$index]['resource']);
        $this->cacheBackend->set($cache_ids[$index], $data, $this->getCacheExpiration(), $tags);
      }

      $results[$index] = $data;
    }

    // We don't store the decoded data. This is to ensure that we can use the
    // same cached data regardless of whether to return JSON data or not.
    if ($decode) {
      foreach ($results as $index => $data) {
        if (!empty($data)) {
          // Decode the data, skip if invalid.
          try {
            $data = json_decode($data, TRUE, 512, JSON_THROW_ON_ERROR);
          }
          catch (\Exception $exception) {
            $data = NULL;
            $this->getLogger()->notice('Unable to decode ReliefWeb API data for request @url with payload @payload', [
              '@url' => $api_url . '/' . $queries[$index]['resource'],
              '@payload' => print_r($queries[$index]['payload'], TRUE),
            ]);
          }

          // Add the resulting data with same index as the query.
          $results[$index] = $data;
        }
      }
    }
    return $results;
  }

  /**
   * Sanitize and simplify an API query payload.
   *
   * @param array $payload
   *   API query payload.
   * @param bool $combine
   *   TRUE to optimize the filters by combining their values when possible.
   *
   * @return array
   *   Sanitized payload.
   */
  protected function sanitizePayload(array $payload, bool $combine = FALSE): array {
    if (empty($payload)) {
      return [];
    }
    // Remove search value and fields if the value is empty.
    if (empty($payload['query']['value'])) {
      unset($payload['query']);
    }
    // Optimize the filter if any.
    if (isset($payload['filter'])) {
      $filter = $this->optimizeFilter($payload['filter'], $combine);

      if (!empty($filter)) {
        $payload['filter'] = $filter;
      }
      else {
        unset($payload['filter']);
      }
    }
    // Optimize the facet filters if any.
    if (isset($payload['facets'])) {
      foreach ($payload['facets'] as $key => $facet) {
        if (isset($facet['filter'])) {
          $filter = $this->optimizeFilter($facet['filter'], $combine);
          if (!empty($filter)) {
            $payload['facets'][$key]['filter'] = $filter;
          }
          else {
            unset($payload['facets'][$key]['filter']);
          }
        }
      }
    }
    return $payload;
  }

  /**
   * Optimize a filter, removing uncessary nested conditions.
   *
   * @param array $filter
   *   Filter following the API syntax.
   * @param bool $combine
   *   TRUE to optimize even more the filter by combining values when possible.
   *
   * @return array|null
   *   Optimized filter.
   */
  protected function optimizeFilter(array $filter, bool $combine = FALSE): ?array {
    if (isset($filter['conditions'])) {
      if (isset($filter['operator'])) {
        $filter['operator'] = strtoupper($filter['operator']);
      }

      foreach ($filter['conditions'] as $key => $condition) {
        $condition = $this->optimizeFilter($condition, $combine);
        if (isset($condition)) {
          $filter['conditions'][$key] = $condition;
        }
        else {
          unset($filter['conditions'][$key]);
        }
      }
      // @todo eventually check if it's worthy to optimize by combining
      // filters with same field and same negation inside a conditional filter.
      if (!empty($filter['conditions'])) {
        if ($combine) {
          $filter['conditions'] = $this->combineConditions($filter['conditions'], $filter['operator'] ?? NULL);
        }
        if (count($filter['conditions']) === 1) {
          $condition = reset($filter['conditions']);
          if (!empty($filter['negate'])) {
            $condition['negate'] = TRUE;
          }
          $filter = $condition;
        }
      }
      else {
        $filter = NULL;
      }
    }
    return !empty($filter) ? $filter : NULL;
  }

  /**
   * Combine simple filter conditions to shorten the filters.
   *
   * @param array $conditions
   *   Filter conditions.
   * @param string $operator
   *   Operator to join the conditions.
   *
   * @return array
   *   Combined and simplied filter conditions.
   */
  protected function combineConditions(array $conditions, string $operator = 'AND'): array {
    $operator = $operator ?: 'AND';
    $filters = [];
    $result = [];

    foreach ($conditions as $condition) {
      $field = $condition['field'] ?? NULL;
      $value = $condition['value'] ?? NULL;
      $condition_operator = $condition['operator'] ?? NULL;

      // Nested conditions - flatten the condition's conditions.
      if (!empty($condition['conditions'])) {
        $condition['conditions'] = $this->combineConditions($condition['conditions'], $condition_operator);
        $result[] = $condition;
      }
      // Existence filter - keep as is.
      elseif (is_null($value)) {
        $result[] = $condition;
      }
      // Range filter - keep as is.
      elseif (is_array($value) && (isset($value['from']) || isset($value['to']))) {
        $result[] = $condition;
      }
      // Different operator or negated condition - keep as is.
      elseif ((isset($condition_operator) && $condition_operator !== $operator) || !empty($condition['negate'])) {
        $result[] = $condition;
      }
      elseif (is_array($value)) {
        foreach ($value as $item) {
          $filters[$field][] = $item;
        }
      }
      else {
        $filters[$field][] = $value;
      }
    }

    foreach ($filters as $field => $values) {
      $filter = [
        'field' => $field,
      ];

      $value = array_unique($values);
      if (count($value) === 1) {
        $filter['value'] = reset($value);
      }
      else {
        $filter['value'] = $value;
        $filter['operator'] = $operator;
      }
      $result[] = $filter;
    }
    return $result;
  }

  /**
   * Determine the cache id of an API query.
   *
   * @param string $resource
   *   API resource.
   * @param string $method
   *   Request method.
   * @param array|string|null $payload
   *   API payload.
   *
   * @return string
   *   Cache id.
   */
  protected function getCacheIdFromPayload(string $resource, $method, array $payload): string {
    $hash = hash('sha256', serialize($payload ?? ''));
    return 'reliefweb_api:query:' . $resource . ':' . $method . ':' . $hash;
  }

  /**
   * Determine the cache tags of an API query's resource.
   *
   * @param string $resource
   *   API resource.
   *
   * @return array
   *   Cache tags.
   */
  protected function getCacheTags($resource) {
    return [];
  }

  /**
   * Get the list of filters that can be used to refine the list of documents.
   */
  protected function getFilters(): array {
    $filters = [
      'PC' => [
        'name' => $this->t('Primary country'),
        'shortname' => TRUE,
        'type' => 'reference',
        'field' => 'primary_country.id',
        'widget' => [
          'type' => 'autocomplete',
          'label' => $this->t('Search for a primary country'),
          'resource' => 'countries',
        ],
        'operator' => 'AND',
      ],
      'C' => [
        'name' => $this->t('Country'),
        'shortname' => TRUE,
        'type' => 'reference',
        'field' => 'country.id',
        'widget' => [
          'type' => 'autocomplete',
          'label' => $this->t('Search for a country'),
          'resource' => 'countries',
        ],
        'operator' => 'AND',
      ],
      'S' => [
        'name' => $this->t('Organization'),
        'shortname' => TRUE,
        'type' => 'reference',
        'field' => 'source.id',
        'widget' => [
          'type' => 'autocomplete',
          'label' => $this->t('Search for an organization'),
          'resource' => 'sources',
          'parameters' => [
            'filter' => [
              'field' => 'content_type',
              'value' => 'report',
            ],
          ],
        ],
        'operator' => 'AND',
      ],
      'OT' => [
        'name' => $this->t('Organization type'),
        'type' => 'reference',
        'field' => 'source.type.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select an organization type'),
          'resource' => 'references/organization-types',
        ],
        'operator' => 'OR',
      ],
      'D' => [
        'name' => $this->t('Disaster'),
        'type' => 'reference',
        'field' => 'disaster.id',
        'widget' => [
          'type' => 'autocomplete',
          'label' => $this->t('Search for a disaster'),
          'resource' => 'disasters',
          'parameters' => [
            'sort' => 'date:desc',
          ],
        ],
        'operator' => 'OR',
      ],
      'DT' => [
        'name' => $this->t('Disaster type'),
        'type' => 'reference',
        'exclude' => [
          // Complex Emergency.
          41764,
        ],
        'field' => 'disaster_type.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a disaster type'),
          'resource' => 'references/disaster-types',
        ],
        'operator' => 'AND',
      ],
      'T' => [
        'name' => $this->t('Theme'),
        'type' => 'reference',
        'field' => 'theme.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a theme'),
          'resource' => 'references/themes',
        ],
        'operator' => 'AND',
      ],
      'F' => [
        'name' => $this->t('Content format'),
        'type' => 'reference',
        'field' => 'format.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a content format'),
          'resource' => 'references/content-formats',
        ],
        'operator' => 'OR',
      ],
      'L' => [
        'name' => $this->t('Language'),
        'type' => 'reference',
        'exclude' => [
          // Other.
          31996,
        ],
        'field' => 'language.id',
        'widget' => [
          'type' => 'options',
          'label' => $this->t('Select a language'),
          'resource' => 'references/languages',
        ],
        'operator' => 'OR',
      ],
      'DO' => [
        'name' => $this->t('Original publication date'),
        'type' => 'date',
        'field' => 'date.original',
        'widget' => [
          'type' => 'date',
          'label' => $this->t('Select original publication date'),
        ],
      ],
      'DA' => [
        'name' => $this->t('Posting date on ReliefWeb'),
        'type' => 'date',
        'field' => 'date.created',
        'widget' => [
          'type' => 'date',
          'label' => $this->t('Select posting date on ReliefWeb'),
        ],
      ],
    ];

    $references = $this->loadReferences($filters);
    foreach ($references as $code => $terms) {
      // Use the values as options to preserve order in the js script.
      $filters[$code]['widget']['options'] = array_values($terms);
    }

    foreach ($filters as $code => $filter) {
      $filters[$code]['code'] = $code;

      if ($filter['widget']['type'] === 'autocomplete') {
        $filters[$code]['widget']['url'] = $this->getApiSuggestUrl($filter['widget']['resource'], $filter['widget']['parameters'] ?? []);
      }
    }

    return $filters;
  }

}

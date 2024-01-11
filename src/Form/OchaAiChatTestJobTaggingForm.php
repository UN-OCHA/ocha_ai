<?php

namespace Drupal\ocha_ai_chat\Form;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Sts\StsClient;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ocha_ai_chat\Helpers\VectorHelper;
use Drupal\ocha_ai_chat\Services\OchaAiChat;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Test job tagging form for the Ocha AI Chat module.
 */
class OchaAiChatTestJobTaggingForm extends FormBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The OCHA AI chat service.
   *
   * @var Drupal\ocha_ai_chat\Services\OchaAiChat
   */
  protected OchaAiChat $ochaAiChat;

  /**
   * AWS Bedrock API client.
   *
   * @var \Aws\BedrockRuntime\BedrockRuntimeClient
   */
  protected BedrockRuntimeClient $apiClient;

  /**
   * Store the configuration of the plugins.
   *
   * @var array
   */
  protected array $pluginConfigs;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\ocha_ai_chat\Services\OchaAiChat $ocha_ai_chat
   *   The OCHA AI chat service.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    StateInterface $state,
    OchaAiChat $ocha_ai_chat
  ) {
    $this->currentUser = $current_user;
    $this->state = $state;
    $this->ochaAiChat = $ocha_ai_chat;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('state'),
      $container->get('ocha_ai_chat.chat')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?bool $popup = NULL): array {
    $all_results = $form_state->getValue('results');
    if (!empty($all_results)) {
      foreach ($all_results as $key => $item) {
        $form[$key] = $this->formatResults($item['title'], $item['results']);
      }
    }

    $form['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Text'),
      '#description' => $this->t('Enter the job title and its description.'),
      '#default_value' => $form_state->getValue('text', ''),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Classify'),
      '#name' => 'submit',
      '#description' => $this->t('It may take several minutes to get the answer.'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $text = trim($form_state->getValue('text', ''));

    if (!empty($text)) {
      $embeddings = $this->getEmbeddings($text, TRUE);

      $types = ['max_mean', 'max', 'mean', 'mean_with_cutoff'];

      $results = [
        'full' => $this->getSimilarTerms($embeddings, FALSE, $types),
        'average' => $this->getSimilarTerms($embeddings, TRUE, $types),
      ];

      $form_state->setValue('results', [
        'max_mean' => [
          'title' => $this->t('Max x Mean similarity of terms against all text chunks'),
          'results' => $results['full']['max_mean'],
        ],
        'max_mean_average' => [
          'title' => $this->t('Max x Mean similarity of terms against average of full text'),
          'results' => $results['average']['max_mean'],
        ],
        'max' => [
          'title' => $this->t('Max similarity of terms against all text chunks'),
          'results' => $results['full']['max'],
        ],
        'max_average' => [
          'title' => $this->t('Max similarity of terms against average of full text'),
          'results' => $results['average']['max'],
        ],
        'mean' => [
          'title' => $this->t('Mean similarity of terms against all text chunks'),
          'results' => $results['full']['mean'],
        ],
        'mean_average' => [
          'title' => $this->t('Mean similarity of terms against average of full text'),
          'results' => $results['average']['mean'],
        ],
        'mean_with_cutoff' => [
          'title' => $this->t('Mean similarity of terms against all text chunks, with cutoff'),
          'results' => $results['full']['mean_with_cutoff'],
        ],
        'mean_with_cutoff_average' => [
          'title' => $this->t('Mean similarity of terms against average of full text, with cutoff'),
          'results' => $results['average']['mean_with_cutoff'],
        ],
      ]);
    }

    // The client is not serializable so we need to unset before rebuilding the
    // form.
    unset($this->apiClient);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Format similarity comparison results.
   *
   * @param string $title
   *   Title of the container.
   * @param array $results
   *   Results of the similarity comparison.
   *
   * @return array
   *   Render array of the results.
   */
  protected function formatResults(string $title, array $results): array {
    $container = [
      '#type' => 'details',
      '#title' => $title,
      '#open' => FALSE,
    ];

    foreach ($results as $vocabulary => $terms) {
      $items = [];

      foreach ($terms as $term => $score) {
        $items[] = [
          '#type' => 'inline_template',
          '#template' => '<strong>{{ term }}</strong>: {{ score }}',
          '#context' => [
            'term' => $term,
            'score' => $score,
          ],
        ];
      }

      $container[$vocabulary] = [
        '#theme' => 'item_list',
        // @todo use a real title.
        '#title' => ucfirst($vocabulary),
        '#items' => $items,
        '#list_type' => 'ol',
        '#attributes' => [
          'class' => [
            'ocha-ai-chat-reference-list',
          ],
        ],
      ];
    }
    return $container;
  }

  /**
   * Get the most probable terms for the text.
   *
   * @param array $embeddings
   *   Embeddings of a text as associative arrays with text and embedding
   *   properties.
   * @param bool $average_embeddings
   *   If TRUE, generate the mean of the embeddings and compute the similarities
   *   against it.
   * @param array $types
   *   Types of formula to use to compare the similarity scores. One of "max",
   *   "mean" or "mean_with_cutoff" or "max_mean".
   *
   * @return array
   *   Associative array with vacobularies as keys and list of terms and their
   *   similarity score as values.
   */
  protected function getSimilarTerms(array $embeddings, bool $average_embeddings = FALSE, array $types = ['max']): array {
    if ($average_embeddings) {
      $embeddings = [
        [
          'embedding' => VectorHelper::mean(array_map(function ($item) {
            return $item['embedding'];
          }, $embeddings)),
        ],
      ];
    };

    $vocabularies = [];
    foreach ($this->getTermEmbeddings() as $vocabulary => $term_embeddings) {

      $results = [];
      foreach ($term_embeddings as $term => $term_embedding) {
        $similarities = [];

        foreach ($embeddings as $item) {
          $similarities[] = VectorHelper::cosineSimilarity($term_embedding, $item['embedding']);
        }

        foreach ($types as $type) {
          switch ($type) {
            case 'max':
              $results[$type][$term] = max($similarities);
              break;

            case 'mean':
              $results[$type][$term] = array_sum($similarities) / count($similarities);
              break;

            case 'mean_with_cutoff':
              $similarities = $this->filterSimilarities($similarities, 0.2);
              $results[$type][$term] = array_sum($similarities) / count($similarities);
              break;

            // Max x mean.
            default:
              // Max x mean.
              $results[$type][$term] = max($similarities) * array_sum($similarities) / count($similarities);
          }
        }
      }

      // Sort by similarity score descending.
      foreach ($types as $type) {
        arsort($results[$type]);
        $vocabularies[$type][$vocabulary] = $results[$type];
      }
    }
    return $vocabularies;
  }

  /**
   * Filter similarity scores with a cutoff value.
   *
   * @param array $similarities
   *   Similarity scores.
   * @param float|null $alpha
   *   Coefficient to calculate the cutoff value.
   *
   * @return array
   *   Filtered similarity scores.
   */
  protected function filterSimilarities(array $similarities, ?float $alpha = NULL): array {
    $cutoff = $this->getSimilarityScoreCutOff($similarities, 0.2);
    return array_filter($similarities, function ($score) use ($cutoff) {
      return $score >= $cutoff;
    });
  }

  /**
   * Get the embeddings for the taxonomy terms used to classify jobs.
   */
  protected function getTermEmbeddings(): array {
    $embeddings = $this->state->get('ocha_ai_chat_term_embeddings');
    if (empty($embeddings)) {
      $vocabularies = [
        'experience' => [
          '0-2 years' => '0-2 years: No prior experience or minimal experience.',
          '3-4 years' => '3-4 years: Minimum of 3 years of experience.',
          '5-9 years' => '5-9 years: Minimum of 5 years of experience.',
          '10+ years' => '10+ years: Minimum of 10 years of experience and above.',
        ],
        'career_category' => [
          'Administration/Finance' => 'Administration/Finance pertains to operational and financial activities related to running an organization; financial and operational management and oversight of assets and resources of an organization and its activities including budgeting, accounting, auditing; and general office support.',
          'Donor Relations/Grants Management' => 'Donor Relations/Grants Management covers activities related to fundraising, such as developing proposals for resource mobilization; managing and maintaining partnerships; monitoring and reporting on funds received in accordance with donor agreements.',
          'Human Resources' => 'Human Resources covers management of people within organizations, such as recruitment, hiring, retention, training and career development of employees for the successful operation of organizations.',
          'Information and Communications Technology' => 'Information and Communications Technology covers planning and managing ICT infrastructure to create, process, store, access and transmit all forms of information and electronic data, including audio-visual and telecommunication networks, software and application development, hardware and network architecture to meet the ICT needs of an organization.',
          'Information Management' => 'Information Management covers collecting, consolidating, analyzing, visualizing and/or sharing of data/information about crises/disasters including developing and maintaining standards, databases, systems, tools, platforms and products; Includes mapping/GIS functions.',
          'Logistics/Procurement' => 'Logistics/Procurement refers to the supply chain management covering planning and execution of guidance and policy of acquisitions, procurement, warehousing, asset/inventory management, transportation and freight planning of goods and resources. Includes maintenance and security of vehicles, physical assets, premises and staff.',
          'Advocacy/Communications' => 'Advocacy/Communications covers developing and implementing strategies to build support for agenda and policy by the public and decision-makers; delivering public information using various communication channels and methods such as campaigns, print, internet, social media, digital and audio/visual; building and facilitating strategic media contacts; includes translation services.',
          'Monitoring and Evaluation' => 'Monitoring and Evaluation covers collecting and assessing information on quality and progress of projects and programmes, designing methodologies and evaluation tools; recommending best practices and lessons learned to improve effectiveness and impact of activities through reports, training/workshop, etc.',
          'Program/Project Management' => 'Program/Project Management pertains to the management of all stages of a program/project cycle - planning, design development, proposal writing, implementation, reporting, program/project operations, quality assurance and compliance; overseeing staff and processes, and facilitating strategic contacts.',
        ],
        'theme' => [
          'Agriculture' => 'Agriculture includes fisheries; animal husbandry; and distribution of inputs such as seeds; aid activities helping to improve food security, agricultural and veterinary training.',
          'Camp Coordination and Camp Management' => 'Camp Management and Camp Coordination includes ensuring equitable access to services and protection for displaced persons living in communal settings, to improve their quality of life and dignity during displacement, and advocate for solutions while preparing them for life after displacement.',
          'Climate Change and Environment' => 'Climate Change and Environment includes humanitarian implications of climate change and/or environmental changes, such as increased vulnerability, migration or displacement.',
          'Contributions' => 'Contributions is defined as financial and in-kind humanitarian aid, as announced by the recipient (government, multilateral agencies, and NGOs), by donors (government, multilateral funding institutions, and pooled funds), or in media reporting.',
          'Coordination' => 'Coordination includes intra- and inter-cluster coordination, civil-military coordination, private sector partnership.',
          'Disaster Management' => 'Disaster Management includes policy and operational activities pertaining to the various stages of natural disasters at all levels, including early warning, disaster preparedness, prevention, risk reduction and mitigation.',
          'Education' => 'Education includes establishment of temporary learning spaces, provision of school supplies, and support to teachers and other school personnel, governmental entities. Post-conflict/disaster normalization support, including rehabilitation of schooling infrastructure.',
          'Food and Nutrition' => 'Food and Nutrition includes food security, food aid, school feeding, supplementary feeding, and therapeutic feeding.',
          'Gender' => 'Gender covers victims of emergencies or disasters and beneficiaries of humanitarian action irrespective of sex, focusing on issues affecting the genders differently. Also includes women as peacemakers and agents of change.',
          'Health' => 'Health includes emergency medical services, equipment and supplies; reproductive health; psycho-social support; mobile medical clinics; and disease control and surveillance.',
          'HIV/AIDS' => 'HIV/AIDS includes delivery of HIV/AIDS services in emergencies and humanitarian consequences of prolonged high prevalence.',
          'Humanitarian Financing' => 'Humanitarian Financing includes good humanitarian donorship and related policy framework and coordinated funding mechanisms such as pooled funds (Central Emergency Response Fund (CERF), Common Humanitarian Fund (CHF), Emergency Response Fund (ERF)). Accountability and transparency. Partnership.',
          'Logistics and Telecommunications' => 'Logistics and Telecommunications is defined as operational activities concerned with the supply, handling, storage and transportation of aid material and aid worker, and provision of ICT services and support to aid personnel serving in emergencies.',
          'Mine Action' => 'Mine Actions addresses problems of landmines, unexploded ordinances (UXO) and explosive remnants of war (ERW), including clearance, education, victim assistance and advocacy. (Sour: UN Mine Action Gateway)',
          'Peacekeeping and Peacebuilding' => 'Peacekeeping and Peacebuilding pertains to policies, programs, and associated efforts : resolve conflict; prevent conflict escalation; uphold law and order in a conflict zone; and restore social and political institutions disrupted by the conflict; such as ceasefire/peace negotiation; disarmament/demobilisation/reintegration; multilateral peacekeeping and political missions; and electoral support/observation missions.',
          'Protection and Human Rights' => 'Protection and Human Rights pertains to civilians, IDPs and refugees in the context of human rights violations, gender-based violence, international humanitarian, criminal and human rights law, including humanitarian access.',
          'Recovery and Reconstruction' => 'Recovery and Reconstruction includes replacement/restoration of assets, infrastructure and livelihoods lost, damaged or interrupted in natural disasters or conflict. The theme also covers Early Recovery which encompass specific interventions to help people move from dependence on humanitarian relief towards sustainable development.',
          'Safety and Security' => 'Safety and Security is defined as policies, measures and incidents relating to safety and security of humanitarian aid workers in the field. Safety and security of civilians is covered under "Protection and Human Rights."',
          'Shelter and Non-Food Items' => 'Shelter and Non-Food Items includes provision of shelter materials and non-food household item packages. The theme also covers Camp Coordination and Camp Management. Long-term/permanent reconstruction/rebuilding of housing is covered under "Recovery and Reconstruction."',
          'Water Sanitation Hygiene' => 'Water Sanitation Hygiene includes emergency provision of safe drinking water, hygiene and sanitation services, environmental sanitation and water supply, as well as hygiene promotion campaigns.',
        ],
      ];
      $embeddings = [];
      foreach ($vocabularies as $vocabulary => $terms) {
        $data = $this->getEmbeddings($terms, FALSE);
        foreach (array_keys($terms) as $index => $term) {
          $embeddings[$vocabulary][$term] = $data[$index]['embedding'];
        }
      }
      $this->state->get('ocha_ai_chat_term_embeddings', $embeddings);
    }
    return $embeddings;
  }

  /**
   * Get the country terms.
   *
   * @return array
   *   The country terms keyed by names with name (+ optional shortname) as
   *   values.
   */
  protected function getCountryTerms(): array {
    $countries = [
      [
        "name" => "Bonaire, Saint Eustatius and Saba (The Netherlands)",
        "shortname" => "Bonaire, Saint Eustatius and Saba (The Netherlands)",
      ],
      [
        "name" => "Saint Martin (France)",
        "shortname" => "Saint Martin (France)",
      ],
      [
        "name" => "Wallis and Futuna (France)",
        "shortname" => "Wallis and Futuna (France)",
      ],
      [
        "name" => "United Arab Emirates",
        "shortname" => "UAE",
      ],
      [
        "name" => "Timor-Leste",
        "shortname" => "Timor-Leste",
      ],
      [
        "name" => "Singapore",
        "shortname" => "Singapore",
      ],
      [
        "name" => "Saint Helena",
        "shortname" => "Saint Helena",
      ],
      [
        "name" => "R\u00e9union (France)",
        "shortname" => "R\u00e9union (France)",
      ],
      [
        "name" => "Qatar",
        "shortname" => "Qatar",
      ],
      [
        "name" => "Pitcairn Islands",
        "shortname" => "Pitcairn Islands",
      ],
      [
        "name" => "Luxembourg",
        "shortname" => "Luxembourg",
      ],
      [
        "name" => "Kuwait",
        "shortname" => "Kuwait",
      ],
      [
        "name" => "Jamaica",
        "shortname" => "Jamaica",
      ],
      [
        "name" => "Gibraltar",
        "shortname" => "Gibraltar",
      ],
      [
        "name" => "Gabon",
        "shortname" => "Gabon",
      ],
      [
        "name" => "Faroe Islands (Denmark)",
        "shortname" => "Faroe Islands (Denmark)",
      ],
      [
        "name" => "Easter Island (Chile)",
        "shortname" => "Easter Island (Chile)",
      ],
      [
        "name" => "C\u00f4te d'Ivoire",
        "shortname" => "C\u00f4te d'Ivoire",
      ],
      [
        "name" => "Christmas Island (Australia)",
        "shortname" => "Christmas Island (Australia)",
      ],
      [
        "name" => "China - Taiwan Province",
        "shortname" => "China - Taiwan Province",
      ],
      [
        "name" => "China - Hong Kong (Special Administrative Region)",
        "shortname" => "China - Hong Kong (Special Administrative Region)",
      ],
      [
        "name" => "Channel Islands",
        "shortname" => "Channel Islands",
      ],
      [
        "name" => "Cayman Islands",
        "shortname" => "Cayman Islands",
      ],
      [
        "name" => "Canary Islands (Spain)",
        "shortname" => "Canary Islands (Spain)",
      ],
      [
        "name" => "Brunei Darussalam",
        "shortname" => "Brunei Darussalam",
      ],
      [
        "name" => "British Virgin Islands",
        "shortname" => "British Virgin Islands",
      ],
      [
        "name" => "Comoros",
        "shortname" => "Comoros",
      ],
      [
        "name" => "Cabo Verde",
        "shortname" => "Cabo Verde",
      ],
      [
        "name" => "Botswana",
        "shortname" => "Botswana",
      ],
      [
        "name" => "Ghana",
        "shortname" => "Ghana",
      ],
      [
        "name" => "Mauritius",
        "shortname" => "Mauritius",
      ],
      [
        "name" => "Guyana",
        "shortname" => "Guyana",
      ],
      [
        "name" => "Greece",
        "shortname" => "Greece",
      ],
      [
        "name" => "Mongolia",
        "shortname" => "Mongolia",
      ],
      [
        "name" => "Japan",
        "shortname" => "Japan",
      ],
      [
        "name" => "Papua New Guinea",
        "shortname" => "PNG",
      ],
      [
        "name" => "New Zealand",
        "shortname" => "New Zealand",
      ],
      [
        "name" => "Micronesia (Federated States of)",
        "shortname" => "Micronesia",
      ],
      [
        "name" => "Cook Islands",
        "shortname" => "Cook Islands",
      ],
      [
        "name" => "Samoa",
        "shortname" => "Samoa",
      ],
      [
        "name" => "Tonga",
        "shortname" => "Tonga",
      ],
      [
        "name" => "Turkmenistan",
        "shortname" => "Turkmenistan",
      ],
      [
        "name" => "Kazakhstan",
        "shortname" => "Kazakhstan",
      ],
      [
        "name" => "Uzbekistan",
        "shortname" => "Uzbekistan",
      ],
      [
        "name" => "Argentina",
        "shortname" => "Argentina",
      ],
      [
        "name" => "Malaysia",
        "shortname" => "Malaysia",
      ],
      [
        "name" => "Chile",
        "shortname" => "Chile",
      ],
      [
        "name" => "Aruba (The Netherlands)",
        "shortname" => "Aruba (The Netherlands)",
      ],
      [
        "name" => "Cura\u00e7ao (The Netherlands)",
        "shortname" => "Cura\u00e7ao (The Netherlands)",
      ],
      [
        "name" => "Austria",
        "shortname" => "Austria",
      ],
      [
        "name" => "Cyprus",
        "shortname" => "Cyprus",
      ],
      [
        "name" => "Estonia",
        "shortname" => "Estonia",
      ],
      [
        "name" => "Ecuador",
        "shortname" => "Ecuador",
      ],
      [
        "name" => "Georgia",
        "shortname" => "Georgia",
      ],
      [
        "name" => "Latvia",
        "shortname" => "Latvia",
      ],
      [
        "name" => "Lithuania",
        "shortname" => "Lithuania",
      ],
      [
        "name" => "France",
        "shortname" => "France",
      ],
      [
        "name" => "Slovakia",
        "shortname" => "Slovakia",
      ],
      [
        "name" => "Netherlands",
        "shortname" => "Netherlands",
      ],
      [
        "name" => "Norway",
        "shortname" => "Norway",
      ],
      [
        "name" => "Russian Federation",
        "shortname" => "Russia",
      ],
      [
        "name" => "Serbia",
        "shortname" => "Serbia",
      ],
      [
        "name" => "Trinidad and Tobago",
        "shortname" => "Trinidad and Tobago",
      ],
      [
        "name" => "United Kingdom of Great Britain and Northern Ireland",
        "shortname" => "UK",
      ],
      [
        "name" => "United States of America",
        "shortname" => "USA",
      ],
      [
        "name" => "Guatemala",
        "shortname" => "Guatemala",
      ],
      [
        "name" => "Sweden",
        "shortname" => "Sweden",
      ],
      [
        "name" => "Djibouti",
        "shortname" => "Djibouti",
      ],
      [
        "name" => "Nepal",
        "shortname" => "Nepal",
      ],
      [
        "name" => "Paraguay",
        "shortname" => "Paraguay",
      ],
      [
        "name" => "Central African Republic",
        "shortname" => "CAR",
      ],
      [
        "name" => "Jordan",
        "shortname" => "Jordan",
      ],
      [
        "name" => "Sri Lanka",
        "shortname" => "Sri Lanka",
      ],
      [
        "name" => "Kenya",
        "shortname" => "Kenya",
      ],
      [
        "name" => "Democratic Republic of the Congo",
        "shortname" => "DR Congo",
      ],
      [
        "name" => "Philippines",
        "shortname" => "Philippines",
      ],
      [
        "name" => "Zimbabwe",
        "shortname" => "Zimbabwe",
      ],
      [
        "name" => "Madagascar",
        "shortname" => "Madagascar",
      ],
      [
        "name" => "Yemen",
        "shortname" => "Yemen",
      ],
      [
        "name" => "Iraq",
        "shortname" => "Iraq",
      ],
      [
        "name" => "Haiti",
        "shortname" => "Haiti",
      ],
      [
        "name" => "Niger",
        "shortname" => "Niger",
      ],
      [
        "name" => "T\u00fcrkiye",
        "shortname" => "T\u00fcrkiye",
      ],
      [
        "name" => "Brazil",
        "shortname" => "Brazil",
      ],
      [
        "name" => "Syrian Arab Republic",
        "shortname" => "Syria",
      ],
      [
        "name" => "Afghanistan",
        "shortname" => "Afghanistan",
      ],
      [
        "name" => "Somalia",
        "shortname" => "Somalia",
      ],
      [
        "name" => "occupied Palestinian territory",
        "shortname" => "oPt",
      ],
      [
        "name" => "Saint Barth\u00e9lemy (France)",
        "shortname" => "Saint Barth\u00e9lemy (France)",
      ],
      [
        "name" => "Turks and Caicos Islands",
        "shortname" => "Turks and Caicos Islands",
      ],
      [
        "name" => "Tokelau",
        "shortname" => "Tokelau",
      ],
      [
        "name" => "Saint Vincent and the Grenadines",
        "shortname" => "St. Vincent & Grenadines",
      ],
      [
        "name" => "Saint Kitts and Nevis",
        "shortname" => "Saint Kitts and Nevis",
      ],
      [
        "name" => "Puerto Rico (The United States of America)",
        "shortname" => "Puerto Rico (The United States of America)",
      ],
      [
        "name" => "Northern Mariana Islands (The United States of America)",
        "shortname" => "Northern Mariana Islands (The United States of America)",
      ],
      [
        "name" => "Norfolk Island (Australia)",
        "shortname" => "Norfolk Island (Australia)",
      ],
      [
        "name" => "New Caledonia (France)",
        "shortname" => "New Caledonia (France)",
      ],
      [
        "name" => "Montserrat",
        "shortname" => "Montserrat",
      ],
      [
        "name" => "Monaco",
        "shortname" => "Monaco",
      ],
      [
        "name" => "Mayotte (France)",
        "shortname" => "Mayotte (France)",
      ],
      [
        "name" => "Malta",
        "shortname" => "Malta",
      ],
      [
        "name" => "Maldives",
        "shortname" => "Maldives",
      ],
      [
        "name" => "Lao People's Democratic Republic (the)",
        "shortname" => "Lao PDR",
      ],
      [
        "name" => "Holy See",
        "shortname" => "Holy See",
      ],
      [
        "name" => "Guam",
        "shortname" => "Guam",
      ],
      [
        "name" => "Galapagos Islands (Ecuador)",
        "shortname" => "Galapagos Islands (Ecuador)",
      ],
      [
        "name" => "Falkland Islands (Malvinas)",
        "shortname" => "Falkland Islands (Malvinas)",
      ],
      [
        "name" => "Dominica",
        "shortname" => "Dominica",
      ],
      [
        "name" => "China - Macau (Special Administrative Region)",
        "shortname" => "China - Macau (Special Administrative Region)",
      ],
      [
        "name" => "Bahrain",
        "shortname" => "Bahrain",
      ],
      [
        "name" => "Azores Islands (Portugal)",
        "shortname" => "Azores Islands (Portugal)",
      ],
      [
        "name" => "Antigua and Barbuda",
        "shortname" => "Antigua and Barbuda",
      ],
      [
        "name" => "Anguilla",
        "shortname" => "Anguilla",
      ],
      [
        "name" => "Aland Islands (Finland)",
        "shortname" => "Aland Islands (Finland)",
      ],
      [
        "name" => "South Africa",
        "shortname" => "South Africa",
      ],
      [
        "name" => "Namibia",
        "shortname" => "Namibia",
      ],
      [
        "name" => "Cambodia",
        "shortname" => "Cambodia",
      ],
      [
        "name" => "Guinea-Bissau",
        "shortname" => "Guinea-Bissau",
      ],
      [
        "name" => "Eritrea",
        "shortname" => "Eritrea",
      ],
      [
        "name" => "Equatorial Guinea",
        "shortname" => "Equatorial Guinea",
      ],
      [
        "name" => "Lesotho",
        "shortname" => "Lesotho",
      ],
      [
        "name" => "Indonesia",
        "shortname" => "Indonesia",
      ],
      [
        "name" => "Fiji",
        "shortname" => "Fiji",
      ],
      [
        "name" => "Republic of Korea",
        "shortname" => "Republic of Korea",
      ],
      [
        "name" => "Nauru",
        "shortname" => "Nauru",
      ],
      [
        "name" => "Solomon Islands",
        "shortname" => "Solomon Islands",
      ],
      [
        "name" => "Tuvalu",
        "shortname" => "Tuvalu",
      ],
      [
        "name" => "Palau",
        "shortname" => "Palau",
      ],
      [
        "name" => "Albania",
        "shortname" => "Albania",
      ],
      [
        "name" => "Bolivia (Plurinational State of)",
        "shortname" => "Bolivia",
      ],
      [
        "name" => "Bosnia and Herzegovina",
        "shortname" => "Bosnia and Herzegovina",
      ],
      [
        "name" => "Belgium",
        "shortname" => "Belgium",
      ],
      [
        "name" => "Gambia",
        "shortname" => "Gambia",
      ],
      [
        "name" => "Germany",
        "shortname" => "Germany",
      ],
      [
        "name" => "Hungary",
        "shortname" => "Hungary",
      ],
      [
        "name" => "Italy",
        "shortname" => "Italy",
      ],
      [
        "name" => "Ireland",
        "shortname" => "Ireland",
      ],
      [
        "name" => "Poland",
        "shortname" => "Poland",
      ],
      [
        "name" => "the Republic of North Macedonia",
        "shortname" => "North Macedonia",
      ],
      [
        "name" => "Panama",
        "shortname" => "Panama",
      ],
      [
        "name" => "Romania",
        "shortname" => "Romania",
      ],
      [
        "name" => "Portugal",
        "shortname" => "Portugal",
      ],
      [
        "name" => "Switzerland",
        "shortname" => "Switzerland",
      ],
      [
        "name" => "Liechtenstein",
        "shortname" => "Liechtenstein",
      ],
      [
        "name" => "Rwanda",
        "shortname" => "Rwanda",
      ],
      [
        "name" => "Mauritania",
        "shortname" => "Mauritania",
      ],
      [
        "name" => "Armenia",
        "shortname" => "Armenia",
      ],
      [
        "name" => "Egypt",
        "shortname" => "Egypt",
      ],
      [
        "name" => "Costa Rica",
        "shortname" => "Costa Rica",
      ],
      [
        "name" => "Mexico",
        "shortname" => "Mexico",
      ],
      [
        "name" => "Vanuatu",
        "shortname" => "Vanuatu",
      ],
      [
        "name" => "Angola",
        "shortname" => "Angola",
      ],
      [
        "name" => "Dominican Republic",
        "shortname" => "Dominican Rep.",
      ],
      [
        "name" => "Zambia",
        "shortname" => "Zambia",
      ],
      [
        "name" => "El Salvador",
        "shortname" => "El Salvador",
      ],
      [
        "name" => "Senegal",
        "shortname" => "Senegal",
      ],
      [
        "name" => "Colombia",
        "shortname" => "Colombia",
      ],
      [
        "name" => "Mali",
        "shortname" => "Mali",
      ],
      [
        "name" => "Libya",
        "shortname" => "Libya",
      ],
      [
        "name" => "Cameroon",
        "shortname" => "Cameroon",
      ],
      [
        "name" => "Burundi",
        "shortname" => "Burundi",
      ],
      [
        "name" => "China",
        "shortname" => "China",
      ],
      [
        "name" => "Mozambique",
        "shortname" => "Mozambique",
      ],
      [
        "name" => "Ukraine",
        "shortname" => "Ukraine",
      ],
      [
        "name" => "South Sudan",
        "shortname" => "South Sudan",
      ],
      [
        "name" => "Lebanon",
        "shortname" => "Lebanon",
      ],
      [
        "name" => "Pakistan",
        "shortname" => "Pakistan",
      ],
      [
        "name" => "Sint Maarten (The Netherlands)",
        "shortname" => "Sint Maarten (The Netherlands)",
      ],
      [
        "name" => "Western Sahara",
        "shortname" => "Western Sahara",
      ],
      [
        "name" => "United States Virgin Islands",
        "shortname" => "United States Virgin Islands",
      ],
      [
        "name" => "Togo",
        "shortname" => "Togo",
      ],
      [
        "name" => "Svalbard and Jan Mayen Islands",
        "shortname" => "Svalbard and Jan Mayen Islands",
      ],
      [
        "name" => "Suriname",
        "shortname" => "Suriname",
      ],
      [
        "name" => "Slovenia",
        "shortname" => "Slovenia",
      ],
      [
        "name" => "Seychelles",
        "shortname" => "Seychelles",
      ],
      [
        "name" => "Sao Tome and Principe",
        "shortname" => "Sao Tome and Principe",
      ],
      [
        "name" => "San Marino",
        "shortname" => "San Marino",
      ],
      [
        "name" => "Saint Pierre and Miquelon (France)",
        "shortname" => "Saint Pierre and Miquelon (France)",
      ],
      [
        "name" => "Saint Lucia",
        "shortname" => "Saint Lucia",
      ],
      [
        "name" => "Oman",
        "shortname" => "Oman",
      ],
      [
        "name" => "Netherlands Antilles (The Netherlands)",
        "shortname" => "Netherlands Antilles (The Netherlands)",
      ],
      [
        "name" => "Martinique (France)",
        "shortname" => "Martinique (France)",
      ],
      [
        "name" => "Madeira (Portugal)",
        "shortname" => "Madeira (Portugal)",
      ],
      [
        "name" => "Isle of Man (The United Kingdom of Great Britain and Northern Ireland)",
        "shortname" => "Isle of Man (The United Kingdom of Great Britain and Northern Ireland)",
      ],
      [
        "name" => "Heard Island and McDonald Islands (Australia)",
        "shortname" => "Heard Island and McDonald Islands (Australia)",
      ],
      [
        "name" => "Guadeloupe (France)",
        "shortname" => "Guadeloupe (France)",
      ],
      [
        "name" => "Grenada",
        "shortname" => "Grenada",
      ],
      [
        "name" => "Greenland (Denmark)",
        "shortname" => "Greenland (Denmark)",
      ],
      [
        "name" => "French Polynesia (France)",
        "shortname" => "French Polynesia (France)",
      ],
      [
        "name" => "French Guiana (France)",
        "shortname" => "French Guiana (France)",
      ],
      [
        "name" => "Cocos (Keeling) Islands (Australia)",
        "shortname" => "Cocos (Keeling) Islands (Australia)",
      ],
      [
        "name" => "Bhutan",
        "shortname" => "Bhutan",
      ],
      [
        "name" => "Bermuda",
        "shortname" => "Bermuda",
      ],
      [
        "name" => "Barbados",
        "shortname" => "Barbados",
      ],
      [
        "name" => "Bahamas",
        "shortname" => "Bahamas",
      ],
      [
        "name" => "Andorra",
        "shortname" => "Andorra",
      ],
      [
        "name" => "American Samoa",
        "shortname" => "American Samoa",
      ],
      [
        "name" => "Thailand",
        "shortname" => "Thailand",
      ],
      [
        "name" => "Azerbaijan",
        "shortname" => "Azerbaijan",
      ],
      [
        "name" => "World",
        "shortname" => "World",
      ],
      [
        "name" => "United Republic of Tanzania",
        "shortname" => "Tanzania",
      ],
      [
        "name" => "Eswatini",
        "shortname" => "Eswatini",
      ],
      [
        "name" => "Benin",
        "shortname" => "Benin",
      ],
      [
        "name" => "Nicaragua",
        "shortname" => "Nicaragua",
      ],
      [
        "name" => "Kyrgyzstan",
        "shortname" => "Kyrgyzstan",
      ],
      [
        "name" => "India",
        "shortname" => "India",
      ],
      [
        "name" => "Australia",
        "shortname" => "Australia",
      ],
      [
        "name" => "Niue (New Zealand)",
        "shortname" => "Niue (New Zealand)",
      ],
      [
        "name" => "Marshall Islands",
        "shortname" => "Marshall Islands",
      ],
      [
        "name" => "Kiribati",
        "shortname" => "Kiribati",
      ],
      [
        "name" => "Tajikistan",
        "shortname" => "Tajikistan",
      ],
      [
        "name" => "Algeria",
        "shortname" => "Algeria",
      ],
      [
        "name" => "Belarus",
        "shortname" => "Belarus",
      ],
      [
        "name" => "Belize",
        "shortname" => "Belize",
      ],
      [
        "name" => "Bulgaria",
        "shortname" => "Bulgaria",
      ],
      [
        "name" => "Croatia",
        "shortname" => "Croatia",
      ],
      [
        "name" => "Canada",
        "shortname" => "Canada",
      ],
      [
        "name" => "Czechia",
        "shortname" => "Czechia",
      ],
      [
        "name" => "Cuba",
        "shortname" => "Cuba",
      ],
      [
        "name" => "Denmark",
        "shortname" => "Denmark",
      ],
      [
        "name" => "Peru",
        "shortname" => "Peru",
      ],
      [
        "name" => "Moldova",
        "shortname" => "Moldova",
      ],
      [
        "name" => "Montenegro",
        "shortname" => "Montenegro",
      ],
      [
        "name" => "Finland",
        "shortname" => "Finland",
      ],
      [
        "name" => "Tunisia",
        "shortname" => "Tunisia",
      ],
      [
        "name" => "Saudi Arabia",
        "shortname" => "Saudi Arabia",
      ],
      [
        "name" => "Iceland",
        "shortname" => "Iceland",
      ],
      [
        "name" => "Uruguay",
        "shortname" => "Uruguay",
      ],
      [
        "name" => "Spain",
        "shortname" => "Spain",
      ],
      [
        "name" => "Democratic People's Republic of Korea",
        "shortname" => "DPRK",
      ],
      [
        "name" => "Guinea",
        "shortname" => "Guinea",
      ],
      [
        "name" => "Viet Nam",
        "shortname" => "Viet Nam",
      ],
      [
        "name" => "Congo",
        "shortname" => "Congo",
      ],
      [
        "name" => "Sierra Leone",
        "shortname" => "Sierra Leone",
      ],
      [
        "name" => "Israel",
        "shortname" => "Israel",
      ],
      [
        "name" => "Honduras",
        "shortname" => "Honduras",
      ],
      [
        "name" => "Iran (Islamic Republic of)",
        "shortname" => "Iran",
      ],
      [
        "name" => "Venezuela (Bolivarian Republic of)",
        "shortname" => "Venezuela",
      ],
      [
        "name" => "Morocco",
        "shortname" => "Morocco",
      ],
      [
        "name" => "Burkina Faso",
        "shortname" => "Burkina Faso",
      ],
      [
        "name" => "Malawi",
        "shortname" => "Malawi",
      ],
      [
        "name" => "Liberia",
        "shortname" => "Liberia",
      ],
      [
        "name" => "Nigeria",
        "shortname" => "Nigeria",
      ],
      [
        "name" => "Myanmar",
        "shortname" => "Myanmar",
      ],
      [
        "name" => "Ethiopia",
        "shortname" => "Ethiopia",
      ],
      [
        "name" => "Uganda",
        "shortname" => "Uganda",
      ],
      [
        "name" => "Bangladesh",
        "shortname" => "Bangladesh",
      ],
      [
        "name" => "Chad",
        "shortname" => "Chad",
      ],
      [
        "name" => "Sudan",
        "shortname" => "Sudan",
      ],
    ];

    $terms = [];
    foreach ($countries as $country) {
      $name = $country['name'];
      if ($country['shortname'] !== $name) {
        $name .= ' (' . $country['shortname'] . ')';
      }
      $terms[$country['name']] = $name;
    }
    return $terms;
  }

  /**
   * Perform a request against the API to get the embeddings for the text.
   *
   * @param string|array $text
   *   Text.
   * @param bool $query
   *   TRUE if the text is a search query.
   *
   * @return array
   *   Embedding for the text.
   *
   * @throws \Exception
   *   Throw an exception if the generation of the embeddding fails.
   */
  protected function getEmbeddings(string|array $text, bool $query = FALSE): array {
    if (empty($text)) {
      return [];
    }

    $text_splitter_plugin = $this->ochaAiChat->getTextSplitterPluginManager()->getPlugin('token');

    // Split the text into chunks of around 300 tokens to be below the 512
    // recommend token limit.
    if (is_string($text)) {
      $texts = $text_splitter_plugin->splitText($text, 300, 0);
    }
    else {
      $texts = [];
      foreach ($text as $part) {
        $texts = array_merge($texts, $text_splitter_plugin->splitText($part, 300, 0));
      }
    }

    // Group the texts to reduce the number of API calls as they API can
    // generate embeddings for group of texts up to 2048 tokens in total.
    $token_count = 0;
    $group = [];
    $groups = [];
    foreach ($texts as $text) {
      // On average a token is worth 4 characters.
      $text_token_count = ceil(mb_strlen($text) / 4);
      // Use a smaller value than 2048 tokens to avoid cases where the number
      // of tokens is higher than the average above.
      if ($token_count + $text_token_count > 1500) {
        $groups[] = $group;
        $group = [];
        $token_count = 0;
      }
      $group[] = $text;
      $token_count += $text_token_count;
    }
    if (!empty($group)) {
      $groups[] = $group;
    }

    $embeddings = [];
    foreach ($groups as $group) {
      foreach (array_chunk($group, 96) as $chunk) {
        $data = $this->requestEmbeddings($chunk, $query);
        if (!empty($data['embeddings'])) {
          foreach ($data['texts'] as $index => $text) {
            $embeddings[] = [
              // @todo the texts are not really needed.
              'text' => $text,
              'embedding' => $data['embeddings'][$index],
            ];
          }
        }
      }
    }
    return $embeddings;
  }

  /**
   * Perform a request against the API to get the embeddings for the text.
   *
   * @param array $texts
   *   Texts to embed.
   * @param bool $query
   *   TRUE if the text is a search query.
   *
   * @return array
   *   Embedding for the text.
   *
   * @throws \Exception
   *   Throw an exception if the generation of the embeddding fails.
   */
  protected function requestEmbeddings(array $texts, bool $query = FALSE): array {
    if (empty($texts)) {
      return [];
    }

    $payload = [
      'accept' => 'application/json',
      'body' => json_encode([
        'texts' => array_values($texts),
        'input_type' => $query ? 'search_query' : 'search_document',
        'truncate' => 'NONE',
      ]),
      'contentType' => 'application/json',
      'modelId' => 'cohere.embed-multilingual-v3',
    ];

    try {
      /** @var \Aws\Result $response */
      $response = $this->getApiClient()->invokeModel($payload);
    }
    catch (\Exception $exception) {
      $this->getLogger($this->getFormId())->error(strtr('Embedding request failed with error: @error.', [
        '@error' => $exception->getMessage(),
      ]));
      throw $exception;
    }

    try {
      $data = json_decode($response->get('body')->getContents(), TRUE);
    }
    catch (\Exception $exception) {
      $this->getLogger($this->getFormId())->error('Unable to decode embedding response.');
      throw $exception;
    }

    return $data;
  }

  /**
   * Get the Bedrock API Client.
   *
   * @return \Aws\BedrockRuntime\BedrockRuntimeClient
   *   API Client.
   */
  protected function getApiClient(): BedrockRuntimeClient {
    if (!isset($this->apiClient)) {
      $region = $this->getPluginSetting('embedding', 'aws_bedrock', 'region');
      $role_arn = $this->getPluginSetting('embedding', 'aws_bedrock', 'role_arn', NULL, FALSE);

      if (!empty($role_arn)) {
        $stsClient = new StsClient([
          'region' => $region,
          'version' => 'latest',
        ]);

        $result = $stsClient->AssumeRole([
          'RoleArn' => $role_arn,
          'RoleSessionName' => 'aws-bedrock-ocha-ai-chat',
        ]);

        $credentials = [
          'key'    => $result['Credentials']['AccessKeyId'],
          'secret' => $result['Credentials']['SecretAccessKey'],
          'token'  => $result['Credentials']['SessionToken'],
        ];
      }
      else {
        $credentials = [
          'key' => $this->getPluginSetting('embedding', 'aws_bedrock', 'api_key'),
          'secret' => $this->getPluginSetting('embedding', 'aws_bedrock', 'api_secret'),
        ];
      }

      $options = [
        'credentials' => $credentials,
        'region'  => $region,
      ];

      $endpoint = $this->getPluginSetting('embedding', 'aws_bedrock', 'endpoint', NULL, FALSE);
      if (!empty($endpoint)) {
        $options['endpoint'] = $endpoint;
      }

      $this->apiClient = new BedrockRuntimeClient($options);
    }
    return $this->apiClient;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginSetting(string $plugin_type, string $plugin_id, string $key, mixed $default = NULL, bool $throw_if_null = TRUE): mixed {
    if (!isset($this->pluginConfigs[$plugin_type][$plugin_id])) {
      if (!isset($this->pluginConfigs[$plugin_type])) {
        $this->pluginConfigs[$plugin_type] = [];
      }
      $this->pluginConfigs[$plugin_type][$plugin_id] = $this->getPluginConfig($plugin_type, $plugin_id);
    }

    $setting = $this->pluginConfigs[$plugin_type][$plugin_id][$key] ?? $default;
    if (is_null($setting) && $throw_if_null) {
      throw new \Exception(strtr('Missing @key for plugin @id', [
        '@key' => $key,
        '@id' => $plugin_id,
      ]));
    }
    return $setting;
  }

  /**
   * Get the config factory.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The config factory.
   *
   * @todo check how to inject that service instead.
   */
  protected function getPluginConfig(string $plugin_type, string $plugin_id): array {
    return $this->configFactory()
      ->get('ocha_ai_chat.settings')
      ->get('plugins.' . $plugin_type . '.' . $plugin_id) ?? [];
  }

  /**
   * Calculate the similarity score cut-off.
   *
   * @param array $similarity_scores
   *   List of similarity scores as floats.
   * @param float|null $alpha
   *   Coefficient to adjust the cut-off value.
   *
   * @return float
   *   Similarity score cut-off.
   */
  protected function getSimilarityScoreCutOff(array $similarity_scores, ?float $alpha = NULL): float {
    $alpha = $alpha ?? (float) $this->getPluginSetting('vector_store', 'elasticsearch', 'cutoff_coefficient');

    $count = count($similarity_scores);
    if ($count === 0) {
      return 0.0;
    }
    elseif ($count === 1) {
      return reset($similarity_scores);
    }

    // Determine the average similarity score.
    $mean = array_sum($similarity_scores) / $count;

    // Determine the standard deviation.
    $sample = FALSE;
    $variance = 0.0;
    foreach ($similarity_scores as $value) {
      $variance += pow((float) $value - $mean, 2);
    };
    $deviation = (float) sqrt($variance / ($sample ? $count - 1 : $count));

    // Calculate the similarity cut-off.
    $cutoff = $mean + $alpha * $deviation;

    // The above formula can result in a cutoff higher than the highest
    // similarity. In that case we return the max similarity to avoid discarding
    // everything.
    return min($cutoff, max($similarity_scores));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ocha_ai_chat_test_job_tagging_form';
  }

}

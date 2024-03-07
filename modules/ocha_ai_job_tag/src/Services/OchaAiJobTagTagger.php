<?php

namespace Drupal\ocha_ai_job_tag\Services;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ocha_ai\Helpers\VectorHelper;
use Drupal\ocha_ai\Plugin\CompletionPluginManagerInterface;
use Drupal\ocha_ai\Plugin\EmbeddingPluginManagerInterface;
use Drupal\ocha_ai\Plugin\SourcePluginManagerInterface;
use Drupal\ocha_ai\Plugin\TextExtractorPluginManagerInterface;
use Drupal\ocha_ai\Plugin\TextSplitterPluginManagerInterface;
use Drupal\ocha_ai\Plugin\VectorStorePluginManagerInterface;
use Drupal\ocha_ai_chat\Services\OchaAiChat;

/**
 * OCHA AI Chat service.
 */
class OchaAiJobTagTagger extends OchaAiChat {

  /**
   * Constructor.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
    AccountProxyInterface $current_user,
    Connection $database,
    TimeInterface $time,
    CompletionPluginManagerInterface $completion_plugin_manager,
    EmbeddingPluginManagerInterface $embedding_plugin_manager,
    SourcePluginManagerInterface $source_plugin_manager,
    TextExtractorPluginManagerInterface $text_extractor_plugin_manager,
    TextSplitterPluginManagerInterface $text_splitter_plugin_manager,
    VectorStorePluginManagerInterface $vector_store_plugin_manager
  ) {
    $this->config = $config_factory->get('ocha_ai_job_tag.settings');
    $this->logger = $logger_factory->get('ocha_ai_job_tag');
    $this->state = $state;
    $this->currentUser = $current_user;
    $this->database = $database;
    $this->time = $time;
    $this->completionPluginManager = $completion_plugin_manager;
    $this->embeddingPluginManager = $embedding_plugin_manager;
    $this->sourcePluginManager = $source_plugin_manager;
    $this->textExtractorPluginManager = $text_extractor_plugin_manager;
    $this->textSplitterPluginManager = $text_splitter_plugin_manager;
    $this->vectorStorePluginManager = $vector_store_plugin_manager;
  }

  /**
   * Get the default settings for the OCHA AI Chat.
   *
   * @return array
   *   The OCHA AI Chat settings.
   */
  public function getSettings(): array {
    if (!isset($this->settings)) {
      $config_defaults = $this->config->get('defaults') ?? [];

      $state_defaults = $this->state->get('ocha_ai_job_tag.default_settings', []);

      $this->settings = array_replace_recursive($config_defaults, $state_defaults);
    }
    return $this->settings;
  }

  /**
   * Tag a job given a title and description.
   */
  public function tag(string $title, string $description): array {
    $text = $title . "\n\n" . $description;

    $embeddings = $this->getEmbeddings($text, TRUE);

    $types = ['max_mean', 'max', 'mean', 'mean_with_cutoff'];

    $results = [
      'full' => $this->getSimilarTerms($embeddings, FALSE, $types),
      'average' => $this->getSimilarTerms($embeddings, TRUE, $types),
    ];

    return $results;
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

    $text_splitter_plugin = $this->getTextSplitterPlugin();

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
        $embeddings[] = $this->requestEmbeddings($chunk, $query);
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

    $data = $this->getEmbeddingPlugin()->generateEmbeddings($texts, $query);

    return $data;
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
    $embeddings = reset($embeddings);
    if ($average_embeddings) {
      $embeddings = [
        VectorHelper::mean($embeddings),
      ];
    };

    $vocabularies = [];
    foreach ($this->getTermEmbeddings() as $vocabulary => $term_embeddings) {

      $results = [];
      foreach ($term_embeddings as $term => $term_embedding) {
        $similarities = [];

        foreach ($embeddings as $item) {
          $similarities[] = VectorHelper::cosineSimilarity($term_embedding, $item);
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
   * Get the embeddings for the taxonomy terms used to classify jobs.
   */
  protected function getTermEmbeddings(): array {
    $embeddings = $this->state->get('ocha_ai_job_tag_term_embeddings');
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
        // Keep first one.
        $data = $this->getEmbeddings($terms, FALSE);
        $data = reset($data);

        foreach (array_keys($terms) as $index => $term) {
          $embeddings[$vocabulary][$term] = $data[$index];
        }
      }

      $this->state->get('ocha_ai_job_tag_term_embeddings', $embeddings);
    }
    return $embeddings;
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
  protected function getSimilarityScoreCutOff(array $similarity_scores, ?float $alpha = 0.5): float {
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

}

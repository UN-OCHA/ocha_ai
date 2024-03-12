<?php

namespace Drupal\ocha_ai_job_tag\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\ocha_ai_job_tag\Services\OchaAiJobTagTagger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extract text from a document file.
 *
 * @QueueWorker(
 *   id = "ocha_ai_job_tag_tagger",
 *   title = @Translation("Use AI to summarize"),
 *   cron = {"time" = 30}
 * )
 */
class OchaAiJobTagTaggerWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The job tagger.
   *
   * @var \Drupal\ocha_ai_job_tag\Services\OchaAiJobTagTagger
   */
  protected $jobTagger;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, OchaAiJobTagTagger $job_tagger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->jobTagger = $job_tagger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('ocha_ai_job_tag.tagger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $nid = $data->nid;

    if (empty($nid)) {
      return;
    }

    /** @var \Drupal\node\Entity\Node $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node || $node->bundle() !== 'job') {
      return;
    }

    if ($node->body->isEmpty()) {
      return;
    }

    $data = $this->jobTagger->tag($node->getTitle(), $node->get('body')->value);

    if (empty($data)) {
      return;
    }

    if (!isset($data['average']['mean_with_cutoff'])) {
      return;
    }

    // Use average mean with cutoff.
    $data = $data['average']['mean_with_cutoff'];

    $message = [];
    $needs_save = FALSE;
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    if (isset($data['experience']) && $node->field_job_experience->isEmpty()) {
      $message[] = '**Experience**' . "\n\n- " . implode("\n- ", $this->createRevisionLogLine($data['experience'])) . "\n\n";
      $first = reset(array_keys($data['experience']));
      $terms = $storage->loadByProperties([
        'name' => $first,
        'vid' => 'job_experience',
      ]);
      $term = reset($terms);

      $node->set('field_job_experience', $term);
      $needs_save = TRUE;
    }

    if (isset($data['career_category']) && $node->field_career_categories->isEmpty()) {
      $message[] = '**Career category**' . "\n\n- " . implode("\n- ", $this->createRevisionLogLine($data['career_category'])) . "\n\n";
      $first = reset(array_keys($data['career_category']));
      $terms = $storage->loadByProperties([
        'name' => $first,
        'vid' => 'career_category',
      ]);
      $term = reset($terms);

      $node->set('field_career_categories', $term);
      $needs_save = TRUE;
    }

    if (isset($data['theme']) && $node->field_theme->isEmpty()) {
      $message[] = '**Theme(s)**' . "\n\n- " . implode("\n- ", $this->createRevisionLogLine($data['theme'])) . "\n\n";

      $themes = $this->getTop3Themes($data['theme']);
      $result = [];

      foreach ($themes as $theme) {
        $terms = $storage->loadByProperties([
          'name' => $theme,
          'vid' => 'theme',
        ]);
        $result[] = reset($terms);
      }

      $node->set('field_theme', $result);
      $needs_save = TRUE;
    }

    if ($needs_save) {
      if ($node->hasField('reliefweb_job_tagger_info')) {
        $node->set('reliefweb_job_tagger_info', [
          'value' => implode("\n\n", $message),
          'format' => 'markdown',
        ]);
      }
      $node->save();
    }
  }

  /**
   * Convert raw data to human-readable string.
   */
  protected function createRevisionLogLine(array $data) : array {
    // Max 5 items.
    $data = array_slice($data, 0, 5);

    return array_map(
      function ($v, $k) {
        return $k . ': ' . floor(100 * $v) . '%';
      },
      $data,
      array_keys($data)
    );
  }

  /**
   * Get top 3 relevant themes.
   */
  protected function getTop3Themes($themes) {
    $result = [];

    $themes = array_slice($themes, 0, 3, TRUE);

    foreach ($themes as $theme => $score) {
      // Add first one regardless of score.
      if (empty($result)) {
        $result[] = $theme;
        continue;
      }

      if ($score > .25) {
        $result[] = $theme;
      }

    }

    return $result;
  }

}

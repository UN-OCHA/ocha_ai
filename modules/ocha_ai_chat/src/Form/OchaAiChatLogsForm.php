<?php

namespace Drupal\ocha_ai_chat\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\ocha_ai_chat\Services\OchaAiChat;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Logs form for the Ocha AI Chat module.
 */
class OchaAiChatLogsForm extends FormBase {


  /**
   * The database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The OCHA AI chat service.
   *
   * @var \Drupal\ocha_ai_chat\Services\OchaAiChat
   */
  protected OchaAiChat $ochaAiChat;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system.
   * @param \Drupal\ocha_ai_chat\Services\OchaAiChat $ocha_ai_chat
   *   The OCHA AI chat service.
   */
  public function __construct(
    Connection $database,
    AccountProxyInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    OchaAiChat $ocha_ai_chat,
  ) {
    $this->database = $database;
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->ochaAiChat = $ocha_ai_chat;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('ocha_ai_chat.chat')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Expose the filters.
    $form['#method'] = 'GET';
    $form['#cache'] = ['max-age' => 0];

    $form_state->setMethod('GET');
    $form_state->disableCache();

    $question = $this->getRequest()->get('question') ?? '';
    $answer = $this->getRequest()->get('answer') ?? '';
    $user = $this->getRequest()->get('user') ?? '';

    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filters'),
    ];

    $form['filters']['question'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Question'),
      '#default_value' => $question,
    ];

    $form['filters']['answer'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Answer'),
      '#default_value' => $answer,
    ];

    $form['filters']['user'] = [
      '#type' => 'select',
      '#title' => $this->t('User'),
      '#options' => [],
      '#default_value' => $user,
      '#empty_option' => $this->t('- Select -'),
    ];

    $user_ids = $this->database
      ->select('ocha_ai_chat_logs', 'ocha_ai_chat_logs')
      ->fields('ocha_ai_chat_logs', ['uid'])
      ->distinct()
      ->execute()
      ?->fetchCol() ?? [];

    $users = [];
    if (!empty($user_ids)) {
      $users = $this->entityTypeManager
        ->getStorage('user')
        ->loadMultiple($user_ids);

      $form['filters']['user']['#options'] = array_map(function ($user) {
        return $user->label();
      }, $users);
    }

    $form['filters']['submit'] = [
      '#type' => 'submit',
      // No name so it doesn't appear in the query parameters.
      '#name' => '',
      '#value' => $this->t('Filter'),
      '#button_type' => 'primary',
    ];

    $header = [
      'timestamp' => [
        'data' => $this->t('Timestamp'),
        'field' => 'timestamp',
        'sort' => 'desc',
      ],
      'source' => [
        'data' => $this->t('Source'),
      ],
      'question' => [
        'data' => $this->t('Question'),
      ],
      'answer' => [
        'data' => $this->t('Answer'),
      ],
      'context' => [
        'data' => $this->t('Context'),
      ],
      'status' => [
        'data' => $this->t('Status'),
      ],
      'duration' => [
        'data' => $this->t('Duration'),
      ],
      'user' => [
        'data' => $this->t('User'),
      ],
      'rate' => [
        'data' => $this->t('Rate'),
        'field' => 'satisfaction',
      ],
      'feedback' => [
        'data' => $this->t('Feedback'),
      ],
      'thumbs' => [
        'data' => $this->t('Thumbs'),
      ],
      'copied' => [
        'data' => $this->t('Copied'),
      ],
      'stats' => [
        'data' => $this->t('Stats'),
      ],
    ];

    // Retrieve the log records.
    $query = $this->database
      ->select('ocha_ai_chat_logs', 'ocha_ai_chat_logs')
      ->fields('ocha_ai_chat_logs')
      ->extend('\Drupal\Core\Database\Query\TableSortExtender')
      ->orderByHeader($header)
      ->extend('\Drupal\Core\Database\Query\PagerSelectExtender')
      ->limit(20);

    if (!empty($question)) {
      $query->condition('ocha_ai_chat_logs.question', '%' . $question . '%', 'LIKE');
    }
    if (!empty($answer)) {
      $query->condition('ocha_ai_chat_logs.question', '%' . $answer . '%', 'LIKE');
    }
    if (!empty($user)) {
      $query->condition('ocha_ai_chat_logs.uid', $user, '=');
    }

    $link_options = [
      'attributes' => [
        'rel' => 'noreferrer noopener',
        'target' => '_blank',
      ],
    ];

    $rows = [];
    foreach ($query->execute() ?? [] as $record) {
      $source_plugin_id = $record->source_plugin_id;
      $source_plugin = $this->ochaAiChat->getSourcePluginManager()->getPlugin($source_plugin_id);
      $source_data = json_decode($record->source_data, TRUE);
      $passages = json_decode($record->passages, TRUE);
      $stats = json_decode($record->stats, TRUE);

      $rows[] = [
        'timestamp' => gmdate('Y-m-d H:i:s', $record->timestamp),
        'source' => [
          'data' => $source_plugin->renderSourceData($source_data),
        ],
        'question' => $record->question,
        'answer' => $record->answer,
        'context' => [
          'data' => [
            '#type' => 'details',
            '#title' => $this->t('Context'),
            '#open' => FALSE,
            'passages' => $this->formatPassages($passages),
          ],
        ],
        'status' => $record->status,
        'duration' => $record->duration,
        'user' => isset($users[$record->uid]) ? $users[$record->uid]->toLink(options: $link_options) : '',
        'rate' => $record->satisfaction ?? 0,
        'feedback' => [
          'data' => [
            '#type' => 'details',
            '#title' => $this->t('Feedback'),
            '#open' => FALSE,
            'feedback' => [
              '#markup' => $record->feedback ?? $this->t('No feedback provided.'),
            ],
          ],
        ],
        'thumbs' => $record->thumbs ?? '',
        'copied' => $record->copied ?? '',
        'stats' => [
          'data' => [
            '#type' => 'details',
            '#title' => $this->t('Stats'),
            '#open' => FALSE,
            'passages' => $this->formatStats($stats),
          ],
        ],
      ];
    }

    $form['download'] = [
      '#type' => 'container',
      '#id' => 'download',
      '#tree' => TRUE,
    ];
    $form['download']['submit'] = [
      '#type' => 'submit',
      '#name' => 'download',
      '#value' => $this->t('Download as CSV'),
      '#limit_validation_errors' => [
        ['download'],
      ],
      '#ajax' => [
        'callback' => [$this, 'downloadCsv'],
        'wrapper' => 'download',
        'disable-refocus' => TRUE,
      ],
    ];

    $form['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No content has been found.'),
    ];

    $form['pager'] = [
      '#type' => 'pager',
    ];

    $form['#attached']['library'][] = 'ocha_ai_chat/logs.form';

    return $form;
  }

  /**
   * Format the stats.
   *
   * @param array $stats
   *   Stats.
   *
   * @return array
   *   Render array for the stats.
   */
  protected function formatStats(array $stats): array {
    $items = [];
    foreach ($stats as $key => $value) {
      $items[] = [
        '#type' => 'inline_template',
        '#template' => '<strong>{{ key }}:</strong> {{ value }}',
        '#context' => [
          'key' => $key,
          'value' => round($value, 3) . 's',
        ],
      ];
    }
    return [
      '#theme' => 'item_list',
      '#items' => $items,
    ];
  }

  /**
   * Format the text passages.
   *
   * @param array $passages
   *   Passages.
   *
   * @return array
   *   Render array for the passages.
   */
  protected function formatPassages(array $passages): array {
    $link_options = [
      'attributes' => [
        'rel' => 'noreferrer noopener',
        'target' => '_blank',
      ],
    ];

    $items = [];
    foreach ($passages as $passage) {
      $source_title = $passage['source']['title'];
      if (!empty($passage['source']['page'])) {
        $source_title .= ' (page ' . $passage['source']['page'] . ')';
      }
      $source_url = Url::fromUri($passage['source']['url'], $link_options);

      // Source organizations.
      $sources = implode(', ', array_filter(array_map(function ($source) {
        return $source['shortname'] ?? $source['name'] ?? '';
      }, $passage['source']['source'] ?? [])));

      // Publication date.
      $date = date_create($passage['source']['date']['original'])->format('j F Y');

      $items[] = [
        '#type' => 'inline_template',
        '#template' => '{{ text }}<br><small>Source: {{ sources }}, {{ title }}, {{ date }}</small>',
        '#context' => [
          'text' => $passage['text'],
          'sources' => $sources,
          'title' => Link::fromTextAndUrl($source_title, $source_url),
          'date' => $date,
        ],
      ];
    }
    return [
      '#theme' => 'item_list',
      '#items' => $items,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Remove elements from being submitted as GET variables.
   *
   * @param array $form
   *   From.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Ajax response to replace the button with a link to download the file.
   *
   * @todo see if can just send a response to download the file.
   */
  public function downloadCsv(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $selector = '#download';
    $directory = 'private://ocha_ai_chat_logs/';

    // Create a temporary managed file so it can be deleted on cron.
    $file = $this->entityTypeManager->getStorage('file')->create();
    $file->setFileName($file->uuid() . '.csv');
    $file->setFileUri($directory . $file->uuid() . '.csv');
    $file->setTemporary();
    $file->setOwnerId($this->currentUser->id());
    $file->save();

    try {
      $this->fileSystem->prepareDirectory($directory, $this->fileSystem::CREATE_DIRECTORY);
    }
    catch (\Exception $exception) {
      return $response->addCommand(new MessageCommand('Unable to create directory for the log export', $selector));
    }

    $handle = fopen($file->getFileUri(), 'a');
    if ($handle === FALSE) {
      return $response->addCommand(new MessageCommand('Unable to create temporary file for the log export', $selector));
    }

    // Retrieve the log records.
    try {
      $question = $this->getRequest()->get('question');
      $answer = $this->getRequest()->get('answer');
      $user = $this->getRequest()->get('user');

      while (TRUE) {
        $query = $this->database
          ->select('ocha_ai_chat_logs', 'ocha_ai_chat_logs')
          ->fields('ocha_ai_chat_logs')
          ->orderBy('ocha_ai_chat_logs.id', 'DESC')
          ->range(0, 50);

        if (!empty($question)) {
          $query->condition('ocha_ai_chat_logs.question', '%' . $question . '%', 'LIKE');
        }
        if (!empty($answer)) {
          $query->condition('ocha_ai_chat_logs.question', '%' . $answer . '%', 'LIKE');
        }
        if (!empty($user)) {
          $query->condition('ocha_ai_chat_logs.uid', $user, '=');
        }

        if (isset($last_id)) {
          $query->condition('ocha_ai_chat_logs.id', $last_id, '<');
        }

        $results = $query->execute()?->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
        if (empty($results)) {
          break;
        }

        if (!isset($last_id)) {
          if (fputcsv($handle, array_keys(reset($results))) === FALSE) {
            throw new \Exception('Unable to write headers to file for the log export');
          }
        }

        foreach ($results as $result) {
          if (fputcsv($handle, $result) === FALSE) {
            throw new \Exception('Unable to write rows to file for the log export');
          }
        }

        $last_id = min(array_keys($results));
      }
    }
    catch (\Exception $exception) {
      return $response->addCommand(new MessageCommand($exception->getMessage(), $selector));
    }
    finally {
      fclose($handle);
    }

    $message = $this->t('Download the logs <a href="@url" target="_blank">here</a>.', [
      '@url' => $file->createFileUrl(),
    ]);
    return $response->addCommand(new MessageCommand($message, $selector));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ocha_ai_chat_logs_form';
  }

}

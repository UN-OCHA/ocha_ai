<?php

namespace Drupal\ocha_ai_chat\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ocha_ai_chat\Services\OchaAiChat;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Chat form for the Ocha AI Chat module.
 */
class OchaAiChatChatForm extends FormBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

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
   * Constructor.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\ocha_ai_chat\Services\OchaAiChat $ocha_ai_chat
   *   The OCHA AI chat service.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    Connection $database,
    StateInterface $state,
    OchaAiChat $ocha_ai_chat
  ) {
    $this->currentUser = $current_user;
    $this->database = $database;
    $this->state = $state;
    $this->ochaAiChat = $ocha_ai_chat;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('database'),
      $container->get('state'),
      $container->get('ocha_ai_chat.chat')
    );
  }

  /**
   * Get the title.
   *
   * @param bool|null $popup
   *   Whether the page is displayed in a popup or not.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   */
  public function getPageTitle(?bool $popup = NULL): TranslatableMarkup {
    $limit = $this->getRequest()?->query?->get('limit');
    if (isset($limit) && $limit == 1) {
      return $this->t('Ask ReliefWeb');
    }
    return $this->t('Ask ReliefWeb');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?bool $popup = NULL): array {
    $defaults = $this->ochaAiChat->getSettings();

    // Add the source widget.
    $form = $this->ochaAiChat->getSourcePlugin()->getSourceWidget($form, $form_state, $defaults);
    if (isset($form['source'])) {
      $form['source']['#attributes']['class'][] = 'ocha-ai-chat-chat-form__source';
    }

    // Advanced options for test purposes.
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced options'),
      '#open' => FALSE,
      '#access' => $this->currentUser->hasPermission('access ocha ai chat advanced features'),
      '#attributes' => [
        'class' => [
          'ocha-ai-chat-chat-form__advanced',
        ],
      ],
    ];

    // Completion plugin.
    $completion_options = array_map(function ($plugin) {
      return $plugin->getPluginLabel();
    }, $this->ochaAiChat->getCompletionPluginManager()->getAvailablePlugins());

    $completion_default = $form_state->getValue('completion_plugin_id') ??
      $defaults['plugins']['completion']['plugin_id'] ??
      key($completion_options);

    $form['advanced']['completion_plugin_id'] = [
      '#type' => 'select',
      '#title' => $this->t('AI service'),
      '#description' => $this->t('Select which AI service will generate the answer.'),
      '#options' => $completion_options,
      '#default_value' => $completion_default,
      '#required' => TRUE,
    ];

    // Add the chat history.
    $history = $form_state->getValue('history', '');
    $form['chat'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('History'),
      '#title_display' => 'invisible',
      '#access' => TRUE,
      '#tree' => TRUE,
    ];
    $form['history'] = [
      '#type' => 'hidden',
      '#value' => $history,
    ];

    // Output instructions as part of scrollable chat history.
    if (!empty($defaults['form']['instructions']['value'])) {
      $form['chat']['content'] = [
        '#type' => 'processed_text',
        '#prefix' => '<div id="ocha-ai-chat-instructions" class="ocha-ai-chat-chat-form__instructions">',
        '#suffix' => '</div>',
        '#text' => $defaults['form']['instructions']['value'],
        '#format' => $defaults['form']['instructions']['format'] ?? 'text_editor_simple',
      ];
    }

    // Read config to determine feedback type for each history entry.
    $feedback_type = \Drupal::config('ocha_ai_chat.settings')->get('defaults.form.feedback');

    foreach (json_decode($history, TRUE) ?? [] as $index => $record) {
      $form['chat'][$index] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['ocha-ai-chat-result'],
        ],
      ];
      $form['chat'][$index]['result'] = [
        '#type' => 'inline_template',
        '#template' => '<dl class="chat"><div class="chat__q"><dt class="visually-hidden">Question</dt><dd>{{ question }}</dd></div><div class="chat__a"><dt class="visually-hidden">Answer</dt><dd>{{ answer }}</dd></div>{% if references %}<div class="chat__refs"><dt>References</dt><dd>{{ references }}</dd></div>{% endif %}</dl>',
        '#context' => [
          'question' => $record['question'],
          'answer' => $record['answer'],
          'references' => $this->formatReferences($record['references']),
        ],
      ];

      if ($feedback_type === 'simple') {
        // Container for simple feedback.
        $form['chat'][$index]['feedback'] = [
          '#type' => 'fieldset',
          '#title' => $this->t('Provide feedback'),
          '#title_display' => 'invisible',
          '#id' => 'chat-result-' . $index . '-simple-feedback',
          '#attributes' => [
            'class' => ['ocha-ai-chat-result__simple-feedback'],
          ],
        ];

        // Thumbs up.
        $form['chat'][$index]['feedback']['good'] = [
          '#type' => 'submit',
          '#name' => 'chat-result-' . $index . '-simple-feedback-good',
          '#value' => $this->t('Good'),
          '#attributes' => [
            'class' => ['feedback-button', 'feedback-button--good'],
            'data-result-id' => $record['id'],
          ],
          '#ajax' => [
            'callback' => [$this, 'submitSimpleFeedback'],
            'wrapper' => 'chat-result-' . $index . '-simple-feedback',
            'disable-refocus' => TRUE,
          ],
        ];

        // Thumbs down.
        $form['chat'][$index]['feedback']['bad'] = [
          '#type' => 'submit',
          '#name' => 'chat-result-' . $index . '-simple-feedback-bad',
          '#value' => $this->t('Bad'),
          '#attributes' => [
            'class' => ['feedback-button', 'feedback-button--bad'],
            'data-result-id' => $record['id'],
          ],
          '#ajax' => [
            'callback' => [$this, 'submitSimpleFeedback'],
            'wrapper' => 'chat-result-' . $index . '-simple-feedback',
            'disable-refocus' => TRUE,
          ],
        ];
      }
      else {
        $form['chat'][$index]['feedback'] = [
          '#type' => 'details',
          '#title' => $this->t('Please give feedback'),
          '#id' => 'chat-result-' . $index . '-feedback',
          '#open' => FALSE,
          '#attributes' => [
            'class' => ['ocha-ai-chat-result-feedback'],
          ],
        ];
        $form['chat'][$index]['feedback']['satisfaction'] = [
          '#type' => 'select',
          '#title' => $this->t('Rate the answer'),
          '#options' => [
            0 => $this->t('- Select -'),
            1 => $this->t('Very bad'),
            2 => $this->t('Bad'),
            3 => $this->t('Passable'),
            4 => $this->t('Good'),
            5 => $this->t('Very good'),
          ],
          '#default_value' => $form_state->getValue([
            'chat', $index, 'feedback', 'satisfaction',
          ]),
        ];
        $form['chat'][$index]['feedback']['comment'] = [
          '#type' => 'textarea',
          '#title' => $this->t('Comment'),
          '#default_value' => $form_state->getValue([
            'chat', $index, 'feedback', 'comment',
          ]),
        ];
        $form['chat'][$index]['feedback']['submit'] = [
          '#type' => 'submit',
          '#name' => 'chat-result-' . $index . '-feedback-submit',
          '#value' => $this->t('Submit feedback'),
          '#limit_validation_errors' => [
            ['chat', $index, 'feedback'],
          ],
          '#attributes' => [
            'data-result-id' => $record['id'],
          ],
          '#ajax' => [
            'callback' => [$this, 'submitFeedback'],
            'wrapper' => 'chat-result-' . $index . '-feedback',
            'disable-refocus' => TRUE,
          ],
        ];
      }
    }

    $form['question'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Enter your question'),
      '#title_display' => 'invisible',
      '#default_value' => NULL,
      '#placeholder' => $this->t('Ex: How many people are in need of food assistance?'),
      '#rows' => 2,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Ask'),
      '#name' => 'submit',
      '#description' => $this->t('It may take several minutes to get the answer.'),
      '#button_type' => 'primary',
    ];

    $form['#attached']['library'][] = 'ocha_ai_chat/chat.form';

    // Submit the form via ajax.
    $id = Html::cleanCssIdentifier($this->getFormId() . '-wrapper');
    $form['#prefix'] = '<div id="' . $id . '" class="' . $id . '">';
    $form['#suffix'] = '</div>';
    $form['actions']['submit']['#attributes']['class'][] = 'use-ajax-submit';
    $form['actions']['submit']['#ajax'] = [
      'wrapper' => $id,
      'disable-refocus' => TRUE,
    ];

    // @todo check if we need a theme.
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $triggering_element = $form_state->getTriggeringElement();

    // Only answer the question if the main submit button was pressed.
    if (isset($triggering_element['#name']) && $triggering_element['#name'] === 'submit') {
      $source_data = $this->ochaAiChat->getSourcePlugin()->getSourceData($form, $form_state);
      $source_limit = $form_state->getValue(['source', 'limit']);
      $question = $form_state->getValue('question');

      // Get the answer to the question.
      // @todo use server events etc. for a better UX.
      if (!empty($question) && !empty($source_data)) {
        $completion_plugin_id = $form_state->getValue('completion_plugin_id');
        if (isset($completion_plugin_id)) {
          $completion_plugin = $this->ochaAiChat
            ->getCompletionPluginManager()
            ->getPlugin($completion_plugin_id);
        }
        else {
          $completion_plugin = NULL;
        }

        $data = $this->ochaAiChat->answer($question, $source_data, $source_limit, $completion_plugin);

        // Generate a list of references used to generate the answer.
        $references = [];
        foreach ($data['passages'] as $passage) {
          $reference_source_url = $passage['source']['url'];
          if (!isset($references[$reference_source_url])) {
            $references[$reference_source_url] = [
              'title' => $passage['source']['title'],
              'url' => $reference_source_url,
              'attachments' => [],
            ];
          }
          if (isset($passage['source']['attachment'])) {
            $attachment_url = $passage['source']['attachment']['url'];
            $attachment_page = $passage['source']['attachment']['page'];
            $references[$reference_source_url]['attachments'][$attachment_url][$attachment_page] = $attachment_page;
          }
        }

        // Update the chat history.
        $history = json_decode($form_state->getValue('history', ''), TRUE) ?? [];
        $history[] = [
          'id' => $data['id'],
          'question' => $question,
          'answer' => $data['answer'],
          'references' => $references,
        ];

        $form_state->setValue('history', json_encode($history));
      }

      // Remove the question from the user input so that the question is empty
      // when the form is rebuilt.
      $user_input = $form_state->getUserInput();
      unset($user_input['question']);
      $form_state->setUserInput($user_input);

      // Rebuild the form so that it is reloaded with the inputs from the user
      // as well as the AI answer.
      $form_state->setRebuild(TRUE);
    }
  }

  /**
   * Submit the feedback about a chat result.
   *
   * @param array $form
   *   The main form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Ajax response to confirm the feedback was submitted.
   */
  public function submitFeedback(array &$form, FormStateInterface $form_state): AjaxResponse {
    $triggering_element = $form_state->getTriggeringElement();

    $id = $triggering_element['#attributes']['data-result-id'];
    $selector = '#' . $triggering_element['#ajax']['wrapper'];
    $parents = array_slice($triggering_element['#array_parents'], 0, -1);
    $feedback = $form_state->getValue($parents);

    $this->ochaAiChat->addAnswerFeedback($id, $feedback['satisfaction'], $feedback['comment']);

    $response = new AjaxResponse();
    $response->addCommand(new MessageCommand($this->t('Feedback submitted, thank you.'), $selector));
    return $response;
  }

  /**
   * Submit simple feedback about a chat result.
   *
   * @param array $form
   *   The main form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Ajax response to confirm the feedback was submitted.
   */
  public function submitSimpleFeedback(array &$form, FormStateInterface $form_state): AjaxResponse {
    $triggering_element = $form_state->getTriggeringElement();

    $id = $triggering_element['#attributes']['data-result-id'];
    $selector = '#' . $triggering_element['#ajax']['wrapper'];
    $feedback = $triggering_element['#array_parents'][3];

    // Convert the thumbs up/down to an integer
    if ($feedback === 'good') {
      $feedback_int = 4;
      $feedback_msg = 'User clicked thumbs up';
    }
    else {
      $feedback_int = 2;
      $feedback_msg = 'User clicked thumbs down';
    }

    // Record the feedback
    $this->ochaAiChat->addAnswerFeedback($id, $feedback_int, $feedback_msg);

    $response = new AjaxResponse();
    $response->addCommand(new MessageCommand($this->t('Feedback submitted, thank you.'), $selector));
    return $response;
  }


  /**
   * Hide the chat instructions and save the preference.
   *
   * @param array $form
   *   The main form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form.
   */
  public function hideInstructions(array &$form, FormStateInterface $form_state): array {
    $triggering_element = $form_state->getTriggeringElement();

    $hide = $form_state->getValue($triggering_element['#array_parents'], FALSE);

    $this->database
      ->upsert('ocha_ai_chat_preferences')
      ->key('uid')
      ->fields(['uid', 'hide_instructions'])
      ->values([$this->currentUser->id(), !empty($hide) ? 1 : 0])
      ->execute();

    return $form['instructions'];
  }

  /**
   * Format a list of references.
   *
   * @param array $references
   *   References.
   *
   * @return array
   *   Render array.
   */
  protected function formatReferences(array $references): array {
    if (empty($references)) {
      return [];
    }

    $link_options = [
      'attributes' => [
        'rel' => 'noreferrer noopener',
        'target' => '_blank',
      ],
    ];

    $items = [];
    foreach ($references as $reference) {
      $links = [];
      // Link to the document.
      $links[] = [
        'title' => $reference['title'],
        'url' => Url::fromUri($reference['url'], $link_options),
        'attributes' => [
          'class' => [
            'ocha-ai-chat-reference__link',
            'ocha-ai-chat-reference__link--document',
          ],
        ],
      ];
      // link(s) to the attachment(s).
      foreach ($reference['attachments'] ?? [] as $url => $pages) {
        $links[] = [
          'title' => $this->formatPlural(count($pages), 'attachment (p. @pages)', 'attachment (pp. @pages)', [
            '@pages' => implode(', ', $pages),
          ]),
          'url' => Url::fromUri($url, $link_options),
          'attributes' => [
            'class' => [
              'ocha-ai-chat-reference__link',
              'ocha-ai-chat-reference__link--attachment',
            ],
          ],
        ];
      }
      $items[] = [
        '#theme' => 'links',
        '#links' => $links,
        '#attributes' => [
          'class' => [
            'ocha-ai-chat-reference',
          ],
        ],
        '#wrapper_attributes' => [
          'class' => [
            'ocha-ai-chat-reference-list__item',
          ],
        ],
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#list_type' => 'ul',
      '#attributes' => [
        'class' => [
          'ocha-ai-chat-reference-list',
        ],
        'role' => 'list',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ocha_ai_chat_chat_form';
  }

}

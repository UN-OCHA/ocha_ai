<?php

namespace Drupal\ocha_ai_chat\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\honeypot\HoneypotService;
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
   * @var \Drupal\ocha_ai_chat\Services\OchaAiChat
   */
  protected OchaAiChat $ochaAiChat;

  /**
   * The Honeypot service.
   *
   * @var \Drupal\honeypot\HoneypotService
   */
  protected HoneypotService $honeypotService;

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
   * @param \Drupal\honeypot\HoneypotService $honeypot_service
   *   The Honeypot service.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    Connection $database,
    StateInterface $state,
    OchaAiChat $ocha_ai_chat,
    HoneypotService $honeypot_service,
  ) {
    $this->currentUser = $current_user;
    $this->database = $database;
    $this->state = $state;
    $this->ochaAiChat = $ocha_ai_chat;
    $this->honeypotService = $honeypot_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('database'),
      $container->get('state'),
      $container->get('ocha_ai_chat.chat'),
      $container->get('honeypot')
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
    $settings = $this->ochaAiChat->getSettings();
    if (!empty($popup) && !empty($settings['form']['popup_title'])) {
      return $this->t('@title', ['@title' => $settings['form']['popup_title']]);
    }
    elseif (!empty($settings['form']['form_title'])) {
      return $this->t('@title', ['@title' => $settings['form']['form_title']]);
    }
    return $this->t('Ask about this document');
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

    // Get the feedback type to use for each history entry.
    $feedback_type = $defaults['form']['feedback'] ?? 'detailed';

    $previous_questions = json_decode($history, TRUE) ?? [];
    $last_index = array_key_last($previous_questions);

    foreach ($previous_questions as $index => $record) {
      // Used on two different form elements; they must match to function.
      $answer_id = 'chat__a--' . $index;

      // Get thumbs state.
      $thumbs_state = $this->ochaAiChat->getAnswerThumbs($record['id']);

      $form['chat'][$index] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['ocha-ai-chat-result'],
        ],
      ];
      $form['chat'][$index]['result'] = [
        '#type' => 'inline_template',
        '#template' => '<dl class="chat"><div class="chat__q"><dt class="visually-hidden">Question</dt><dd>{{ question }}</dd></div><div class="chat__a"><dt class="visually-hidden">Answer</dt><dd id="{{ answer_id }}">{{ answer|trim|nl2br }}</dd></div>{% if references %}<div class="chat__refs"><dt>References</dt><dd>{{ references }}</dd></div>{% endif %}</dl>',
        '#context' => [
          'question' => $record['question'],
          'answer' => $record['answer'],
          'answer_id' => $answer_id,
          'references' => $this->formatReferences($record['references']),
        ],
      ];

      // There are multiple "modes" for feedback. We check the config value
      // before deciding what UI widgets to render.
      if ($feedback_type === 'simple' || $feedback_type === 'both') {
        // Container for simple feedback.
        $form['chat'][$index]['feedback_simple'] = [
          '#type' => 'fieldset',
          '#title' => $this->t('Provide feedback'),
          '#title_display' => 'invisible',
          '#id' => 'chat-result-' . $index . '-simple-feedback',
          '#attributes' => [
            'class' => ['ocha-ai-chat-result-feedback', 'ocha-ai-chat-result-feedback--simple'],
          ],
        ];

        $ajax_buttons = [];
        if ($index == $last_index) {
          $ajax_buttons = [
            'callback' => [$this, 'submitSimpleFeedback'],
            'wrapper' => 'chat-result-' . $index . '-simple-feedback',
            'disable-refocus' => TRUE,
          ];
        }

        // Thumbs up.
        $form['chat'][$index]['feedback_simple']['good'] = [
          '#type' => 'submit',
          '#name' => 'chat-result-' . $index . '-simple-feedback-good',
          '#value' => $this->t('Like'),
          '#attributes' => [
            'class' => [
              'feedback-button',
              'feedback-button--good',
              ($thumbs_state == 'up') ? 'feedback-button--pressed' : '',
            ],
            'data-result-id' => $record['id'],
          ],
          '#ajax' => $ajax_buttons,
          '#disabled' => $index != $last_index,
        ];

        // Thumbs down.
        $form['chat'][$index]['feedback_simple']['bad'] = [
          '#type' => 'submit',
          '#name' => 'chat-result-' . $index . '-simple-feedback-bad',
          '#value' => $this->t('Dislike'),
          '#attributes' => [
            'class' => [
              'feedback-button',
              'feedback-button--bad',
              ($thumbs_state == 'down') ? 'feedback-button--pressed' : '',
            ],
            'data-result-id' => $record['id'],
          ],
          '#ajax' => $ajax_buttons,
          '#disabled' => $index != $last_index,
        ];

        // Copy button.
        $form['chat'][$index]['feedback_simple']['copy'] = [
          '#type' => 'submit',
          '#name' => 'chat-result-' . $index . '-copy-clipboard',
          '#value' => $this->t('Copy to clipboard'),
          '#attributes' => [
            'class' => ['feedback-button', 'feedback-button--copy'],
            'data-result-id' => $record['id'],
            'data-for' => $answer_id,
          ],
          '#ajax' => [
            'callback' => [$this, 'recordCopyToClipboard'],
            'wrapper' => 'chat-result-' . $index . '-simple-feedback',
            'disable-refocus' => TRUE,
          ],
        ];

        // Copy button failure feedback. Successful feedback is handled by the
        // callback in the `copy` form element.
        $form['chat'][$index]['feedback_simple']['copy_feedback'] = [
          '#type' => 'inline_template',
          '#template' => '<span hidden data-failure="{{ failure_message }}" role="status" class="clipboard-feedback"></span>',
          '#context' => [
            'failure_message' => $this->t('Copying failed'),
          ],
        ];

        // If both modes are active, render button to toggle detailed feedback.
        if ($feedback_type === 'both') {
          $form['chat'][$index]['feedback_simple']['show_detailed'] = [
            '#type' => 'inline_template',
            '#template' => '<button data-for="{{ target }}" class="feedback-button--show-detailed">{{ button_text }}</button>',
            '#context' => [
              'target' => 'chat-result-' . $index . '-feedback',
              'button_text' => $this->t('Give detailed feedback'),
            ],
          ];
        }
      }

      // Detailed feedback.
      if ($feedback_type !== 'simple') {
        $form['chat'][$index]['feedback'] = [
          '#type' => 'details',
          '#title' => $this->t('Provide detailed feedback'),
          '#id' => 'chat-result-' . $index . '-feedback',
          '#open' => FALSE,
          '#attributes' => [
            'class' => ['ocha-ai-chat-result-feedback'],
            'hidden' => $feedback_type === 'both' ? '' : FALSE,
          ],
        ];
        $form['chat'][$index]['feedback']['satisfaction'] = [
          '#type' => 'select',
          '#title' => $this->t('Rate the answer'),
          '#options' => [
            0 => $this->t('- Choose a rating -'),
            1 => $this->t('Very bad'),
            2 => $this->t('Bad'),
            3 => $this->t('Passable'),
            4 => $this->t('Good'),
            5 => $this->t('Very good'),
          ],
          '#default_value' => $form_state->getValue([
            'chat', $index, 'feedback', 'satisfaction',
          ]),
          '#disabled' => $index != $last_index,
        ];
        $form['chat'][$index]['feedback']['comment'] = [
          '#type' => 'textarea',
          '#title' => $this->t('Comment'),
          '#default_value' => $form_state->getValue([
            'chat', $index, 'feedback', 'comment',
          ]),
          '#disabled' => $index != $last_index,
        ];

        if ($index == $last_index) {
          $has_value = !empty($form_state->getValue(['chat', $index, 'feedback', 'comment']));
          $form['chat'][$index]['feedback']['submit'] = [
            '#type' => 'submit',
            '#name' => 'chat-result-' . $index . '-feedback-submit',
            '#value' => $has_value ? $this->t('Edit feedback') : $this->t('Submit feedback'),
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
            '#disabled' => $index != $last_index,
          ];
        }
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
      'progress' => [
        'type' => 'throbber',
        'message' => $this->t('Analyzing the document...'),
      ],
    ];

    $honeypot_options = ['honeypot', 'time_restriction'];
    $this->honeypotService->addFormProtection($form, $form_state, $honeypot_options);

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
          'references' => $data['status'] === 'success' ? $references : [],
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
    $response->addCommand(new HtmlCommand($selector . ' > .form-submit', $this->t('Edit feedback')));
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

    $response = new AjaxResponse();

    // Convert the thumbs up/down to a string.
    if ($feedback === 'good') {
      $feedback_val = 'up';
      $feedback_msg = $this->t('Glad you liked this answer.');
      $response->addCommand(new InvokeCommand($selector . ' .feedback-button.feedback-button--good', 'addClass', ['feedback-button--pressed']));
      $response->addCommand(new InvokeCommand($selector . ' .feedback-button.feedback-button--bad', 'removeClass', ['feedback-button--pressed']));
    }
    else {
      $feedback_val = 'down';
      $feedback_msg = $this->t('Thank you for your feedback.');
      $response->addCommand(new InvokeCommand($selector . ' .feedback-button.feedback-button--bad', 'addClass', ['feedback-button--pressed']));
      $response->addCommand(new InvokeCommand($selector . ' .feedback-button.feedback-button--good', 'removeClass', ['feedback-button--pressed']));
    }

    // Record the feedback.
    $this->ochaAiChat->addAnswerThumbs($id, $feedback_val);

    // Add message.
    $response->addCommand(new MessageCommand($feedback_msg, $selector));

    return $response;
  }

  /**
   * Record that the copy-to-clipboard button was pressed for this answer.
   *
   * @param array $form
   *   The main form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Ajax response to confirm the action was recorded.
   */
  public function recordCopyToClipboard(array &$form, FormStateInterface $form_state): AjaxResponse {
    $triggering_element = $form_state->getTriggeringElement();

    // Determine which button was pressed.
    $id = $triggering_element['#attributes']['data-result-id'];
    $selector = '#' . $triggering_element['#ajax']['wrapper'];

    // Record the feedback.
    $this->ochaAiChat->addAnswerCopy($id, 'copied');

    // Prepare user feedback.
    $feedback_msg = $this->t('Answer was copied to clipboard');

    // Update form with feedback.
    $response = new AjaxResponse();
    $response->addCommand(new MessageCommand($feedback_msg, $selector));
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
   * Format an answer.
   *
   * @param string $answer
   *   Answer.
   * @param string $format
   *   Formatting mode.
   *
   * @return string
   *   String of HTML.
   */
  protected function formatAnswer(string $answer, string $format): string {
    if (!$answer) {
      return '';
    }

    // If none of the following formatting applies, return the original answer.
    $formatted_answer = $answer;

    // Restore line breaks. The LLM returns line breaks, but they need to be
    // converted to HTML to be seen in the browser.
    $formatted_answer = str_replace("\n", '<br>', $formatted_answer);

    // Return formatted answer.
    return $formatted_answer;
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

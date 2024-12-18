<?php

namespace Drupal\ocha_ai_chat\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\ocha_ai_chat\Services\OchaAiChat;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a 'Chat popup' Block.
 *
 * @Block(
 *   id = "ocha_ai_chat_chat_popup",
 *   admin_label = @Translation("OCHA AI Chat popup"),
 *   category = @Translation("Chat"),
 * )
 */
class ChatPopup extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a \Drupal\Component\Plugin\PluginBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\ocha_ai_chat\Services\OchaAiChat $ochaAiChat
   *   The OCHA AI chat service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected RequestStack $requestStack,
    protected OchaAiChat $ochaAiChat,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('request_stack'),
      $container->get('ocha_ai_chat.chat')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    $access_result = parent::blockAccess($account);
    if (!$account->hasPermission('access ocha ai chat')) {
      return $access_result::forbidden();
    }
    return $access_result;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $current_request = $this->requestStack->getCurrentRequest();
    $current_url = str_replace($current_request->getHttpHost(), 'reliefweb.int', $current_request->getUri());

    $query = [];
    if ($this->checkReportUrl($current_url)) {
      $query['url'] = $current_url;
      $query['limit'] = 1;
    }
    elseif (!$this->checkRiverUrl($current_url)) {
      $query['url'] = $current_url;
    }
    else {
      return [];
    }

    $url = Url::fromRoute('ocha_ai_chat.chat_form.popup', [], [
      'query' => $query,
    ]);

    $settings = $this->ochaAiChat->getSettings();
    if (!empty($settings['form']['popup_title'])) {
      $title = $this->t('@title', [
        '@title' => $settings['form']['popup_title'],
      ]);
    }
    else {
      $title = $this->t('Ask ReliefWeb');
    }

    return [
      '#theme' => 'ocha_ai_chat_chat_popup',
      '#title' => $title,
      '#link' => Link::fromTextAndUrl($this->t('Go to chat page'), $url),
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Validate a ReliefWeb river URL.
   *
   * @param string $url
   *   River URL.
   *
   * @return bool
   *   TRUE if the URL is valid.
   */
  protected function checkRiverUrl(string $url): bool {
    // @todo allow other rivers.
    return !empty($url) && preg_match('@^https?://reliefweb\.int/updates([?#]|$)@', $url) === 1;
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
    return !empty($url) && preg_match('@^https?://reliefweb\.int/(report|map)/[^/]+/[^/]+$@', $url) === 1;
  }

}

<?php

namespace Drupal\ocha_ai_chat\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
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
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a \Drupal\Component\Plugin\PluginBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RequestStack $request_stack
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
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
      $container->get('request_stack')
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
      $query['url'] = 'https://reliefweb.int/updates?' . http_build_query([
        'search' => 'url_alias:"' . $current_url . '"',
      ]);
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

    return [
      '#theme' => 'ocha_ai_chat_chat_popup',
      '#title' => $this->t('Chat with documents'),
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
    return !empty($url) && preg_match('@^https?://reliefweb\.int/report/[^/]+/[^/]+$@', $url) === 1;
  }

}

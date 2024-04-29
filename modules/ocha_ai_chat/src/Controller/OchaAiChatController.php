<?php

namespace Drupal\ocha_ai_chat\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class OchaAiChat Controller.
 *
 * @package Drupal\ocha_ai_chat\Controller
 */
class OchaAiChatController extends ControllerBase {
  use LoggerChannelTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs controller.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The Connection object.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The RequestStack object.
   */
  public function __construct(Connection $connection, RequestStack $request_stack) {
    $this->connection = $connection;
    $this->requestStack = $request_stack;
  }

  /**
   * Generate chat log statistics.
   *
   * Run a bunch of queries against the ocha_ai_chat_logs table and return
   * the numbers in some sensible JSON blob format.
   */
  public function statistics(RouteMatchInterface $route_match, Request $request) {
    $response = [];

    // @codingStandardsIgnoreLine
    $database = $this->connection;

    // Number of interactions.
    $query = $database->query("SELECT FROM_UNIXTIME(timestamp, '%Y-%m - Week %V') AS week, COUNT(id) AS interactions FROM ocha_ai_chat_logs GROUP BY week ORDER BY week ASC");
    $result = $query->fetchAll();
    $response['interactions'] = $result;

    // Average satisfaction.
    $query = $database->query("SELECT FROM_UNIXTIME(timestamp, '%Y-%m - Week %V') AS week, AVG(satisfaction) AS average_satisfaction FROM ocha_ai_chat_logs GROUP BY week ORDER BY week ASC");
    $result = $query->fetchAll();
    $response['average_satisfaction'] = $result;

    // Number of questions per user.
    $query = $database->query("SELECT FROM_UNIXTIME(timestamp, '%Y-%m - Week %V') AS week, COUNT(id) / COUNT(DISTINCT uid) AS questions_per_user FROM ocha_ai_chat_logs GROUP BY week ORDER BY week ASC");
    $result = $query->fetchAll();
    $response['questions_per_user'] = $result;

    // Number of questions per user per document.
    $query = $database->query("SELECT FROM_UNIXTIME(timestamp, '%Y-%m - Week %V') AS week, COUNT(id) / COUNT(DISTINCT source_document_ids, uid) AS questions_per_user_per_document FROM ocha_ai_chat_logs GROUP BY week ORDER BY week ASC");
    $result = $query->fetchAll();
    $response['questions_per_user_per_document'] = $result;

    // Average response time in seconds.
    $query = $database->query("SELECT FROM_UNIXTIME(timestamp, '%Y-%m - Week %V') AS week, AVG(SUBSTRING(stats, LOCATE('Get answer', stats) + 12, 8)) AS average_response_time_in_seconds FROM ocha_ai_chat_logs GROUP BY week ORDER BY week ASC");
    $result = $query->fetchAll();
    $response['average_response_time_in_seconds'] = $result;

    // Thumbs.
    $query = $database->query("SELECT FROM_UNIXTIME(timestamp, '%Y-%m - Week %V') AS week, SUM(CASE thumbs WHEN 'up' THEN 1 END) AS thumbs_up, SUM(CASE thumbs WHEN 'down' THEN 1 END) AS thumbs_down FROM ocha_ai_chat_logs GROUP BY week");
    $result = $query->fetchAll();
    $response['thumbs'] = $result;

    // Users asking less than five questions.
    $query = $database->query("SELECT week, COUNT(uid) AS users_under_five_questions FROM (SELECT FROM_UNIXTIME(timestamp, '%Y-%m - Week %V') AS week, uid, count(id) as conversations FROM ocha_ai_chat_logs GROUP BY week, uid HAVING conversations < 5) A GROUP BY week ORDER BY week ASC");
    $result = $query->fetchAll();
    $response['users_under_five_questions'] = $result;

    // Users asking between five and ten questions.
    $query = $database->query("SELECT week, COUNT(uid) AS users_five_to_ten_questions FROM (SELECT FROM_UNIXTIME(timestamp, '%Y-%m - Week %V') AS week, uid, count(id) as conversations FROM ocha_ai_chat_logs GROUP BY week, uid HAVING conversations >= 5 AND conversations <= 10) A GROUP BY week ORDER BY week ASC");
    $result = $query->fetchAll();
    $response['users_five_to_ten_questions'] = $result;

    // Users asking between ten and twenty questions.
    $query = $database->query("SELECT week, COUNT(uid) AS users_ten_to_twenty_questions FROM (SELECT FROM_UNIXTIME(timestamp, '%Y-%m - Week %V') AS week, uid, count(id) as conversations FROM ocha_ai_chat_logs GROUP BY week, uid HAVING conversations > 10 AND conversations <= 20) A GROUP BY week ORDER BY week ASC");
    $result = $query->fetchAll();
    $response['users_ten_to_twenty_questions'] = $result;

    // Users asking more than twenty questions.
    $query = $database->query("SELECT week, COUNT(uid) AS users_over_twenty_questions FROM (SELECT FROM_UNIXTIME(timestamp, '%Y-%m - Week %V') AS week, uid, count(id) as conversations FROM ocha_ai_chat_logs GROUP BY week, uid HAVING conversations > 20) A GROUP BY week ORDER BY week ASC");
    $result = $query->fetchAll();
    $response['users_over_twenty_questions'] = $result;

    return new JsonResponse($response, 200);
  }

  /**
   * Access result callback.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Determines the access to controller.
   */
  public function access(AccountInterface $account) {
    $header_secret = $this->requestStack->getCurrentRequest()->headers->get('ocha-ai-chat-statistics') ?? NULL;
    $config_secret = $this->config('ocha_ai')->get('statistics.key');
    if (($header_secret && $header_secret === $config_secret)
      || $account->hasPermission('view ocha ai chat logs')) {
      $access_result = AccessResult::allowed();
    }
    else {
      $access_result = AccessResult::forbidden();
      $logger = $this->getLogger('ocha_ai');
      $logger->warning('Unauthorized access to statistics denied');
    }
    $access_result
      ->setCacheMaxAge(0)
      ->addCacheContexts([
        'headers:ocha-ai-chat-statistics',
        'user.roles',
      ])
      ->addCacheTags(['ocha_ai_chat_statistics']);
    return $access_result;
  }

}

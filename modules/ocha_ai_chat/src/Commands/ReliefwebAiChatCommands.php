<?php

namespace Drupal\ocha_ai_chat\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ocha_ai_chat\Services\OchaAiChat;
use Drush\Commands\DrushCommands;

/**
 * Analyze AI Chat logs.
 */
class ReliefwebAiChatCommands extends DrushCommands {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected OchaAiChat $ochaChat,
  ) {}

  /**
   * Analyze logs.
   *
   * @command ocha-ai-chat:analyze-logs
   *
   * @usage ocha-ai-chat:analyze-logs
   *   Analyze logs.
   *
   * @validate-module-enabled ocha_ai_chat
   */
  public function analyzeLogs(string $filename = '/var/www/html/rw-chat-logs.tsv', string $filename_out = '/var/www/html/rw-chat-rerun.tsv') {
    if (!file_exists($filename)) {
      $this->output->writeln('File not found: ' . $filename);
    }

    $out = fopen($filename_out, 'w');

    $f = fopen($filename, 'r');
    $header = fgetcsv($f, NULL, "\t");
    $header_lowercase = array_map('strtolower', $header);
    $header_lowercase[] = 'url';
    $header_lowercase[] = 'new answer';
    $header_lowercase[] = 'new status';
    $header_lowercase[] = 'original_answer';

    fputcsv($out, $header_lowercase, "\t");

    // Get data.
    $count = 0;
    while (($row = fgetcsv($f, NULL, "\t")) && $count < 1000) {
      $data = [];
      for ($i = 0; $i < count($row); $i++) {
        $data[$header_lowercase[$i]] = $row[$i];
      }

      if (!isset($data['status']) || $data['status'] == 'success') {
        continue;
      }

      $source_data = json_decode($data['source_data'], TRUE);
      $data['url'] = $source_data['url'];
      $data['url'] = str_replace('https://reliefweb.int/updates?search=url_alias:', '', $data['url']);
      $data['url'] = str_replace('"', '', $data['url']);

      $count++;
      $this->output->writeln($count . '. ' . $data['url']);

      $question = $data['question'];
      $answer = $this->ochaChat->answer($question, $source_data);

      $data['new answer'] = $answer['answer'];
      $data['new status'] = $answer['status'];
      $data['new original_answer'] = $answer['original_answer'];
      fputcsv($out, $data, "\t");
    }

    fclose($f);
    fclose($out);
  }

}

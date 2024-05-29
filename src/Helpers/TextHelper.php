<?php

namespace Drupal\ocha_ai\Helpers;

use Drupal\Component\Utility\Unicode;

/**
 * Helper to manipulate text.
 */
class TextHelper {

  public const UNIT_TOKEN = 'token';
  public const UNIT_CHAR = 'char';

  /**
   * Estimate the number of tokens for a text.
   *
   * @param string $text
   *   Text.
   *
   * @return int
   *   Estimated number of tokens in the text.
   */
  public static function estimateTokenCount(string $text): int {
    $word_count = count(preg_split('/[^\p{L}\p{N}\']+/u', $text));
    return floor(4 * $word_count / 3);
  }

  /**
   * Estimate the string length based on number of tokens.
   *
   * @param int $token_count
   *   Text.
   *
   * @return int
   *   Estimated number of characters in the text.
   */
  public static function estimateStringLength(int $token_count): int {
    return (int) floor(.9 * 4 * $token_count);
  }

  /**
   * Split a text in paragraphs.
   *
   * @param string $text
   *   Text.
   *
   * @return array
   *   Array of one or more strings.
   */
  public static function splitTextInParagraphs(string $text) : array {
    $text = trim($text);
    if (empty($text)) {
      return [];
    }

    return preg_split('/\n{2,}/u', $text, -1, \PREG_SPLIT_NO_EMPTY);
  }

  /**
   * Split paragraph in sentences.
   *
   * @param string $text
   *   Text.
   * @param string $pattern_id
   *   Pattern to use.
   *
   * @return array
   *   Array of one or more strings with meta data.
   */
  public static function splitParagraphsInSentences(string $text, string $pattern_id = 'default') : array {
    $text = trim($text);
    if (empty($text)) {
      return [];
    }

    if ($pattern_id == 'default') {
      $pattern_id = 'punctuation';
    }

    // Possible patterns to use.
    $patterns = [
      'punctuation' => '/([;.!?。؟]+)\s+/u',
      'split_capital' => '~[.?!]+\K\s+(?=[A-Z])~',
    ];
    $pattern = $patterns[$pattern_id] ?? $patterns['punctuation'];

    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim(preg_replace($pattern, "$1\n", $text));

    $sentences = [];
    foreach (preg_split('/\n+/u', $text, -1, \PREG_SPLIT_NO_EMPTY) as $line) {
      $sentences[] = [
        'text' => $line,
        'token_count' => self::estimateTokenCount($line),
        'char_count' => mb_strlen($line),
      ];
    }

    return $sentences;
  }

  /**
   * Split a text based in lines of <length> characters.
   *
   * @param string $text
   *   Text.
   * @param int $length
   *   Max lenght.
   * @param string $unit
   *   Unit for length.
   * @param string $pattern_id
   *   Pattern to use.
   *
   * @return array
   *   Array of one or more strings.
   */
  public static function splitInLines(string $text, int $length, string $unit = self::UNIT_TOKEN, string $pattern_id = 'default') : array {
    $output = [];
    $text = trim($text);

    if (empty($text)) {
      return $output;
    }

    if ($length <= 0) {
      return $output;
    }

    $str_length = $length;
    if ($unit == self::UNIT_TOKEN) {
      $str_length = self::estimateStringLength($length);
    }

    $paragraphs = self::splitTextInParagraphs($text);
    if (empty($paragraphs)) {
      return $output;
    }

    foreach ($paragraphs as $paragraph) {
      $sentences = self::splitParagraphsInSentences($paragraph, $pattern_id);

      foreach ($sentences as $sentence) {
        if (self::getLength($sentence, $unit) < $length) {
          $output[] = $sentence;
          continue;
        }

        // Line is too long.
        while (mb_strlen($sentence['text']) > 0) {
          $line = trim(self::truncate($sentence['text'], $str_length));
          $output[] = [
            'text' => $line,
            'token_count' => self::estimateTokenCount($line),
            'char_count' => mb_strlen($line),
          ];
          $sentence['text'] = trim(mb_substr($sentence['text'], mb_strlen($line)));
        }
      }
    }

    return $output;
  }

  /**
   * Split a text based in lines of n characters with an optional overlap.
   *
   * @param string $text
   *   Text.
   * @param int $length
   *   Max lenght.
   * @param string $unit
   *   Unit for length.
   * @param string $overlap
   *   Overlap in number of characters.
   * @param string $pattern_id
   *   Pattern to use.
   *
   * @return array
   *   Array of one or more strings.
   */
  public static function splitInLinesOptimalLength(string $text, int $length, string $unit = self::UNIT_TOKEN, int $overlap = 0, string $pattern_id = 'default') : array {
    $output = [];
    $text = trim($text);
    $overlap = max(0, $overlap);

    if (empty($text)) {
      return $output;
    }

    if ($length <= 0) {
      return $output;
    }

    $overlap_length = $overlap;
    if ($unit == self::UNIT_TOKEN) {
      $overlap_length = self::estimateStringLength($overlap);
    }

    $str_length = $length;
    if ($unit == self::UNIT_TOKEN) {
      $str_length = self::estimateStringLength($length);
    }

    // Split in multiple lines, respecting max length.
    $sentences = self::splitInLines($text, $length - $overlap, $unit, $pattern_id);

    $line = [];
    foreach ($sentences as $sentence) {
      if (empty($line)) {
        $line_text = $sentence['text'];
        $line = [
          'text' => $line_text,
          'token_count' => self::estimateTokenCount($line_text),
          'char_count' => mb_strlen($line_text),
        ];
        continue;
      }

      if ((self::getLength($line, $unit) + self::getLength($sentence, $unit)) < $length) {
        $line_text = trim($line['text']) . ' ' . trim($sentence['text']);
        $line = [
          'text' => $line_text,
          'token_count' => self::estimateTokenCount($line_text),
          'char_count' => mb_strlen($line_text),
        ];

        continue;
      }

      // Adding sentence will make a too long string, start new one.
      $output[] = trim($line['text']);

      $line_text = $sentence['text'];
      $line = [
        'text' => $line_text,
        'token_count' => self::estimateTokenCount($line_text),
        'char_count' => mb_strlen($line_text),
      ];
      continue;



      $part = trim(self::truncate($sentence['text'], $str_length - $line['char_count']));
      $line_text = trim($line['text']) . ' ' . $part;
      $line = [
        'text' => $line_text,
        'token_count' => self::estimateTokenCount($line_text),
        'char_count' => mb_strlen($line_text),
      ];

      $output[] = trim($line['text']);

      $str_overlap = '';
      if ($overlap_length > 0) {
        $str_overlap = trim(strrev(self::truncate(strrev($line['text']), $overlap_length)));
      }

      $line_text = $str_overlap . ' ' . trim(mb_substr($sentence['text'], mb_strlen($part)));
      $line = [
        'text' => $line_text,
        'token_count' => self::estimateTokenCount($line_text),
        'char_count' => mb_strlen($line_text),
      ];
    }

    if (!empty($line)) {
      $output[] = $line['text'];
    }

    return $output;
  }

  /**
   * Return length from meta data.
   */
  protected static function getLength(array $input, string $unit) : int {
    switch ($unit) {
      case self::UNIT_TOKEN:
        return $input['token_count'] ?? 0;

      case self::UNIT_CHAR:
        return $input['char_count'] ?? 0;

      default:
        return 0;
    }
  }

  /**
   * Truncate on word boundary.
   */
  public static function truncate(string $string, int $max_length) {
    $max_length = max($max_length, 0);

    if ($max_length == 0) {
      return '';
    }

    if (mb_strlen($string) <= $max_length) {
      return $string;
    }

    $string = mb_substr($string, 0, $max_length + 1);
    $parts = preg_split('/\s+/', $string);
    array_pop($parts);

    return implode(' ', $parts);
  }

}

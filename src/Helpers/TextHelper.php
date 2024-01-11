<?php

namespace Drupal\ocha_ai_chat\Helpers;

/**
 * Helper to manipulate text.
 */
class TextHelper {

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

}

<?php

namespace Drupal\ocha_ai\Plugin;

/**
 * Interface for the ranker plugins.
 */
interface RankerPluginInterface {

  /**
   * Rank texts.
   *
   * @param string $text
   *   Text to which the other texts will be compared (ex: question).
   * @param array<string> $texts
   *   Texts to rank by relevance to the given text.
   * @param string $language
   *   Language of the texts.
   * @param int|null $limit
   *   Maximum number of relevant texts to return.
   *
   * @return array<string, float>
   *   Ranked texts (keys) with their score (value).
   */
  public function rankTexts(string $text, array $texts, string $language, ?int $limit = NULL): array;

}

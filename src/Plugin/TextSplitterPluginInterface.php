<?php

namespace Drupal\ocha_ai\Plugin;

/**
 * Interface for the text splitter plugins.
 */
interface TextSplitterPluginInterface {

  /**
   * Split text.
   *
   * @param string $text
   *   Text to split.
   * @param int|null $length
   *   Length of the chunks (ex: character length, number of tokens etc.).
   * @param int|null $overlap
   *   Number of characters, tokens etc. from the previous chunk to include in
   *   the current chunk of text to preserve context.
   *
   * @return array
   *   List of text chunks
   */
  public function splitText(string $text, ?int $length = NULL, ?int $overlap = NULL): array;

}

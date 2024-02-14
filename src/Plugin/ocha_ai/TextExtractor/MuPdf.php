<?php

namespace Drupal\ocha_ai\Plugin\ocha_ai\TextExtractor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\ocha_ai\Plugin\TextExtractorPluginBase;

/**
 * PDF to text extractor using MuPDF.
 *
 * @OchaAiChatTextExtractor(
 *   id = "mupdf",
 *   label = @Translation("MuPDF"),
 *   description = @Translation("Extract text from PDF using MuPDF"),
 *   mimetypes = {
 *     "application/pdf",
 *   }
 * )
 *
 * @todo if we can use FFI we may be able to extract the text for each page
 * without having to call mutool for each page which would be much faster.
 */
class MuPdf extends TextExtractorPluginBase {

  /**
   * Sentence terminals.
   *
   * @var string
   */
  protected string $sentenceTerminals;

  /**
   * Path to mutool executable.
   *
   * @var string
   */
  protected string $mutool;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $plugin_type = $this->getPluginType();
    $plugin_id = $this->getPluginId();
    $config = $this->getConfiguration() + $this->defaultConfiguration();

    $form['plugins'][$plugin_type][$plugin_id]['mutool'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mutool'),
      '#description' => $this->t('Path to the mutool executable'),
      '#default_value' => $config['mutool'] ?? NULL,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getText(string $path): string {
    // This is much faster than retrieving the text for each page but we lose
    // the ability to reference particular pages.
    return $this->getPageRangeText($path, '1-N');
  }

  /**
   * {@inheritdoc}
   */
  public function getPageTexts(string $path): array {
    $page_count = $this->getPageCount($path);

    // For easier referencing when retrieving relevant parts of a document,
    // we retrieve the text for each page individually.
    //
    // @todo evaluate whether to extract the entire text at once instead as it
    // might help with paragraphs split between pages.
    $texts = [];
    for ($page = 1; $page <= $page_count; $page++) {
      $texts[$page] = $this->getPageRangeText($path, $page . '-' . $page);
    }

    return $texts;
  }

  /**
   * {@inheritdoc}
   */
  public function getPageCount(string $path): int {
    $mutool = $this->getMutool();
    $source = escapeshellarg($path);

    $command = "{$mutool} info -M {$source}";
    exec($command, $output, $result_code);

    if (empty($result_code) && preg_match('/Pages: (?<count>\d+)/', implode("\n", $output), $matches) === 1) {
      return intval($matches['count']);
    }
    return 1;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedMimetypes(): array {
    return ['application/pdf'];
  }

  /**
   * {@inheritdoc}
   */
  protected function getPageRangeText(string $path, string $page_range): string {
    $tempfile = tempnam(sys_get_temp_dir(), 'mupdf_');

    $mutool = $this->getMutool();
    $options = implode(',', [
      'preserve-ligatures',
      'preserve-whitespace',
      'dehyphenate',
      'mediabox-clip=yes',
    ]);
    $destination = escapeshellarg($tempfile);
    $source = escapeshellarg($path);

    $command = "{$mutool} convert -F text -O {$options} -o {$destination} {$source} {$page_range}";
    exec($command, $output, $result_code);

    if (empty($result_code)) {
      $text = file_get_contents($tempfile);
    }
    else {
      $text = '';
    }

    unlink($tempfile);
    return $this->correctText($text);
  }

  /**
   * Normalize a text removing unwanted chacters, dehyphenate etc.
   *
   * @param string $text
   *   Text to normalize.
   *
   * @return string
   *   Normalized text.
   */
  protected function normalizeText(string $text): string {
    // Remove carriage return characters.
    $text = preg_replace(['/\r(?!\n)/u', '/\r/u'], [' ', ''], $text);

    // Remove exponents.
    $text = preg_replace('/\n\d{1,2}\n/u', '', $text);

    // Correct hyphenated words.
    $text = preg_replace('/(\S-)\n/u', '$1', $text);

    // Remove non-break space.
    $text = preg_replace('/[\xa0]/u', ' ', $text);

    // Remove extra spaces.
    $text = preg_replace('/[ ]+/u', ' ', $text);

    // Remove non printable or invalid characters.
    $text = preg_replace('/[^\P{C}\t\r\n]/u', '', $text);

    return $text;
  }

  /**
   * Try to guess paragraphs.
   *
   * @param string $text
   *   Text.
   *
   * @return string
   *   Text with paragraph separators.
   */
  protected function guessParagraphs(string $text): string {
    // We consider that 2 line breaks following a sentence terminal is a
    // paragraph separator. We replace them with a marker.
    $text = preg_replace('/([' . $this->getSentenceTerminals() . '])\s*(?:\n\s*){2,}/u', '$1#P#', $text);

    // Convert line breaks not following a sentence terminal into spaces.
    $text = preg_replace('/([^' . $this->getSentenceTerminals() . '])\s*(?:\n\s*){1,}/u', '$1 ', $text);

    // Remaining line breaks are considered valid line breaks and are preserved.
    $text = preg_replace('/\s*(?:\n\s*){1,}/u', "\n", $text);

    // Remove consecutive white spaces.
    $text = preg_replace('/\s+/u', ' ', $text);

    // Replace the paragraph separator with double line breaks.
    $text = preg_replace('/(?:#P#)+/', "\n\n", $text);

    return $text;
  }

  /**
   * Try to guess lists.
   *
   * @param string $text
   *   Text.
   *
   * @return string
   *   Text with list separators.
   *
   * @todo add some common bullet characters.
   */
  protected function guessLists(string $text): string {
    // Line breaks before (a) or a) or a.
    $text = preg_replace('/([,;:?!.)])(?:(?:\s*\n\s*)+)([(]?[A-z0-9]{1,3}[).])/u', '$1#L#$2', $text);

    // Line breaks before bullet points.
    $text = preg_replace('/([,;:?!.)])(?:(?:\s*\n\s*)+)([\x{2022}\x{2024}\x{00b7}\x{2027}\x{25e6}\x{22c5}*-])/u', '$1#L#$2', $text);

    return $text;
  }

  /**
   * Try to guess valid line breaks.
   *
   * @param string $text
   *   Text.
   *
   * @return string
   *   Text with line break separators.
   */
  protected function guessValidLineBreaks(string $text): string {
    // Convert line breaks not following a sentence terminal into spaces.
    $text = preg_replace('/([^' . $this->getSentenceTerminals() . '])\s*(?:\n\s*){1,}/u', '$1 ', $text);

    // Remaining line breaks are considered valid line breaks and are preserved.
    $text = preg_replace('/\s*(?:\n\s*){1,}/u', "#B#", $text);

    return $text;
  }

  /**
   * Format text, replacing separators from other functions.
   *
   * @param string $text
   *   Text.
   *
   * @return string
   *   Formatted text.
   */
  protected function formatText(string $text): string {
    // Invalid line breaks.
    $text = preg_replace('/\s*\n\s*/u', ' ', $text);
    // Paragraphs.
    $text = preg_replace('/#P#/u', "\n\n", $text);
    // Lists.
    $text = preg_replace('/#L#/u', "\n\n", $text);
    // Valid line breaks.
    $text = preg_replace('/#B#/u', "  \n", $text);

    return $text;
  }

  /**
   * Correct a text extracted from a PDF.
   *
   * @param string $text
   *   Text.
   *
   * @return string
   *   Corrected text.
   */
  protected function correctText(string $text): string {
    // Clean the text.
    $text = $this->normalizeText($text);

    // Guess format.
    $text = $this->guessParagraphs($text);
    $text = $this->guessLists($text);
    $text = $this->guessValidLineBreaks($text);

    // Correct format.
    $text = $this->formatText($text);

    return trim($text);
  }

  /**
   * Get sentence ending characters to use in a regex.
   *
   * @return string
   *   Sentence terminals.
   */
  protected function getSentenceTerminals(): string {
    if (!isset($this->sentenceTerminals)) {
      $this->sentenceTerminals = implode('', [
        // (!) Po EXCLAMATION MARK.
        '\x{0021}',
        // (.) Po FULL STOP.
        '\x{002E}',
        // (?) Po QUESTION MARK.
        '\x{003F}',
        // (։) Po ARMENIAN FULL STOP.
        '\x{0589}',
        // (؟) Po ARABIC QUESTION MARK.
        '\x{061F}',
        // (۔) Po ARABIC FULL STOP.
        '\x{06D4}',
        // (܀) Po SYRIAC END OF PARAGRAPH.
        '\x{0700}',
        // (܁) Po SYRIAC SUPRALINEAR FULL STOP.
        '\x{0701}',
        // (܂) Po SYRIAC SUBLINEAR FULL STOP.
        '\x{0702}',
        // (।) Po DEVANAGARI DANDA.
        '\x{0964}',
        // (၊) Po MYANMAR SIGN LITTLE SECTION.
        '\x{104A}',
        // (။) Po MYANMAR SIGN SECTION.
        '\x{104B}',
        // (።) Po ETHIOPIC FULL STOP.
        '\x{1362}',
        // (፧) Po ETHIOPIC QUESTION MARK.
        '\x{1367}',
        // (፨) Po ETHIOPIC PARAGRAPH SEPARATOR.
        '\x{1368}',
        // (᙮) Po CANADIAN SYLLABICS FULL STOP.
        '\x{166E}',
        // (᠃) Po MONGOLIAN FULL STOP.
        '\x{1803}',
        // (᠉) Po MONGOLIAN MANCHU FULL STOP.
        '\x{1809}',
        // (‼) Po DOUBLE EXCLAMATION MARK.
        '\x{203C}',
        // (‽) Po INTERROBANG.
        '\x{203D}',
        // (⁇) Po DOUBLE QUESTION MARK.
        '\x{2047}',
        // (⁈) Po QUESTION EXCLAMATION MARK.
        '\x{2048}',
        // (⁉) Po EXCLAMATION QUESTION MARK.
        '\x{2049}',
        // (。) Po IDEOGRAPHIC FULL STOP.
        '\x{3002}',
        // (﹒) Po SMALL FULL STOP.
        '\x{FE52}',
        // (﹗) Po SMALL EXCLAMATION MARK.
        '\x{FE57}',
        // (！) Po FULLWIDTH EXCLAMATION MARK.
        '\x{FF01}',
        // (．) Po FULLWIDTH FULL STOP.
        '\x{FF0E}',
        // (？) Po FULLWIDTH QUESTION MARK.
        '\x{FF1F}',
        // (｡) Po HALFWIDTH IDEOGRAPHIC FULL STOP.
        '\x{FF61}',
      ]);
    }
    return $this->sentenceTerminals;
  }

  /**
   * Get the mutool executable.
   *
   * @return string
   *   Path to the mutool executable.
   */
  protected function getMutool(): string {
    if (!isset($this->mutool)) {
      $mutool = $this->getPluginSetting('mutool', '/usr/bin/mutool');
      if (is_executable($mutool)) {
        $this->mutool = $mutool;
      }
      else {
        throw new \Exception('Mutool executable not found or invalid.');
      }
    }
    return $this->mutool;
  }

}

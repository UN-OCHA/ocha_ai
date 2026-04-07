<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Plugin\ocha_ai\Completion;

/**
 * Capability flags supported by completion plugins.
 */
enum CompletionCapability: string {

  case FileInput = 'file_input';
  case ThinkingMode = 'thinking_mode';
  case StructuredOutput = 'structured_output';

}

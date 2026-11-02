<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Exception;

/**
 * Per-request completion failure that may succeed on retry.
 *
 * Examples: model tool-use glitches or timeouts for a single call. The same
 * input may work on a later attempt.
 */
class CompletionRequestException extends CompletionException {}

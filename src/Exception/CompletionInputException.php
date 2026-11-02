<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Exception;

/**
 * Permanent completion failure caused by invalid or unsuitable input.
 *
 * Examples: request validation errors such as input token limits exceeded.
 * Retrying the same request as-is will not succeed.
 */
class CompletionInputException extends CompletionException {}

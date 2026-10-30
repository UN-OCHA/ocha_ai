<?php

declare(strict_types=1);

namespace Drupal\ocha_ai\Exception;

/**
 * Completion failure caused by the provider or transport layer.
 *
 * Examples: throttling, outages, authentication failures, or network errors.
 * These typically affect many requests until the underlying issue is resolved.
 */
class CompletionProviderException extends CompletionException {}

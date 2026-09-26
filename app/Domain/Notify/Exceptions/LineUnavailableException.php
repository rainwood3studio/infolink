<?php

namespace App\Domain\Notify\Exceptions;

use RuntimeException;

/**
 * LINE could not be reached or answered 5xx. Transient: the push job retries with backoff.
 */
class LineUnavailableException extends RuntimeException {}

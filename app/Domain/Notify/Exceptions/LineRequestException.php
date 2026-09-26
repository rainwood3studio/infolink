<?php

namespace App\Domain\Notify\Exceptions;

use RuntimeException;

/**
 * LINE refused the push (4xx: bad token, unknown user, quota exhausted...). Retrying will not help.
 */
class LineRequestException extends RuntimeException {}

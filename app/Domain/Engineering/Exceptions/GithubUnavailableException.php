<?php

namespace App\Domain\Engineering\Exceptions;

use RuntimeException;

/**
 * GitHub could not be reached, answered 5xx, or the token is rate limited (primary or secondary limit).
 * Not alert-worthy: the sync records a failed run and tries again next hour.
 */
class GithubUnavailableException extends RuntimeException {}

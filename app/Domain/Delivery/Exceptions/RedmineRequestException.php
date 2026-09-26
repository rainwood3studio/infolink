<?php

namespace App\Domain\Delivery\Exceptions;

use RuntimeException;

/**
 * Redmine answered but refused the request (4xx: bad API key, missing permission, bad filter) or is not configured.
 */
class RedmineRequestException extends RuntimeException {}

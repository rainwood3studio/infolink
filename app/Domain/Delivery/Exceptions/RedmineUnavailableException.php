<?php

namespace App\Domain\Delivery\Exceptions;

use RuntimeException;

/**
 * Redmine could not be reached (off the company network / VPN, timeout, 5xx). Expected and not alert-worthy:
 * the sync records a failed run and tries again next hour.
 */
class RedmineUnavailableException extends RuntimeException {}

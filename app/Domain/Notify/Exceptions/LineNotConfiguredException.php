<?php

namespace App\Domain\Notify\Exceptions;

use RuntimeException;

/**
 * LINE_CHANNEL_ACCESS_TOKEN or LINE_USER_ID is empty. The Notifier logs the attempt as skipped instead of failing.
 */
class LineNotConfiguredException extends RuntimeException {}

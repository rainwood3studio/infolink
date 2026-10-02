<?php

namespace App\Domain\Engineering\Exceptions;

use RuntimeException;

/**
 * GitHub answered but refused the request (4xx: bad token, missing repo access, not found, GraphQL query errors)
 * or is not configured. Scoped to one request: the sync skips the repo and carries on.
 */
class GithubRequestException extends RuntimeException {}

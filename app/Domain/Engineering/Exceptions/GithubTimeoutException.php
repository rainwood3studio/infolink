<?php

namespace App\Domain\Engineering\Exceptions;

/**
 * GitHub gave up on the request (502/504, or dropped the connection, after retries) — typically a GraphQL page that
 * is too expensive to compute, such as commit history with line counts over very large commits. Callers can retry
 * with a smaller page.
 */
class GithubTimeoutException extends GithubUnavailableException {}

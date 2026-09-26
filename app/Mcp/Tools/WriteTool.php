<?php

namespace App\Mcp\Tools;

use App\Enums\Source;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/**
 * A tool that writes; requires the `write` token ability. Writes are idempotent (external keys / fingerprints)
 * and are recorded with source `claude`.
 */
#[IsIdempotent]
abstract class WriteTool extends InfolinkTool
{
    protected const Source SOURCE = Source::Claude;

    protected function ability(): string
    {
        return self::ABILITY_WRITE;
    }
}

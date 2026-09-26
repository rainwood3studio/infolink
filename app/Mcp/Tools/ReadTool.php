<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * A tool that only reads; requires the `read` token ability.
 */
#[IsReadOnly]
abstract class ReadTool extends InfolinkTool
{
    protected function ability(): string
    {
        return self::ABILITY_READ;
    }
}

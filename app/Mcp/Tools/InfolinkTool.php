<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base for every INFOLINK MCP tool. A tool is only listed and callable when the caller's Sanctum token has
 * the tool's ability (`read` or `write`). Writes made through tools record the token name as `actor`
 * (see HasSource::resolveActor()).
 */
abstract class InfolinkTool extends Tool
{
    public const string ABILITY_READ = 'read';

    public const string ABILITY_WRITE = 'write';

    /**
     * The token ability required to see and call this tool.
     */
    abstract protected function ability(): string;

    public function shouldRegister(Request $request): bool
    {
        return $this->isAllowed($request);
    }

    protected function isAllowed(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && method_exists($user, 'tokenCan') && $user->tokenCan($this->ability());
    }

    /**
     * Guard for handle(): tools can be called directly even when not listed.
     */
    protected function forbidden(Request $request): ?Response
    {
        return $this->isAllowed($request)
            ? null
            : Response::error("This token lacks the [{$this->ability()}] ability.");
    }
}

<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\InfolinkTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use ReflectionClass;

#[Name('INFOLINK')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
INFOLINK (聯騰資訊) operations hub: finance, receivables, Redmine delivery, action items and insights for a 3-person software company.

- Start any analysis with `get_briefing`; it returns the whole picture in one call.
- Amounts are whole NTD integers. Receivable amounts are untaxed unless a field says `taxed`.
- Read metric `description`s before interpreting numbers. Delivery: all final acceptance is done by one person (文豪), so closed counts are NOT throughput.
- Before writing an insight or action item, list existing ones to avoid duplicates. Insights are deduplicated by `fingerprint` (`<category>-<subject>:<id>`, e.g. `receivable-overdue:長照-期中款`).
- Writes are idempotent; re-running the same analysis must not create duplicates. Never delete data — resolve or drop instead.
MARKDOWN)]
class InfolinkServer extends Server
{
    /**
     * Tools and prompts are discovered from app/Mcp/Tools and app/Mcp/Prompts; see boot().
     */
    protected array $tools = [];

    protected array $resources = [];

    protected array $prompts = [];

    protected function boot(): void
    {
        $this->tools = $this->discover('Tools', InfolinkTool::class);
        $this->prompts = $this->discover('Prompts', Prompt::class);
    }

    /**
     * @param  class-string  $baseClass
     * @return list<class-string>
     */
    protected function discover(string $directory, string $baseClass): array
    {
        $classes = [];

        foreach (glob(app_path("Mcp/{$directory}/*.php")) ?: [] as $file) {
            $class = 'App\\Mcp\\'.$directory.'\\'.basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, $baseClass) && ! (new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}

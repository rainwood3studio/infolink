<?php

namespace App\Mcp\Tools;

use App\Domain\Insights\InsightService;
use App\Enums\InsightStatus;
use App\Models\Insight;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('resolve_insight')]
#[Description('Close an insight because the underlying issue is dealt with (e.g. the overdue payment arrived). Identify it by `id` or `fingerprint` (resolves every unresolved insight with that fingerprint). Always give a `note` with the reason. Idempotent: resolving an already resolved insight changes nothing. Data is never deleted.')]
class ResolveInsight extends WriteTool
{
    public function handle(Request $request, InsightService $insights): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'required_without:fingerprint'],
            'fingerprint' => ['nullable', 'string', 'required_without:id'],
            'note' => ['required', 'string'],
        ], [
            'id.required_without' => 'Identify the insight with `id` or `fingerprint` (see list_insights).',
            'fingerprint.required_without' => 'Identify the insight with `id` or `fingerprint` (see list_insights).',
            'note.required' => 'Pass a `note` explaining why it is resolved, e.g. 9/30 已入帳 472,500.',
        ]);

        if (isset($validated['id'])) {
            $insight = Insight::query()->find($validated['id']);

            if ($insight === null) {
                return Response::error("No insight with id {$validated['id']}.");
            }

            if (! in_array($insight->status, [InsightStatus::Open, InsightStatus::Acknowledged], true)) {
                return Response::json(['result' => 'already_closed', 'id' => $insight->id, 'status' => $insight->status->value]);
            }

            $insights->resolve($insight, $validated['note']);

            return Response::json(['result' => 'resolved', 'id' => $insight->id, 'fingerprint' => $insight->fingerprint]);
        }

        $count = $insights->resolveByFingerprint($validated['fingerprint'], $validated['note']);

        if ($count > 0) {
            return Response::json(['result' => 'resolved', 'fingerprint' => $validated['fingerprint'], 'resolved' => $count]);
        }

        if (Insight::query()->where('fingerprint', $validated['fingerprint'])->exists()) {
            return Response::json(['result' => 'already_closed', 'fingerprint' => $validated['fingerprint'], 'resolved' => 0]);
        }

        return Response::error("No insight with fingerprint [{$validated['fingerprint']}]; use list_insights to find it.");
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Insight id.'),
            'fingerprint' => $schema->string()->description('Insight fingerprint, instead of id.'),
            'note' => $schema->string()->description('Why it is resolved; appended to the insight notes.')->required(),
        ];
    }
}

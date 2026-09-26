<?php

namespace Tests\Feature\Domain\Delivery\Sync;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * In-memory Redmine REST API for Http::fake(), modelled on real INFOLINK Redmine payloads: list endpoints page by
 * offset/limit and return total_count; issue.project only carries {id, name}; status carries is_closed.
 * Filters are not applied — tests assert on the sent query instead.
 */
class FakeRedmine
{
    /** @var list<array<string, mixed>> */
    public array $issues = [];

    /** @var list<array<string, mixed>> */
    public array $timeEntries = [];

    /** @var list<array<string, mixed>> */
    public array $projects = [];

    /** @var list<array<string, mixed>> */
    public array $issueStatuses = [
        ['id' => 1, 'name' => '新建立', 'is_closed' => false],
        ['id' => 2, 'name' => '實作中', 'is_closed' => false],
        ['id' => 7, 'name' => '驗證中', 'is_closed' => false],
        ['id' => 5, 'name' => '完成', 'is_closed' => true],
    ];

    /** Answer HTTP 500 for list requests at or beyond this offset (simulates a mid-sync failure). */
    public ?int $failFromOffset = null;

    public bool $unreachable = false;

    public function __construct()
    {
        $this->projects = [
            self::project(2, 'release', 'Release'),
            self::project(7, 'im', '我識'),
            self::project(15, 'tcsb-5f-b2c', '墊腳石 | 5F '),
            self::project(26, 'iam-monster-one', 'iam-monster-one'),
        ];

        Http::fake(fn (Request $request) => $this->respond($request));
    }

    private function respond(Request $request): mixed
    {
        if ($this->unreachable) {
            return Http::failedConnection()($request);
        }

        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $list = match (true) {
            str_ends_with($path, '/issues.json') => ['issues', $this->issues],
            str_ends_with($path, '/time_entries.json') => ['time_entries', $this->timeEntries],
            str_ends_with($path, '/projects.json') => ['projects', $this->projects],
            default => null,
        };

        if (str_ends_with($path, '/issue_statuses.json')) {
            return Http::response(['issue_statuses' => $this->issueStatuses]);
        }

        if ($list === null) {
            return Http::response(['errors' => ['Not found']], 404);
        }

        [$key, $records] = $list;
        $offset = (int) ($query['offset'] ?? 0);
        $limit = min(100, (int) ($query['limit'] ?? 25));

        if ($this->failFromOffset !== null && $key !== 'projects' && $offset >= $this->failFromOffset) {
            return Http::response('Internal Server Error', 500);
        }

        return Http::response([
            $key => array_slice($records, $offset, $limit),
            'total_count' => count($records),
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function project(int $id, string $identifier, string $name): array
    {
        return [
            'id' => $id, 'name' => $name, 'identifier' => $identifier, 'description' => '', 'homepage' => '',
            'status' => 1, 'is_public' => false, 'inherit_members' => false,
            'created_on' => '2025-03-28T17:03:14Z', 'updated_on' => '2025-04-22T01:33:58Z',
        ];
    }

    /**
     * An issue shaped like GET /issues.json output.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function issue(int $id, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'project' => ['id' => 26, 'name' => 'iam-monster-one'],
            'tracker' => ['id' => 1, 'name' => 'Bug'],
            'status' => ['id' => 1, 'name' => '新建立', 'is_closed' => false],
            'priority' => ['id' => 2, 'name' => '正常'],
            'author' => ['id' => 6, 'name' => '永彬'],
            'assigned_to' => ['id' => 6, 'name' => '永彬'],
            'parent' => ['id' => 1763],
            'subject' => "[購物車] 活動會重複兩行 #{$id}",
            'description' => null,
            'start_date' => '2026-09-24',
            'due_date' => null,
            'done_ratio' => 0,
            'is_private' => false,
            'estimated_hours' => null,
            'total_estimated_hours' => null,
            'spent_hours' => 0,
            'total_spent_hours' => 0,
            'custom_fields' => [
                ['id' => 2, 'name' => '客戶期待順序', 'value' => ''],
                ['id' => 3, 'name' => '緊急度', 'value' => ''],
            ],
            'created_on' => '2026-09-24T04:10:45Z',
            'updated_on' => '2026-09-24T04:53:10Z',
            'closed_on' => null,
        ], $overrides);
    }

    /**
     * A time entry shaped like GET /time_entries.json output.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function timeEntry(int $id, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'project' => ['id' => 7, 'name' => '我識'],
            'issue' => ['id' => 2780],
            'user' => ['id' => 8, 'name' => '鈺文'],
            'activity' => ['id' => 9, 'name' => '開發'],
            'hours' => 1,
            'comments' => '',
            'spent_on' => '2026-09-24',
            'created_on' => '2026-09-24T03:19:50Z',
            'updated_on' => '2026-09-24T03:19:50Z',
        ], $overrides);
    }
}

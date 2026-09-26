<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\QueryException;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use PDO;
use PDOException;

/**
 * Ad-hoc SQL for Claude. The real enforcement is the PostgreSQL role `infolink_ro` (SELECT on the v_* views only,
 * statement_timeout 10 s, default_transaction_read_only; see the create_report_views migration); the checks here
 * only give early, readable errors. Every query runs on the `pgsql_ro` connection inside a READ ONLY transaction
 * that is always rolled back.
 */
#[Name('run_readonly_sql')]
#[Description(<<<'TEXT'
Run one read-only PostgreSQL SELECT (or WITH … SELECT) over the analysis views for ad-hoc questions the other tools don't answer. Only the v_* views below are readable; base tables, pg_catalog and information_schema are not. Limits: 10 s, 500 rows (the result says `truncated: true` when there were more — aggregate or filter instead). No comments, no semicolons except a trailing one. Pass `sql: "help"` for the live column list with types and view descriptions.
Amounts are whole NTD integers; dates are Asia/Taipei.

Views and columns:
- v_metric_latest: metric_key, name, category, unit, period_type, better (up|down|none), dimension, period_start, value, previous_period_start, previous_value, change, target, warn_threshold, critical_threshold, over_warn, over_critical, status (ok|warn|critical), is_pinned, source, updated_at, description — one row per metric_key+dimension ('' = no dimension).
- v_metric_values: metric_key, name, category, unit, period_type, dimension, period_start, value, source, notes, updated_at — full metric history.
- v_receivables_open: receivable_id, customer_id, customer (short name), customer_name, project_id, project, item, amount_untaxed, tax_rate, amount_taxed, expected_on, status (planned|invoiced), invoiced_on, confidence (high|low), is_recurring, is_overdue, days_overdue, notes.
- v_cash_monthly: month (YYYY-MM), month_start, inflow, revenue_inflow, outflow, regular_outflow (excl. one-off & reimbursement), one_off_outflow, reimbursement_outflow, net, month_end_balance, txn_count, last_txn_date (current month is partial).
- v_transactions: transaction_id, bank_account, txn_date, sequence, summary, counterparty, withdrawal, deposit, amount (deposit − withdrawal), balance, category, is_one_off, receivable_id, notes.
- v_cost_baselines: effective_from, effective_until, monthly_cost, notes.
- v_planned_cash_flows: planned_cash_flow_id, flow_on, amount (+ in / − out), description, notes.
- v_delivery_trend: snapshot_date, open, verifying (驗證中), verifying_acceptor (assigned to 文豪), verifying_others, verifying_unassigned, unassigned, stalled_30d, stalled_90d, overdue, reconstructed (true = only `open` is known).
- v_delivery_snapshots: snapshot_date, project_identifier, status, assignee_name ('' = unassigned), count, stalled_30d, stalled_90d, overdue, is_reconstructed.
- v_redmine_issues: issue_id, project_identifier, project_name, tracker, status, is_closed, priority, assignee_name, author_name, subject, start_date, due_date, done_ratio, estimated_hours, created_on, updated_on, closed_on, age_days, idle_days, is_overdue.
- v_redmine_status_changes: issue_id, project_identifier, from_status, to_status, assignee_name, previous_assignee_name, changed_at.
- v_redmine_time_entries: time_entry_id, issue_id, project_identifier, user_name, activity, hours, spent_on, comments.
- v_open_attention: kind (insight|action_item), id, severity, priority, category, title, detail, status, due_on, days_overdue, owner, fingerprint, first_seen_at, last_seen_at — unresolved critical/warning insights + pending action items overdue or due today.
TEXT)]
class RunReadonlySql extends ReadTool
{
    public const int MAX_ROWS = 500;

    public const string CONNECTION = 'pgsql_ro';

    /**
     * Whole-word patterns rejected before the query reaches the database (the role would refuse most of them too).
     */
    protected const string FORBIDDEN = '/\b(pg_\w+|information_schema|set_config|current_setting|dblink\w*|lo_\w+|\w+_to_xml\w*|query_to_\w+|insert|update|delete|merge|truncate|drop|alter|create|grant|revoke|copy|into|execute|call|lock|listen|notify|vacuum)\b/i';

    public function handle(Request $request): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'sql' => ['required', 'string', 'max:20000'],
        ]);

        $sql = trim($validated['sql']);
        $isHelp = strcasecmp($sql, 'help') === 0;
        $sql = rtrim($sql, " \t\n\r\0\x0B;");

        if (! $isHelp && ($error = $this->rejection($sql))) {
            return Response::error($error);
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return Response::error('run_readonly_sql requires PostgreSQL: the v_* views and the infolink_ro role only exist there.');
        }

        if ($isHelp) {
            return $this->help();
        }

        try {
            $result = $this->select('SELECT * FROM ('.$sql."\n) AS q LIMIT ".(self::MAX_ROWS + 1));
        } catch (PDOException|QueryException $exception) {
            return Response::error($this->errorMessage($exception));
        }

        $truncated = count($result['rows']) > self::MAX_ROWS;
        $rows = array_slice($result['rows'], 0, self::MAX_ROWS);

        return Response::json([
            'row_count' => count($rows),
            'truncated' => $truncated,
            'columns' => $result['columns'],
            'rows' => $rows,
        ]);
    }

    /**
     * Why the statement is refused, or null when it may be sent to the database.
     */
    protected function rejection(string $sql): ?string
    {
        return match (true) {
            $sql === '' => 'Empty SQL.',
            str_contains($sql, ';') => 'Only a single statement is allowed (no semicolons except a trailing one).',
            str_contains($sql, '--') || str_contains($sql, '/*') => 'SQL comments are not allowed.',
            preg_match('/^\(*\s*(select|with)\b/i', $sql) !== 1 => 'Only SELECT or WITH … SELECT statements are allowed.',
            preg_match('/\bfor\s+(update|share|no\s+key\s+update|key\s+share)\b/i', $sql) === 1 => 'Locking clauses (FOR UPDATE / FOR SHARE) are not allowed.',
            preg_match(self::FORBIDDEN, $sql, $match) === 1 => "`{$match[1]}` is not allowed: only SELECTs over the v_* views. Call with sql: \"help\" for the view list.",
            default => null,
        };
    }

    /**
     * The live list of readable views and columns, as the read-only role sees them.
     */
    protected function help(): Response
    {
        try {
            $result = $this->select(<<<'SQL'
                SELECT c.table_name AS view, c.column_name AS column, c.data_type AS type,
                    obj_description(format('%I.%I', c.table_schema, c.table_name)::regclass, 'pg_class') AS view_comment
                FROM information_schema.columns c
                WHERE c.table_schema = 'public' AND c.table_name LIKE 'v\_%'
                ORDER BY c.table_name, c.ordinal_position
                SQL);
        } catch (PDOException|QueryException $exception) {
            return Response::error($this->errorMessage($exception));
        }

        $views = [];

        foreach ($result['rows'] as [$view, $column, $type, $comment]) {
            $views[$view] ??= ['description' => $comment, 'columns' => []];
            $views[$view]['columns'][$column] = $type;
        }

        return Response::json(['views' => $views]);
    }

    /**
     * Run a query as infolink_ro in a READ ONLY transaction that is always rolled back.
     *
     * @return array{columns: list<string>, rows: list<list<mixed>>}
     */
    protected function select(string $sql): array
    {
        $pdo = DB::connection(self::CONNECTION)->getPdo();

        $pdo->beginTransaction();

        try {
            $pdo->exec('SET TRANSACTION READ ONLY');
            $pdo->exec("SET LOCAL statement_timeout = '10s'");

            $statement = $pdo->query($sql);
            $columns = [];

            for ($index = 0; $index < $statement->columnCount(); $index++) {
                $columns[] = (string) ($statement->getColumnMeta($index)['name'] ?? "column_{$index}");
            }

            return ['columns' => $columns, 'rows' => $statement->fetchAll(PDO::FETCH_NUM)];
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    protected function errorMessage(PDOException|QueryException $exception): string
    {
        $message = $exception instanceof QueryException && $exception->getPrevious() !== null
            ? $exception->getPrevious()->getMessage()
            : $exception->getMessage();

        $hint = str_contains($message, 'permission denied')
            ? ' Only the v_* views are readable; call with sql: "help" for the list.'
            : '';

        return 'Query failed: '.$message.$hint;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sql' => $schema->string()
                ->description('One SELECT / WITH … SELECT over the v_* views (max 500 rows, 10 s). Pass "help" for the live column list.')
                ->required(),
        ];
    }
}

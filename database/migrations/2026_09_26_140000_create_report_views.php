<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Analysis views (`v_*`) for Claude's ad-hoc SQL and charts, plus the read-only role `infolink_ro` that may SELECT
 * only these views (docs/02-data-model.md 分析用 view, docs/01-architecture.md 安全).
 *
 * PostgreSQL only: on other drivers (the SQLite test suite) this migration does nothing and `run_readonly_sql`
 * reports that it requires PostgreSQL.
 *
 * - Views run with the owner's privileges, so `infolink_ro` needs no grant on the underlying tables.
 * - Raw payloads (redmine `raw` json, insight `evidence`, forecast rows), users, tokens, sessions, jobs and
 *   cache tables are never exposed.
 * - v_delivery_trend: the acceptor (services.redmine.acceptor_name, REDMINE_ACCEPTOR_NAME) is baked into the view
 *   definition at migration time as an assignee-name prefix (same rule as RedmineIssue::isAcceptor()). After
 *   changing REDMINE_ACCEPTOR_NAME, re-create the view (roll back and re-run this migration, or a new migration).
 * - A later migration that adds a `v_*` view must `GRANT SELECT ON <view> TO infolink_ro` itself.
 */
return new class extends Migration
{
    public const string ROLE = 'infolink_ro';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->views() as $name => $definition) {
            DB::statement("DROP VIEW IF EXISTS {$name}");
            DB::statement("CREATE VIEW {$name} AS {$definition['sql']}");
            DB::statement("COMMENT ON VIEW {$name} IS ".DB::getPdo()->quote($definition['comment']));
        }

        $this->createReadonlyRole();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse(array_keys($this->views())) as $name) {
            DB::statement("DROP VIEW IF EXISTS {$name}");
        }

        $role = self::ROLE;
        $database = DB::connection()->getDatabaseName();

        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    EXECUTE 'REVOKE ALL ON SCHEMA public FROM {$role}';
                    EXECUTE format('REVOKE ALL ON DATABASE %I FROM {$role}', '{$database}');
                    BEGIN
                        -- Roles are cluster-wide: only drop when no other database (e.g. *_test) still grants it.
                        DROP ROLE {$role};
                    EXCEPTION WHEN dependent_objects_still_exist THEN
                        RAISE NOTICE 'role {$role} kept: still referenced by another database';
                    END;
                END IF;
            END
            \$\$
            SQL);
    }

    /**
     * Create (or update the password of) the login role and grant it SELECT on the v_* views only.
     */
    protected function createReadonlyRole(): void
    {
        $role = self::ROLE;
        $database = DB::connection()->getDatabaseName();
        $password = (string) config('database.connections.pgsql_ro.password');

        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    CREATE ROLE {$role} LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS;
                END IF;
            END
            \$\$
            SQL);

        DB::statement("ALTER ROLE {$role} WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS CONNECTION LIMIT 10");

        if ($password !== '') {
            DB::statement("ALTER ROLE {$role} WITH PASSWORD ".DB::getPdo()->quote($password));
        }

        DB::statement("ALTER ROLE {$role} SET statement_timeout = '10s'");
        DB::statement("ALTER ROLE {$role} SET default_transaction_read_only = on");
        DB::statement("ALTER ROLE {$role} SET idle_in_transaction_session_timeout = '30s'");

        // No temp tables for anyone but the owner/superuser (the ro role inherits PUBLIC's privileges).
        DB::statement(sprintf('REVOKE TEMPORARY ON DATABASE %s FROM PUBLIC', $this->quoteIdentifier($database)));
        DB::statement(sprintf('REVOKE ALL ON DATABASE %s FROM %s', $this->quoteIdentifier($database), $role));
        DB::statement(sprintf('GRANT CONNECT ON DATABASE %s TO %s', $this->quoteIdentifier($database), $role));

        DB::statement('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
        DB::statement("REVOKE ALL ON SCHEMA public FROM {$role}");
        DB::statement("GRANT USAGE ON SCHEMA public TO {$role}");

        DB::statement("REVOKE ALL ON ALL TABLES IN SCHEMA public FROM {$role}");
        DB::statement("REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM {$role}");

        foreach (array_keys($this->views()) as $name) {
            DB::statement("GRANT SELECT ON {$name} TO {$role}");
        }
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * @return array<string, array{comment: string, sql: string}>
     */
    protected function views(): array
    {
        $acceptor = (string) config('services.redmine.acceptor_name');
        $isAcceptor = $acceptor === ''
            ? 'false'
            : 'left(s.assignee_name, '.mb_strlen($acceptor).') = '.DB::getPdo()->quote($acceptor);

        return [
            'v_metric_latest' => [
                'comment' => 'One row per metric_key + dimension: latest value and period, previous value, change, thresholds and breach flags (respecting `better`; same rule as MetricRecorder::statusFor).',
                'sql' => <<<'SQL'
                    WITH ranked AS (
                        SELECT mv.metric_key, mv.dimension, mv.period_start, mv.value, mv.source, mv.updated_at,
                            row_number() OVER (PARTITION BY mv.metric_key, mv.dimension ORDER BY mv.period_start DESC, mv.id DESC) AS rn
                        FROM metric_values mv
                    ), latest AS (
                        SELECT cur.metric_key, cur.dimension, cur.period_start, cur.value, cur.source, cur.updated_at,
                            prev.period_start AS previous_period_start, prev.value AS previous_value
                        FROM ranked cur
                        LEFT JOIN ranked prev ON prev.metric_key = cur.metric_key AND prev.dimension = cur.dimension AND prev.rn = 2
                        WHERE cur.rn = 1
                    ), judged AS (
                        SELECT l.*, d.name, d.category, d.unit, d.period_type, d.better, d.target, d.warn_threshold,
                            d.critical_threshold, d.is_pinned, d.description,
                            CASE d.better
                                WHEN 'up' THEN true
                                WHEN 'down' THEN false
                                ELSE CASE WHEN d.warn_threshold IS NOT NULL AND d.critical_threshold IS NOT NULL
                                    THEN d.critical_threshold < d.warn_threshold END
                            END AS low_is_bad
                        FROM latest l
                        JOIN metric_definitions d ON d.key = l.metric_key
                    ), flagged AS (
                        SELECT j.*,
                            COALESCE(CASE WHEN j.low_is_bad THEN j.value < j.warn_threshold ELSE j.value > j.warn_threshold END, false)
                                AND j.low_is_bad IS NOT NULL AS over_warn,
                            COALESCE(CASE WHEN j.low_is_bad THEN j.value < j.critical_threshold ELSE j.value > j.critical_threshold END, false)
                                AND j.low_is_bad IS NOT NULL AS over_critical
                        FROM judged j
                    )
                    SELECT metric_key, name, category, unit, period_type, better, dimension,
                        period_start, value, previous_period_start, previous_value, value - previous_value AS change,
                        target, warn_threshold, critical_threshold, over_warn, over_critical,
                        CASE WHEN over_critical THEN 'critical' WHEN over_warn THEN 'warn' ELSE 'ok' END AS status,
                        is_pinned, source, updated_at, description
                    FROM flagged
                    SQL,
            ],
            'v_metric_values' => [
                'comment' => 'Full metric history (one row per metric_key + period_start + dimension) with the metric name and unit.',
                'sql' => <<<'SQL'
                    SELECT mv.metric_key, d.name, d.category, d.unit, d.period_type, mv.dimension, mv.period_start, mv.value,
                        mv.source, mv.notes, mv.updated_at
                    FROM metric_values mv
                    JOIN metric_definitions d ON d.key = mv.metric_key
                    SQL,
            ],
            'v_receivables_open' => [
                'comment' => 'Outstanding receivables (status planned/invoiced). Amounts whole NTD; amount_taxed includes tax. days_overdue = days past expected_on (0 if not yet due).',
                'sql' => <<<'SQL'
                    SELECT r.id AS receivable_id, r.customer_id, c.short_name AS customer, c.name AS customer_name,
                        r.project_id, p.name AS project, r.item, r.amount_untaxed, r.tax_rate, r.amount_taxed,
                        r.expected_on, r.status, r.invoiced_on, r.confidence, r.is_recurring,
                        r.expected_on < CURRENT_DATE AS is_overdue,
                        GREATEST(CURRENT_DATE - r.expected_on, 0) AS days_overdue,
                        r.notes
                    FROM receivables r
                    JOIN customers c ON c.id = r.customer_id
                    LEFT JOIN projects p ON p.id = r.project_id
                    WHERE r.status IN ('planned', 'invoiced')
                    SQL,
            ],
            'v_cash_monthly' => [
                'comment' => 'Bank activity per calendar month (all accounts). regular_outflow excludes one-off and reimbursement withdrawals. month_end_balance = sum of each account\'s last balance on or before month end. The current month is partial (see last_txn_date).',
                'sql' => <<<'SQL'
                    WITH monthly AS (
                        SELECT date_trunc('month', t.txn_date)::date AS month_start,
                            sum(t.deposit) AS inflow,
                            sum(t.deposit) FILTER (WHERE t.category = 'revenue') AS revenue_inflow,
                            sum(t.withdrawal) AS outflow,
                            sum(t.withdrawal) FILTER (WHERE NOT t.is_one_off AND t.category <> 'reimbursement') AS regular_outflow,
                            sum(t.withdrawal) FILTER (WHERE t.is_one_off) AS one_off_outflow,
                            sum(t.withdrawal) FILTER (WHERE t.category = 'reimbursement') AS reimbursement_outflow,
                            count(*) AS txn_count,
                            max(t.txn_date) AS last_txn_date
                        FROM bank_transactions t
                        GROUP BY 1
                    ), balances AS (
                        SELECT m.month_start, sum(last_txn.balance) AS month_end_balance
                        FROM monthly m
                        CROSS JOIN bank_accounts a
                        CROSS JOIN LATERAL (
                            SELECT t.balance FROM bank_transactions t
                            WHERE t.bank_account_id = a.id AND t.txn_date < (m.month_start + interval '1 month')::date
                            ORDER BY t.txn_date DESC, t.sequence DESC, t.id DESC
                            LIMIT 1
                        ) last_txn
                        GROUP BY m.month_start
                    )
                    SELECT to_char(m.month_start, 'YYYY-MM') AS month, m.month_start,
                        m.inflow::bigint AS inflow, COALESCE(m.revenue_inflow, 0)::bigint AS revenue_inflow,
                        m.outflow::bigint AS outflow, COALESCE(m.regular_outflow, 0)::bigint AS regular_outflow,
                        COALESCE(m.one_off_outflow, 0)::bigint AS one_off_outflow,
                        COALESCE(m.reimbursement_outflow, 0)::bigint AS reimbursement_outflow,
                        (m.inflow - m.outflow)::bigint AS net, b.month_end_balance::bigint AS month_end_balance,
                        m.txn_count, m.last_txn_date
                    FROM monthly m
                    LEFT JOIN balances b ON b.month_start = m.month_start
                    SQL,
            ],
            'v_transactions' => [
                'comment' => 'Bank transactions. amount = deposit - withdrawal (whole NTD). category: revenue/salary/insurance/tax/rent/subscription/reimbursement/other.',
                'sql' => <<<'SQL'
                    SELECT t.id AS transaction_id, a.name AS bank_account, t.txn_date, t.sequence, t.summary, t.counterparty,
                        t.withdrawal, t.deposit, t.deposit - t.withdrawal AS amount, t.balance, t.category, t.is_one_off,
                        t.receivable_id, t.notes
                    FROM bank_transactions t
                    JOIN bank_accounts a ON a.id = t.bank_account_id
                    SQL,
            ],
            'v_cost_baselines' => [
                'comment' => 'Recurring monthly cost baseline (whole NTD), effective from the given date until the next row.',
                'sql' => <<<'SQL'
                    SELECT effective_from,
                        lead(effective_from) OVER (ORDER BY effective_from) AS effective_until,
                        monthly_cost, notes
                    FROM cost_baselines
                    SQL,
            ],
            'v_planned_cash_flows' => [
                'comment' => 'Known future one-off cash flows used by the forecast (amount: positive = inflow, negative = outflow).',
                'sql' => <<<'SQL'
                    SELECT id AS planned_cash_flow_id, flow_on, amount, description, notes
                    FROM planned_cash_flows
                    SQL,
            ],
            'v_delivery_trend' => [
                'comment' => 'Daily Redmine open-issue stock from status snapshots. verifying_acceptor = 驗證中 assigned to the final acceptor (assignee name prefix '.($acceptor === '' ? '(none configured)' : $acceptor).', fixed at migration time). reconstructed days only know `open`; their other counts are 0.',
                'sql' => <<<SQL
                    SELECT s.snapshot_date,
                        sum(s.count)::int AS open,
                        COALESCE(sum(s.count) FILTER (WHERE s.status = '驗證中'), 0)::int AS verifying,
                        COALESCE(sum(s.count) FILTER (WHERE s.status = '驗證中' AND s.assignee_name <> '' AND {$isAcceptor}), 0)::int AS verifying_acceptor,
                        COALESCE(sum(s.count) FILTER (WHERE s.status = '驗證中' AND s.assignee_name <> '' AND NOT ({$isAcceptor})), 0)::int AS verifying_others,
                        COALESCE(sum(s.count) FILTER (WHERE s.status = '驗證中' AND s.assignee_name = ''), 0)::int AS verifying_unassigned,
                        COALESCE(sum(s.count) FILTER (WHERE s.assignee_name = ''), 0)::int AS unassigned,
                        sum(s.stalled_30d)::int AS stalled_30d,
                        sum(s.stalled_90d)::int AS stalled_90d,
                        sum(s.overdue)::int AS overdue,
                        bool_and(s.is_reconstructed) AS reconstructed
                    FROM redmine_status_snapshots s
                    GROUP BY s.snapshot_date
                    SQL,
            ],
            'v_delivery_snapshots' => [
                'comment' => 'Raw daily snapshot rows: open issue count per snapshot_date + project + status + assignee (empty = unassigned).',
                'sql' => <<<'SQL'
                    SELECT snapshot_date, project_identifier, status, assignee_name, count, stalled_30d, stalled_90d, overdue,
                        is_reconstructed
                    FROM redmine_status_snapshots
                    SQL,
            ],
            'v_redmine_issues' => [
                'comment' => 'Mirrored Redmine issues (deleted ones excluded, raw payload omitted). age_days since created; idle_days since last update.',
                'sql' => <<<'SQL'
                    SELECT id AS issue_id, project_identifier, project_name, tracker, status, is_closed, priority,
                        assignee_name, author_name, subject, start_date, due_date, done_ratio, estimated_hours,
                        created_on, updated_on, closed_on,
                        (CURRENT_DATE - created_on::date) AS age_days,
                        (CURRENT_DATE - updated_on::date) AS idle_days,
                        (NOT is_closed AND due_date IS NOT NULL AND due_date < CURRENT_DATE) AS is_overdue
                    FROM redmine_issues
                    WHERE deleted_at IS NULL
                    SQL,
            ],
            'v_redmine_status_changes' => [
                'comment' => 'Redmine issue status transitions (from journals), with the assignee at the time of change.',
                'sql' => <<<'SQL'
                    SELECT issue_id, project_identifier, from_status, to_status, assignee_name, previous_assignee_name, changed_at
                    FROM redmine_status_changes
                    SQL,
            ],
            'v_redmine_time_entries' => [
                'comment' => 'Redmine time entries (raw payload omitted).',
                'sql' => <<<'SQL'
                    SELECT id AS time_entry_id, issue_id, project_identifier, user_name, activity, hours, spent_on, comments
                    FROM redmine_time_entries
                    SQL,
            ],
            'v_open_attention' => [
                'comment' => '今天要處理: unresolved (open/acknowledged) critical/warning insights + pending (todo/doing/waiting) action items overdue or due today. kind = insight | action_item.',
                'sql' => <<<'SQL'
                    SELECT 'insight' AS kind, i.id, i.severity, NULL::varchar AS priority, i.category, i.title,
                        i.body AS detail, i.status, NULL::date AS due_on, NULL::int AS days_overdue, NULL::varchar AS owner,
                        i.fingerprint, i.first_seen_at, i.last_seen_at
                    FROM insights i
                    WHERE i.status IN ('open', 'acknowledged') AND i.severity IN ('critical', 'warning')
                        AND (i.expires_at IS NULL OR i.expires_at > now())
                    UNION ALL
                    SELECT 'action_item', a.id, NULL, a.priority, NULL, a.title, a.detail, a.status, a.due_on,
                        CURRENT_DATE - a.due_on, a.owner, NULL, a.created_at, a.updated_at
                    FROM action_items a
                    WHERE a.status IN ('todo', 'doing', 'waiting') AND a.due_on <= CURRENT_DATE
                    SQL,
            ],
        ];
    }
};

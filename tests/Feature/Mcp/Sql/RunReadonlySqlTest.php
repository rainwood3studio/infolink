<?php

use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\RunReadonlySql;
use App\Models\ActionItem;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Insight;
use App\Models\MetricDefinition;
use App\Models\MetricValue;
use App\Models\Receivable;
use App\Models\RedmineStatusSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * Postgres-only tests run with:
 * docker compose exec -T app sh -c 'DB_CONNECTION=pgsql DB_DATABASE=infolink_test php vendor/bin/pest --compact tests/Feature/Mcp/Sql'
 */
afterEach(fn () => DB::disconnect(RunReadonlySql::CONNECTION));

function skipUnlessPostgres(): void
{
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires PostgreSQL (the v_* views and the infolink_ro role).');
    }
}

function runSql(string $sql): TestResponse
{
    return InfolinkServer::actingAs(mcpUser(['read']))->tool(RunReadonlySql::class, ['sql' => $sql]);
}

test('the tool is hidden without the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(RunReadonlySql::class, ['sql' => 'select 1'])
        ->assertHasErrors(['not found']);
});

test('statements other than a single select are rejected before reaching the database', function (string $sql, string $error) {
    runSql($sql)->assertHasErrors([$error]);
})->with([
    'multiple statements' => ['select 1 from v_cash_monthly; delete from users', 'single statement'],
    'dml' => ['delete from receivables', 'Only SELECT'],
    'data-modifying cte' => ['with x as (delete from receivables returning *) select * from x', '`delete` is not allowed'],
    'catalog' => ['select * from pg_catalog.pg_authid', '`pg_catalog` is not allowed'],
    'information schema' => ['select * from information_schema.tables', '`information_schema` is not allowed'],
    'settings' => ["select set_config('statement_timeout', '0', false)", '`set_config` is not allowed'],
    'sleep' => ['select pg_sleep(30)', '`pg_sleep` is not allowed'],
    'select into' => ['select * into t from v_cash_monthly', '`into` is not allowed'],
    'locking' => ['select * from v_transactions for update', 'Locking clauses'],
    'comments' => ['select 1 -- hi', 'comments are not allowed'],
]);

test('on sqlite the tool says it needs postgres', function () {
    if (DB::connection()->getDriverName() === 'pgsql') {
        $this->markTestSkipped('Only meaningful on the sqlite suite.');
    }

    runSql('select * from v_cash_monthly')->assertHasErrors(['requires PostgreSQL']);
});

test('views are readable through the read-only role', function () {
    skipUnlessPostgres();

    runSql('select count(*) as n from v_receivables_open;')
        ->assertOk()
        ->assertSee(['"columns":["n"]', '"truncated":false']);
});

test('base tables and secrets are denied by the role itself', function (string $table) {
    skipUnlessPostgres();

    runSql("select * from {$table}")->assertHasErrors(['permission denied']);
})->with(['users', 'personal_access_tokens', 'receivables', 'redmine_issues', 'sessions']);

test('results are capped at 500 rows and report truncation', function () {
    skipUnlessPostgres();

    runSql('select g from generate_series(1, 600) as g')
        ->assertOk()
        ->assertSee(['"row_count":500', '"truncated":true', '[500]]'])
        ->assertDontSee('[501]');
});

test('help lists only the readable views with their columns', function () {
    skipUnlessPostgres();

    runSql('help')
        ->assertOk()
        ->assertSee(['v_receivables_open', 'days_overdue', 'v_open_attention', 'v_delivery_trend'])
        ->assertDontSee(['"users"', 'personal_access_tokens', '"raw"']);
});

test('the read-only role is read-only, time-limited and cannot create objects', function () {
    skipUnlessPostgres();

    $readonly = DB::connection(RunReadonlySql::CONNECTION);

    expect($readonly->selectOne('show statement_timeout')->statement_timeout)->toBe('10s')
        ->and($readonly->selectOne('show default_transaction_read_only')->default_transaction_read_only)->toBe('on')
        ->and(fn () => $readonly->statement('create temp table scratch (id int)'))->toThrow(QueryException::class)
        ->and(fn () => $readonly->statement('create table public.scratch (id int)'))->toThrow(QueryException::class);
});

test('v_receivables_open shows outstanding receivables with names and days overdue', function () {
    skipUnlessPostgres();

    $customer = Customer::factory()->create(['short_name' => '長照']);
    Receivable::factory()->for($customer)->create(['item' => '期中款', 'amount_untaxed' => 100_000, 'expected_on' => today()->subDays(12)]);
    Receivable::factory()->for($customer)->create(['item' => '已收', 'status' => 'received']);

    $rows = DB::table('v_receivables_open')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->customer)->toBe('長照')
        ->and((int) $rows[0]->amount_taxed)->toBe(105_000)
        ->and($rows[0]->days_overdue)->toBe(12)
        ->and($rows[0]->is_overdue)->toBeTrue();
});

test('v_metric_latest compares the latest value with the previous one and respects better', function () {
    skipUnlessPostgres();

    $definition = MetricDefinition::factory()->create(['better' => 'up', 'warn_threshold' => 1_000_000, 'critical_threshold' => 500_000]);
    MetricValue::factory()->create(['metric_key' => $definition->key, 'period_start' => today()->subWeek(), 'value' => 900_000]);
    MetricValue::factory()->create(['metric_key' => $definition->key, 'period_start' => today(), 'value' => 800_000]);

    $row = DB::table('v_metric_latest')->where('metric_key', $definition->key)->first();

    expect((float) $row->value)->toBe(800_000.0)
        ->and((float) $row->previous_value)->toBe(900_000.0)
        ->and((float) $row->change)->toBe(-100_000.0)
        ->and($row->over_warn)->toBeTrue()
        ->and($row->over_critical)->toBeFalse()
        ->and($row->status)->toBe('warn');
});

test('v_cash_monthly splits regular outflow and carries the month-end balance', function () {
    skipUnlessPostgres();

    $account = BankAccount::factory()->create();
    $month = today()->startOfMonth();
    BankTransaction::factory()->for($account)->create(['txn_date' => $month, 'deposit' => 50_000, 'withdrawal' => 0, 'balance' => 1_050_000]);
    BankTransaction::factory()->for($account)->create(['txn_date' => $month->copy()->addDay(), 'deposit' => 0, 'withdrawal' => 30_000, 'balance' => 1_020_000]);
    BankTransaction::factory()->for($account)->create(['txn_date' => $month->copy()->addDays(2), 'deposit' => 0, 'withdrawal' => 20_000, 'balance' => 1_000_000, 'is_one_off' => true]);

    $row = DB::table('v_cash_monthly')->where('month', $month->format('Y-m'))->first();

    expect((int) $row->inflow)->toBe(50_000)
        ->and((int) $row->outflow)->toBe(50_000)
        ->and((int) $row->regular_outflow)->toBe(30_000)
        ->and((int) $row->one_off_outflow)->toBe(20_000)
        ->and((int) $row->month_end_balance)->toBe(1_000_000);
});

test('v_delivery_trend splits verifying between the acceptor and others', function () {
    skipUnlessPostgres();

    $acceptor = config('services.redmine.acceptor_name');
    RedmineStatusSnapshot::factory()->create(['status' => '驗證中', 'assignee_name' => $acceptor.' 王', 'count' => 5]);
    RedmineStatusSnapshot::factory()->create(['status' => '驗證中', 'assignee_name' => '小明', 'count' => 3]);
    RedmineStatusSnapshot::factory()->create(['status' => '新建立', 'assignee_name' => '', 'count' => 2, 'stalled_90d' => 1]);

    $row = DB::table('v_delivery_trend')->where('snapshot_date', today()->toDateString())->first();

    expect($row->open)->toBe(10)
        ->and($row->verifying_acceptor)->toBe(5)
        ->and($row->verifying_others)->toBe(3)
        ->and($row->unassigned)->toBe(2)
        ->and($row->stalled_90d)->toBe(1)
        ->and($row->reconstructed)->toBeFalse();
});

test('v_open_attention lists unresolved warnings and due action items only', function () {
    skipUnlessPostgres();

    Insight::factory()->create(['title' => 'cash low', 'severity' => 'critical']);
    Insight::factory()->create(['title' => 'fyi', 'severity' => 'info']);
    Insight::factory()->create(['title' => 'done', 'status' => 'resolved']);
    ActionItem::factory()->create(['title' => 'call bank', 'due_on' => today()->subDay()]);
    ActionItem::factory()->create(['title' => 'later', 'due_on' => today()->addWeek()]);
    ActionItem::factory()->create(['title' => 'finished', 'due_on' => today(), 'status' => 'done']);

    expect(DB::table('v_open_attention')->orderBy('title')->pluck('title')->all())->toBe(['call bank', 'cash low']);
});

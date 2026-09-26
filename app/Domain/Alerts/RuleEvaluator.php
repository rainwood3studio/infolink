<?php

namespace App\Domain\Alerts;

use App\Domain\Alerts\Rules\BriefMissingRule;
use App\Domain\Alerts\Rules\CashLowRule;
use App\Domain\Alerts\Rules\ClosingRiskRule;
use App\Domain\Alerts\Rules\DealStaleRule;
use App\Domain\Alerts\Rules\DeliveryBacklogGrowingRule;
use App\Domain\Alerts\Rules\MetricThresholdRule;
use App\Domain\Alerts\Rules\ReceivableDueRule;
use App\Domain\Alerts\Rules\ReceivableOverdueRule;
use App\Domain\Alerts\Rules\Rule;
use App\Domain\Alerts\Rules\SyncFailedRule;
use App\Domain\Alerts\Rules\VatReserveRule;
use App\Domain\Insights\InsightService;
use App\Enums\Source;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\AlertRule;
use App\Models\Insight;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Runs the active alert rules and turns what fires into insights (source: system) through the InsightService, which
 * deduplicates by fingerprint; notifications happen downstream.
 *
 * Auto-resolve: an unresolved system insight whose fingerprint a rule owns (see {@see Rule::ownedFingerprints()},
 * by default the fingerprint template up to its first placeholder) but that did not fire this time is resolved with
 * 「條件解除，自動結案」. A rule that throws resolves nothing and marks the run failed; other rules still run.
 * Each non-dry evaluation is recorded as a `rules` sync run; a dry run writes nothing at all.
 */
class RuleEvaluator
{
    public const string RESOLVE_NOTE = '條件解除，自動結案';

    /**
     * Rule classes selectable as `query_class`.
     *
     * @var list<class-string<Rule>>
     */
    public const array QUERY_RULES = [
        CashLowRule::class,
        ReceivableDueRule::class,
        ReceivableOverdueRule::class,
        VatReserveRule::class,
        DeliveryBacklogGrowingRule::class,
        ClosingRiskRule::class,
        DealStaleRule::class,
        SyncFailedRule::class,
        BriefMissingRule::class,
    ];

    public function __construct(protected InsightService $insights) {}

    /**
     * @return array<class-string<Rule>, string>
     */
    public static function queryRuleOptions(): array
    {
        return collect(self::QUERY_RULES)
            ->mapWithKeys(fn (string $class): array => [$class => $class::label().'（'.class_basename($class).'）'])
            ->all();
    }

    /**
     * Evaluate every active rule, or only the active rule with `$ruleKey`.
     */
    public function evaluate(?string $ruleKey = null, bool $dryRun = false, ?CarbonImmutable $now = null): EvaluationResult
    {
        $now ??= CarbonImmutable::now();

        $rules = AlertRule::query()
            ->where('is_active', true)
            ->when($ruleKey !== null, fn (Builder $query) => $query->where('key', $ruleKey))
            ->orderBy('id')
            ->get();

        $run = $dryRun ? null : SyncRun::query()->create([
            'job' => SyncJob::Rules,
            'started_at' => now(),
            'status' => SyncStatus::Running,
        ]);

        $outcomes = $rules->map(fn (AlertRule $rule): RuleOutcome => $this->evaluateRule($rule, $dryRun, $now))->all();
        $result = new EvaluationResult($outcomes, $dryRun, $run);

        if ($run !== null) {
            $stats = $result->stats();
            $errors = collect($outcomes)->filter(fn (RuleOutcome $outcome): bool => $outcome->error !== null);

            $run->update([
                'status' => $errors->isEmpty() ? SyncStatus::Ok : SyncStatus::Failed,
                'finished_at' => now(),
                'stats' => array_filter($stats, fn (int $value, string $key): bool => $key !== 'errors' || $value > 0, ARRAY_FILTER_USE_BOTH),
                'error' => $errors->isEmpty() ? null : Str::limit($errors->map(fn (RuleOutcome $outcome): string => "{$outcome->key}: {$outcome->error}")->implode("\n"), 2000),
            ]);
        }

        return $result;
    }

    public function evaluateRule(AlertRule $rule, bool $dryRun = false, ?CarbonImmutable $now = null): RuleOutcome
    {
        $now ??= CarbonImmutable::now();
        $outcome = new RuleOutcome($rule->key, $rule->name);

        try {
            $handler = $this->handlerFor($rule);
            $firings = $handler->evaluate($rule, $now);

            $apply = function () use ($rule, $handler, $firings, $outcome, $dryRun, $now): void {
                $fired = [];

                foreach ($firings as $firing) {
                    $attributes = $this->attributes($rule, $firing);

                    if (isset($fired[$attributes['fingerprint']])) {
                        continue;
                    }

                    $fired[$attributes['fingerprint']] = true;
                    $exists = Insight::query()->unresolved()->where('fingerprint', $attributes['fingerprint'])->exists();

                    if (! $dryRun) {
                        $this->insights->raise($attributes, Source::System);
                    }

                    $outcome->firings[] = [
                        'action' => $exists ? RuleOutcome::UPDATE : RuleOutcome::RAISE,
                        'fingerprint' => $attributes['fingerprint'],
                        'severity' => $attributes['severity']->value,
                        'title' => $attributes['title'],
                    ];
                }

                foreach ($this->staleInsights($handler->ownedFingerprints($rule), array_keys($fired)) as $insight) {
                    if (! $dryRun) {
                        $this->insights->resolve($insight, self::RESOLVE_NOTE);
                    }

                    $outcome->resolved[] = ['insight_id' => $insight->id, 'fingerprint' => $insight->fingerprint, 'title' => $insight->title];
                }

                if (! $dryRun) {
                    $rule->forceFill(['last_evaluated_at' => $now])->save();
                }
            };

            $dryRun ? $apply() : DB::transaction($apply);
        } catch (Throwable $exception) {
            report($exception);

            $outcome->firings = [];
            $outcome->resolved = [];
            $outcome->error = Str::limit($exception->getMessage(), 500);
        }

        return $outcome;
    }

    public function handlerFor(AlertRule $rule): Rule
    {
        if (filled($rule->query_class)) {
            if (! is_subclass_of($rule->query_class, Rule::class)) {
                throw new InvalidArgumentException("Rule [{$rule->key}]: [{$rule->query_class}] is not a rule class.");
            }

            return app($rule->query_class);
        }

        if (filled($rule->metric_key)) {
            return app(MetricThresholdRule::class);
        }

        throw new InvalidArgumentException("Rule [{$rule->key}] needs a metric_key or a query_class.");
    }

    /**
     * Fill `{name}` placeholders.
     *
     * @param  array<string, string|int|float>  $vars
     */
    public static function render(string $template, array $vars): string
    {
        return strtr($template, collect($vars)->mapWithKeys(fn (string|int|float $value, string $key): array => ['{'.$key.'}' => (string) $value])->all());
    }

    /**
     * @return array<string, mixed>
     */
    protected function attributes(AlertRule $rule, Firing $firing): array
    {
        return [
            'fingerprint' => $firing->fingerprint ?? self::render($rule->fingerprint_template, $firing->vars),
            'kind' => $firing->kind,
            'severity' => $firing->severity ?? $rule->severity,
            'category' => $rule->category,
            'title' => Str::limit($firing->title ?? self::render($rule->title_template, $firing->vars), 250),
            'body' => $firing->body,
            'evidence' => ['alert_rule' => $rule->key, ...$firing->evidence],
        ];
    }

    /**
     * Unresolved system insights matching the owned fingerprints that did not fire this time. LIKE only narrows the
     * query (wildcards in a prefix are not escaped); the exact match is done in PHP.
     *
     * @param  list<string>  $owned  exact fingerprints, or prefixes ending in `*`
     * @param  list<string>  $fired
     * @return list<Insight>
     */
    protected function staleInsights(array $owned, array $fired): array
    {
        $matches = function (string $fingerprint) use ($owned): bool {
            foreach ($owned as $pattern) {
                if (str_ends_with($pattern, '*') ? str_starts_with($fingerprint, substr($pattern, 0, -1)) : $fingerprint === $pattern) {
                    return true;
                }
            }

            return false;
        };

        return Insight::query()
            ->unresolved()
            ->where('source', Source::System)
            ->where(function (Builder $query) use ($owned): void {
                foreach ($owned as $pattern) {
                    $query->orWhere('fingerprint', 'like', rtrim($pattern, '*').'%');
                }
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (Insight $insight): bool => $matches($insight->fingerprint) && ! in_array($insight->fingerprint, $fired, true))
            ->values()
            ->all();
    }
}

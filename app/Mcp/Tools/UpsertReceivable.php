<?php

namespace App\Mcp\Tools;

use App\Domain\Finance\ReceivableService;
use App\Enums\Confidence;
use App\Enums\ReceivableStatus;
use App\Mcp\Tools\Concerns\WriteToolHelpers;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('upsert_receivable')]
#[Description('Create or update a receivable (expected customer payment). Matched, in order, by `id`, by `external_key`, or by customer + project + item (+ the expected_on month for recurring fees), so re-running never duplicates. amount_untaxed is UNTAXED; the response includes amount_taxed = round(untaxed × (1 + tax_rate)). Recurring monthly fees (維運費) are one receivable per month with is_recurring=true. If an amount or date is uncertain, use confidence=low and explain in notes — never guess. Use record_receivable_payment (not this tool) to mark money received.')]
class UpsertReceivable extends WriteTool
{
    use WriteToolHelpers;

    /**
     * Fields copied from the request onto the receivable when present.
     */
    protected const array FIELDS = ['item', 'amount_untaxed', 'tax_rate', 'expected_on', 'confidence', 'status', 'invoiced_on', 'is_recurring', 'notes', 'vault_ref'];

    public function handle(Request $request, ReceivableService $receivables): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'id' => ['nullable', 'integer'],
            'external_key' => ['nullable', 'string', 'max:255'],
            'customer' => ['required', 'string'],
            'project' => ['nullable', 'string'],
            'item' => ['required', 'string', 'max:255'],
            'amount_untaxed' => ['nullable', 'integer', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'expected_on' => ['nullable', 'date_format:Y-m-d'],
            'confidence' => ['nullable', Rule::in(self::enumValues(Confidence::class))],
            'status' => ['nullable', Rule::in(self::enumValues(ReceivableStatus::class))],
            'invoiced_on' => ['nullable', 'date_format:Y-m-d'],
            'is_recurring' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'vault_ref' => ['nullable', 'string', 'max:255'],
        ], [
            'customer.required' => 'Pass `customer`: the customer short_name (e.g. 長照).',
            'item.required' => 'Pass `item`, e.g. 期中款, 尾款, 維運費.',
            'amount_untaxed.integer' => 'amount_untaxed must be a whole NTD integer, UNTAXED.',
            'tax_rate.max' => 'tax_rate is a fraction (0.05 for 5%), not a percentage.',
            'confidence.in' => 'confidence must be high or low.',
            'status.in' => 'status must be one of: '.implode(', ', self::enumValues(ReceivableStatus::class)).' (overdue is derived, never set).',
        ]);

        $customer = Customer::query()->where('short_name', $validated['customer'])->first()
            ?? Customer::query()->where('name', $validated['customer'])->first();

        if ($customer === null) {
            $known = Customer::query()->orderBy('short_name')->pluck('short_name');

            return Response::error("Unknown customer [{$validated['customer']}]. Customers (short_name): ".$known->implode(', ').'. Customers are created in the admin panel, not by Claude.');
        }

        $project = null;

        if (filled($validated['project'] ?? null)) {
            $project = $customer->projects()->where('name', $validated['project'])->first();

            if ($project === null) {
                return Response::error("Unknown project [{$validated['project']}] for {$customer->short_name}. Projects: ".($customer->projects()->pluck('name')->implode(', ') ?: '(none)').'. Omit `project` if it is not tied to one.');
            }
        }

        $existing = $this->findExisting($validated, $customer, $project);

        if (is_string($existing)) {
            return Response::error($existing);
        }

        $attributes = [
            ...array_intersect_key($validated, array_flip(self::FIELDS)),
            'customer_id' => $customer->id,
            'project_id' => $project?->id,
        ];

        if (! array_key_exists('project', $validated) && $existing !== null) {
            unset($attributes['project_id']);
        }

        if ($existing === null) {
            if (! isset($validated['amount_untaxed'], $validated['expected_on'])) {
                return Response::error('A new receivable needs amount_untaxed (untaxed NTD integer) and expected_on (YYYY-MM-DD). If the amount is uncertain, give your best documented figure with confidence=low and explain in notes.');
            }

            $attributes['tax_rate'] ??= 0.05;
        }

        $receivable = DB::transaction(fn (): Receivable => $existing !== null
            ? $receivables->upsert([...$attributes, 'id' => $existing->id])
            : $receivables->upsert($attributes, self::SOURCE, $validated['external_key'] ?? $this->derivedKey($validated, $customer, $project)));

        return Response::json([
            'result' => $existing === null ? 'created' : 'updated',
            'receivable' => self::presentReceivable($receivable->refresh()),
        ]);
    }

    /**
     * The receivable this call refers to, null when it is new, or an error message.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function findExisting(array $validated, Customer $customer, ?Project $project): Receivable|string|null
    {
        if (isset($validated['id'])) {
            return Receivable::query()->find($validated['id']) ?? "No receivable with id {$validated['id']}.";
        }

        if (filled($validated['external_key'] ?? null)) {
            $byKey = Receivable::query()->where('external_key', $validated['external_key'])->get();

            if ($byKey->count() > 1) {
                return "external_key [{$validated['external_key']}] matches several receivables (ids ".$byKey->pluck('id')->implode(', ').'); pass `id` instead.';
            }

            if ($byKey->isNotEmpty()) {
                return $byKey->first();
            }
        }

        $isRecurring = (bool) ($validated['is_recurring'] ?? false);

        if ($isRecurring && ! isset($validated['expected_on'])) {
            return 'A recurring receivable needs expected_on: each month is its own receivable.';
        }

        $matches = Receivable::query()
            ->where('customer_id', $customer->id)
            ->when(
                $project !== null,
                fn (Builder $query) => $query->where('project_id', $project->id),
                fn (Builder $query) => $query->whereNull('project_id'),
            )
            ->where('item', $validated['item'])
            ->when($isRecurring, function (Builder $query) use ($validated): void {
                $month = CarbonImmutable::parse($validated['expected_on']);

                $query->whereDate('expected_on', '>=', $month->startOfMonth())->whereDate('expected_on', '<=', $month->endOfMonth());
            })
            ->get();

        if ($matches->count() > 1) {
            return "Several receivables match {$customer->short_name} / ".($project->name ?? '(no project)')." / {$validated['item']} (ids ".$matches->pluck('id')->implode(', ').'); pass `id` or `external_key` to pick one.';
        }

        return $matches->first();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function derivedKey(array $validated, Customer $customer, ?Project $project): string
    {
        $key = implode('|', [$customer->short_name, $project->name ?? '', $validated['item']]);

        return ($validated['is_recurring'] ?? false)
            ? $key.'|'.CarbonImmutable::parse($validated['expected_on'])->format('Y-m')
            : $key;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Update this receivable (from list_receivables).'),
            'external_key' => $schema->string()->description('Your idempotency key. Optional: derived from customer|project|item(|YYYY-MM for recurring).'),
            'customer' => $schema->string()->description('Customer short_name, e.g. 長照. Must exist.')->required(),
            'project' => $schema->string()->description('Project name under that customer, e.g. APOS 2.0. Optional.'),
            'item' => $schema->string()->description('期中款 / 尾款 / 維運費 …')->required(),
            'amount_untaxed' => $schema->integer()->description('UNTAXED amount in whole NTD. Required when creating.'),
            'tax_rate' => $schema->number()->description('Fraction; default 0.05. Use 0 when the fee is quoted tax-free.'),
            'expected_on' => $schema->string()->format('date')->description('Expected payment date YYYY-MM-DD. Required when creating.'),
            'confidence' => $schema->string()->enum(self::enumValues(Confidence::class))->description('low when amount or timing is uncertain (excluded from the forecast).'),
            'status' => $schema->string()->enum(self::enumValues(ReceivableStatus::class))->description('planned / invoiced / cancelled. Use record_receivable_payment to mark received.'),
            'invoiced_on' => $schema->string()->format('date'),
            'is_recurring' => $schema->boolean()->description('Monthly fixed fee (維運費); one receivable per month.'),
            'notes' => $schema->string()->description('Why / source of the numbers; required in spirit when confidence=low.'),
            'vault_ref' => $schema->string()->description('Vault note path, e.g. 03.Business/Finance/2026 下半年現金推估.md'),
        ];
    }
}

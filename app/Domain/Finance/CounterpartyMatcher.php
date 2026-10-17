<?php

namespace App\Domain\Finance;

use App\Models\Customer;
use Illuminate\Support\Collection;

/**
 * Finds the customer a bank line's counterparty belongs to, from the customers' bank aliases
 * ({@see Customer::bankAliases()}: explicit 銀行入帳戶名, name and short name).
 *
 * Rule: an exact match wins; otherwise one of alias / counterparty must be a prefix of the other (「我識出版社」 vs
 * 「我識出版社有限公司」), the longest alias winning. Aliases (or counterparties) shorter than
 * {@see self::MIN_PREFIX_LENGTH} never prefix-match, so 「我」 does not swallow every counterparty. Shared by the
 * 客戶收款 page and the deposit matcher so both agree on who paid.
 */
class CounterpartyMatcher
{
    /** Aliases shorter than this never prefix-match. */
    public const int MIN_PREFIX_LENGTH = 2;

    /** @var Collection<int, Customer> */
    protected Collection $customers;

    /**
     * @param  Collection<int, Customer>|null  $customers  Defaults to every customer (loaded once).
     */
    public function __construct(?Collection $customers = null)
    {
        $this->customers = $customers ?? Customer::query()->get();
    }

    /**
     * The best-matching customer for a counterparty, or null when nobody's alias fits.
     */
    public function match(?string $counterparty): ?Customer
    {
        $counterparty = filled($counterparty) ? trim((string) $counterparty) : null;

        if ($counterparty === null) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($this->customers as $customer) {
            foreach ($customer->bankAliases() as $alias) {
                $score = self::score($alias, $counterparty);

                if ($score > $bestScore) {
                    [$best, $bestScore] = [$customer, $score];
                }
            }
        }

        return $best;
    }

    /**
     * 0 for no match; an exact match outranks any prefix match, and a longer alias outranks a shorter one.
     */
    public static function score(string $alias, string $counterparty): int
    {
        if ($alias === $counterparty) {
            return 1_000 + mb_strlen($alias);
        }

        if (mb_strlen($alias) < self::MIN_PREFIX_LENGTH || mb_strlen($counterparty) < self::MIN_PREFIX_LENGTH) {
            return 0;
        }

        return str_starts_with($counterparty, $alias) || str_starts_with($alias, $counterparty) ? min(mb_strlen($alias), mb_strlen($counterparty)) : 0;
    }
}

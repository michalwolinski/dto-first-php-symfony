<?php

declare(strict_types=1);

namespace App\Domain\Invoice;

use InvalidArgumentException;

/**
 * Money as integer minor units plus a currency, because that is the only way to keep it exact.
 * The DTOs at the boundary may carry "12.50" and a currency code; the domain never sees a float.
 */
final readonly class Money
{
    private function __construct(
        private int $minorUnits,
        private string $currency,
    ) {
        if ($minorUnits <= 0) {
            throw new InvalidArgumentException('An invoice amount must be greater than zero.');
        }

        if (3 !== strlen($currency)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a three-letter currency code.', $currency));
        }
    }

    public static function of(int $minorUnits, string $currency): self
    {
        return new self($minorUnits, strtoupper($currency));
    }

    public static function fromDecimalString(string $amount, string $currency): self
    {
        // Fifteen digits, not "any number of digits". The multiply by 100 below leaves the int range
        // at seventeen digits, and fifteen is the margin that keeps two decimals and the largest
        // amount on the safe side of it: without a bound, a long enough input produces a float and a
        // TypeError instead of an invoice, which turns a rejected payload into a 500.
        if (1 !== preg_match('/^\d{1,15}(\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a decimal amount with at most fifteen digits and two decimals.', $amount));
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return new self((int) $whole * 100 + (int) str_pad($fraction, 2, '0'), $currency);
    }

    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits && $this->currency === $other->currency;
    }
}

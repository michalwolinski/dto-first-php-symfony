<?php

declare(strict_types=1);

namespace App\Domain\Invoice;

use InvalidArgumentException;

/**
 * Rule 1 lives here: this file may not import anything from outside the domain — no framework, no
 * ORM, no HTTP. The gate in tests/Architecture fails the build if that stops being true.
 */
final readonly class InvoiceId
{
    private const string UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (1 !== \preg_match(self::UUID_V4, $value)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a UUID v4.', $value));
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}

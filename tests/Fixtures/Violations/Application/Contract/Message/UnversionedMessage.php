<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Violations\Application\Contract\Message;

/**
 * Deliberate rule-5 violation: a cross-process contract with no version, so a consumer cannot tell
 * which shape it received.
 */
final readonly class UnversionedMessage
{
    public function __construct(
        public string $invoiceId,
        public int $amountMinor,
    ) {
    }
}

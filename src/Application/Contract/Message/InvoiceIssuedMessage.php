<?php

declare(strict_types=1);

namespace App\Application\Contract\Message;

/**
 * The cross-process contract: the same event, addressed to a consumer that is not this process.
 *
 * Two things make it a contract rather than a convenience. It is named after the wire shape, not
 * after the class that happens to send it (the aggregate is never serialized), and it carries a
 * version — so a consumer can tell which shape it received without guessing from the fields.
 */
final readonly class InvoiceIssuedMessage
{
    public const int VERSION = 1;

    public function __construct(
        public int $version,
        public string $invoiceId,
        public int $amountMinor,
        public string $currency,
        public string $occurredAt,
    ) {
    }
}

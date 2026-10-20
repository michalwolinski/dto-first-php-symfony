<?php

declare(strict_types=1);

namespace App\Domain\Invoice\Event;

use App\Domain\Invoice\InvoiceId;
use App\Domain\Invoice\Money;
use DateTimeImmutable;

/**
 * A domain event is a domain type: it speaks in Money and InvoiceId, and it is allowed to exist
 * inside the boundary. The message that goes on the wire is a different object — see
 * App\Application\Contract\Message\InvoiceIssuedMessage — and a mapper is what turns one into the
 * other. That separation is rule 4: the domain never knows the wire shape.
 */
final readonly class InvoiceIssued
{
    public function __construct(
        public InvoiceId $invoiceId,
        public Money $amount,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}

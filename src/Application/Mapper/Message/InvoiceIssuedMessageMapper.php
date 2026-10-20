<?php

declare(strict_types=1);

namespace App\Application\Mapper\Message;

use App\Application\Contract\Message\InvoiceIssuedMessage;
use App\Domain\Invoice\Event\InvoiceIssued;

/**
 * Domain event to wire message. This is the crossing where a leaked aggregate does the most damage
 * — a serialized Invoice couples every consumer to the domain's private shape — so it gets its own
 * mapper, its own version, and its own test.
 */
final readonly class InvoiceIssuedMessageMapper
{
    public function map(InvoiceIssued $event): InvoiceIssuedMessage
    {
        return new InvoiceIssuedMessage(
            version: InvoiceIssuedMessage::VERSION,
            invoiceId: $event->invoiceId->toString(),
            amountMinor: $event->amount->minorUnits(),
            currency: $event->amount->currency(),
            occurredAt: $event->occurredAt->format(DATE_ATOM),
        );
    }
}

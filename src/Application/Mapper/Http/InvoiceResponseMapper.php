<?php

declare(strict_types=1);

namespace App\Application\Mapper\Http;

use App\Application\Contract\Http\InvoiceResponse;
use App\Domain\Invoice\Invoice;

/**
 * The outbound direction. The response DTO is assembled here, field by field, which is what makes
 * "the domain cannot change the wire by accident" true rather than aspirational.
 */
final readonly class InvoiceResponseMapper
{
    public function map(Invoice $invoice): InvoiceResponse
    {
        return new InvoiceResponse(
            id: $invoice->id()->toString(),
            customerId: $invoice->customerId(),
            amountMinor: $invoice->amount()->minorUnits(),
            currency: $invoice->amount()->currency(),
            status: $invoice->status()->value,
            dueDate: $invoice->dueDate()->format(DATE_ATOM),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Mapper\Http;

use App\Application\Contract\Command\CreateInvoiceCommand;
use App\Application\Contract\Http\V2\CreateInvoiceRequest;
use App\Domain\Invoice\Money;
use DateTimeImmutable;

/**
 * The V2 direction of the same crossing. It differs from V1 in exactly two ways — a decimal string
 * becomes minor units, and the due date is supplied instead of defaulted by the domain — and both
 * differences are visible here rather than scattered through the handler.
 */
final readonly class V2CreateInvoiceRequestMapper
{
    public function map(CreateInvoiceRequest $request): CreateInvoiceCommand
    {
        return new CreateInvoiceCommand(
            customerId: $request->customerId,
            amount: Money::fromDecimalString($request->amount, $request->currency),
            dueDate: new DateTimeImmutable($request->dueDate.' 00:00:00'),
        );
    }
}

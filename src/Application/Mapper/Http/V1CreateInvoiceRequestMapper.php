<?php

declare(strict_types=1);

namespace App\Application\Mapper\Http;

use App\Application\Contract\Command\CreateInvoiceCommand;
use App\Application\Contract\Http\V1\CreateInvoiceRequest;
use App\Domain\Invoice\Money;

/**
 * Rule 3: every crossing has a named mapper with a test. This one owns exactly one direction —
 * V1 payload to command — and it is the only place that knows the two shapes are the same call.
 */
final readonly class V1CreateInvoiceRequestMapper
{
    public function map(CreateInvoiceRequest $request): CreateInvoiceCommand
    {
        return new CreateInvoiceCommand(
            customerId: $request->customerId,
            amount: Money::of($request->amountMinor, $request->currency),
            dueDate: null,
        );
    }
}

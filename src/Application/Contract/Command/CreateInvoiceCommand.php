<?php

declare(strict_types=1);

namespace App\Application\Contract\Command;

use App\Domain\Invoice\Money;
use DateTimeImmutable;

/**
 * The boundary-agnostic command. Two very different request payloads (V1 and V2) produce this same
 * object, which is why the handler below has no idea which version of the API called it.
 */
final readonly class CreateInvoiceCommand
{
    public function __construct(
        public string $customerId,
        public Money $amount,
        public ?DateTimeImmutable $dueDate,
    ) {
    }
}

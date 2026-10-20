<?php

declare(strict_types=1);

namespace App\Application\Contract\Http;

/**
 * The output contract. The aggregate is never returned from a controller: response shapes are
 * decided here, so a refactor inside the domain cannot silently change the wire (rule 4). It is
 * built by App\Application\Mapper\Http\InvoiceResponseMapper — not by a static factory on the DTO,
 * so the mapping has a name, a place and a test (rule 3).
 */
final readonly class InvoiceResponse
{
    public function __construct(
        public string $id,
        public string $customerId,
        public int $amountMinor,
        public string $currency,
        public string $status,
        public string $dueDate,
    ) {
    }
}

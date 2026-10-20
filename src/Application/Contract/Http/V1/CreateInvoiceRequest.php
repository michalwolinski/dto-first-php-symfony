<?php

declare(strict_types=1);

namespace App\Application\Contract\Http\V1;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Version 1 of the create-invoice payload: the client sends minor units and a currency code.
 * The validation lives here, on the type, and nowhere else — rule 2. Handlers receive a command
 * built from this object, never this object itself.
 */
final readonly class CreateInvoiceRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $customerId = '',
        #[Assert\NotNull]
        #[Assert\Positive]
        public int $amountMinor = 0,
        #[Assert\NotBlank]
        #[Assert\Currency]
        public string $currency = '',
    ) {
    }
}

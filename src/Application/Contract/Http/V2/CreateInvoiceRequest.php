<?php

declare(strict_types=1);

namespace App\Application\Contract\Http\V2;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Version 2 of the same call: a decimal amount string instead of minor units, plus a due date.
 * It is a different type, with its own validation, mapping to the same command as V1 — rule 5.
 * Versioning lives on the DTO; the database stores one shape.
 *
 * The digit bound on the amount is not decoration: `Money` multiplies the whole part by 100, and
 * an amount with enough digits overflows an int on the way into minor units. Fifteen digits plus
 * the fraction stays inside one.
 */
final readonly class CreateInvoiceRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $customerId = '',
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^\d{1,15}(\.\d{1,2})?$/', message: 'Use a decimal amount with at most fifteen digits, for example "1250.00".')]
        public string $amount = '',
        #[Assert\NotBlank]
        #[Assert\Currency]
        public string $currency = '',
        #[Assert\NotBlank]
        #[Assert\Date]
        public string $dueDate = '',
    ) {
    }
}

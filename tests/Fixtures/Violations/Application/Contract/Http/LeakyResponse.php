<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Violations\Application\Contract\Http;

use App\Domain\Invoice\Invoice;

/**
 * Deliberate rule-4 violation: a type that leaves the process holding a domain type.
 */
final readonly class LeakyResponse
{
    public function __construct(public Invoice $invoice)
    {
    }
}

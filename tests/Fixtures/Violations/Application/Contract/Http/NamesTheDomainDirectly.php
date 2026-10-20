<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Violations\Application\Contract\Http;

/**
 * Deliberate rule-4 violation in the shape rule 1 had already stopped allowing: the response holds
 * the aggregate, typed out in full with no `use` statement to find. It is the forbidden
 * `InvoiceResponse` example — `public function __construct(public Invoice $invoice)` — with the
 * import left out, which an import scan alone cannot see.
 */
final readonly class NamesTheDomainDirectly
{
    public function __construct(public \App\Domain\Invoice\Invoice $invoice)
    {
    }
}

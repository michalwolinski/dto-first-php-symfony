<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Domain\Invoice\Invoice;
use App\Domain\Invoice\InvoiceId;
use App\Domain\Invoice\InvoiceRepository;

/**
 * A second path through the same boundary shape, so the doctrine is not a one-off: the controller
 * passes an id (a string from the URL), and this handler turns it into a domain identifier before
 * anything else happens.
 *
 * A malformed id never reaches this method. The route declares `Requirement::UUID_V4` on the
 * parameter, so a path that is not a UUID does not match a route at all and is answered with 404 —
 * the earlier version of this handler relied on `InvoiceId::fromString()` throwing, which meant a
 * typo answered 500 with an `InvalidArgumentException` escaping the error contract. What arrives
 * here is well-formed and may still be unknown, and that is a domain refusal, not a parse failure.
 */
final readonly class MarkInvoicePaidHandler
{
    public function __construct(private InvoiceRepository $invoices)
    {
    }

    public function __invoke(string $invoiceId): Invoice
    {
        $invoice = $this->invoices->ofId(InvoiceId::fromString($invoiceId));
        $invoice->markPaid();
        $this->invoices->save($invoice);

        return $invoice;
    }
}

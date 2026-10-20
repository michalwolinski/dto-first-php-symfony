<?php

declare(strict_types=1);

namespace App\Domain\Invoice;

use App\Domain\Invoice\Exception\InvoiceNotFoundException;

/**
 * The domain's view of storage: it speaks in Invoice and InvoiceId. Whatever the implementation
 * does underneath — rows, documents, an API — a row never crosses this line.
 */
interface InvoiceRepository
{
    /** @throws InvoiceNotFoundException when there is no such invoice */
    public function ofId(InvoiceId $id): Invoice;

    public function save(Invoice $invoice): void;

    public function nextIdentity(): InvoiceId;
}

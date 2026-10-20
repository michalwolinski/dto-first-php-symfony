<?php

declare(strict_types=1);

namespace App\Domain\Invoice;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Paid = 'paid';
}

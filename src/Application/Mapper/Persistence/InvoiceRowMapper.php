<?php

declare(strict_types=1);

namespace App\Application\Mapper\Persistence;

use App\Domain\Invoice\Invoice;
use App\Domain\Invoice\InvoiceId;
use App\Domain\Invoice\InvoiceStatus;
use App\Domain\Invoice\Money;
use DateTimeImmutable;

/**
 * The storage crossing. Rows are arrays here rather than entities, which is deliberate: the point
 * is that a row — whatever it is made of — is translated at the edge instead of travelling inward.
 *
 * @phpstan-type Row array{id: string, customer_id: string, amount_minor: int, currency: string, status: string, due_date: string}
 */
final readonly class InvoiceRowMapper
{
    /** @param Row $row */
    public function toDomain(array $row): Invoice
    {
        return Invoice::fromState(
            InvoiceId::fromString($row['id']),
            $row['customer_id'],
            Money::of($row['amount_minor'], $row['currency']),
            InvoiceStatus::from($row['status']),
            new DateTimeImmutable($row['due_date']),
        );
    }

    /** @return Row */
    public function toRow(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id()->toString(),
            'customer_id' => $invoice->customerId(),
            'amount_minor' => $invoice->amount()->minorUnits(),
            'currency' => $invoice->amount()->currency(),
            'status' => $invoice->status()->value,
            'due_date' => $invoice->dueDate()->format(DATE_ATOM),
        ];
    }

    /** @param mixed $value */
    public function looksLikeARow($value): bool
    {
        return \is_array($value) && \is_string($value['id'] ?? null);
    }
}

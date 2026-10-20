<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mapper\Persistence\InvoiceRowMapper;
use App\Domain\Invoice\Exception\InvoiceNotFoundException;
use App\Domain\Invoice\Invoice;
use App\Domain\Invoice\InvoiceId;
use App\Domain\Invoice\InvoiceRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Storage, without a database: the rows are arrays kept in memory. What matters for the doctrine is
 * the direction of the translation — the repository stores rows and hands back aggregates, and the
 * rows never leave this class.
 */
final class InMemoryInvoiceRepository implements InvoiceRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    public function __construct(private readonly InvoiceRowMapper $mapper)
    {
    }

    public function ofId(InvoiceId $id): Invoice
    {
        $row = $this->rows[$id->toString()] ?? null;

        if (null === $row) {
            throw new InvoiceNotFoundException($id->toString());
        }

        return $this->mapper->toDomain($row);
    }

    public function save(Invoice $invoice): void
    {
        $this->rows[$invoice->id()->toString()] = $this->mapper->toRow($invoice);
    }

    public function nextIdentity(): InvoiceId
    {
        return InvoiceId::fromString(Uuid::v4()->toRfc4122());
    }

    /**
     * Test seam: what storage actually holds, so a test can assert that no aggregate leaked into it.
     *
     * @return array<string, array<string, mixed>>
     */
    public function storedRows(): array
    {
        return $this->rows;
    }
}

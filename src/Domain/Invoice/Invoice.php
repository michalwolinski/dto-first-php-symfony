<?php

declare(strict_types=1);

namespace App\Domain\Invoice;

use App\Domain\Invoice\Event\InvoiceIssued;
use App\Domain\Invoice\Exception\InvoiceNotPayableException;
use DateInterval;
use DateTimeImmutable;
use DomainException;

/**
 * The aggregate. Every invariant lives inside these methods, which is why the boundary's only job
 * is to hand it values that are already the right shape: validation at the entry asks "is this a
 * well-formed request", and this class answers "is this a legal invoice".
 *
 * The payment term is a domain policy: 14 days unless the caller (V2 of the payload) has an
 * opinion. V1 of the payload has no such field, and it does not need one — the boundary translates,
 * the domain decides.
 */
final class Invoice
{
    public const int DEFAULT_PAYMENT_TERM_DAYS = 14;

    /** @var list<InvoiceIssued> */
    private array $events = [];

    private InvoiceStatus $status = InvoiceStatus::Draft;

    private function __construct(
        private readonly InvoiceId $id,
        private readonly string $customerId,
        private readonly Money $amount,
        private readonly DateTimeImmutable $dueDate,
    ) {
    }

    public static function issue(
        InvoiceId $id,
        string $customerId,
        Money $amount,
        DateTimeImmutable $issuedAt,
        ?DateTimeImmutable $dueDate = null,
    ): self {
        if ('' === \trim($customerId)) {
            throw new DomainException('An invoice needs a customer.');
        }

        $invoice = new self(
            $id,
            $customerId,
            $amount,
            $dueDate ?? $issuedAt->add(new DateInterval('P'.self::DEFAULT_PAYMENT_TERM_DAYS.'D')),
        );
        $invoice->status = InvoiceStatus::Issued;
        $invoice->events[] = new InvoiceIssued($id, $amount, $issuedAt);

        return $invoice;
    }

    /**
     * Rebuilding from stored state is not the same as issuing: no event is recorded, because
     * nothing happened — it already happened.
     */
    public static function fromState(
        InvoiceId $id,
        string $customerId,
        Money $amount,
        InvoiceStatus $status,
        DateTimeImmutable $dueDate,
    ): self {
        $invoice = new self($id, $customerId, $amount, $dueDate);
        $invoice->status = $status;

        return $invoice;
    }

    public function markPaid(): void
    {
        if (InvoiceStatus::Issued !== $this->status) {
            throw new InvoiceNotPayableException($this->status->value);
        }

        $this->status = InvoiceStatus::Paid;
    }

    /** @return list<InvoiceIssued> */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    public function id(): InvoiceId
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function status(): InvoiceStatus
    {
        return $this->status;
    }

    public function dueDate(): DateTimeImmutable
    {
        return $this->dueDate;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Contract\Command\CreateInvoiceCommand;
use App\Application\Mapper\Message\InvoiceIssuedMessageMapper;
use App\Domain\Invoice\Invoice;
use App\Domain\Invoice\InvoiceRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The handler knows nothing about HTTP, JSON or API versions: it receives a command whose values
 * are already typed, and it does the domain's work. The only thing it publishes is a message built
 * by a mapper — never the aggregate.
 */
final readonly class CreateInvoiceHandler
{
    public function __construct(
        private InvoiceRepository $invoices,
        private InvoiceIssuedMessageMapper $messages,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateInvoiceCommand $command): Invoice
    {
        $invoice = Invoice::issue(
            $this->invoices->nextIdentity(),
            $command->customerId,
            $command->amount,
            $this->clock->now(),
            $command->dueDate,
        );

        $this->invoices->save($invoice);

        foreach ($invoice->releaseEvents() as $event) {
            $this->bus->dispatch($this->messages->map($event));
        }

        return $invoice;
    }
}

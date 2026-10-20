<?php

declare(strict_types=1);

namespace App\Tests\Boundary;

use App\Application\Contract\Command\CreateInvoiceCommand;
use App\Application\Contract\Http\V1\CreateInvoiceRequest as CreateInvoiceRequestV1;
use App\Application\Contract\Http\V2\CreateInvoiceRequest as CreateInvoiceRequestV2;
use App\Application\Mapper\Http\InvoiceResponseMapper;
use App\Application\Mapper\Http\V1CreateInvoiceRequestMapper;
use App\Application\Mapper\Http\V2CreateInvoiceRequestMapper;
use App\Application\Mapper\Message\InvoiceIssuedMessageMapper;
use App\Application\Mapper\Persistence\InvoiceRowMapper;
use App\Domain\Invoice\Event\InvoiceIssued;
use App\Domain\Invoice\Invoice;
use App\Domain\Invoice\InvoiceId;
use App\Domain\Invoice\InvoiceStatus;
use App\Domain\Invoice\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One test per crossing — rule 3 says every mapper is a named thing, and a named thing without a
 * test is a comment.
 *
 * The shape of the response is deliberately not asserted here. A test at this level can only see
 * the object the mapper returns, and the object is not what the client receives: the controller
 * serialises it through Symfony, which also picks up getter methods the DTO itself would never
 * encode. `theWireCarriesExactlyTheContractFields` in InvoiceBoundaryTest asserts the field list on
 * the real HTTP response instead, which is where the promise actually has to hold.
 */
final class MapperTest extends TestCase
{
    private const string UUID = '9f1c4a7e-1b2d-4f3a-8c5e-6d7b8a9c0d1e';

    #[Test]
    public function v1AndV2ProduceTheSameCommandShape(): void
    {
        $v1 = (new V1CreateInvoiceRequestMapper())->map(new CreateInvoiceRequestV1(self::UUID, 12500, 'EUR'));
        $v2 = (new V2CreateInvoiceRequestMapper())->map(new CreateInvoiceRequestV2(self::UUID, '125.00', 'EUR', '2026-11-30'));

        $this->assertInstanceOf(CreateInvoiceCommand::class, $v1);
        $this->assertInstanceOf(CreateInvoiceCommand::class, $v2);
        $this->assertTrue($v1->amount->equals($v2->amount), 'the same money, expressed two different ways on the wire');
        $this->assertNull($v1->dueDate, 'V1 has no due date, so the domain policy decides');
        $this->assertSame('2026-11-30', $v2->dueDate?->format('Y-m-d'));
    }

    #[Test]
    public function theMapperFillsTheContractFieldsFromTheAggregate(): void
    {
        $response = (new InvoiceResponseMapper())->map(
            Invoice::fromState(
                InvoiceId::fromString(self::UUID),
                self::UUID,
                Money::of(12500, 'EUR'),
                InvoiceStatus::Issued,
                new DateTimeImmutable('2026-11-30'),
            ),
        );

        $this->assertSame(self::UUID, $response->id);
        $this->assertSame(self::UUID, $response->customerId);
        $this->assertSame(12500, $response->amountMinor);
        $this->assertSame('EUR', $response->currency);
        $this->assertSame('issued', $response->status);
        $this->assertSame('2026-11-30T00:00:00+00:00', $response->dueDate);
    }

    #[Test]
    public function theMessageIsVersionedAndSpeaksInScalars(): void
    {
        $event = new InvoiceIssued(InvoiceId::fromString(self::UUID), Money::of(12500, 'EUR'), new DateTimeImmutable('2026-10-15 09:00:00'));

        $message = (new InvoiceIssuedMessageMapper())->map($event);

        $this->assertSame(1, $message->version, 'the wire shape is versioned, the domain event is not');
        $this->assertSame(self::UUID, $message->invoiceId);
        $this->assertSame(12500, $message->amountMinor);
        $this->assertSame('EUR', $message->currency);
        $this->assertSame('2026-10-15T09:00:00+00:00', $message->occurredAt);
    }

    #[Test]
    public function aRowRoundTripsThroughTheMapperWithoutTheAggregateLeaking(): void
    {
        $mapper = new InvoiceRowMapper();
        $invoice = Invoice::fromState(
            InvoiceId::fromString(self::UUID),
            self::UUID,
            Money::of(12500, 'EUR'),
            InvoiceStatus::Paid,
            new DateTimeImmutable('2026-11-30'),
        );

        $row = $mapper->toRow($invoice);
        $rebuilt = $mapper->toDomain($row);

        $this->assertIsArray($row);
        $this->assertArrayHasKey('amount_minor', $row);
        $this->assertTrue($mapper->looksLikeARow($row));
        $this->assertSame($invoice->id()->toString(), $rebuilt->id()->toString());
        $this->assertSame(InvoiceStatus::Paid, $rebuilt->status());
        $this->assertSame(12500, $rebuilt->amount()->minorUnits());
    }
}

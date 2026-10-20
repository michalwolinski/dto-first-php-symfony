<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Invoice\Event\InvoiceIssued;
use App\Domain\Invoice\Exception\InvoiceNotPayableException;
use App\Domain\Invoice\Invoice;
use App\Domain\Invoice\InvoiceId;
use App\Domain\Invoice\InvoiceStatus;
use App\Domain\Invoice\Money;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InvoiceTest extends TestCase
{
    private const string UUID = '9f1c4a7e-1b2d-4f3a-8c5e-6d7b8a9c0d1e';

    #[Test]
    public function issuingAnInvoiceRecordsTheEventAndIssuesIt(): void
    {
        $issuedAt = new DateTimeImmutable('2026-10-15 09:00:00');

        $invoice = Invoice::issue(InvoiceId::fromString(self::UUID), self::UUID, Money::of(12500, 'eur'), $issuedAt);

        $this->assertSame(InvoiceStatus::Issued, $invoice->status());
        $this->assertSame(12500, $invoice->amount()->minorUnits());
        $this->assertSame('EUR', $invoice->amount()->currency(), 'the currency is normalised at the value object, not at the edge');

        $events = $invoice->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(InvoiceIssued::class, $events[0]);
        $this->assertSame($issuedAt, $events[0]->occurredAt);
        $this->assertSame([], $invoice->releaseEvents(), 'events are released once, not replayed');
    }

    #[Test]
    public function thePaymentTermIsADomainPolicyWhenTheCallerHasNoOpinion(): void
    {
        $issuedAt = new DateTimeImmutable('2026-10-15 09:00:00');
        $invoice = Invoice::issue(InvoiceId::fromString(self::UUID), self::UUID, Money::of(100, 'EUR'), $issuedAt);

        $this->assertSame('2026-10-29', $invoice->dueDate()->format('Y-m-d'), '14 days later by default');
        $this->assertSame(Invoice::DEFAULT_PAYMENT_TERM_DAYS, 14);
    }

    #[Test]
    public function aCallerThatKnowsTheDueDateWins(): void
    {
        $invoice = Invoice::issue(
            InvoiceId::fromString(self::UUID),
            self::UUID,
            Money::of(100, 'EUR'),
            new DateTimeImmutable('2026-10-15 09:00:00'),
            new DateTimeImmutable('2026-11-30 00:00:00'),
        );

        $this->assertSame('2026-11-30', $invoice->dueDate()->format('Y-m-d'));
    }

    #[Test]
    public function anIssuedInvoiceCanBePaidOnce(): void
    {
        $invoice = Invoice::issue(InvoiceId::fromString(self::UUID), self::UUID, Money::of(100, 'EUR'), new DateTimeImmutable());

        $invoice->markPaid();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status());

        $this->expectException(InvoiceNotPayableException::class);
        $invoice->markPaid();
    }

    #[Test]
    public function rebuildingFromStateIsNotIssuing(): void
    {
        $invoice = Invoice::fromState(
            InvoiceId::fromString(self::UUID),
            self::UUID,
            Money::of(100, 'EUR'),
            InvoiceStatus::Paid,
            new DateTimeImmutable('2026-11-30'),
        );

        $this->assertSame(InvoiceStatus::Paid, $invoice->status());
        $this->assertSame([], $invoice->releaseEvents(), 'nothing happened, so nothing is announced');
    }

    #[Test]
    public function aCustomerIsRequired(): void
    {
        $this->expectException(DomainException::class);

        Invoice::issue(InvoiceId::fromString(self::UUID), '  ', Money::of(100, 'EUR'), new DateTimeImmutable());
    }

    #[Test]
    #[DataProvider('decimalAmounts')]
    public function amountsAreParsedWithoutFloatArithmetic(string $input, int $expectedMinorUnits): void
    {
        $this->assertSame($expectedMinorUnits, Money::fromDecimalString($input, 'EUR')->minorUnits());
    }

    /** @return iterable<string, array{string, int}> */
    public static function decimalAmounts(): iterable
    {
        yield 'whole' => ['1250', 125000];
        yield 'one decimal' => ['12.5', 1250];
        yield 'two decimals' => ['12.50', 1250];
        yield 'the rounding trap' => ['0.29', 29];
        yield 'more minor units than a double can hold exactly' => ['90071992547409.93', 9007199254740993];
        yield 'the largest amount the bound allows' => ['999999999999999.99', 99999999999999999];
    }

    #[Test]
    #[DataProvider('invalidAmounts')]
    public function anAmountThatIsNotMoneyIsRefused(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimalString($input, 'EUR');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAmounts(): iterable
    {
        yield 'three decimals' => ['1.234'];
        yield 'not a number' => ['twelve'];
        yield 'empty' => [''];
        yield 'negative' => ['-5'];
        yield 'sixteen digits, past the bound' => ['1234567890123456'];
        yield 'seventeen digits, where multiplying by 100 really does leave the int range' => ['99999999999999999'];
        yield 'eighteen digits' => ['999999999999999999.00'];
    }

    #[Test]
    public function zeroIsNotAnInvoiceAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(0, 'EUR');
    }

    #[Test]
    #[DataProvider('identifiers')]
    public function anIdentifierIsAUuidV4OrNothing(string $input, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(InvalidArgumentException::class);
        }

        $id = InvoiceId::fromString($input);

        if ($valid) {
            $this->assertTrue($id->equals(InvoiceId::fromString($input)));
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function identifiers(): iterable
    {
        yield 'a v4 uuid' => [self::UUID, true];
        yield 'a v1 uuid' => ['9f1c4a7e-1b2d-1f3a-8c5e-6d7b8a9c0d1e', false];
        yield 'a free-form string' => ['invoice-42', false];
    }

    #[Test]
    public function releasingEventsTwiceReturnsNothingTheSecondTime(): void
    {
        $invoice = Invoice::issue(InvoiceId::fromString(self::UUID), self::UUID, Money::of(100, 'EUR'), new DateTimeImmutable());

        $this->assertSame(1, \count($invoice->releaseEvents()));
        $this->assertSame(0, \count($invoice->releaseEvents()));
    }
}

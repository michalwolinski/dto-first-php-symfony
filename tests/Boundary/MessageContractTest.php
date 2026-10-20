<?php

declare(strict_types=1);

namespace App\Tests\Boundary;

use App\Application\Contract\Message\InvoiceIssuedMessage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * The cross-process contract, exercised through the application's own serializer — the same one the
 * messenger transport would use. What matters is not that it round-trips (it does) but that the
 * version travels with the payload: a consumer can tell which shape it received instead of guessing
 * from the presence of fields.
 *
 * The last two tests are here to say exactly how far that claim goes, because "the consumer can
 * tell" is only true for the changes that did not need telling. A payload that ADDS a field leaves
 * the old shape readable and its version visible; a payload that RENAMES one never gets that far,
 * because the version lives on the payload the serializer is already failing to build. Reading it
 * out of an envelope before deserialising is the upgrade path — a decision this demo leaves to you,
 * and a limit that is pinned by a test rather than left as an assumption.
 */
final class MessageContractTest extends KernelTestCase
{
    private const string BREAKING_V2 = '{"version":2,"invoiceId":"9f1c4a7e-1b2d-4f3a-8c5e-6d7b8a9c0d1e","amount":12500,"currency":"EUR","occurredAt":"2026-10-15T09:00:00+00:00"}';

    #[Test]
    public function theMessageSurvivesTheWireWithItsVersion(): void
    {
        self::bootKernel();
        $serializer = self::getContainer()->get(SerializerInterface::class);

        $message = new InvoiceIssuedMessage(
            version: InvoiceIssuedMessage::VERSION,
            invoiceId: '9f1c4a7e-1b2d-4f3a-8c5e-6d7b8a9c0d1e',
            amountMinor: 12500,
            currency: 'EUR',
            occurredAt: '2026-10-15T09:00:00+00:00',
        );

        $json = $serializer->serialize($message, 'json');
        $decoded = \json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['version']);
        $this->assertSame(12500, $decoded['amountMinor']);

        $rebuilt = $serializer->deserialize($json, InvoiceIssuedMessage::class, 'json');

        $this->assertSame($message->version, $rebuilt->version);
        $this->assertSame($message->invoiceId, $rebuilt->invoiceId);
        $this->assertSame($message->amountMinor, $rebuilt->amountMinor);
        $this->assertSame($message->occurredAt, $rebuilt->occurredAt);
    }

    #[Test]
    public function aConsumerCanSeeThatAnAdditivePayloadIsNewerThanItKnows(): void
    {
        self::bootKernel();
        $serializer = self::getContainer()->get(SerializerInterface::class);

        // Version 2 adds `note` and changes nothing else — the only kind of change a version field
        // on the payload can protect, because the v1 shape still reads.
        $fromTheFuture = '{"version":2,"invoiceId":"9f1c4a7e-1b2d-4f3a-8c5e-6d7b8a9c0d1e","amountMinor":12500,"currency":"EUR","occurredAt":"2026-10-15T09:00:00+00:00","note":"added in version 2"}';

        $message = $serializer->deserialize($fromTheFuture, InvoiceIssuedMessage::class, 'json');

        $this->assertGreaterThan(InvoiceIssuedMessage::VERSION, $message->version);
        $this->assertSame('9f1c4a7e-1b2d-4f3a-8c5e-6d7b8a9c0d1e', $message->invoiceId, 'the fields this consumer knows are still readable');
        $this->assertSame(12500, $message->amountMinor);
        $this->assertSame(
            ['version', 'invoiceId', 'amountMinor', 'currency', 'occurredAt'],
            \array_keys(\get_object_vars($message)),
            'the field this consumer does not know is dropped, not carried and not guessed at',
        );
    }

    #[Test]
    public function aPayloadThatBreaksTheShapeNeverReachesTheVersionItAnnounces(): void
    {
        self::bootKernel();
        $serializer = self::getContainer()->get(SerializerInterface::class);

        // `amount` where version 1 says `amountMinor`. The version is right there in the JSON and
        // nothing in this demo gets to read it: the serializer fails on the missing constructor
        // argument first. A consumer that had to answer "unsupported version 2" instead of this
        // would read the version out of the envelope before deserialising — which is the sentence
        // this test exists to keep honest.
        $this->expectException(MissingConstructorArgumentsException::class);

        $serializer->deserialize(self::BREAKING_V2, InvoiceIssuedMessage::class, 'json');
    }
}

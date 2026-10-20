<?php

declare(strict_types=1);

namespace App\Tests\Boundary;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The boundary, driven the way a client drives it. What these tests are really pinning is that the
 * version of the payload is a detail of the edge: v1 and v2 are different documents that produce the
 * same internal call, and everything that fails leaves through one of two contracts.
 *
 * This is also the only level at which the response shape can be asserted. The controller serialises
 * a DTO through Symfony's serializer, so a getter method on that DTO becomes a field on the wire
 * whether or not it is a property — a detail no test at the mapper level can see.
 */
final class InvoiceBoundaryTest extends WebTestCase
{
    private const string CUSTOMER = '9f1c4a7e-1b2d-4f3a-8c5e-6d7b8a9c0d1e';

    /** The contract, in order. A new field here is a deliberate act, not a side effect. */
    private const array RESPONSE_FIELDS = ['id', 'customerId', 'amountMinor', 'currency', 'status', 'dueDate'];

    #[Test]
    public function version1CreatesAnInvoiceFromMinorUnits(): void
    {
        $client = $this->client();
        $client->request('POST', '/v1/invoices', server: ['CONTENT_TYPE' => 'application/json'], content: (string) \json_encode([
            'customerId' => self::CUSTOMER,
            'amountMinor' => 12500,
            'currency' => 'EUR',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $body = $this->decode($client->getResponse()->getContent());
        $this->assertSame(12500, $body['amountMinor']);
        $this->assertSame('issued', $body['status']);
        $this->assertStringStartsWith('/invoices/', (string) $client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function version2CreatesTheSameInvoiceFromADecimalString(): void
    {
        $client = $this->client();
        $client->request('POST', '/v2/invoices', server: ['CONTENT_TYPE' => 'application/json'], content: (string) \json_encode([
            'customerId' => self::CUSTOMER,
            'amount' => '125.00',
            'currency' => 'EUR',
            'dueDate' => '2026-11-30',
        ]));

        $this->assertResponseStatusCodeSame(201);

        $body = $this->decode($client->getResponse()->getContent());
        $this->assertSame(12500, $body['amountMinor'], 'the same money as v1, written differently on the wire');
        $this->assertSame('EUR', $body['currency']);
        $this->assertStringStartsWith('2026-11-30', $body['dueDate'], 'v2 supplied the date, so the domain policy did not apply');
    }

    #[Test]
    public function theWireCarriesExactlyTheContractFields(): void
    {
        $client = $this->client();
        $id = $this->createOne($client);

        $client->request('GET', '/invoices/'.$id);

        $this->assertResponseStatusCodeSame(200);

        $body = $this->decode($client->getResponse()->getContent());

        $this->assertSame(
            self::RESPONSE_FIELDS,
            \array_keys($body),
            'adding a method to InvoiceResponse must not be able to add a field to the response by accident',
        );
    }

    #[Test]
    public function theDomainPolicyFillsInWhatVersion1LeftOut(): void
    {
        $client = $this->client();
        // A frozen clock, because "14 days from now" is not an assertion — it is a race with the
        // second hand. The policy belongs to the domain; the boundary only omits the field.
        self::getContainer()->set('clock', new MockClock(new DateTimeImmutable('2026-10-15 09:00:00')));

        $client->request('POST', '/v1/invoices', server: ['CONTENT_TYPE' => 'application/json'], content: (string) \json_encode([
            'customerId' => self::CUSTOMER,
            'amountMinor' => 100,
            'currency' => 'EUR',
        ]));

        $body = $this->decode($client->getResponse()->getContent());
        $this->assertStringStartsWith('2026-10-29', $body['dueDate'], '14 days after the frozen issue date');
    }

    #[Test]
    public function aRejectedPayloadLeavesThroughTheValidationContract(): void
    {
        $client = $this->client();
        $client->request('POST', '/v1/invoices', server: ['CONTENT_TYPE' => 'application/json'], content: (string) \json_encode([
            'customerId' => 'not-a-uuid',
            'amountMinor' => -5,
            'currency' => 'EUR',
        ]));

        $this->assertResponseStatusCodeSame(422);
        $this->assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $body = $this->decode($client->getResponse()->getContent());
        $this->assertSame('https://wolinski.com/problems/validation', $body['type']);
        $this->assertNotSame([], $body['violations'], 'the client learns which fields are wrong, not just that something is');
        $this->assertArrayHasKey('field', $body['violations'][0]);
        $this->assertArrayHasKey('message', $body['violations'][0]);
    }

    #[Test]
    public function anAmountTooLargeForMinorUnitsIsRefusedByTheContract(): void
    {
        $client = $this->client();
        // Eighteen digits: the mapper multiplies by 100, which would leave the int range and turn
        // into a TypeError — a 500. The bound on the type is what makes this a 422 instead.
        $client->request('POST', '/v2/invoices', server: ['CONTENT_TYPE' => 'application/json'], content: (string) \json_encode([
            'customerId' => self::CUSTOMER,
            'amount' => '999999999999999999.00',
            'currency' => 'EUR',
            'dueDate' => '2026-11-30',
        ]));

        $this->assertResponseStatusCodeSame(422);
        $this->assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $this->assertSame('https://wolinski.com/problems/validation', $this->decode($client->getResponse()->getContent())['type']);
    }

    #[Test]
    public function anInvoiceCanBeReadBackInTheContractShape(): void
    {
        $client = $this->client();
        $id = $this->createOne($client);

        $client->request('GET', '/invoices/'.$id);

        $this->assertResponseStatusCodeSame(200);
        $body = $this->decode($client->getResponse()->getContent());
        $this->assertSame($id, $body['id']);
        $this->assertSame('issued', $body['status']);
    }

    #[Test]
    public function payingTwiceIsARefusalWithAContract(): void
    {
        $client = $this->client();
        $id = $this->createOne($client);

        $client->request('POST', '/invoices/'.$id.'/paid');
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('paid', $this->decode($client->getResponse()->getContent())['status']);

        $client->request('POST', '/invoices/'.$id.'/paid');
        $this->assertResponseStatusCodeSame(409);
        $this->assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $this->assertSame('https://wolinski.com/problems/invoice-not-payable', $this->decode($client->getResponse()->getContent())['type']);
    }

    #[Test]
    public function anUnknownInvoiceIsANotFoundProblem(): void
    {
        $client = $this->client();
        $client->request('GET', '/invoices/9f1c4a7e-1b2d-4f3a-8c5e-6d7b8a9c0d1e');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('https://wolinski.com/problems/invoice-not-found', $this->decode($client->getResponse()->getContent())['type']);
    }

    #[Test]
    public function anIdentifierThatIsNotAUuidIsRefusedBeforeTheDomainSeesIt(): void
    {
        $client = $this->client();
        // The route declares Requirement::UUID_V4, so this path matches no route at all. Without
        // the requirement, InvoiceId::fromString() throws and the answer is a 500.
        $client->request('GET', '/invoices/not-a-uuid');

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * A client that does not reboot the kernel between requests. The demo's storage lives in
     * memory, so a reboot would empty it — the same reason a real application would have a
     * database rather than a service.
     */
    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    /** @return array<string, mixed> */
    private function decode(?string $json): array
    {
        return (array) \json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function createOne(KernelBrowser $client): string
    {
        $client->request('POST', '/v1/invoices', server: ['CONTENT_TYPE' => 'application/json'], content: (string) \json_encode([
            'customerId' => self::CUSTOMER,
            'amountMinor' => 500,
            'currency' => 'EUR',
        ]));

        return (string) $this->decode($client->getResponse()->getContent())['id'];
    }
}

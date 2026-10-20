<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\Contract\Http\V1\CreateInvoiceRequest as CreateInvoiceRequestV1;
use App\Application\Contract\Http\V2\CreateInvoiceRequest as CreateInvoiceRequestV2;
use App\Application\Handler\CreateInvoiceHandler;
use App\Application\Handler\MarkInvoicePaidHandler;
use App\Application\Mapper\Http\InvoiceResponseMapper;
use App\Application\Mapper\Http\V1CreateInvoiceRequestMapper;
use App\Application\Mapper\Http\V2CreateInvoiceRequestMapper;
use App\Domain\Invoice\Invoice;
use App\Domain\Invoice\InvoiceId;
use App\Domain\Invoice\InvoiceRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * The only class in the application that is allowed to see a framework type — and even here it is
 * one layer thin: the request DTO is hydrated and validated by the framework, a named mapper turns
 * it into a command, and the response is built from a DTO. No aggregate, no raw array and no
 * payload mapper signature crosses this line in either direction.
 */
final readonly class InvoiceController
{
    public function __construct(
        private CreateInvoiceHandler $createHandler,
        private MarkInvoicePaidHandler $markPaidHandler,
        private V1CreateInvoiceRequestMapper $fromV1,
        private V2CreateInvoiceRequestMapper $fromV2,
        private InvoiceResponseMapper $toResponse,
        private InvoiceRepository $invoices,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route('/v1/invoices', methods: ['POST'])]
    public function createV1(#[MapRequestPayload] CreateInvoiceRequestV1 $request): Response
    {
        return $this->created(($this->createHandler)($this->fromV1->map($request)));
    }

    #[Route('/v2/invoices', methods: ['POST'])]
    public function createV2(#[MapRequestPayload] CreateInvoiceRequestV2 $request): Response
    {
        return $this->created(($this->createHandler)($this->fromV2->map($request)));
    }

    #[Route('/invoices/{id}', methods: ['GET'], requirements: ['id' => Requirement::UUID_V4])]
    public function show(string $id): Response
    {
        $invoice = $this->invoices->ofId(InvoiceId::fromString($id));

        return $this->json($this->toResponse->map($invoice));
    }

    #[Route('/invoices/{id}/paid', methods: ['POST'], requirements: ['id' => Requirement::UUID_V4])]
    public function markPaid(string $id): Response
    {
        $invoice = ($this->markPaidHandler)($id);

        return $this->json($this->toResponse->map($invoice));
    }

    private function created(Invoice $invoice): Response
    {
        $response = $this->json($this->toResponse->map($invoice), Response::HTTP_CREATED);
        $response->headers->set('Location', '/invoices/'.$invoice->id()->toString());

        return $response;
    }

    private function json(object $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse(
            $this->serializer->serialize($payload, 'json'),
            $status,
            [],
            true,
        );
    }
}

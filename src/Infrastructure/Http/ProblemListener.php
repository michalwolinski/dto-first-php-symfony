<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\Mapper\Http\ProblemMapper;
use App\Application\Mapper\Http\ValidationProblemMapper;
use App\Domain\Invoice\Exception\InvoiceNotFoundException;
use App\Domain\Invoice\Exception\InvoiceNotPayableException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * The last metre of the boundary: every failure this application raises leaves through one of the
 * two problem contracts.
 *
 * "This application raises" is the honest scope. A malformed body or a path that matches no route
 * is refused by the framework before any of this runs, and Symfony answers those with its own error
 * response — the doctrine covers the refusals the boundary decides, not the ones the kernel makes
 * before the boundary is reached.
 *
 * The framework's own 422 carries the violations inside a ValidationFailedException (attached to the
 * HttpException as its previous exception), so the DTO that the client sees is assembled by the same
 * kind of named mapper as everything else — the framework's internal shape never reaches the wire.
 */
#[AsEventListener(event: ExceptionEvent::class)]
final readonly class ProblemListener
{
    public function __construct(
        private ValidationProblemMapper $validationProblems,
        private ProblemMapper $problems,
        private SerializerInterface $serializer,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $previous = $exception->getPrevious();

        if ($exception instanceof HttpException && $previous instanceof ValidationFailedException) {
            $event->setResponse($this->respond(
                $this->validationProblems->map($previous->getViolations()),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            ));

            return;
        }

        if ($exception instanceof InvoiceNotFoundException) {
            $event->setResponse($this->respond(
                $this->problems->notFound($exception->getMessage()),
                Response::HTTP_NOT_FOUND,
            ));

            return;
        }

        if ($exception instanceof InvoiceNotPayableException) {
            $event->setResponse($this->respond(
                $this->problems->notPayable($exception->getMessage()),
                Response::HTTP_CONFLICT,
            ));
        }
    }

    private function respond(object $problem, int $status): JsonResponse
    {
        return new JsonResponse(
            $this->serializer->serialize($problem, 'json'),
            $status,
            ['Content-Type' => 'application/problem+json'],
            true,
        );
    }
}

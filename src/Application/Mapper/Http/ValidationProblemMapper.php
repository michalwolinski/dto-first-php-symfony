<?php

declare(strict_types=1);

namespace App\Application\Mapper\Http;

use App\Application\Contract\Http\ValidationProblem;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * The error crossing has a mapper of its own, because "errors are a contract" only holds if the
 * shape has one owner. The framework's violation objects are translated here and nowhere else.
 */
final readonly class ValidationProblemMapper
{
    /**
     * @param iterable<ConstraintViolationInterface> $violations
     */
    public function map(iterable $violations): ValidationProblem
    {
        $mapped = [];

        foreach ($violations as $violation) {
            $mapped[] = [
                'field' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return new ValidationProblem(
            type: 'https://wolinski.com/problems/validation',
            title: 'The payload is not a valid invoice request.',
            violations: $mapped,
        );
    }
}

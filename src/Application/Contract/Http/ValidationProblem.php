<?php

declare(strict_types=1);

namespace App\Application\Contract\Http;

/**
 * Errors are a contract too, and an ad-hoc {"error": "..."} string is the most common way a
 * boundary leaks its implementation. Every rejected payload leaves through this shape.
 */
final readonly class ValidationProblem
{
    /** @param list<array{field: string, message: string}> $violations */
    public function __construct(
        public string $type,
        public string $title,
        public array $violations,
    ) {
    }
}

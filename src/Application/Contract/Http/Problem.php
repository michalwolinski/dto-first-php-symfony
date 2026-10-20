<?php

declare(strict_types=1);

namespace App\Application\Contract\Http;

/**
 * The non-validation error contract (RFC 9457 shape). Ad-hoc {"error": "..."} responses are how a
 * boundary leaks its internals: the client ends up parsing English.
 */
final readonly class Problem
{
    public function __construct(
        public string $type,
        public string $title,
        public string $detail,
    ) {
    }
}

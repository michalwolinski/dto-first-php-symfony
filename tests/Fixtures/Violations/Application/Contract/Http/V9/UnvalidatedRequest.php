<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Violations\Application\Contract\Http\V9;

/**
 * Deliberate rule-2 violation: a request DTO nobody validates.
 */
final readonly class UnvalidatedRequest
{
    public function __construct(
        public string $customerId = '',
        public int $amountMinor = 0,
    ) {
    }
}

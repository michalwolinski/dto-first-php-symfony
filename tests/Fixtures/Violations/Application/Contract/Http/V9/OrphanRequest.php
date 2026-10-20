<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Violations\Application\Contract\Http\V9;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Deliberate rule-3 violation: validated, well-behaved, and connected to nothing — no mapper
 * mentions it, so nobody knows where it goes.
 */
final readonly class OrphanRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $customerId = '',
    ) {
    }
}

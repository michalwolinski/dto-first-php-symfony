<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Violations\Domain;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Deliberate rule-2 violation: payload validation inside the domain.
 */
final readonly class ValidatesPayloads
{
    public function __construct(
        #[Assert\NotBlank]
        public string $name = '',
    ) {
    }
}

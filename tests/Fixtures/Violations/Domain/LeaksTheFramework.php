<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Violations\Domain;

use Symfony\Component\HttpFoundation\Request;

/**
 * Deliberate rule-1 violation: the domain reaches for a framework type.
 */
final readonly class LeaksTheFramework
{
    public function __construct(public Request $request)
    {
    }
}

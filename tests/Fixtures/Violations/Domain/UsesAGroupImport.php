<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Violations\Domain;

use Symfony\Component\HttpFoundation\{
    Request,
};

/**
 * Deliberate rule-1 violation: a grouped import, and on two lines. The first version of the import
 * scan only looked at single-line `use X;` statements, so this dependency was invisible to it.
 */
final readonly class UsesAGroupImport
{
    public function __construct(public Request $request)
    {
    }
}

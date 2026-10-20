<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Violations\Domain;

/**
 * Deliberate rule-1 violation with no import at all: the framework type is written out in full in
 * the signature, which is the doctrine's own forbidden example — `public function __construct(
 * public Request $request)` — with the `use` statement left out. An import scan cannot see this
 * one, so the rule has to look at the body as well.
 */
final readonly class NamesAFullyQualifiedType
{
    public function __construct(public \Symfony\Component\HttpFoundation\Request $request)
    {
    }
}

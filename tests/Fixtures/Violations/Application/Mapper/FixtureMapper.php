<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Violations\Application\Mapper;

use App\Tests\Fixtures\Violations\Application\Contract\Http\LeakyResponse;
use App\Tests\Fixtures\Violations\Application\Contract\Http\NamesTheDomainDirectly;
use App\Tests\Fixtures\Violations\Application\Contract\Http\V9\UnvalidatedRequest;
use App\Tests\Fixtures\Violations\Application\Contract\Message\UnversionedMessage;

/**
 * The mapper that makes rule 3's fixture mean something. Every contract in this tree is mentioned
 * here except `V9\OrphanRequest`, so the gate has exactly one orphan to report — without a mapper
 * in this directory every contract would be an orphan, and the rule would pass for a reason that
 * has nothing to do with the check.
 */
final readonly class FixtureMapper
{
    /** @return array<string, mixed> */
    public function leaky(LeakyResponse $response): array
    {
        return ['invoice' => 'never executed'];
    }

    /** @return array<string, mixed> */
    public function direct(NamesTheDomainDirectly $response): array
    {
        return ['invoice' => 'never executed'];
    }

    /** @return array<string, mixed> */
    public function unvalidated(UnvalidatedRequest $request): array
    {
        return ['customerId' => 'never executed'];
    }

    /** @return array<string, mixed> */
    public function unversioned(UnversionedMessage $message): array
    {
        return ['invoiceId' => 'never executed'];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Violations\Infrastructure\Http;

use App\Domain\Invoice\Invoice;
use LogicException;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Three deliberate rule-4 violations, because the controller half of the rule is a text search and
 * the shapes a text search misses are the point.
 *
 * The first is the plain case: a controller action that hands the aggregate straight to the client.
 * The second puts parentheses inside the parameter list — an attribute with arguments — which
 * defeats a search that assumes the first `)` closes the parameter list and lets the domain return
 * type hide behind it. The third is public and static, which a search anchored on `public function`
 * walks past.
 */
final readonly class LeakyController
{
    public function show(): Invoice
    {
        throw new LogicException('never executed: the gate reads this file, it does not run it');
    }

    public function store(#[Assert\NotBlank(message: 'never executed')] string $customerId): Invoice
    {
        throw new LogicException('never executed: the gate reads this file, it does not run it');
    }

    public static function all(): Invoice
    {
        throw new LogicException('never executed: the gate reads this file, it does not run it');
    }
}

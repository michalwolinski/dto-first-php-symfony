<?php

declare(strict_types=1);

namespace App\Application\Mapper\Http;

use App\Application\Contract\Http\Problem;

/**
 * Domain refusals become problems here, and only here. The policy "which domain exception is which
 * status code" is a boundary decision, so it lives in the boundary's mapper rather than in a
 * controller's try/catch.
 */
final readonly class ProblemMapper
{
    public function notFound(string $invoiceId): Problem
    {
        return new Problem(
            type: 'https://wolinski.com/problems/invoice-not-found',
            title: 'No such invoice.',
            detail: \sprintf('There is no invoice %s.', $invoiceId),
        );
    }

    public function notPayable(string $status): Problem
    {
        return new Problem(
            type: 'https://wolinski.com/problems/invoice-not-payable',
            title: 'This invoice cannot be paid yet.',
            detail: \sprintf('An invoice can only be paid once it is issued; this one is %s.', $status),
        );
    }
}

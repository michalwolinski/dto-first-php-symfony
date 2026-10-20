<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Violations\Infrastructure\Http;

use App\Domain\Invoice\Invoice as Aggregate;
use LogicException;

/**
 * Deliberate rule-4 violation in the two shapes the first version of the return-type search missed:
 * the type is nullable, and the file renamed it with `as`. Resolving short names through the file's
 * own imports has to account for both, or a controller can return the aggregate with `?` in front of
 * it, under a name of its own choosing, and pass.
 *
 * The third shape — the same type written out in full, `\App\Domain\Invoice\Invoice` — cannot live
 * here: `fully_qualified_strict_types` is part of the preset and rewrites it to the alias above, so
 * the test that needs it writes it into a temporary tree instead.
 */
final readonly class NullableAliasedController
{
    public function show(): ?Aggregate
    {
        throw new LogicException('never executed: the gate reads this file, it does not run it');
    }
}

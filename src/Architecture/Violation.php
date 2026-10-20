<?php

declare(strict_types=1);

namespace App\Architecture;

/**
 * One broken rule, in one file, at one line — with the rule quoted, because a gate that says
 * "architecture violation" teaches nobody anything.
 */
final readonly class Violation
{
    public function __construct(
        public string $rule,
        public string $file,
        public int $line,
        public string $detail,
    ) {
    }

    public function describe(): string
    {
        return sprintf('%s:%d — %s (%s)', $this->file, $this->line, $this->detail, $this->rule);
    }
}

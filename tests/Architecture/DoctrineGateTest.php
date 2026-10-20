<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Architecture\DoctrineGate;
use App\Architecture\Violation;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The enforcement the whole doctrine rests on: five rules, checked on every test run, and the exact
 * violations the gate has to report in a directory of deliberate breakages.
 *
 * A gate that has never failed is a gate nobody should trust — that part is unchanged. What changed
 * is how specific the proof is. An assertion that only asks "did a violation come back for this
 * rule" is weaker than it looks: three separate breakages could be deleted from that directory and
 * the rule would still look proven, because one fixture tripped one branch of the check. So every
 * rule is pinned to the whole list of files and lines it has to report, which means a check that
 * quietly stops working turns the test red instead of staying green.
 *
 * The fixtures are also what makes the lines meaningful: there is one deliberate breakage per shape
 * the check claims to understand, so `UsesAGroupImport`, `NamesAFullyQualifiedType`,
 * `NamesTheDomainDirectly`, `NullableAliasedController` and the second and third methods of
 * `LeakyController` each exist because an earlier version of the gate read past them. Two shapes
 * cannot be fixtures at all — an import with a leading backslash, and a fully qualified return type —
 * because the style preset repairs both; each is written by its own test below instead.
 */
final class DoctrineGateTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function rules(): iterable
    {
        foreach (DoctrineGate::RULES as $rule => $description) {
            yield $description => [$rule];
        }
    }

    /**
     * Expected violations, read off the fixture files rather than off the gate: which line a
     * fixture breaks is a property of the fixture, and taking that list from the gate's own output
     * would assert nothing.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function violations(): iterable
    {
        yield 'rule1 — every way of naming something outside the domain' => ['rule1', [
            'Domain/LeaksTheFramework.php:7',
            'Domain/NamesAFullyQualifiedType.php:15',
            'Domain/UsesAGroupImport.php:7',
            'Domain/ValidatesPayloads.php:7',
        ]];

        yield 'rule2 — a validator in the domain, a request nobody validated' => ['rule2', [
            'Domain/ValidatesPayloads.php:7',
            'Application/Contract/Http/V9/UnvalidatedRequest.php:1',
        ]];

        yield 'rule3 — one orphan, and only one' => ['rule3', [
            'Application/Contract/Http/V9/OrphanRequest.php:1',
        ]];

        yield 'rule4 — the aggregate on the wire, in every shape a signature can take' => ['rule4', [
            'Application/Contract/Http/LeakyResponse.php:7',
            'Application/Contract/Http/NamesTheDomainDirectly.php:15',
            'Infrastructure/Http/LeakyController.php:23',
            'Infrastructure/Http/LeakyController.php:28',
            'Infrastructure/Http/LeakyController.php:33',
            'Infrastructure/Http/NullableAliasedController.php:22',
        ]];

        yield 'rule5 — a contract with no version' => ['rule5', [
            'Application/Contract/Message/UnversionedMessage.php:1',
        ]];
    }

    #[Test]
    #[DataProvider('rules')]
    public function theSourceObeysEveryRule(string $rule): void
    {
        $violations = $this->gateForTheRepository()->check($rule);

        $this->assertSame([], \array_map(static fn (Violation $violation): string => $violation->describe(), $violations), \sprintf('%s — %s', $rule, DoctrineGate::RULES[$rule]));
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('violations')]
    public function theGateCatchesExactlyTheseViolations(string $rule, array $expected): void
    {
        $reported = \array_map(
            static fn (Violation $violation): string => \sprintf('%s:%d', $violation->file, $violation->line),
            $this->gateForTheViolationFixtures()->check($rule),
        );

        $this->assertSame($expected, $reported, \sprintf('%s — %s', $rule, DoctrineGate::RULES[$rule]));
    }

    #[Test]
    public function theGateReportsEveryRuleTogether(): void
    {
        $report = $this->gateForTheViolationFixtures()->all();

        $this->assertSame(\array_keys(DoctrineGate::RULES), \array_keys($report));
        $this->assertFalse($this->gateForTheViolationFixtures()->passes());
        $this->assertTrue($this->gateForTheRepository()->passes(), 'the demo itself must satisfy the doctrine it argues for');
    }

    /**
     * One shape cannot live in the fixtures directory, so it is written here instead.
     *
     * `use \Symfony\...;` with a leading backslash is a rule-1 violation, and it is also something
     * `no_leading_import_slash` — part of the style preset — rewrites into compliance. A formatter
     * run therefore repairs the fixture and leaves the rule looking proven by a file that no longer
     * breaks it. That is not a theory: the fixture was written, silently normalised by `pint`, and
     * caught only because the expected-violations assertion above went red.
     *
     * Nothing formats a file this test writes, and the tree is removed again whatever the assertion
     * does.
     */
    #[Test]
    public function theGateUnderstandsTheLeadingBackslashOnAnImport(): void
    {
        $root = $this->temporarySourceTree([
            'Domain/UsesALeadingBackslash.php' => <<<'PHP'
                <?php

                namespace App\Domain;

                use \Symfony\Component\HttpFoundation\Request;

                final readonly class UsesALeadingBackslash
                {
                    public function __construct(public Request $request)
                    {
                    }
                }
                PHP,
        ]);

        try {
            $reported = \array_map(
                static fn (Violation $violation): string => \sprintf('%s:%d', $violation->file, $violation->line),
                (new DoctrineGate($root))->check('rule1'),
            );
        } finally {
            $this->removeTree($root);
        }

        $this->assertSame(['Domain/UsesALeadingBackslash.php:5'], $reported);
    }

    /**
     * The other shape the formatter repairs, and the reason rule 4 has a fixture of its own for a
     * domain type in a contract's body: `fully_qualified_strict_types` rewrites
     * `\App\Domain\Invoice\Invoice` into whatever the file already aliased it to, so a return type
     * written out in full cannot be left in `tests/Fixtures/Violations/` for the gate to find.
     *
     * Rule 4 had the same blind spot rule 1 had — it read the imports and not the body — and this is
     * the case that shows it is gone.
     */
    #[Test]
    public function theGateUnderstandsTheLeadingBackslashOnAReturnType(): void
    {
        $root = $this->temporarySourceTree([
            'Infrastructure/Http/RawController.php' => <<<'PHP'
                <?php

                namespace App\Infrastructure\Http;

                final readonly class RawController
                {
                    public function raw(): \App\Domain\Invoice\Invoice
                    {
                        throw new \LogicException('never executed');
                    }
                }
                PHP,
        ]);

        try {
            $reported = \array_map(
                static fn (Violation $violation): string => \sprintf('%s:%d', $violation->file, $violation->line),
                (new DoctrineGate($root))->check('rule4'),
            );
        } finally {
            $this->removeTree($root);
        }

        $this->assertSame(['Infrastructure/Http/RawController.php:7'], $reported);
    }

    #[Test]
    public function aViolationNamesTheRuleTheFileAndTheLine(): void
    {
        $violation = $this->gateForTheViolationFixtures()->check('rule5')[0];

        $this->assertSame('rule5', $violation->rule);
        $this->assertSame('Application/Contract/Message/UnversionedMessage.php', $violation->file);
        $this->assertGreaterThan(0, $violation->line);
        $this->assertStringContainsString('version', $violation->describe());
    }

    /** @param array<string, string> $files */
    private function temporarySourceTree(array $files): string
    {
        $root = \sys_get_temp_dir().'/doctrine-gate-'.bin2hex(\random_bytes(6));

        foreach ($files as $path => $contents) {
            $file = $root.'/'.$path;

            if (!\mkdir(\dirname($file), 0o777, true) && !\is_dir(\dirname($file))) {
                self::fail(\sprintf('could not create %s', \dirname($file)));
            }

            \file_put_contents($file, $contents);
        }

        return $root;
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }

        \rmdir($root);
    }

    private function gateForTheRepository(): DoctrineGate
    {
        return new DoctrineGate(\dirname(__DIR__, 2).'/src');
    }

    private function gateForTheViolationFixtures(): DoctrineGate
    {
        return new DoctrineGate(__DIR__.'/../Fixtures/Violations');
    }
}

<?php

declare(strict_types=1);

namespace App\Architecture;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The doctrine, executable. Five rules, five checks, and a test per rule that fails the build.
 *
 * What this is: a smoke detector, and the honest version of that sentence names what it cannot see
 * before listing what it can.
 *
 *   - it does not see values. `return $this->json($invoice);` hands the aggregate to the client
 *     without naming a domain type in any signature, and nothing that reads text will catch it.
 *     The controller half of rule 4 checks declared return types, not the object that leaves.
 *   - it does not see semantics. A class can obey all five rules and still leak its implementation
 *     through a badly chosen field.
 *
 * What it does mean is that a reviewer can miss a violation and this cannot. Point it at a
 * different source root and it audits that one instead — which is how the test suite proves each
 * check bites: `tests/Fixtures/Violations` holds one breakage per check, and DoctrineGateTest
 * asserts the exact files and lines reported for each rule.
 *
 * The shapes it understands are the ones PHP actually allows, because each of these was a way past
 * an earlier version of this file: an import with a leading backslash, a grouped import, one written
 * across several lines, an alias, a nullable or unioned return type, a `static` modifier on the
 * action, and a fully qualified name used in a signature or a property with no `use` statement
 * anywhere near it. Both rules that can be defeated this way — rule 1 for the domain, rule 4 for the
 * wire — scan the body as well as the imports, which is the symmetry that was missing at first.
 */
final readonly class DoctrineGate
{
    /** @var array<string, string> */
    public const array RULES = [
        'rule1' => 'Nothing from outside gets a type inside the domain.',
        'rule2' => 'Validation happens once, at the entry, on the type.',
        'rule3' => 'Every crossing has a named mapper.',
        'rule4' => 'The wire never carries a domain type.',
        'rule5' => 'Every cross-process contract carries a version.',
    ];

    /** @var array<string, string> */
    private const array OUTSIDE_NAMESPACES = [
        'Symfony\\' => 'the framework',
        'Doctrine\\' => 'an ORM',
        'Psr\\' => 'a PSR interface',
        'App\\Application' => 'another application layer',
        'App\\Infrastructure' => 'infrastructure',
    ];

    /**
     * The same dependencies written as fully qualified names in the body of a file instead of
     * imported at the top: `public function __construct(public \Symfony\...\Request $request)` is
     * the doctrine's own forbidden example with the `use` statement left out.
     *
     * @var array<string, string>
     */
    private const array OUTSIDE_MARKERS = [
        '\\Symfony\\' => 'the framework',
        '\\Doctrine\\' => 'an ORM',
        '\\Psr\\' => 'a PSR interface',
        '\\App\\Application\\' => 'another application layer',
        '\\App\\Infrastructure\\' => 'infrastructure',
    ];

    public function __construct(
        private string $sourceRoot,
        private string $namespaceRoot = 'App',
    ) {
    }

    /** @return array<string, list<Violation>> */
    public function all(): array
    {
        $report = [];

        foreach (\array_keys(self::RULES) as $rule) {
            $report[$rule] = $this->check($rule);
        }

        return $report;
    }

    public function passes(): bool
    {
        foreach ($this->all() as $violations) {
            if (\count($violations) > 0) {
                return false;
            }
        }

        return true;
    }

    /** @return list<Violation> */
    public function check(string $rule): array
    {
        return match ($rule) {
            'rule1' => $this->nothingFromOutsideInTheDomain(),
            'rule2' => $this->validationAtTheEntry(),
            'rule3' => $this->everyCrossingHasAMapper(),
            'rule4' => $this->noDomainTypesOnTheWire(),
            'rule5' => $this->versionedContracts(),
            default => throw new InvalidArgumentException(\sprintf('There is no %s in this doctrine.', $rule)),
        };
    }

    /** @return list<Violation> */
    private function nothingFromOutsideInTheDomain(): array
    {
        $violations = [];

        foreach ($this->files('Domain') as $file) {
            $path = $this->shortPath($file);

            foreach ($this->imports($file) as [$namespace, , $line]) {
                foreach (self::OUTSIDE_NAMESPACES as $outside => $what) {
                    if (\str_starts_with($namespace, $outside)) {
                        $violations[] = new Violation('rule1', $path, $line, \sprintf('the domain imports %s (%s)', $namespace, $what));
                    }
                }
            }

            foreach ($this->markersInTheBody($file, self::OUTSIDE_MARKERS) as [$marker, $line]) {
                $violations[] = new Violation('rule1', $path, $line, \sprintf('the domain names %s in the body (%s)', $marker, self::OUTSIDE_MARKERS[$marker]));
            }
        }

        return $violations;
    }

    /**
     * Fully qualified names used below the import block — the dependency without a `use` statement
     * in front of it. Import lines are skipped because the scans above already report them, with a
     * better message.
     *
     * A docblock is text like any other, which cuts both ways: a marker here is reported even when
     * it appears in prose, while rule 3's mention check is satisfied by prose. Neither is a bug so
     * much as the price of reading files instead of parsing them.
     *
     * @param array<string, string> $markers
     *
     * @return list<array{string, int}>
     */
    private function markersInTheBody(string $file, array $markers): array
    {
        $found = [];

        foreach (\explode("\n", (string) \file_get_contents($file)) as $number => $line) {
            if (\str_starts_with(\strtolower(\trim($line)), 'use ')) {
                continue;
            }

            foreach ($markers as $marker => $what) {
                if (\str_contains($line, $marker)) {
                    $found[] = [$marker, $number + 1];

                    break;
                }
            }
        }

        return $found;
    }

    /** @return list<Violation> */
    private function validationAtTheEntry(): array
    {
        $violations = [];

        foreach ($this->files('Domain') as $file) {
            foreach ($this->imports($file) as [$namespace, , $line]) {
                if (\str_starts_with($namespace, 'Symfony\\Component\\Validator')) {
                    $violations[] = new Violation('rule2', $this->shortPath($file), $line, 'the domain validates payloads with the framework validator; invariants belong in the aggregate, payload rules at the entry');
                }
            }
        }

        foreach ($this->files('Application/Contract/Http') as $file) {
            if (!\str_ends_with(\basename($file), 'Request.php')) {
                continue;
            }

            $contents = (string) \file_get_contents($file);

            if (!\str_contains($contents, '#[Assert\\') && !\str_contains($contents, '#[\\Symfony\\Component\\Validator\\Constraints\\')) {
                $violations[] = new Violation('rule2', $this->shortPath($file), 1, 'a request DTO without a single constraint is a payload nobody validated');
            }
        }

        return $violations;
    }

    /** @return list<Violation> */
    private function everyCrossingHasAMapper(): array
    {
        $mappers = '';

        foreach ($this->files('Application/Mapper') as $file) {
            $mappers .= (string) \file_get_contents($file);
        }

        $violations = [];

        foreach ($this->files('Application/Contract') as $file) {
            $class = $this->className($file);

            if (!$this->mentions($mappers, $class)) {
                $violations[] = new Violation('rule3', $this->shortPath($file), 1, \sprintf('%s is a boundary type that no mapper mentions', $class));
            }
        }

        return $violations;
    }

    /**
     * The fully qualified name, not the short one. Two versions of this API both call their request
     * DTO `CreateInvoiceRequest`, so a short-name search lets a missing mapper for V2 pass because
     * V1 is mapped — and a substring search alone would let `...\Http\Problem` be vouched for by
     * `...\Http\ProblemMapper`, so the name also has to end where it ends.
     */
    private function mentions(string $haystack, string $needle): bool
    {
        for ($position = \strpos($haystack, $needle); false !== $position; $position = \strpos($haystack, $needle, $position + 1)) {
            $next = $haystack[$position + \strlen($needle)] ?? '';

            if ('' === $next || (!\ctype_alnum($next) && '_' !== $next && '\\' !== $next)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Violation> */
    private function noDomainTypesOnTheWire(): array
    {
        $violations = [];
        $markers = ['\\'.$this->namespaceRoot.'\\Domain\\' => 'a domain type'];

        // "The wire" means the types that leave this process: HTTP payloads and messages. The
        // command is deliberately *not* covered — it is an internal crossing between the API layer
        // and the application, and it is supposed to carry validated domain values (Money, a date)
        // rather than a second vocabulary for the same facts.
        foreach (['Application/Contract/Http', 'Application/Contract/Message'] as $wire) {
            foreach ($this->files($wire) as $file) {
                $path = $this->shortPath($file);

                foreach ($this->imports($file) as [$namespace, , $line]) {
                    if (\str_starts_with($namespace, $this->namespaceRoot.'\\Domain')) {
                        $violations[] = new Violation('rule4', $path, $line, 'a type that leaves the process imports a domain type');
                    }
                }

                // The same dependency with the `use` statement left out, which is the forbidden
                // response typed out in full instead of imported.
                foreach ($this->markersInTheBody($file, $markers) as [$marker, $line]) {
                    $violations[] = new Violation('rule4', $path, $line, 'a type that leaves the process names a domain type in the body');
                }
            }
        }

        foreach ($this->files('Infrastructure/Http') as $file) {
            $contents = (string) \file_get_contents($file);
            $imports = $this->imports($file);

            // Modifiers are captured rather than assumed: `public static function b(): Invoice` is a
            // public action too, and a `private` helper returning the aggregate is not a violation
            // at all, because the aggregate stays inside the process. `[^{]*?` keeps the match
            // inside the declaration — without it the search runs on into the body and can take a
            // return type from the next method that happens to have one.
            if (0 === \preg_match_all('/\b((?:\w+\s+)*?)function\s+\w+\([^{]*?\)\s*:\s*([?\\\\\w|]+)/', $contents, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[2] as $index => [$returnType, $offset]) {
                $modifiers = $matches[1][$index][0];

                if (\str_contains($modifiers, 'private') || \str_contains($modifiers, 'protected')) {
                    continue;
                }

                foreach (\explode('|', $returnType) as $single) {
                    // The leading backslash comes off too: `\App\Domain\...` written in full is the
                    // same type as the imported one, and it has no import to be resolved through.
                    $resolved = $this->resolve(\ltrim($single, '?\\'), $imports);

                    if (\str_starts_with($resolved, $this->namespaceRoot.'\\Domain')) {
                        $violations[] = new Violation('rule4', $this->shortPath($file), $this->lineAt($contents, $offset), \sprintf('a controller action returns the domain type %s', $resolved));
                    }
                }
            }
        }

        return $violations;
    }

    /** @return list<Violation> */
    private function versionedContracts(): array
    {
        $violations = [];

        foreach ($this->files('Application/Contract/Message') as $file) {
            if (!\str_contains((string) \file_get_contents($file), 'const int VERSION')) {
                $violations[] = new Violation('rule5', $this->shortPath($file), 1, 'a cross-process contract without a version cannot be changed safely');
            }
        }

        return $violations;
    }

    /**
     * A return type is written the way the file writes it — `Response`, `Invoice`, or an alias the
     * file chose — so it has to be resolved through the file's own imports before anything can be
     * said about it.
     *
     * @param list<array{string, string|null, int}> $imports
     */
    private function resolve(string $type, array $imports): string
    {
        foreach ($imports as [$namespace, $alias]) {
            $shortName = $alias ?? \basename(\str_replace('\\', '/', $namespace));

            if ($shortName === $type) {
                return $namespace;
            }
        }

        return $type;
    }

    /** @return list<string> */
    private function files(string $relativeDirectory): array
    {
        $directory = $this->sourceRoot.'/'.$relativeDirectory;

        if (!\is_dir($directory)) {
            return [];
        }

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && \str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Every `use` statement in the file, expanded to one entry per imported name, with the alias
     * the file gave it and the line it starts on. `use function` and `use const` are not class
     * imports and are ignored.
     *
     * @return list<array{string, string|null, int}>
     */
    private function imports(string $file): array
    {
        $contents = (string) \file_get_contents($file);

        if (0 === \preg_match_all('/^use\s+([^;]+);/m', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $imports = [];

        foreach ($matches[1] as [$clause, $offset]) {
            $clause = \trim($clause);
            $line = $this->lineAt($contents, $offset);

            if (\str_starts_with($clause, 'function ') || \str_starts_with($clause, 'const ') || \str_contains($clause, '(')) {
                continue;
            }

            if (\str_contains($clause, '{')) {
                [$prefix, $names] = \explode('{', $clause, 2);
                $prefix = \rtrim(\trim($prefix), '\\');

                foreach (\explode(',', \rtrim($names, '}')) as $name) {
                    $name = \trim($name);

                    if ('' !== $name) {
                        $imports[] = [...$this->nameAndAlias($prefix.'\\'.$name), $line];
                    }
                }

                continue;
            }

            $imports[] = [...$this->nameAndAlias($clause), $line];
        }

        return $imports;
    }

    /** @return array{string, string|null} */
    private function nameAndAlias(string $clause): array
    {
        if (1 === \preg_match('/^(.+?)\s+as\s+(\w+)$/i', \trim($clause), $matches)) {
            return [\ltrim($matches[1], '\\'), $matches[2]];
        }

        return [\ltrim(\trim($clause), '\\'), null];
    }

    private function className(string $file): string
    {
        $contents = (string) \file_get_contents($file);

        if (1 !== \preg_match('/^namespace\s+([^;]+);/m', $contents, $matches)) {
            return \basename($file, '.php');
        }

        return \trim($matches[1]).'\\'.\basename($file, '.php');
    }

    private function shortPath(string $file): string
    {
        return \str_replace($this->sourceRoot.'/', '', $file);
    }

    private function lineAt(string $contents, int $offset): int
    {
        return \substr_count(\substr($contents, 0, $offset), "\n") + 1;
    }
}

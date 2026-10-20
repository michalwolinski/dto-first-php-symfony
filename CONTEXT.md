# CONTEXT — DTO-first as a doctrine

Technical decisions behind this repository. The article is the argument; this file is the reasoning
that did not fit there, kept so the next reader does not have to reconstruct it.

## What this repository is for

It exists to answer one question: **what makes a doctrine a doctrine?** The answer offered here is
enforcement — a rule that nothing checks is a preference, and preferences lose to deadlines. So every
rule has a check, every check has a fixture proving it catches something, and the checks run in the
same command as the tests.

## Decisions

### The gate reads text, not the AST

`DoctrineGate` uses file contents and regular expressions. That is a deliberate trade: an AST-based
gate (nikic/php-parser is already in the tree, via PHPUnit) would see more and cost more to read, and
this file is meant to be auditable in one sitting. What it cannot see is semantics — it cannot tell a
well-named field from a badly chosen one — and the README says so rather than implying completeness.

The one place where text was not enough is rule 4's controller check: a return type is written the way
the file writes it (`Invoice`), so the gate resolves short names through the file's own imports before
deciding anything.

### The command is not "the wire"

Rule 4 forbids domain types in types that leave the process — HTTP payloads and messages. The command
is deliberately exempt: it stays inside, and its job is to carry validated domain values (`Money`, a
date) rather than a second vocabulary for the same facts. Without that exemption, every layer would
need its own Money, which is how a codebase ends up with four ways to say 12.50.

### DTOs are values, not services

`config/services.yaml` excludes `src/Application/Contract/` from the container. They were briefly
auto-registered, and the container immediately tried to autowire an `int` — a good illustration of the
mistake. A DTO has no lifecycle, no dependencies and nothing to configure; registering one is how
`new` grows a factory and a factory grows an interface.

### Responses are assembled by mappers, not by factories on the DTO

An `InvoiceResponse::fromInvoice()` static factory looks tidy and puts the knowledge of both shapes in
the DTO, where rule 3 cannot see it. The mapper is a named thing with a test and one direction.

### Versioning lives on the message, never on the domain event

The domain event has no version because it never leaves the process; the message has one because a
consumer needs to know which shape it received. `MessageContractTest` pins both the round trip and the
fact that a newer payload is *detectable* rather than merely parseable.

### Storage is in memory, and the row mapper is still real

The point of the persistence crossing is the direction of the translation, not the database. Rows are
arrays, they are translated at the edge, and `InMemoryInvoiceRepository::storedRows()` exists so a test
can prove no aggregate leaked into storage.

### Pint skips `config/` on purpose

The only PHP in `config/` is `config/reference.php`, which Symfony generates when it builds the
container. It is git-ignored and excluded from linting, and directory-level exclusion is also the only
kind Pint honours — a file-level entry in `exclude` is ignored, which cost a debugging round here. The
YAML in `config/packages/` is not PHP, so excluding the directory hides nothing real.

### One violation cannot live in the fixtures directory at all

`tests/Fixtures/Violations/` holds one deliberate breakage per shape the gate claims to understand, with
one exception: an import with a leading backslash (`use \Symfony\...;`) is written by the test that needs
it, in a temporary tree, rather than kept as a file. `no_leading_import_slash` is part of the style
preset, so a `pint` run rewrites that fixture into compliance and leaves the rule looking proven by a
file that no longer breaks it. That is not hypothetical — the fixture was written, silently normalised,
and only the expected-violations assertion noticed.

Excluding the directory from Pint would be the tidier fix, and it does not work here: Pint's `exclude`
list is inert in this version, pointing it at `src` does not change the file count either. Worth carrying
to the next repository: **a formatter will happily repair the thing your fixture exists to break.**

### Test seams, and why each one exists

- `KERNEL_CLASS` in `phpunit.dist.xml`: `WebTestCase` and `KernelTestCase` need to know which kernel to
  boot. The Flex recipe adds this; this repository writes its config by hand, so it is written too.
- `$client->disableReboot()` in the boundary tests: the client reboots the kernel between requests, and
  the demo's store lives in that kernel. A database would make this unnecessary; the comment in the test
  says so.
- `MockClock` for the payment-term test: "14 days from now" is a race with the second hand, not an
  assertion.

## The measured price of the doctrine

Two benchmarks, both with runtime-built inputs (`benchmarks/mapping-tax.php`,
`benchmarks/validation-cost.php`). Figures are medians of repeated runs on one machine: the ratios are
the stable part, not the absolutes.

- the handwritten mapper costs ~470 ns/op against ~12 ns/op for reading an array — the doctrine's
  baseline price, and ~1.6% of the validated request;
- the serializer costs ~5 300 ns/op, roughly 11× the handwritten mapper;
- validation costs ~29 500 ns/op, and **`Assert\Currency` alone is ~18 800 ns/op** — more than the other
  six constraints in the two payloads combined. Each of those rows is its own `validate()` call and
  carries the validator's fixed cost; an object with no constraints at all is the floor (~1 900 ns/op),
  and subtracting it is what makes the comparison mean anything.

That last figure is the most useful thing in this repository for anyone deciding whether to copy it, and
it comes with a correction that costs nothing to apply: the constraint is expensive because of the
*locale it reads through*, not because of the currency data. A locale without a bundle of its own pays
for a failed lookup on every call, and a failed lookup is not buffered — `en_US_POSIX` ~12 000 ns/op,
`en_US` ~6 100, `en` ~540. `LocaleListener` sets the ICU default from `kernel.default_locale` only on
`kernel.request`, so the same constraint is ~19 800 ns/op outside a request and ~4 100 inside one. The
doctrine decides *where* validation happens, not what it costs; the cheaper alternative (a regex plus
your own code list, which would accept `XXX`) is named in the README, together with what it gives up.

## Deliberately out of scope

No database, no broker, no authentication, no API documentation, no framework admin UI. Each of those
would add a way for the demo to fail for reasons that have nothing to do with the boundary, and the
doctrine's claims are all testable without them.

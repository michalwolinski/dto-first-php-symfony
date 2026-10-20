# DTO-first, as a doctrine your build enforces

> Companion code for [DTO-First: The Boundary Decides What the Domain Sees](https://wolinski.com/blog/dto-first-php-symfony.html)
> by [Michał Woliński](https://wolinski.com).

DTO-first is usually written down as advice, which is why it decays: advice loses to a deadline. This
repository treats it as a doctrine instead — five rules, each one checked by a test that fails the
build, with a fixture per rule proving the check actually catches a violation.

The demo is small on purpose: an invoice, two versions of the same HTTP call, one cross-process
event, and one in-memory store. Nothing here needs a database or a broker, because none of the rules
are about infrastructure.

## Versions

| Component | Version | Why this one |
| --- | --- | --- |
| PHP | **8.6.0RC3** (pinned image) | the newest tag that exists, and the version everything here was measured on. The code itself needs 8.3 for typed class constants and nothing newer — `composer.json` asks for 8.6 because the image is 8.6 |
| `symfony/framework-bundle` | `8.2.x-dev` | newest branch; `composer.lock` pins the commit |
| `symfony/{serializer,validator,messenger,clock,routing,uid,intl}` | `8.2.x-dev` | the boundary stack: hydrate, validate, publish |
| `phpunit/phpunit` | 13.4.2 | newest release; the suite fails on deprecations, notices and warnings |
| `laravel/pint` | 1.32.1 | formatting, `psr12` + the conventions this repository holds to |
| `ext-intl` | image | not for the currency *list* — that ships inside `symfony/intl`. It is for `\Locale`, which `ResourceBundle` calls to resolve the bundle, and which is where the constraint's cost actually comes from (measured below) |
| `ext-mbstring` | image | required by PHPUnit, not by the application |

## Quick start

```bash
docker compose build

docker compose run --rm cli composer test       # 53 tests, 116 assertions
docker compose run --rm cli composer doctrine   # the five rules, checked
docker compose run --rm cli composer bench      # the two benchmarks

# drive the boundary by hand
docker compose run --rm --service-ports server
curl -s localhost:8070/v1/invoices -H 'Content-Type: application/json' -d '{
  "customerId": "9f1c4a7e-1b2d-4f3a-8c5e-6d7b8a9c0d1e",
  "amountMinor": 12500,
  "currency": "EUR"
}'
```

## The five rules

Each rule below shows the shape it forbids, the shape it asks for, and the check that decides. The
checks live in `src/Architecture/DoctrineGate.php`; the fixtures that prove they bite live in
`tests/Fixtures/Violations/`.

### 1. Nothing from outside gets a type inside the domain

```php
// forbidden — the domain now depends on the HTTP kernel
final readonly class Invoice { public function __construct(public Request $request) {} }

// allowed — the domain speaks its own language
final readonly class Invoice { /* Money, InvoiceId, DateTimeImmutable */ }
```

Enforced by: no file under `src/Domain` may import `Symfony\`, `Doctrine\`, `Psr\`, or another
application layer, and none may name one of those in a signature either — a fully qualified type with no
`use` statement is the same dependency. The import reader expands what PHP allows here: a leading
backslash, a grouped import written across several lines, an alias. Built-in PHP types are allowed —
they are the language, not a framework.

### 2. Validation happens once, at the entry, on the type

```php
// forbidden — the aggregate validating a payload
final readonly class Invoice { #[Assert\NotBlank] public string $customerId; }

// allowed — the payload is validated where it arrives
final readonly class CreateInvoiceRequest {
    public function __construct(#[Assert\NotBlank] #[Assert\Uuid] public string $customerId = '') {}
}
```

Enforced by: no Validator import under `src/Domain`, and every `*Request` DTO must carry at least one
constraint. The domain still refuses illegal states — that is an invariant, not a payload rule, and it
throws its own exception.

### 3. Every crossing has a named mapper

Seven boundary types, seven mappers, and every mapper is exercised: four of them by a unit test each in
`MapperTest`, and the two problem mappers by the boundary tests that produce those problems. A type that
no mapper mentions cannot be reachable from any direction, which the gate reports as an orphan.

The comparison is on **fully qualified** names, because the short ones are not unique: V1 and V2 of this
API both call their request DTO `CreateInvoiceRequest`, so a short-name search is satisfied by V1's mapper
and deleting V2's leaves the rule passing. The trailing character matters too, or `…\Http\Problem` would
be vouched for by `…\Http\ProblemMapper`.

Enforced by: every class under `src/Application/Contract` must be named by something under
`src/Application/Mapper`. It is a search over the mappers' source, not a call graph: a mapper that only
mentioned a class in a docblock would satisfy it. The rule makes the translation *named*; it does not
prove the name is called.

### 4. The wire never carries a domain type

```php
// forbidden — the response IS the aggregate, so every refactor is a breaking change
final readonly class InvoiceResponse { public function __construct(public Invoice $invoice) {} }

// allowed — six scalars, named deliberately, built by InvoiceResponseMapper
final readonly class InvoiceResponse { /* id, customerId, amountMinor, currency, status, dueDate */ }
```

Enforced by: no contract type that leaves the process (`Contract/Http`, `Contract/Message`) may import
`App\Domain`, and no public controller action may declare a domain return type — including `?Invoice`, a
union, or a domain type the file renamed with `as`. A parameter attribute with arguments puts parentheses
inside the parameter list, so the search is bounded to the declaration rather than assuming the first `)`
closes it.

What it cannot see is the value. `return $this->json($invoice);` hands the aggregate to the client without
naming a domain type in any signature, and no text search will find it. The return-type check catches the
declared shape, not the object that leaves.

The command is deliberately outside this rule: it does not leave the process, and its job is to carry
validated domain values rather than a second vocabulary for the same facts.

### 5. Every cross-process contract carries a version

```php
// forbidden — a consumer cannot tell which shape it received
final readonly class InvoiceIssuedMessage { public function __construct(public string $invoiceId) {} }

// allowed
final readonly class InvoiceIssuedMessage {
    public const int VERSION = 1;
    public function __construct(public int $version, /* … */) {}
}
```

Enforced by: every class under `Contract/Message` must declare `const int VERSION`. Versioning lives on
the DTO; the domain event has none, because it never leaves.

What the version buys is narrower than it sounds, and two tests in `MessageContractTest` draw the line. A
payload that **adds** a field keeps the old shape readable and its version visible. A payload that
**renames** one never gets that far: the serializer fails on the missing constructor argument before any
consumer can compare the version with what it knows, so against a breaking change the version is
decoration. Reading it out of the envelope before deserialising is the fix, and that is a consumer's
decision about its own compatibility policy — this demo has no consumer to make it, so the limit is
pinned by a test (`aPayloadThatBreaksTheShapeNeverReachesTheVersionItAnnounces`) instead of being left as
an assumption.

## The gate

`composer doctrine` prints each rule and its verdict:

```
  rule1  Nothing from outside gets a type inside the domain.        pass
  rule2  Validation happens once, at the entry, on the type.        pass
  rule3  Every crossing has a named mapper.                         pass
  rule4  The wire never carries a domain type.                      pass
  rule5  Every cross-process contract carries a version.            pass
```

A gate that has never failed is a gate nobody should trust. Neither is "it has failed" enough on its own: a
rule with more than one branch is only half proven by a fixture tripping one of them, so the suite pins the
exact files and lines each rule has to report. Deleting three fixtures at once, or disabling the controller
half of rule 4, used to leave everything green; it no longer does.

One breakage per shape lives in `tests/Fixtures/Violations/`: `Domain/LeaksTheFramework`,
`Domain/NamesAFullyQualifiedType`, `Domain/UsesAGroupImport`, `Domain/ValidatesPayloads`,
`…/V9/UnvalidatedRequest`, `…/V9/OrphanRequest`, `…/Http/LeakyResponse`, `…/Message/UnversionedMessage`,
`Infrastructure/Http/LeakyController` (twice — one of them with arguments inside a parameter attribute) and
`Infrastructure/Http/NullableAliasedController`, plus `Application/Mapper/FixtureMapper`, which is there so
that exactly one contract is an orphan.

The leading-backslash import is the one shape that cannot live there as a file: `no_leading_import_slash`
is part of the style preset, so `pint` repairs the fixture and leaves the rule looking proven by a file
that no longer breaks it. That test writes its shape into a temporary tree instead, where nothing formats
it.

## What the tests pin

| Claim | Evidence |
| --- | --- |
| Two payload versions produce the same internal call | `MapperTest::v1AndV2ProduceTheSameCommandShape`, `InvoiceBoundaryTest::version2CreatesTheSameInvoiceFromADecimalString` |
| The response carries exactly the contract fields | `InvoiceBoundaryTest::theWireCarriesExactlyTheContractFields` — asserted on the HTTP response, because the controller serialises through Symfony, which also picks up getter methods |
| A deferred field is filled by a domain policy, not by the boundary | `InvoiceTest::thePaymentTermIsADomainPolicyWhenTheCallerHasNoOpinion`, `InvoiceBoundaryTest::theDomainPolicyFillsInWhatVersion1LeftOut` |
| Amounts survive decimal input without float arithmetic | `InvoiceTest::amountsAreParsedWithoutFloatArithmetic` (`0.29` and a value past 2^53 are in the provider) |
| An amount too large to become minor units is refused by the contract | `InvoiceTest::anAmountThatIsNotMoneyIsRefused`, `InvoiceBoundaryTest::anAmountTooLargeForMinorUnitsIsRefusedByTheContract` |
| A rejected payload leaves through the validation contract | `InvoiceBoundaryTest::aRejectedPayloadLeavesThroughTheValidationContract` |
| A domain refusal leaves through a problem contract, with the right status | `InvoiceBoundaryTest::payingTwiceIsARefusalWithAContract`, `::anUnknownInvoiceIsANotFoundProblem` |
| An id that is not a UUID never reaches the domain | `InvoiceBoundaryTest::anIdentifierThatIsNotAUuidIsRefusedBeforeTheDomainSeesIt` |
| The aggregate never reaches storage or the wire | `MapperTest::aRowRoundTripsThroughTheMapperWithoutTheAggregateLeaking`, `InvoiceBoundaryTest::anInvoiceCanBeReadBackInTheContractShape` |
| Events are released once and announced as versioned messages | `InvoiceTest::issuingAnInvoiceRecordsTheEventAndIssuesIt`, `MessageContractTest::theMessageSurvivesTheWireWithItsVersion` |
| An additive payload is readable, and the consumer can see it is newer | `MessageContractTest::aConsumerCanSeeThatAnAdditivePayloadIsNewerThanItKnows` |
| A payload that breaks the shape never reaches the version it announces | `MessageContractTest::aPayloadThatBreaksTheShapeNeverReachesTheVersionItAnnounces` |
| The doctrine is obeyed by the code, and the gate catches exactly the right violations | `DoctrineGateTest::theSourceObeysEveryRule`, `::theGateCatchesExactlyTheseViolations`, `::theGateUnderstandsTheLeadingBackslashOnAnImport` |

## Benchmarks

Both figures are ns/op for one incoming payload, on PHP 8.6.0RC3 in the pinned image, with inputs built
at runtime — a benchmark with literal arguments measures the optimiser, not the code. They are medians of
repeated runs on one machine, and the spread is wide enough that the ratios are the finding and the
absolutes are not.

`composer bench` — the mapping tax:

| Shape | ns/op | Against the doctrine |
| --- | --- | --- |
| array access in place (no DTO) | ~12 | the thing the doctrine removes: no type, no validation, no version |
| handwritten mapper | ~470 | the doctrine's default cost — ~1.6% of the validated request below |
| serializer denormalize | ~5 300 | **~11×** the handwritten mapper |
| serializer serialize (output) | ~6 900 | **~27×** writing the same fields by hand with `json_encode` (~255 ns) |

`php benchmarks/validation-cost.php` — where that time actually goes:

| What is validated | ns/op |
| --- | --- |
| an object with no constraints at all | ~1 900 |
| NotBlank | ~3 400 |
| NotNull | ~3 400 |
| Regex | ~3 600 |
| Positive | ~3 500 |
| Date | ~3 800 |
| Uuid | ~5 300 |
| `Currency` | ~18 800 |
| the whole V1 request DTO | ~29 500 |

The first row is the floor: every row is its own `validate()` call, so every row carries the validator's
fixed cost. Subtracting it turns the rows into the price of *adding* one constraint — around 1.5 to 3.4
microseconds for each of them, and seventeen for `Currency`, which is more than all the others together
and 61% of the DTO's validation bill.

That cost is not the currency data, and it is not inherent either. The constraint asks `symfony/intl`
whether the code exists, and that read is resolved through the **default locale's fallback chain**. A
locale without a bundle of its own makes the reader pay for a failed lookup first, and a failed lookup is
not buffered, so the miss is paid again on every call. The image ships `en_US_POSIX`, which has no bundle.
The last section of the benchmark prints the contrast:

| Read | ns/op |
| --- | --- |
| `Currencies::exists('EUR')`, default locale `en_US_POSIX` | ~12 000 |
| `Currencies::exists('EUR')`, default locale `en_US` (also bundle-less) | ~6 100 |
| `Currencies::exists('EUR')`, default locale `en` | ~540 |

Each missing locale on that chain costs ~6 µs and is never cached. The same constraint therefore costs
very different amounts depending on the process it runs in, because `LocaleListener` sets the ICU default
from `kernel.default_locale` on `kernel.request`, and a console command or a Messenger worker never sees a
request:

| Where the validator runs | `validate(Currency)` | `validate(the whole V1 DTO)` |
| --- | --- | --- |
| outside a request (process default `en_US_POSIX`) | ~19 800 | ~29 200 |
| inside a request (`Locale::getDefault()` is `en`) | ~4 100 | ~11 400 |

So the price of the doctrine is the validation, and the largest single term in it is process state rather
than design: give the process a locale that has a bundle (`intl.default_locale=en` in the image, or
`\Locale::setDefault()` at boot — both change the ICU default for everything else in that process too), or
drop `Assert\Currency` for a regex plus your own list of codes: cheaper, and willing to accept `XXX`. The
rule decides *where* validation happens, not what it costs, and the number belongs in the decision.

## Known limits

- **The gate is a smoke detector, not a proof.** It reads files as text, so it sees imports, return
  types and attribute names — and nothing semantic. It cannot see a value: `return $this->json($invoice)`
  hands the aggregate to the client without naming a domain type anywhere. A class can also obey all five
  rules and still leak its implementation through a badly chosen field. Point a proper analyser (deptrac,
  PHPArkitect) at it if you need more; this gate is what makes the rules non-optional, not what makes them
  sufficient.
- **A fixture is only a proof while it still breaks something.** `tests/Fixtures/Violations/` holds shapes
  that a formatter, a refactor or a careless "fix" can quietly repair — which is exactly what happened to
  the leading-backslash import before the expected-violations assertion noticed. The shape that cannot
  survive as a file is built by its test instead.
- **The version protects additive changes, and only those.** Against a payload that renames a field the
  serializer fails before a consumer can read the version, so the detection rule 5 promises does not apply.
  An envelope read before deserialisation is the fix; there is no consumer in this demo to make that
  decision, and the limit is pinned by a test. See rule 5 above.
- **The error contracts cover the errors the boundary raises.** A malformed body, or a path that matches no
  route, is refused by the kernel before the boundary is reached and answered by Symfony's own error path.
  The route requirement on `{id}` is what keeps a malformed identifier out of that gap.
- **The store is in memory.** It makes the demo runnable with one command and the tests independent of
  a database, and it is why the boundary tests disable the client's kernel reboot — a reboot would
  empty the store, which is the same problem a real application solves with a database.
- **Two extensions for one application.** `ext-intl` exists for one constraint, and only for its locale
  resolution: `ResourceBundle::readEntry()` calls `\Locale::getDefault()`, so without the extension the
  constraint dies with `Class "Locale" not found`. `ext-mbstring` exists for the test runner. The boundary
  itself needs neither.
- **The doctrine does not give you a domain model.** Mappers and DTOs keep the boundary honest; they say
  nothing about whether the aggregate inside is worth anything. A project can be perfectly DTO-first and
  still be an anaemic model in a clean coat — which is the counter-argument the article ends on.

## Author

[Michał Woliński](https://wolinski.com) — the companion article is
[DTO-First: The Boundary Decides What the Domain Sees](https://wolinski.com/blog/dto-first-php-symfony.html).

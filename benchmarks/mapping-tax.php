<?php

declare(strict_types=1);

/**
 * The price of the doctrine, measured.
 *
 * Every figure is ns/op for the work of turning one incoming payload into one command, built from
 * inputs that only exist at runtime — a benchmark with literal arguments measures the optimizer,
 * not the code (that lesson cost a rewrite in the PHP 8.6 article, and it applies here too).
 *
 *   docker run --rm -v "$PWD":/app -w /app dtofirst-deps php benchmarks/mapping-tax.php
 */
use App\Application\Contract\Command\CreateInvoiceCommand;
use App\Application\Contract\Http\V1\CreateInvoiceRequest;
use App\Application\Mapper\Http\V1CreateInvoiceRequestMapper;
use App\Domain\Invoice\Money;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;

require_once dirname(__DIR__).'/vendor/autoload.php';

$iterations = (int) ($argv[1] ?? 200_000);
$sink = 0;

// Runtime inputs: same values every run, opaque to the optimizer.
$customerId = substr(str_repeat('9f1c4a7e-1b2d-4f3a-8c5e-6d7b8a9c0d1e', 2), 0, 36);
$payload = [
    'customerId' => $customerId,
    'amountMinor' => (int) substr('12500', 0, 5),
    'currency' => substr('EUR', 0, 3),
];

$serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
// The metadata cache matters more than anything else on this page: without it the validator
// rebuilds the constraints of every class on every call, and the figure stops describing the
// application (which wires cache.validator) and starts describing a misconfiguration.
$validator = Validation::createValidatorBuilder()
    ->enableAttributeMapping()
    ->setMappingCache(new ArrayAdapter())
    ->getValidator();
$mapper = new V1CreateInvoiceRequestMapper();

function bench(string $label, int $iterations, callable $body): void
{
    $body(1_000);

    $started = hrtime(true);
    $body($iterations);
    $elapsed = (hrtime(true) - $started) / $iterations;

    printf("  %-52s %9.2f ns/op\n", $label, $elapsed);
}

printf("PHP %s | %s iterations | the work of one incoming payload\n\n", PHP_VERSION, number_format($iterations));

echo "  No DTO: read the fields out of the array where you need them\n";
bench('array access, in place', $iterations, static function (int $n) use ($payload, &$sink): void {
    for ($i = 0; $i < $n; ++$i) {
        $sink += $payload['amountMinor'];
    }
});

echo "\n  The doctrine: a typed request, then a named mapper\n";
bench('handwritten mapper only', $iterations, static function (int $n) use ($payload, $mapper, &$sink): void {
    for ($i = 0; $i < $n; ++$i) {
        $command = $mapper->map(new CreateInvoiceRequest(
            $payload['customerId'],
            $payload['amountMinor'],
            $payload['currency'],
        ));
        $sink += $command->amount->minorUnits();
    }
});

bench('handwritten mapper + validation', $iterations, static function (int $n) use ($payload, $validator, $mapper, &$sink): void {
    for ($i = 0; $i < $n; ++$i) {
        $request = new CreateInvoiceRequest(
            $payload['customerId'],
            $payload['amountMinor'],
            $payload['currency'],
        );
        $validator->validate($request);
        $command = $mapper->map($request);
        $sink += $command->amount->minorUnits();
    }
});

echo "\n  Letting the serializer do the hydrating\n";
bench('serializer denormalize only', $iterations, static function (int $n) use ($payload, $serializer, &$sink): void {
    for ($i = 0; $i < $n; ++$i) {
        /** @var CreateInvoiceRequest $request */
        $request = $serializer->denormalize($payload, CreateInvoiceRequest::class);
        $sink += $request->amountMinor;
    }
});

bench('serializer denormalize + validation', $iterations, static function (int $n) use ($payload, $serializer, $validator, &$sink): void {
    for ($i = 0; $i < $n; ++$i) {
        /** @var CreateInvoiceRequest $request */
        $request = $serializer->denormalize($payload, CreateInvoiceRequest::class);
        $validator->validate($request);
        $sink += $request->amountMinor;
    }
});

echo "\n  The output side, which the doctrine pays for as well\n";
$command = new CreateInvoiceCommand($customerId, Money::of(12500, 'EUR'), null);
bench('round trip through json_encode (no DTO)', $iterations, static function (int $n) use ($command, &$sink): void {
    for ($i = 0; $i < $n; ++$i) {
        $sink += strlen((string) json_encode([
            'customerId' => $command->customerId,
            'amountMinor' => $command->amount->minorUnits(),
            'currency' => $command->amount->currency(),
        ]));
    }
});

$response = new App\Application\Contract\Http\InvoiceResponse($customerId, $customerId, 12500, 'EUR', 'issued', '2026-10-29T09:00:00+00:00');
bench('serializer serialize(InvoiceResponse)', $iterations, static function (int $n) use ($serializer, $response, &$sink): void {
    for ($i = 0; $i < $n; ++$i) {
        $sink += strlen($serializer->serialize($response, 'json'));
    }
});

echo "\n  guard: ", $sink > 0 ? "the loops ran\n" : "nothing ran\n";

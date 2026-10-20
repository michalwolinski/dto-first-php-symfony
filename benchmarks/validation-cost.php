<?php

declare(strict_types=1);

/**
 * Where the money goes, per constraint, and why the worst of them costs what it costs.
 *
 * The mapping-tax benchmark shows validation dominating the request, which is useless information
 * until you know which constraint is responsible. One of them is several times more expensive than
 * any other, and the reason is not the currency data: the constraint reads through the default
 * locale's fallback chain, and a locale without a bundle of its own pays for a failed read on every
 * call. The last section measures that directly, because "Assert\Currency is expensive" is not
 * actionable until you know which line decides it.
 *
 * The rows are the constraints the two payloads actually carry, one at a time, plus a floor: an
 * object with no constraints at all. Every row includes that floor, which is what calling the
 * validator costs before any constraint runs, so the rows are best read as the price of adding one.
 *
 *   docker run --rm -v "$PWD":/app -w /app dtofirst-deps php benchmarks/validation-cost.php
 */
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Intl\Intl;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

require_once dirname(__DIR__).'/vendor/autoload.php';

$validator = Validation::createValidatorBuilder()
    ->enableAttributeMapping()
    ->setMappingCache(new ArrayAdapter())
    ->getValidator();

function cost(string $label, ValidatorInterface $validator, object $subject, int $iterations = 20_000): void
{
    $validator->validate($subject);     // warm the metadata cache

    $started = hrtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        $validator->validate($subject);
    }

    printf("  %-42s %10.2f ns/op\n", $label, (hrtime(true) - $started) / $iterations);
}

$notBlank = new class () {
    #[Assert\NotBlank]
    public string $value = 'x';
};
$notNull = new class () {
    #[Assert\NotNull]
    public int $value = 12500;
};
$uuid = new class () {
    #[Assert\Uuid]
    public string $value = '9f1c4a7e-1b2d-4f3a-8c5e-6d7b8a9c0d1e';
};
$currency = new class () {
    #[Assert\Currency]
    public string $value = 'EUR';
};
$regex = new class () {
    #[Assert\Regex(pattern: '/^\d{1,15}(\.\d{1,2})?$/')]
    public string $value = '125.00';
};
$date = new class () {
    #[Assert\Date]
    public string $value = '2026-11-30';
};
$positive = new class () {
    #[Assert\Positive]
    public int $value = 12500;
};
$noConstraints = new class () {
    public string $value = 'x';
};
$whole = new class () {
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $customerId = '9f1c4a7e-1b2d-4f3a-8c5e-6d7b8a9c0d1e';

    #[Assert\NotNull]
    #[Assert\Positive]
    public int $amountMinor = 12500;

    #[Assert\NotBlank]
    #[Assert\Currency]
    public string $currency = 'EUR';
};

printf("PHP %s | same validator, same metadata cache, one constraint at a time\n\n", PHP_VERSION);

// The first row is the floor: what calling validate() costs before a single constraint runs. Every
// row below it carries that fixed cost as well, so they are the price of adding one constraint to a
// request — not the constraint's own cost. Subtracting the floor is what makes the Currency row
// comparable to the others.
cost('no constraints at all (the call itself)', $validator, $noConstraints);
cost('NotBlank', $validator, $notBlank);
cost('NotNull', $validator, $notNull);
cost('Uuid', $validator, $uuid);
cost('Regex', $validator, $regex);
cost('Positive', $validator, $positive);
cost('Date', $validator, $date);
cost('Currency (the ICU-backed one)', $validator, $currency);
cost('the whole V1 request DTO', $validator, $whole);

// The figure above is not the price of the constraint. Assert\Currency asks
// Symfony\Component\Intl\Currencies whether the code exists, and that read is resolved
// through the default locale's fallback chain. A locale without a bundle of its own pays
// for a failed read first — and a failed read is not buffered (BufferedBundleReader only
// stores what it successfully read), so the miss is paid again on the next call. The PHP
// image ships ICU's default, en_US_POSIX, which has no bundle.
echo "\nWhere the Currency figure actually goes\n\n";

$bundle = Intl::getDataDirectory().'/currencies/'.Locale::getDefault().'.php';
printf("  %-46s %s\n", 'Locale::getDefault()', Locale::getDefault());
printf("  %-46s %s\n", 'a bundle for '.Locale::getDefault().'?', is_file($bundle) ? 'yes' : 'no');

function lookup(string $label, callable $body, int $iterations = 20_000): void
{
    $body(2_000);

    $started = hrtime(true);
    $body($iterations);

    printf("  %-46s %10.2f ns/op\n", $label, (hrtime(true) - $started) / $iterations);
}

lookup('Currencies::exists("EUR"), default locale', static function (int $n): void {
    for ($i = 0; $i < $n; ++$i) {
        Currencies::exists('EUR');
    }
});
lookup('Currencies::getName("EUR", "en")', static function (int $n): void {
    for ($i = 0; $i < $n; ++$i) {
        Currencies::getName('EUR', 'en');
    }
});

Locale::setDefault('en');
echo "\n  ...and the same read once the default locale is one that has a bundle\n\n";
printf("  %-46s %s\n", 'Locale::getDefault()', Locale::getDefault());
lookup('Currencies::exists("EUR"), default locale "en"', static function (int $n): void {
    for ($i = 0; $i < $n; ++$i) {
        Currencies::exists('EUR');
    }
});

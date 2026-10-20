# PHP 8.6 is still an RC. The exact tag is pinned on purpose: the doctrine's gate has to run on
# the same interpreter every time, or "the gate is passing" means nothing.
FROM php:8.6.0RC3-cli

# Two extensions beyond the defaults, and neither is needed by the boundary itself:
#   - mbstring, because PHPUnit requires it (the tooling is less pure than the application);
#   - intl, because Assert\Currency resolves its bundle through \Locale::getDefault(), and \Locale
#     is an intl class — without the extension the constraint dies with "Class Locale not found".
#     The currency data itself ships inside symfony/intl, so the extension is load-bearing for the
#     locale rather than for the list, which is exactly where the constraint's cost comes from:
#     see the last section of benchmarks/validation-cost.php.
# The doctrine is about types and mappings, so the platform stays this thin on purpose.
#
# mbstring is built with --disable-mbregex on purpose: mbregex is deprecated in PHP 8.6 because
# Oniguruma is no longer maintained, so this image does not carry the dependency on its way out.
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip git libicu-dev \
 && docker-php-ext-configure mbstring --disable-mbregex \
 && docker-php-ext-install -j"$(nproc)" mbstring intl \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --no-scripts

COPY . .

CMD ["php", "bin/doctrine"]

<?php

declare(strict_types=1);

namespace App\Domain\Invoice\Exception;

use DomainException;

/**
 * The domain's own vocabulary for "not there". The HTTP layer decides that this means 404; the
 * domain only knows that it is a rule of its own world.
 */
final class InvoiceNotFoundException extends DomainException
{
}

<?php

declare(strict_types=1);

namespace App\Domain\Invoice\Exception;

use DomainException;

/**
 * A second domain refusal, so the boundary has more than one thing to translate — and so "every
 * error leaves through a contract" is visible rather than claimed.
 */
final class InvoiceNotPayableException extends DomainException
{
}

<?php

namespace App\Exceptions;

use RuntimeException;

class TelegramDocumentTransportException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $transportFailure)
    {
        parent::__construct($message);
    }
}

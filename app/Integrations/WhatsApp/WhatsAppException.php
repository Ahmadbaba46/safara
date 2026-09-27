<?php

namespace App\Integrations\WhatsApp;

use RuntimeException;

class WhatsAppException extends RuntimeException
{
    /** Meta error 131047: more than 24 hours since the client last wrote. */
    public function outsideWindow(): bool
    {
        return $this->getCode() === 131047 || str_contains($this->getMessage(), '131047');
    }
}

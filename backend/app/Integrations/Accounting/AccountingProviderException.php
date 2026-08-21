<?php

namespace App\Integrations\Accounting;

/**
 * Thrown when an accounting provider operation fails.
 *
 * Classification: EXTERNAL DEPENDENCY
 */
class AccountingProviderException extends \RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('No accounting provider is configured. Connect an external accounting system in Admin > Integrations.');
    }

    public static function connectionFailed(string $message): self
    {
        return new self("Accounting provider connection failed: {$message}");
    }

    public static function exportFailed(string $entity, string $message): self
    {
        return new self("Failed to export {$entity} to accounting system: {$message}");
    }
}

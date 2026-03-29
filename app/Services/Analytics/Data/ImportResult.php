<?php

declare(strict_types=1);

namespace App\Services\Analytics\Data;

/**
 * DTO: Result of a GA4 import operation.
 *
 * Immutable data object returned by GaImporter.
 */
final readonly class ImportResult
{
    /**
     * @param  array<string, mixed>  $metrics  Imported metrics summary.
     */
    public function __construct(
        public bool $imported,
        public array $metrics = [],
        public ?string $error = null,
    ) {}

    public function failed(): bool
    {
        return !$this->imported;
    }

    /**
     * Create a success result.
     *
     * @param  array<string, mixed>  $metrics
     */
    public static function success(array $metrics): self
    {
        return new self(imported: true, metrics: $metrics);
    }

    /**
     * Create a failure result.
     */
    public static function failure(string $error): self
    {
        return new self(imported: false, error: $error);
    }
}

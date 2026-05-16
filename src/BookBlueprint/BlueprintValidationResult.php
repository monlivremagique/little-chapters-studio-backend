<?php

declare(strict_types=1);

namespace App\BookBlueprint;

final class BlueprintValidationResult
{
    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     * @param list<string> $locales
     * @param list<string> $assets
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $warnings,
        public readonly int $pageCount,
        public readonly array $locales,
        public readonly array $assets,
    ) {
    }

    public function isValid(): bool
    {
        return [] === $this->errors;
    }
}

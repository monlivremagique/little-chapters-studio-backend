<?php

declare(strict_types=1);

namespace App\Integration\Replicate;

interface ReplicateTextGenerationClientInterface
{
    public function isConfigured(): bool;

    public function assertConfigured(): void;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createPrediction(string $modelReference, array $input): array;

    /** @return array<string, mixed> */
    public function getPrediction(string $predictionId): array;
}

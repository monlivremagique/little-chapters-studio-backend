<?php

declare(strict_types=1);

namespace App\Integration\Replicate;

interface ReplicatePredictionClientInterface
{
    public function isConfigured(): bool;

    public function assertConfigured(): void;

    public function getModelReference(): string;

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function createPrediction(array $input): array;

    /**
     * @return array<string, mixed>
     */
    public function getPrediction(string $predictionId): array;

    /**
     * @return array{content:string,mimeType:string}
     */
    public function downloadFile(string $url): array;
}

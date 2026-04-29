<?php

declare(strict_types=1);

namespace App\Tests\Double\Replicate;

use App\Integration\Replicate\ReplicatePredictionClientInterface;

final class FakeReplicatePredictionClient implements ReplicatePredictionClientInterface
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $predictionsById = [];

    /** @var list<list<array<string, mixed>>> */
    private array $queuedPredictionSequences = [];

    /** @var array<string, array{content:string,mimeType:string}> */
    private array $downloadsByUrl = [];

    /** @var list<array<string, mixed>> */
    private array $createInputs = [];

    private string $modelReference = 'black-forest-labs/flux-2-pro';

    public function isConfigured(): bool
    {
        return true;
    }

    public function assertConfigured(): void
    {
    }

    public function getModelReference(): string
    {
        return $this->modelReference;
    }

    /** @param array<string, mixed> $input */
    public function createPrediction(array $input): array
    {
        $this->createInputs[] = $input;
        $predictionId = sprintf('fake_prediction_%d', count($this->createInputs));

        if ([] !== $this->queuedPredictionSequences) {
            $sequence = array_shift($this->queuedPredictionSequences);
            $normalized = [];

            foreach ($sequence as $prediction) {
                $normalized[] = $prediction + ['id' => $predictionId];
            }

            $this->predictionsById[$predictionId] = $normalized;
        } else {
            $this->predictionsById[$predictionId] = [[
                'id' => $predictionId,
                'status' => 'starting',
            ]];
        }

        $initialPrediction = $this->predictionsById[$predictionId][0];
        $remainingSequence = array_slice($this->predictionsById[$predictionId], 1);
        $this->predictionsById[$predictionId] = [] !== $remainingSequence ? $remainingSequence : [$initialPrediction];

        return $initialPrediction;
    }

    public function getPrediction(string $predictionId): array
    {
        $sequence = $this->predictionsById[$predictionId] ?? null;

        if (null === $sequence || [] === $sequence) {
            throw new \RuntimeException(sprintf('Unknown fake prediction id "%s".', $predictionId));
        }

        $current = array_shift($sequence);
        $this->predictionsById[$predictionId] = [] !== $sequence ? $sequence : [$current];

        return $current;
    }

    public function downloadFile(string $url): array
    {
        if (!isset($this->downloadsByUrl[$url])) {
            throw new \RuntimeException(sprintf('Unknown fake download URL "%s".', $url));
        }

        return $this->downloadsByUrl[$url];
    }

    /**
     * @param list<array<string, mixed>> $predictionSequence
     */
    public function seedNextPredictionSequence(array $predictionSequence): string
    {
        $this->queuedPredictionSequences[] = $predictionSequence;

        return sprintf('fake_prediction_%d', count($this->predictionsById) + count($this->queuedPredictionSequences));
    }

    public function registerDownload(string $url, string $content, string $mimeType = 'image/png'): void
    {
        $this->downloadsByUrl[$url] = [
            'content' => $content,
            'mimeType' => $mimeType,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function getCreateInputs(): array
    {
        return $this->createInputs;
    }

    public function reset(): void
    {
        $this->predictionsById = [];
        $this->queuedPredictionSequences = [];
        $this->downloadsByUrl = [];
        $this->createInputs = [];
    }
}

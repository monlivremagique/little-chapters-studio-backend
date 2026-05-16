<?php

declare(strict_types=1);

namespace App\Tests\Double\Replicate;

use App\Integration\Replicate\ReplicateTextGenerationClientInterface;

final class FakeReplicateTextGenerationClient implements ReplicateTextGenerationClientInterface
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $predictionsById = [];

    /** @var list<list<array<string, mixed>>> */
    private array $queuedPredictionSequences = [];

    /** @var list<array{modelReference:string,input:array<string,mixed>}> */
    private array $createInputs = [];

    public function isConfigured(): bool
    {
        return true;
    }

    public function assertConfigured(): void
    {
    }

    public function createPrediction(string $modelReference, array $input): array
    {
        $this->createInputs[] = [
            'modelReference' => $modelReference,
            'input' => $input,
        ];
        $predictionId = sprintf('fake_text_prediction_%d', count($this->createInputs));

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
            throw new \RuntimeException(sprintf('Unknown fake text prediction id "%s".', $predictionId));
        }

        $current = array_shift($sequence);
        $this->predictionsById[$predictionId] = [] !== $sequence ? $sequence : [$current];

        return $current;
    }

    /** @param list<array<string, mixed>> $predictionSequence */
    public function seedNextPredictionSequence(array $predictionSequence): string
    {
        $this->queuedPredictionSequences[] = $predictionSequence;

        return sprintf('fake_text_prediction_%d', count($this->predictionsById) + count($this->queuedPredictionSequences));
    }

    /** @return list<array{modelReference:string,input:array<string,mixed>}> */
    public function getCreateInputs(): array
    {
        return $this->createInputs;
    }

    public function reset(): void
    {
        $this->predictionsById = [];
        $this->queuedPredictionSequences = [];
        $this->createInputs = [];
    }
}

<?php

declare(strict_types=1);

namespace App\Message;

final class PipelineStepMessage
{
    public const STEP_DEFINITIONS = [
        1  => ['name' => 'validate-brief', 'label' => 'Validation du brief'],
        2  => ['name' => 'generate-master', 'label' => 'Génération du master (Claude)'],
        3  => ['name' => 'qa-pass-one', 'label' => 'QA corrective pass 1 (GPT)'],
        4  => ['name' => 'qa-pass-two', 'label' => 'QA corrective pass 2 (GPT)'],
        5  => ['name' => 'qa-gate', 'label' => 'QA gate (informatif)'],
        6  => ['name' => 'validate-blueprint', 'label' => 'Validation du blueprint'],
        7  => ['name' => 'generate-runtimes', 'label' => 'Génération des runtimes FR/NL/EN'],
        8  => ['name' => 'validate-runtimes', 'label' => 'Validation des runtimes'],
        9  => ['name' => 'generate-images', 'label' => 'Génération des images (FLUX)'],
        10 => ['name' => 'check-assets', 'label' => 'Vérification des assets'],
        11 => ['name' => 'sync-catalog', 'label' => 'Sync catalogue Sylius'],
        12 => ['name' => 'verify-catalog', 'label' => 'Vérification catalogue'],
    ];

    public const TOTAL_STEPS = 12;

    public function __construct(
        private readonly int $projectId,
        private readonly int $step,
    ) {
    }

    public function getProjectId(): int { return $this->projectId; }
    public function getStep(): int { return $this->step; }

    public function getStepName(): string { return self::STEP_DEFINITIONS[$this->step]['name'] ?? 'unknown'; }
    public function getStepLabel(): string { return self::STEP_DEFINITIONS[$this->step]['label'] ?? 'Étape inconnue'; }
    public function getProgressPct(): int { return (int) round(($this->step - 1) / self::TOTAL_STEPS * 100); }
    public function isFinal(): bool { return $this->step >= self::TOTAL_STEPS; }
    public function getNextStep(): ?self { return $this->step < self::TOTAL_STEPS ? new self($this->projectId, $this->step + 1) : null; }
}

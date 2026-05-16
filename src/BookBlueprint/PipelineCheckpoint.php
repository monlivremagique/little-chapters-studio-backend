<?php

declare(strict_types=1);

namespace App\BookBlueprint;

final class PipelineCheckpoint
{
    private string $filePath;
    /** @var array<string, array{completed_at:string,duration_seconds:float}> */
    private array $state = [];

    public function __construct(string $blueprintDir)
    {
        $this->filePath = rtrim($blueprintDir, '/').'/.pipeline-state.json';
        $this->load();
    }

    public function isCompleted(string $step): bool
    {
        return isset($this->state[$step]);
    }

    public function markCompleted(string $step, float $durationSeconds): void
    {
        $this->state[$step] = [
            'completed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'duration_seconds' => round($durationSeconds, 1),
        ];
        $this->save();
    }

    public function getCompletedSteps(): array
    {
        return array_keys($this->state);
    }

    public function reset(): void
    {
        $this->state = [];
        if (is_file($this->filePath)) {
            unlink($this->filePath);
        }
    }

    public static function ensureDir(string $blueprintDir): void
    {
        if (!is_dir($blueprintDir) && !mkdir($blueprintDir, 0775, true) && !is_dir($blueprintDir)) {
            throw new \RuntimeException(sprintf('Cannot create blueprint directory "%s".', $blueprintDir));
        }
    }

    private function load(): void
    {
        if (!is_file($this->filePath) || !is_readable($this->filePath)) {
            return;
        }

        try {
            $decoded = json_decode((string) file_get_contents($this->filePath), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $this->state = $decoded;
            }
        } catch (\JsonException) {
        }
    }

    private function save(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $tmp = $this->filePath.'.tmp';
        if (false !== file_put_contents($tmp, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n")) {
            rename($tmp, $this->filePath);
        }
    }
}

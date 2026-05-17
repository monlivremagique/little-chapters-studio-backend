<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:book:qa-gate',
    description: 'Standalone QA gate: validate master blueprint scores, heroBible, and visualBible against premium thresholds.',
)]
final class QaGateCommand extends Command
{
    private const QA_PREMIUM_MIN_SCORE = 9.0;
    private const QA_PREMIUM_MIN_INDIVIDUAL = 8.0;

    protected function configure(): void
    {
        $this
            ->addArgument('blueprint-dir', InputArgument::REQUIRED, 'Blueprint directory containing master.json and claude-qa-report.json.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $inputPath = rtrim(trim((string) $input->getArgument('blueprint-dir')), '/');

        // Accept both directory path and direct master.json path
        if (is_file($inputPath) && str_ends_with($inputPath, '.json')) {
            $masterPath = $inputPath;
            $blueprintDir = dirname($masterPath);
        } elseif (is_dir($inputPath)) {
            $blueprintDir = $inputPath;
            $masterPath = $blueprintDir.'/master.json';
        } else {
            $io->error(sprintf('Path not found: %s', $inputPath));
            return Command::FAILURE;
        }

        if (!is_file($masterPath)) {
            $io->error(sprintf('master.json not found in: %s', $blueprintDir));
            return Command::FAILURE;
        }

        try {
            $master = json_decode((string) file_get_contents($masterPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(sprintf('Cannot parse master.json: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        $io->title('Standalone QA Gate');
        $io->writeln(sprintf('Blueprint directory: %s', $blueprintDir));
        $io->writeln(sprintf('Slug: %s', $master['metadata']['slug'] ?? '(unknown)'));

        // ── heroBible check ──
        $heroBible = is_array($master['heroBible'] ?? null) ? $master['heroBible'] : [];
        $identityRules = is_array($heroBible['identityRules'] ?? null) ? $heroBible['identityRules'] : [];
            if ([] === $heroBible || [] === $identityRules) {
            $io->warning('QA GATE (info): heroBible.identityRules is missing or empty.');
        }

        $characterDesign = trim((string) ($heroBible['characterDesign'] ?? ''));
        if ('' === $characterDesign) {
            $io->warning('QA GATE (info): heroBible.characterDesign is missing or empty.');
        }

        $forbiddenDrift = is_array($heroBible['forbiddenDrift'] ?? null) ? $heroBible['forbiddenDrift'] : [];
        if ([] === $forbiddenDrift) {
            $io->warning('QA GATE (info): heroBible.forbiddenDrift is missing or empty.');
        }
        $io->writeln('<info>  ✓ heroBible: identityRules, characterDesign, forbiddenDrift present</info>');

        // ── visualBible check ──
        $visualBible = is_array($master['visualBible'] ?? null) ? $master['visualBible'] : [];
        $styleRules = is_array($visualBible['style_rules'] ?? null) ? $visualBible['style_rules'] : [];
        if ([] === $visualBible || [] === $styleRules) {
            $io->warning('QA GATE (info): visualBible.style_rules is missing or empty.');
        }

        $paletteRaw = $visualBible['palette'] ?? '';
        $palette = is_string($paletteRaw) ? trim($paletteRaw) : (is_array($paletteRaw) ? trim(implode(', ', array_values(array_filter($paletteRaw, 'is_string')))) : '');
        if ('' === $palette) {
            $io->warning('QA GATE (info): visualBible.palette is missing or empty.');
        }

        $lightingRaw = $visualBible['lighting'] ?? '';
        $lighting = is_string($lightingRaw) ? trim($lightingRaw) : (is_array($lightingRaw) ? trim(implode(', ', array_values(array_filter($lightingRaw, 'is_string')))) : '');
        if ('' === $lighting) {
            $io->warning('QA GATE (info): visualBible.lighting is missing or empty.');
        }
        $io->writeln('<info>  ✓ visualBible: style_rules, palette, lighting present</info>');

        // ── QA report scores ──
        $qaReportPath = $blueprintDir.'/claude-qa-report.json';
        if (!is_file($qaReportPath)) {
            $io->warning('QA GATE (info): claude-qa-report.json is missing. Scores check skipped.');
            $io->success('QA gate (info mode) — no blocking.');
            return Command::SUCCESS;
        }

        try {
            $qaReport = json_decode((string) file_get_contents($qaReportPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(sprintf('QA GATE FAILURE: claude-qa-report.json cannot be parsed: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        $scores = is_array($qaReport['scores'] ?? null) ? $qaReport['scores'] : [];

        // Handle both nested scorecard and flat score formats
        $numericScores = [];
        foreach ($scores as $dimension => $value) {
            if (is_numeric($value)) {
                $numericScores[] = (float) $value;
            } elseif (is_array($value) && isset($value['value']) && is_numeric($value['value'])) {
                $numericScores[] = (float) $value['value'] / 10;
            }
        }

        if ([] === $numericScores) {
            $io->warning('No numeric scores in QA report — score gate skipped.');
            return Command::SUCCESS;
        }

        $average = array_sum($numericScores) / count($numericScores);
        $io->writeln(sprintf('QA average score: <comment>%.2f / 10 (%.0f %%)</comment>', $average, $average * 10));
        foreach ($scores as $dimension => $value) {
            $displayValue = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $io->writeln(sprintf('  %-26s %s', $dimension.':', $displayValue));
        }

        $uniqueScores = array_values(array_unique($numericScores));
        if (count($uniqueScores) === 1 && count($numericScores) >= 4) {
            $io->warning(sprintf('All %d QA dimension scores are identical (%s/10). This pattern may indicate AI score anchoring.', count($numericScores), number_format($uniqueScores[0], 1)));
        }

        $gateMode = strtolower(trim((string) getenv('QA_GATE_MODE') ?: 'balanced'));
        if ('balanced' === $gateMode || 'strict' === $gateMode) {
            $minIndividual = 'strict' === $gateMode ? self::QA_PREMIUM_MIN_SCORE : self::QA_PREMIUM_MIN_INDIVIDUAL;
            $allAboveThreshold = true;
            foreach ($scores as $dimension => $value) {
                $scoreVal = is_numeric($value) ? (float) $value : (is_array($value) ? ((float) ($value['value'] ?? 0) / 10) : 0);
                if ($scoreVal < $minIndividual) {
                    $allAboveThreshold = false;
                    $io->warning(sprintf(
                        'QA GATE (info): dimension "%s" score %.1f/10 is below recommended %.1f/10.',
                        (string) $dimension, $scoreVal, $minIndividual
                    ));
                }
            }
            if ($allAboveThreshold) {
                $io->writeln(sprintf('<info>  ✓ %s mode: all individual scores ≥ %.1f/10</info>', $gateMode, $minIndividual));
            }
        } else {
            $io->writeln(sprintf('<info>  ✓ lenient mode: no individual score check</info>'));
        }

        if ($average < self::QA_PREMIUM_MIN_SCORE) {
            $blockingIssues = is_array($qaReport['blockingIssues'] ?? null)
                ? array_values(array_filter(array_map('trim', $qaReport['blockingIssues']), static fn (string $s): bool => '' !== $s))
                : [];
            $io->warning(sprintf('QA GATE (info): average score %.2f/10 is below premium %.0f/10.', $average, self::QA_PREMIUM_MIN_SCORE * 10));
        }

        $io->success(sprintf('QA gate PASSED: %.2f/10 (%.0f%%). heroBible and visualBible valid.', $average, $average * 10));
        return Command::SUCCESS;
    }
}

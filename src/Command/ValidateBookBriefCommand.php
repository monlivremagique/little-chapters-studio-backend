<?php

declare(strict_types=1);

namespace App\Command;

use App\BookBrief\BookBriefPromptBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'app:book:validate-brief',
    description: 'Validates a book brief YAML file against all engine requirements without triggering any API calls.',
)]
final class ValidateBookBriefCommand extends Command
{
    /** @var list<string> */
    private const VALID_LANGUAGE_CODES = ['fr', 'en', 'nl'];

    /** @var string Age range pattern: e.g. "3-5", "4-7", "8-10" */
    private const AGE_RANGE_PATTERN = '/^\d+\s*[-–]\s*\d+$/';

    public function __construct(
        private readonly BookBriefPromptBuilder $bookBriefPromptBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('brief', InputArgument::REQUIRED, 'Path to the brief YAML file to validate.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $briefPath = trim((string) $input->getArgument('brief'));

        $io->title(sprintf('Book Brief Validation: %s', basename($briefPath)));

        // ── File existence check ───────────────────────────────────────────
        if (!is_file($briefPath) || !is_readable($briefPath)) {
            $io->error(sprintf('Brief file not found or not readable: %s', $briefPath));

            return Command::FAILURE;
        }

        // ── YAML parse ────────────────────────────────────────────────────
        try {
            $brief = Yaml::parseFile($briefPath);
        } catch (ParseException $e) {
            $io->error(sprintf('Invalid YAML: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        if (!is_array($brief)) {
            $io->error('The brief root must be a YAML mapping/object.');

            return Command::FAILURE;
        }

        $errors = [];
        $warnings = [];

        // ── Semantic field validation ─────────────────────────────────────
        $errors = [...$errors, ...$this->validateSemanticFields($brief, $warnings)];

        // ── Engine prompt build (catches all required-field RuntimeExceptions) ─
        try {
            $built = $this->bookBriefPromptBuilder->build($brief);
            $io->writeln(sprintf('<info>Prompt builder accepted brief for slug: %s</info>', $built['slug']));
        } catch (\RuntimeException $e) {
            $errors[] = sprintf('Prompt builder rejected brief: %s', $e->getMessage());
        }

        // ── Results ───────────────────────────────────────────────────────
        if ([] !== $warnings) {
            $io->warning($warnings);
        }

        if ([] !== $errors) {
            $io->error(array_merge(['Brief validation FAILED. Fix the following issues:'], $errors));

            return Command::FAILURE;
        }

        $io->success(sprintf('Brief is valid. Slug: %s. Ready for: php bin/console app:book-factory:create-from-brief %s', $brief['slug'] ?? '(unknown)', $briefPath));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $brief
     * @param list<string> $warnings
     * @return list<string>
     */
    private function validateSemanticFields(array $brief, array &$warnings): array
    {
        $errors = [];

        // slug
        $slug = trim((string) ($brief['slug'] ?? ''));
        if ('' === $slug) {
            $errors[] = 'Field "slug" is required.';
        } elseif (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            $errors[] = sprintf('Field "slug" must contain only lowercase letters, digits, and hyphens. Got: "%s"', $slug);
        }

        // age
        $age = trim((string) ($brief['age'] ?? ''));
        if ('' === $age) {
            $errors[] = 'Field "age" is required (e.g. "3-5", "4-7", "8-10").';
        } elseif (!preg_match(self::AGE_RANGE_PATTERN, $age)) {
            $errors[] = sprintf('Field "age" must be a range like "3-5" or "4-7". Got: "%s"', $age);
        } else {
            preg_match('/^(\d+)\s*[-–]\s*(\d+)$/', $age, $m);
            $ageMin = (int) $m[1];
            $ageMax = (int) $m[2];
            if ($ageMin >= $ageMax) {
                $errors[] = sprintf('Field "age" min (%d) must be less than max (%d).', $ageMin, $ageMax);
            }

            if ($ageMax > 14) {
                $warnings[] = sprintf('Age max %d seems high for a children\'s book. Verify this is correct.', $ageMax);
            }
        }

        // languages
        $languages = $brief['languages'] ?? [];
        if (!is_array($languages) || [] === $languages) {
            $errors[] = 'Field "languages" must be a non-empty list.';
        } else {
            foreach ($languages as $lang) {
                if (!in_array($lang, self::VALID_LANGUAGE_CODES, true)) {
                    $errors[] = sprintf('Field "languages" contains invalid code "%s". Allowed: %s', $lang, implode(', ', self::VALID_LANGUAGE_CODES));
                }
            }
        }

        // theme
        $themes = $brief['theme'] ?? [];
        if (!is_array($themes) || [] === $themes) {
            $errors[] = 'Field "theme" must be a non-empty list of theme strings.';
        }

        // Required string fields with minimum length
        foreach (['title', 'story_subject', 'main_emotion', 'learning_message', 'visual_style'] as $field) {
            $value = trim((string) ($brief[$field] ?? ''));
            if ('' === $value) {
                $errors[] = sprintf('Field "%s" is required and must not be empty.', $field);
            } elseif (mb_strlen($value) < 5) {
                $warnings[] = sprintf('Field "%s" is very short ("%s"). Consider adding more detail.', $field, $value);
            }
        }

        // Optional but validated fields
        $arcType = trim((string) ($brief['arc_type'] ?? ''));
        if ('' !== $arcType) {
            $knownArcs = ['comfort-to-courage', 'quest-with-revelation', 'ordinary-to-extraordinary', 'loss-and-healing', 'friendship-forged'];
            if (!in_array($arcType, $knownArcs, true)) {
                $warnings[] = sprintf('Field "arc_type" "%s" is not a recognized arc type. Known: %s', $arcType, implode(', ', $knownArcs));
            }
        }

        // scenes validation
        $scenes = $brief['scenes'] ?? [];
        if (is_array($scenes) && [] !== $scenes) {
            foreach ($scenes as $index => $scene) {
                if (!is_array($scene)) {
                    $errors[] = sprintf('Field "scenes[%d]" must be a mapping with "id" and "moment".', $index);
                    continue;
                }

                if ('' === trim((string) ($scene['id'] ?? ''))) {
                    $errors[] = sprintf('Field "scenes[%d].id" is required.', $index);
                }

                if ('' === trim((string) ($scene['moment'] ?? ''))) {
                    $errors[] = sprintf('Field "scenes[%d].moment" is required.', $index);
                }
            }
        }

        return $errors;
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\FrontCatalog\FrontCatalogMetadata;
use App\FrontCatalog\FrontCatalogProvider;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:diagnose-catalog-locales',
    description: 'Checks multilingual catalog completeness across FR/EN/NL for books, attributes, and blueprint placeholders.',
)]
final class DiagnoseCatalogLocalesCommand extends Command
{
    /** @var array<string, string> */
    private const LOCALES = [
        'fr' => 'fr_FR',
        'en' => 'en_US',
        'nl' => 'nl_NL',
    ];

    /** @var list<string> */
    private const REQUIRED_ATTRIBUTE_CODES = [
        'book_badge',
        'book_blueprint_json',
        'book_cover_type',
        'book_description',
        'book_emotional_promise',
        'book_features',
        'book_format',
        'book_language',
        'book_long_description',
        'book_personalization_level',
        'book_reviews_json',
        'book_subtitle',
        'book_theme',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly FrontCatalogMetadata $frontCatalogMetadata,
        private readonly FrontCatalogProvider $frontCatalogProvider,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $issues = [];
        $expectedBookCount = count($this->frontCatalogMetadata->books());

        $issues = array_merge($issues, $this->diagnoseAttributeCoverage($expectedBookCount));
        $issues = array_merge($issues, $this->diagnoseChannelLocales());
        $issues = array_merge($issues, $this->diagnoseLocalizedBooks());

        if ([] === $issues) {
            $io->success('Multilingual catalog diagnostics passed. FR/EN/NL data and blueprint placeholders are complete.');

            return Command::SUCCESS;
        }

        $io->error('Multilingual catalog diagnostics found issues.');
        $io->listing($issues);

        return Command::FAILURE;
    }

    /** @return list<string> */
    private function diagnoseAttributeCoverage(int $expectedBookCount): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT pa.code, pav.locale_code, COUNT(*) AS cnt
                FROM sylius_product_attribute_value pav
                INNER JOIN sylius_product_attribute pa ON pa.id = pav.attribute_id
                INNER JOIN sylius_product p ON p.id = pav.product_id AND p.enabled = TRUE
                WHERE pa.code IN (?)
                GROUP BY pa.code, pav.locale_code
            SQL,
            [self::REQUIRED_ATTRIBUTE_CODES],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['code']][(string) $row['locale_code']] = (int) $row['cnt'];
        }

        $issues = [];

        foreach (self::REQUIRED_ATTRIBUTE_CODES as $attributeCode) {
            foreach (self::LOCALES as $localeCode) {
                $count = (int) ($counts[$attributeCode][$localeCode] ?? 0);

                if ($count !== $expectedBookCount) {
                    $issues[] = sprintf(
                        'Attribute %s has %d/%d values for %s.',
                        $attributeCode,
                        $count,
                        $expectedBookCount,
                        $localeCode,
                    );
                }
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function diagnoseChannelLocales(): array
    {
        $rows = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT locale.code
                FROM sylius_channel_locales channel_locale
                INNER JOIN sylius_channel channel ON channel.id = channel_locale.channel_id
                INNER JOIN sylius_locale locale ON locale.id = channel_locale.locale_id
                WHERE channel.code = ?
            SQL,
            ['LITTLE_CHAPTERS_BE_FR'],
        );

        $issues = [];

        foreach (self::LOCALES as $localeCode) {
            if (!in_array($localeCode, $rows, true)) {
                $issues[] = sprintf('Channel LITTLE_CHAPTERS_BE_FR is missing locale %s.', $localeCode);
            }
        }

        return $issues;
    }

    /** @return list<string> */
    private function diagnoseLocalizedBooks(): array
    {
        $issues = [];

        foreach (array_keys($this->frontCatalogMetadata->books()) as $slug) {
            foreach (array_keys(self::LOCALES) as $locale) {
                try {
                    $book = $this->frontCatalogProvider->getBookBySlug($slug, $locale);
                } catch (\Throwable $exception) {
                    $issues[] = sprintf('Book %s is not readable in locale %s: %s', $slug, $locale, $exception->getMessage());
                    continue;
                }

                $availableLocales = array_values(array_unique(array_map('strval', $book['availableLocales'] ?? [])));
                sort($availableLocales);

                if ($availableLocales !== ['en', 'fr', 'nl']) {
                    $issues[] = sprintf('Book %s exposes unexpected availableLocales in locale %s.', $slug, $locale);
                }

                foreach (['title', 'subtitle', 'description', 'longDescription', 'emotionalPromise'] as $field) {
                    if ('' === trim((string) ($book[$field] ?? ''))) {
                        $issues[] = sprintf('Book %s has empty %s in locale %s.', $slug, $field, $locale);
                    }
                }

                $blueprint = is_array($book['bookBlueprint'] ?? null) ? $book['bookBlueprint'] : [];
                $pages = is_array($blueprint['pages'] ?? null) ? $blueprint['pages'] : [];

                if ([] === $pages) {
                    $issues[] = sprintf('Book %s has no blueprint pages in locale %s.', $slug, $locale);
                    continue;
                }

                foreach (['dedication', 'summary'] as $pageId) {
                    $page = $this->findPage($pages, $pageId);

                    if (null === $page) {
                        $issues[] = sprintf('Book %s is missing %s page in locale %s.', $slug, $pageId, $locale);
                        continue;
                    }

                    if ('' === trim((string) ($page['default_image_path'] ?? ''))) {
                        $issues[] = sprintf('Book %s has empty %s default_image_path in locale %s.', $slug, $pageId, $locale);
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * @param list<array<string, mixed>> $pages
     *
     * @return array<string, mixed>|null
     */
    private function findPage(array $pages, string $pageId): ?array
    {
        foreach ($pages as $page) {
            if (($page['id'] ?? null) === $pageId) {
                return $page;
            }
        }

        return null;
    }
}

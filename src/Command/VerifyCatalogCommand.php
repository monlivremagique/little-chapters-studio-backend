<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:book:verify-catalog',
    description: 'Verifies a book is visible in the public catalog API with correct FR/EN/NL locales.',
)]
final class VerifyCatalogCommand extends Command
{
    /** @var list<string> */
    private const REQUIRED_LOCALES = ['fr', 'en', 'nl'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Book slug to verify.')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Base URL for local API.', 'http://localhost:8001');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = trim((string) $input->getArgument('slug'));
        $baseUrl = rtrim(trim((string) $input->getOption('base-url')), '/');

        $io->title(sprintf('Catalog Verification: %s', $slug));
        $io->writeln(sprintf('Base URL: %s', $baseUrl));

        $errors = [];

        try {
            // ── Check /api/books includes slug ──
            $io->section('1/3 — Book in /api/books catalog');
            $catalog = $this->requestJson(sprintf('%s/api/books', $baseUrl));
            $catalogSlugs = array_map(
                static fn (array $entry): string => (string) ($entry['slug'] ?? ''),
                array_filter($catalog, 'is_array'),
            );
            if (!in_array($slug, $catalogSlugs, true)) {
                $errors[] = sprintf('Book "%s" does NOT appear in GET /api/books', $slug);
                $io->error($errors[0]);
            } else {
                $io->writeln(sprintf('  ✓ "%s" found in catalog', $slug));
            }

            // ── Check /api/books/{slug}?locale=fr|en|nl ──
            $io->section('2/3 — Locale-specific pages correct');
            $payloads = [];
            foreach (self::REQUIRED_LOCALES as $locale) {
                $payloads[$locale] = $this->requestJson(sprintf('%s/api/books/%s?locale=%s', $baseUrl, rawurlencode($slug), $locale));
                $bookBlueprint = is_array($payloads[$locale]['bookBlueprint'] ?? null) ? $payloads[$locale]['bookBlueprint'] : null;
                if (null === $bookBlueprint) {
                    $errors[] = sprintf('Locale "%s": missing bookBlueprint in API response.', $locale);
                    $io->warning(sprintf('Locale "%s": missing bookBlueprint', $locale));
                    continue;
                }
                $metadata = is_array($bookBlueprint['metadata'] ?? null) ? $bookBlueprint['metadata'] : [];
                if (($metadata['locale'] ?? null) !== $locale) {
                    $errors[] = sprintf('Locale "%s": blueprint metadata.locale mismatch (got "%s")', $locale, $metadata['locale'] ?? 'null');
                }
                $io->writeln(sprintf('  ✓ locale %s: bookBlueprint OK', $locale));
            }

            if ([] === $errors) {
                // ── Check page ID consistency across locales ──
                $frPages = is_array($payloads['fr']['bookBlueprint']['pages'] ?? null) ? $payloads['fr']['bookBlueprint']['pages'] : [];
                $expectedPageIds = array_map(
                    static fn (array $page): string => (string) ($page['id'] ?? ''),
                    array_filter($frPages, 'is_array'),
                );
                $io->writeln(sprintf('  ✓ FR page count: %d', count($expectedPageIds)));

                foreach (['en', 'nl'] as $locale) {
                    $pages = is_array($payloads[$locale]['bookBlueprint']['pages'] ?? null) ? $payloads[$locale]['bookBlueprint']['pages'] : [];
                    $pageIds = array_map(
                        static fn (array $page): string => (string) ($page['id'] ?? ''),
                        array_filter($pages, 'is_array'),
                    );
                    if ($pageIds !== $expectedPageIds) {
                        $errors[] = sprintf('Page IDs diverged for locale "%s"', $locale);
                    }
                }
                if ([] === $errors) {
                    $io->writeln('  ✓ page IDs consistent across all 3 locales');
                }
            }

            // ── Check asset images return HTTP 200 ──
            $io->section('3/3 — Asset images HTTP 200');
            $brokenImages = [];

            foreach (self::REQUIRED_LOCALES as $locale) {
                if (!isset($payloads[$locale])) continue;
                $pages = is_array($payloads[$locale]['bookBlueprint']['pages'] ?? null) ? $payloads[$locale]['bookBlueprint']['pages'] : [];
                foreach ($pages as $page) {
                    if (!is_array($page)) continue;
                    $pageId = (string) ($page['id'] ?? 'page');
                    $path = trim((string) ($page['default_image_path'] ?? ''));
                    if ('' === $path) {
                        $brokenImages[] = ['locale' => $locale, 'id' => $pageId, 'error' => 'missing default_image_path'];
                        continue;
                    }
                    try {
                        $response = $this->httpClient->request('GET', $baseUrl.$path);
                        $statusCode = $response->getStatusCode();
                    } catch (\Throwable) {
                        $statusCode = null;
                    }
                    if (200 !== $statusCode) {
                        $brokenImages[] = ['locale' => $locale, 'id' => $pageId, 'path' => $path, 'status' => $statusCode];
                    }
                }
            }

            if ([] !== $brokenImages) {
                $errors[] = sprintf('Broken images: %s', json_encode($brokenImages, JSON_UNESCAPED_SLASHES));
                $io->error($errors[count($errors) - 1]);
            } else {
                $io->writeln('  ✓ all asset images return HTTP 200');
            }
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
            $io->error($e->getMessage());
        }

        if ([] !== $errors) {
            $io->error(sprintf('Catalog verification FAILED with %d error(s).', count($errors)));
            return Command::FAILURE;
        }

        $io->success(sprintf('"%s" is fully visible and correct across FR/EN/NL in the catalog.', $slug));
        return Command::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function requestJson(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url);
            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                throw new \RuntimeException(sprintf('HTTP %d for "%s".', $statusCode, $url));
            }
            $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf('API verification failed for "%s": %s', $url, $exception->getMessage()));
        }
        return is_array($decoded) ? $decoded : throw new \RuntimeException(sprintf('Non-object payload for "%s".', $url));
    }
}

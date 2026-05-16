<?php

declare(strict_types=1);

namespace App\Command;

use App\BookBlueprint\BlueprintValidator;
use App\Entity\Channel\Channel;
use App\Entity\Channel\ChannelPricing;
use App\Entity\Product\Product;
use App\Entity\Product\ProductAttribute;
use App\Entity\Product\ProductAttributeValue;
use App\Entity\Product\ProductTaxon;
use App\Entity\Product\ProductVariant;
use App\Entity\Taxonomy\Taxon;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:sync-book-blueprints',
    description: 'Sync local book_blueprint_json files into Sylius products and ensure default assets exist.',
)]
final class SyncBookBlueprintsCommand extends Command
{
    /** @var array<string, string> */
    private const SHORT_TO_SYLIUS_LOCALE = [
        'en' => 'en_US',
        'fr' => 'fr_FR',
        'nl' => 'nl_NL',
    ];

    private const CHANNEL_CODE = 'MLM_BE_FR';
    private const DEFAULT_PRICE = 3990;
    private const DEFAULT_ORIGINAL_PRICE = 4490;
    private const DEFAULT_FORMAT = '21 x 21 cm';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BlueprintValidator $blueprintValidator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'allow-manual-craft',
            null,
            InputOption::VALUE_NONE,
            'Allow syncing books whose claude-qa-report.json contains model="manual-craft". Without this flag, manual-craft books are skipped and not published.',
        );
        $this->addOption(
            'slug',
            null,
            InputOption::VALUE_REQUIRED,
            'Sync only a single book blueprint slug instead of all discovered blueprints.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $allowManualCraft = (bool) $input->getOption('allow-manual-craft');
        $singleSlug = trim((string) $input->getOption('slug'));
        $filesystem = new Filesystem();
        $attribute = $this->entityManager->getRepository(ProductAttribute::class)->findOneBy(['code' => 'book_blueprint_json']);

        if (!$attribute instanceof ProductAttribute) {
            $io->error('Missing product attribute "book_blueprint_json".');

            return Command::FAILURE;
        }

        $channel = $this->entityManager->getRepository(Channel::class)->findOneBy(['code' => self::CHANNEL_CODE]);
        if (!$channel instanceof Channel) {
            $io->error(sprintf('Missing Sylius channel "%s".', self::CHANNEL_CODE));

            return Command::FAILURE;
        }

        $taxonsByCode = $this->loadTaxonsByCode([
            'AMIS_ANIMAUX',
            'AVENTURES_MAGIQUES',
            'HEROS_DU_QUOTIDIEN',
            'HISTOIRES_DU_SOIR',
        ]);
        $attributesByCode = $this->loadAttributesByCode([
            'book_age_max',
            'book_age_min',
            'book_badge',
            'book_blueprint_json',
            'book_cover_type',
            'book_format',
            'book_language',
            'book_pages',
            'book_personalization_level',
            'book_theme',
        ]);

        $synced = 0;
        $processedSlugs = [];

        $directories = '' !== $singleSlug
            ? [sprintf('%s/resources/book-blueprints/%s', $this->projectDir, $singleSlug)]
            : $this->discoverV2BlueprintDirectories();

        foreach ($directories as $directory) {
            $masterPath = $directory.'/master.json';
            $masterBlueprint = $this->decodeJsonFile($masterPath);

            if (!is_array($masterBlueprint)) {
                $io->warning(sprintf('Skipping unreadable V2 master blueprint "%s".', $masterPath));
                continue;
            }

            $slug = trim((string) (($masterBlueprint['metadata']['slug'] ?? basename($directory))));
            $status = trim((string) (($masterBlueprint['metadata']['status'] ?? 'draft')));

            if (isset($processedSlugs[$slug])) {
                $io->warning(sprintf(
                    'Duplicate slug "%s" in V2 directory "%s" — skipping to prevent catalog corruption. Remove or rename the duplicate blueprint directory.',
                    $slug,
                    basename($directory),
                ));
                continue;
            }

            // P0-5: Block manual-craft books unless explicitly allowed
            $qaReportPath = $directory.'/claude-qa-report.json';
            $qaReport = $this->decodeJsonFile($qaReportPath);
            if (is_array($qaReport) && 'manual-craft' === ($qaReport['model'] ?? null)) {
                if (!$allowManualCraft) {
                    $io->warning(sprintf(
                        'Skipping "%s": claude-qa-report.json has model="manual-craft". This book bypassed the AI generation pipeline and cannot be published by default. Run with --allow-manual-craft to override.',
                        $slug,
                    ));
                    continue;
                }

                $io->warning(sprintf('Syncing "%s" with model="manual-craft" (--allow-manual-craft flag set). This book bypassed the AI pipeline.', $slug));
            }

            $masterValidation = $this->blueprintValidator->validateMasterBlueprint($masterBlueprint);
            if (!$masterValidation->isValid()) {
                $io->warning(sprintf(
                    'Skipping "%s": invalid master blueprint: %s',
                    $slug,
                    implode(' | ', $masterValidation->errors),
                ));
                continue;
            }

            $runtimeBlueprints = $this->loadRuntimeBlueprints($directory);

            if ([] === $runtimeBlueprints) {
                $io->warning(sprintf('Skipping "%s": no generated runtime.{fr|en|nl}.json files were found.', $slug));
                continue;
            }

            $runtimeErrors = $this->validateRuntimeSet($runtimeBlueprints);
            if ([] !== $runtimeErrors) {
                $io->warning(sprintf('Skipping "%s": invalid runtime set: %s', $slug, implode(' | ', $runtimeErrors)));
                continue;
            }

            // NOTE: print-ready.pdf is a customer-personalized fulfillment artifact generated
            // post-order. It is NOT part of admin template publication. Template readiness is
            // gated only on: metadata.status=published + runtime files + template assets.

            $runtimeBlueprints = $this->materializeGeneratedAssets($slug, $directory, $runtimeBlueprints, $masterBlueprint, $filesystem);
            $publicationErrors = $this->validatePublicationAssets($runtimeBlueprints, 'published' === $status);
            if ([] !== $publicationErrors) {
                $io->warning(sprintf('Skipping "%s": publication assets are not ready: %s', $slug, implode(' | ', $publicationErrors)));
                continue;
            }

            $product = $this->findOrCreateProduct($slug, $masterBlueprint, $channel, $taxonsByCode);
            $this->syncRuntimeBlueprints($product, $attribute, $runtimeBlueprints);
            $this->syncBookAttributes($product, $attributesByCode, $masterBlueprint, array_keys($runtimeBlueprints));
            $this->entityManager->persist($product);
            $processedSlugs[$slug] = true;
            ++$synced;
        }

        foreach ($this->discoverLegacyBlueprintFiles() as $filePath) {
            $slug = basename($filePath, '.json');
            if (isset($processedSlugs[$slug])) {
                continue;
            }

            $blueprint = $this->decodeJsonFile($filePath);
            if (!is_array($blueprint) || !is_array($blueprint['pages'] ?? null)) {
                $io->warning(sprintf('Skipping invalid legacy blueprint "%s".', $filePath));
                continue;
            }

            $product = $this->findProductBySlugOrCode($slug, null);
            if (!$product instanceof Product) {
                $io->warning(sprintf('Skipping legacy blueprint "%s": no Sylius product exists for this slug.', $slug));
                continue;
            }

            $legacyBlueprint = $this->normalizeLegacyBlueprint($slug, $blueprint, $filesystem);
            $this->syncBlueprintAttributeValue($product, $attribute, 'fr_FR', $legacyBlueprint, $slug);
            $this->entityManager->persist($product);
            ++$synced;
        }

        $this->entityManager->flush();

        $io->success(sprintf('Synced %d book blueprint(s) into Sylius products.', $synced));

        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function discoverV2BlueprintDirectories(): array
    {
        $directories = glob($this->projectDir.'/resources/book-blueprints/*', GLOB_ONLYDIR) ?: [];
        sort($directories);

        return array_values(array_filter(
            $directories,
            static fn (string $directory): bool => is_file($directory.'/master.json'),
        ));
    }

    /** @return list<string> */
    private function discoverLegacyBlueprintFiles(): array
    {
        $files = glob($this->projectDir.'/resources/book-blueprints/*.json') ?: [];
        sort($files);

        return $files;
    }

    /** @return array<string, ProductAttribute> */
    private function loadAttributesByCode(array $codes): array
    {
        /** @var list<ProductAttribute> $attributes */
        $attributes = $this->entityManager->getRepository(ProductAttribute::class)->findBy(['code' => $codes]);
        $indexed = [];

        foreach ($attributes as $attribute) {
            $indexed[(string) $attribute->getCode()] = $attribute;
        }

        return $indexed;
    }

    /** @return array<string, Taxon> */
    private function loadTaxonsByCode(array $codes): array
    {
        /** @var list<Taxon> $taxons */
        $taxons = $this->entityManager->getRepository(Taxon::class)->findBy(['code' => $codes]);
        $indexed = [];

        foreach ($taxons as $taxon) {
            $indexed[(string) $taxon->getCode()] = $taxon;
        }

        return $indexed;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadRuntimeBlueprints(string $directory): array
    {
        $runtimeBlueprints = [];

        foreach (self::SHORT_TO_SYLIUS_LOCALE as $shortLocale => $localeCode) {
            $path = sprintf('%s/generated/runtime.%s.json', $directory, $shortLocale);
            $runtimeBlueprint = $this->decodeJsonFile($path);

            if (is_array($runtimeBlueprint)) {
                $runtimeBlueprints[$localeCode] = $runtimeBlueprint;
            }
        }

        return $runtimeBlueprints;
    }

    /**
     * @param array<string, array<string, mixed>> $runtimeBlueprints
     * @return list<string>
     */
    private function validateRuntimeSet(array $runtimeBlueprints): array
    {
        $errors = [];
        $expectedLocales = array_values(self::SHORT_TO_SYLIUS_LOCALE);
        sort($expectedLocales);
        $actualLocales = array_keys($runtimeBlueprints);
        sort($actualLocales);

        if ($actualLocales !== $expectedLocales) {
            $errors[] = sprintf('expected runtime locales [%s], got [%s]', implode(',', $expectedLocales), implode(',', $actualLocales));
        }

        $expectedPageIds = null;

        foreach (self::SHORT_TO_SYLIUS_LOCALE as $shortLocale => $syliusLocale) {
            $runtimeBlueprint = $runtimeBlueprints[$syliusLocale] ?? null;
            if (!is_array($runtimeBlueprint)) {
                continue;
            }

            $validation = $this->blueprintValidator->validateRuntimeBlueprint($runtimeBlueprint);
            if (!$validation->isValid()) {
                $errors[] = sprintf('%s runtime invalid: %s', $shortLocale, implode('; ', $validation->errors));
            }

            $metadata = is_array($runtimeBlueprint['metadata'] ?? null) ? $runtimeBlueprint['metadata'] : [];
            if (($metadata['locale'] ?? null) !== $shortLocale) {
                $errors[] = sprintf('%s runtime metadata.locale must equal "%s"', $shortLocale, $shortLocale);
            }

            $pages = is_array($runtimeBlueprint['pages'] ?? null) ? array_values($runtimeBlueprint['pages']) : [];
            if ([] === $pages) {
                $errors[] = sprintf('%s runtime has no pages', $shortLocale);
                continue;
            }

            $pageIds = [];
            foreach ($pages as $index => $page) {
                if (!is_array($page)) {
                    $errors[] = sprintf('%s runtime pages[%d] is not an object', $shortLocale, $index);
                    continue;
                }

                $pageId = trim((string) ($page['id'] ?? ''));
                $imagePath = trim((string) ($page['default_image_path'] ?? ''));
                if ('' === $pageId) {
                    $errors[] = sprintf('%s runtime pages[%d] is missing id', $shortLocale, $index);
                }
                if ('' === $imagePath) {
                    $errors[] = sprintf('%s runtime page "%s" is missing default_image_path', $shortLocale, '' !== $pageId ? $pageId : (string) $index);
                }
                $pageIds[] = $pageId;
            }

            if (null === $expectedPageIds) {
                $expectedPageIds = $pageIds;
            } elseif ($expectedPageIds !== $pageIds) {
                $errors[] = sprintf('%s runtime page order diverges from the first locale', $shortLocale);
            }
        }

        return array_values(array_unique($errors));
    }

    /** @return array<string, mixed>|null */
    private function decodeJsonFile(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, Taxon> $taxonsByCode
     * @param array<string, mixed> $masterBlueprint
     */
    private function findOrCreateProduct(string $slug, array $masterBlueprint, Channel $channel, array $taxonsByCode): Product
    {
        $productCode = trim((string) (($masterBlueprint['metadata']['productCode'] ?? '')));
        $product = $this->findProductBySlugOrCode($slug, '' !== $productCode ? $productCode : null);

        // P0-4: Respect metadata.status — only 'published' books are enabled in the public catalog
        $metadataStatus = trim((string) ($masterBlueprint['metadata']['status'] ?? 'draft'));
        $shouldBeEnabled = 'published' === $metadataStatus;

        if (!$product instanceof Product) {
            $product = new Product();
            $product->setCode('' !== $productCode ? $productCode : strtoupper(str_replace('-', '_', $slug)));
            $product->setEnabled($shouldBeEnabled);
            $product->addChannel($channel);
        }

        $product->setEnabled($shouldBeEnabled);
        if (!$product->hasChannel($channel)) {
            $product->addChannel($channel);
        }

        $this->syncProductTranslations($product, $slug, $masterBlueprint);
        $this->syncProductTaxons($product, $masterBlueprint, $taxonsByCode);
        $this->syncProductVariant($product, $channel);

        return $product;
    }

    private function findProductBySlugOrCode(string $slug, ?string $code): ?Product
    {
        if (null !== $code && '' !== $code) {
            $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $code]);
            if ($product instanceof Product) {
                return $product;
            }
        }

        /** @var Product|null $product */
        $product = $this->entityManager->getRepository(Product::class)
            ->createQueryBuilder('p')
            ->innerJoin('p.translations', 'translation')
            ->andWhere('translation.slug = :slug')
            ->setParameter('slug', $slug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $product;
    }

    /** @param array<string, mixed> $masterBlueprint */
    private function syncProductTranslations(Product $product, string $slug, array $masterBlueprint): void
    {
        $locales = is_array($masterBlueprint['locales'] ?? null) ? $masterBlueprint['locales'] : [];

        foreach (self::SHORT_TO_SYLIUS_LOCALE as $shortLocale => $localeCode) {
            $localeNode = is_array($locales[$shortLocale] ?? null) ? $locales[$shortLocale] : [];
            $bookNode = is_array($localeNode['book'] ?? null) ? $localeNode['book'] : [];
            $displayTitle = $this->displayTitleFromTemplate((string) ($bookNode['title_template'] ?? $slug));
            $shortDescription = $this->defaultShortDescription($shortLocale, $displayTitle);
            $description = $this->defaultDescription($shortLocale, $displayTitle);

            $product->setCurrentLocale($localeCode);
            $product->setFallbackLocale($localeCode);
            $product->setName($displayTitle);
            $product->setSlug($slug);
            $product->setShortDescription($shortDescription);
            $product->setDescription($description);
        }

        $product->setCurrentLocale('fr_FR');
        $product->setFallbackLocale('fr_FR');
    }

    private function displayTitleFromTemplate(string $titleTemplate): string
    {
        $title = trim($titleTemplate);
        $title = preg_replace('/^\{child_name\}\s+(?:et|and|en)\s+/i', '', $title) ?? $title;
        $title = str_replace('{child_name}', '', $title);
        $title = trim($title, " -,");

        return '' !== $title ? $title : 'Livre personalise premium';
    }

    private function defaultShortDescription(string $shortLocale, string $displayTitle): string
    {
        return match ($shortLocale) {
            'en' => sprintf('Premium personalized storybook inspired by %s.', $displayTitle),
            'nl' => sprintf('Premium gepersonaliseerd verhalenboek rond %s.', $displayTitle),
            default => sprintf('Livre premium personnalise inspire de %s.', $displayTitle),
        };
    }

    private function defaultDescription(string $shortLocale, string $displayTitle): string
    {
        return match ($shortLocale) {
            'en' => sprintf('%s is a premium bedtime-safe personalized storybook prepared for the local admin catalogue workflow.', $displayTitle),
            'nl' => sprintf('%s is een premium, bedtime-safe gepersonaliseerd verhalenboek voor de lokale admin-catalogusworkflow.', $displayTitle),
            default => sprintf('%s est un livre personnalise premium bedtime-safe prepare pour le workflow admin local.', $displayTitle),
        };
    }

    /** @param array<string, Taxon> $taxonsByCode @param array<string, mixed> $masterBlueprint */
    private function syncProductTaxons(Product $product, array $masterBlueprint, array $taxonsByCode): void
    {
        $taxonCodes = $this->resolveTaxonCodes($masterBlueprint);
        $position = 0;
        $mainTaxon = null;

        foreach ($taxonCodes as $taxonCode) {
            $taxon = $taxonsByCode[$taxonCode] ?? null;
            if (!$taxon instanceof Taxon) {
                continue;
            }

            if (!$product->hasTaxon($taxon)) {
                $productTaxon = new ProductTaxon();
                $productTaxon->setTaxon($taxon);
                $productTaxon->setPosition($position);
                $product->addProductTaxon($productTaxon);
                $this->entityManager->persist($productTaxon);
            }

            if (null === $mainTaxon) {
                $mainTaxon = $taxon;
            }

            ++$position;
        }

        if ($mainTaxon instanceof Taxon) {
            $product->setMainTaxon($mainTaxon);
        }
    }

    /** @param array<string, mixed> $masterBlueprint @return list<string> */
    private function resolveTaxonCodes(array $masterBlueprint): array
    {
        $metadata = is_array($masterBlueprint['metadata'] ?? null) ? $masterBlueprint['metadata'] : [];
        $themes = is_array($metadata['theme'] ?? null) ? array_values(array_filter($metadata['theme'], 'is_string')) : [];
        $editorialPositioning = strtolower((string) ($metadata['editorialPositioning'] ?? ''));
        $taxonCodes = [];

        if (array_intersect(['magic', 'wonder', 'courage'], $themes) !== []) {
            $taxonCodes[] = 'AVENTURES_MAGIQUES';
        }

        if (str_contains($editorialPositioning, 'bedtime')) {
            $taxonCodes[] = 'HISTOIRES_DU_SOIR';
        }

        if ([] === $taxonCodes) {
            $taxonCodes[] = 'AVENTURES_MAGIQUES';
        }

        return array_values(array_unique($taxonCodes));
    }

    private function syncProductVariant(Product $product, Channel $channel): void
    {
        $variant = $product->getVariants()->first();
        if (!$variant instanceof ProductVariant) {
            $variant = new ProductVariant();
            $variant->setCode(sprintf('%s_DEFAULT', (string) $product->getCode()));
            $variant->setCurrentLocale('fr_FR');
            $variant->setFallbackLocale('fr_FR');
            $variant->setName('Default');
            $variant->setEnabled(true);
            $variant->setTracked(false);
            $variant->setOnHand(999);
            $product->addVariant($variant);
            $this->entityManager->persist($variant);
        }

        $channelPricing = $variant->getChannelPricingForChannel($channel);
        if (!$channelPricing instanceof ChannelPricing) {
            $channelPricing = new ChannelPricing();
            $channelPricing->setChannelCode($channel->getCode());
            $variant->addChannelPricing($channelPricing);
            $this->entityManager->persist($channelPricing);
        }

        if (null === $channelPricing->getPrice()) {
            $channelPricing->setPrice(self::DEFAULT_PRICE);
        }

        if (null === $channelPricing->getOriginalPrice()) {
            $channelPricing->setOriginalPrice(self::DEFAULT_ORIGINAL_PRICE);
        }

        $variant->setEnabled(true);
    }

    /**
     * @param string $sourceDirectory
     * @param array<string, array<string, mixed>> $runtimeBlueprints
     * @param array<string, mixed> $masterBlueprint
     * @return array<string, array<string, mixed>>
     */
    private function materializeGeneratedAssets(string $slug, string $sourceDirectory, array $runtimeBlueprints, array $masterBlueprint, Filesystem $filesystem): array
    {
        $sceneAssetKeys = $this->buildSceneAssetKeyMap($masterBlueprint);
        $bookDirectory = $this->projectDir.'/public/uploads/books/'.$slug;
        $filesystem->mkdir($bookDirectory, 0775);

        foreach ($runtimeBlueprints as $localeCode => $runtimeBlueprint) {
            $runtimeBlueprints[$localeCode] = $this->applyAssetOverrides($slug, $sourceDirectory, $runtimeBlueprint, $sceneAssetKeys, $filesystem);
        }

        return $runtimeBlueprints;
    }

    /** @param array<string, mixed> $masterBlueprint @return array<string, string> */
    private function buildSceneAssetKeyMap(array $masterBlueprint): array
    {
        $map = [];
        $sceneDefinitions = is_array($masterBlueprint['sceneDefinitions'] ?? null) ? $masterBlueprint['sceneDefinitions'] : [];

        foreach ($sceneDefinitions as $scene) {
            if (!is_array($scene)) {
                continue;
            }

            $sceneId = trim((string) ($scene['id'] ?? ''));
            $assetKey = trim((string) ($scene['assetKey'] ?? ''));

            if ('' !== $sceneId && '' !== $assetKey) {
                $map[$sceneId] = $assetKey;
            }
        }

        return $map;
    }

    /**
     * @param string $sourceDirectory
     * @param array<string, mixed> $runtimeBlueprint
     * @param array<string, string> $sceneAssetKeys
     * @return array<string, mixed>
     */
    private function applyAssetOverrides(string $slug, string $sourceDirectory, array $runtimeBlueprint, array $sceneAssetKeys, Filesystem $filesystem): array
    {
        $publicBookDirectory = $this->projectDir.'/public/uploads/books/'.$slug;
        $pages = is_array($runtimeBlueprint['pages'] ?? null) ? $runtimeBlueprint['pages'] : [];
        $assetDefaults = is_array($runtimeBlueprint['assets']['defaults'] ?? null) ? $runtimeBlueprint['assets']['defaults'] : [];

        foreach ($pages as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageId = trim((string) ($page['id'] ?? 'page'));
            $generatedSourcePath = 'cover' === $pageId
                ? $sourceDirectory.'/generated-cover/cover-generated.png'
                : sprintf('%s/generated-pages/%s-generated.png', $sourceDirectory, $pageId);
            $generatedPublicPath = 'cover' === $pageId
                ? sprintf('/uploads/books/%s/cover-generated.png', $slug)
                : sprintf('/uploads/books/%s/%s-generated.png', $slug, $pageId);

            // Primary: copy from generated-cover / generated-pages workspace
            if (is_file($generatedSourcePath) && is_readable($generatedSourcePath)) {
                $filesystem->copy($generatedSourcePath, $this->projectDir.'/public'.$generatedPublicPath, true);
                $runtimeBlueprint['pages'][$index]['default_image_path'] = $generatedPublicPath;
                $assetKey = $sceneAssetKeys[$pageId] ?? null;
                if (null !== $assetKey) {
                    $assetDefaults[$assetKey] = $generatedPublicPath;
                }

                continue;
            }

            // Fallback: generated PNG already committed to public/uploads/ (Railway deployment)
            $committedPublicPath = $this->projectDir.'/public'.$generatedPublicPath;
            if (is_file($committedPublicPath) && is_readable($committedPublicPath)) {
                $runtimeBlueprint['pages'][$index]['default_image_path'] = $generatedPublicPath;
                $assetKey = $sceneAssetKeys[$pageId] ?? null;
                if (null !== $assetKey) {
                    $assetDefaults[$assetKey] = $generatedPublicPath;
                }

                continue;
            }

            $defaultImagePath = trim((string) ($page['default_image_path'] ?? ''));
            if ('' === $defaultImagePath) {
                $defaultImagePath = sprintf('/uploads/books/%s/%s-default.svg', $slug, $pageId);
                $runtimeBlueprint['pages'][$index]['default_image_path'] = $defaultImagePath;
            }

            $targetPath = $this->projectDir.'/public'.$defaultImagePath;
            if (!$filesystem->exists($targetPath)) {
                $filesystem->mkdir(dirname($targetPath), 0775);
                $filesystem->dumpFile($targetPath, $this->renderDefaultPageSvg($slug, $page));
            }

            if (!str_starts_with($targetPath, $publicBookDirectory)) {
                $filesystem->mkdir($publicBookDirectory, 0775);
            }
        }

        $runtimeBlueprint['assets']['defaults'] = $assetDefaults;

        return $runtimeBlueprint;
    }

    /**
     * @param array<string, array<string, mixed>> $runtimeBlueprints
     * @return list<string>
     */
    private function validatePublicationAssets(array $runtimeBlueprints, bool $published): array
    {
        if (!$published) {
            return [];
        }

        $errors = [];

        foreach ($runtimeBlueprints as $localeCode => $runtimeBlueprint) {
            foreach (is_array($runtimeBlueprint['pages'] ?? null) ? $runtimeBlueprint['pages'] : [] as $page) {
                if (!is_array($page)) {
                    continue;
                }

                $pageId = trim((string) ($page['id'] ?? ''));
                $path = trim((string) ($page['default_image_path'] ?? ''));
                if ('' === $path) {
                    $errors[] = sprintf('%s:%s has empty default_image_path', $localeCode, $pageId);
                    continue;
                }

                if (!str_ends_with(strtolower($path), '.png')) {
                    $errors[] = sprintf('%s:%s uses non-PNG asset "%s"', $localeCode, $pageId, $path);
                    continue;
                }

                $publicPath = $this->projectDir.'/public'.$path;
                if (!is_file($publicPath) || !is_readable($publicPath)) {
                    $errors[] = sprintf('%s:%s missing public PNG "%s"', $localeCode, $pageId, $path);
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param array<string, array<string, mixed>> $runtimeBlueprints
     */
    private function syncRuntimeBlueprints(Product $product, ProductAttribute $attribute, array $runtimeBlueprints): void
    {
        foreach ($runtimeBlueprints as $localeCode => $runtimeBlueprint) {
            $this->syncBlueprintAttributeValue(
                $product,
                $attribute,
                $localeCode,
                $runtimeBlueprint,
                sprintf('%s:%s', (string) $product->getCode(), $localeCode),
            );
        }
    }

    /**
     * @param array<string, ProductAttribute> $attributesByCode
     * @param array<string, mixed> $masterBlueprint
     * @param list<string> $localizedRuntimeLocales
     */
    private function syncBookAttributes(Product $product, array $attributesByCode, array $masterBlueprint, array $localizedRuntimeLocales): void
    {
        $metadata = is_array($masterBlueprint['metadata'] ?? null) ? $masterBlueprint['metadata'] : [];
        [$ageMin, $ageMax] = $this->extractAgeRange($metadata);
        $theme = $this->resolveBookTheme($masterBlueprint);
        $pageCount = (int) ($metadata['pageCount'] ?? count(is_array($masterBlueprint['sceneDefinitions'] ?? null) ? $masterBlueprint['sceneDefinitions'] : []));
        $languageValue = strtolower(implode(',', array_values(array_unique(array_map(
            fn (string $localeCode): string => $this->shortLocaleFromSyliusLocale($localeCode),
            $localizedRuntimeLocales,
        )))));

        $this->syncScalarAttribute($product, $attributesByCode['book_age_min'] ?? null, null, $ageMin);
        $this->syncScalarAttribute($product, $attributesByCode['book_age_max'] ?? null, null, $ageMax);
        $this->syncScalarAttribute($product, $attributesByCode['book_theme'] ?? null, null, $theme);
        $this->syncScalarAttribute($product, $attributesByCode['book_personalization_level'] ?? null, null, 'premium');
        $this->syncScalarAttribute($product, $attributesByCode['book_language'] ?? null, null, '' !== $languageValue ? $languageValue : 'fr,en,nl');
        $this->syncScalarAttribute($product, $attributesByCode['book_pages'] ?? null, null, $pageCount);
        $this->syncScalarAttribute($product, $attributesByCode['book_format'] ?? null, null, self::DEFAULT_FORMAT);
        $this->syncScalarAttribute($product, $attributesByCode['book_cover_type'] ?? null, null, 'rigide');
        $this->syncScalarAttribute($product, $attributesByCode['book_badge'] ?? null, null, 'Nouveau');
    }

    /** @return array{int,int} */
    private function extractAgeRange(array $metadata): array
    {
        $ageRange = trim((string) ($metadata['ageRange'] ?? '4-7'));
        if (preg_match('/^(\d+)\s*[-–]\s*(\d+)$/', $ageRange, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        return [4, 7];
    }

    /** @param array<string, mixed> $masterBlueprint */
    private function resolveBookTheme(array $masterBlueprint): string
    {
        $metadata = is_array($masterBlueprint['metadata'] ?? null) ? $masterBlueprint['metadata'] : [];
        $themes = is_array($metadata['theme'] ?? null) ? array_values(array_filter($metadata['theme'], 'is_string')) : [];
        $editorialPositioning = strtolower((string) ($metadata['editorialPositioning'] ?? ''));

        if (in_array('animaux', $themes, true)) {
            return 'animaux';
        }

        if (str_contains($editorialPositioning, 'bedtime') && !in_array('magic', $themes, true)) {
            return 'douceur';
        }

        if (in_array('courage', $themes, true) || in_array('magic', $themes, true) || in_array('wonder', $themes, true)) {
            return 'aventure';
        }

        return 'aventure';
    }

    private function shortLocaleFromSyliusLocale(string $localeCode): string
    {
        $flipped = array_flip(self::SHORT_TO_SYLIUS_LOCALE);

        return $flipped[$localeCode] ?? 'fr';
    }

    private function syncScalarAttribute(Product $product, ?ProductAttribute $attribute, ?string $localeCode, mixed $value): void
    {
        if (!$attribute instanceof ProductAttribute) {
            return;
        }

        $effectiveLocaleCode = $attribute->isTranslatable() ? $localeCode : null;
        $attributeValue = $product->getAttributeByCodeAndLocale((string) $attribute->getCode(), $effectiveLocaleCode);

        if (!$attributeValue instanceof ProductAttributeValue) {
            $attributeValue = new ProductAttributeValue();
            $attributeValue->setAttribute($attribute);
            $attributeValue->setLocaleCode($effectiveLocaleCode);
            $product->addAttribute($attributeValue);
            $this->entityManager->persist($attributeValue);
        }

        if ($attributeValue->getValue() !== $value) {
            $attributeValue->setValue($value);
        }
    }

    /**
     * @param array<string, mixed> $blueprint
     */
    private function syncBlueprintAttributeValue(Product $product, ProductAttribute $attribute, string $localeCode, array $blueprint, string $identifier): void
    {
        $attributeValue = $product->getAttributeByCodeAndLocale('book_blueprint_json', $localeCode);

        if (!$attributeValue instanceof ProductAttributeValue) {
            $attributeValue = new ProductAttributeValue();
            $attributeValue->setAttribute($attribute);
            $attributeValue->setLocaleCode($localeCode);
            $product->addAttribute($attributeValue);
            $this->entityManager->persist($attributeValue);
        }

        $encodedBlueprint = $this->encodeBlueprint($identifier, $blueprint);
        if ($attributeValue->getValue() !== $encodedBlueprint) {
            $attributeValue->setValue($encodedBlueprint);
        }
    }

    /**
     * @param array<string, mixed> $blueprint
     */
    private function encodeBlueprint(string $identifier, array $blueprint): string
    {
        $normalizedBlueprint = json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $normalizedBlueprint) {
            throw new \RuntimeException(sprintf('Blueprint encoding failed for "%s".', $identifier));
        }

        return $normalizedBlueprint;
    }

    /**
     * @param array<string, mixed> $blueprint
     * @return array<string, mixed>
     */
    private function normalizeLegacyBlueprint(string $slug, array $blueprint, Filesystem $filesystem): array
    {
        foreach ($blueprint['pages'] as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageId = (string) ($page['id'] ?? '');

            if ('dedication' === $pageId) {
                $blueprint['pages'][$index]['default_image_path'] = sprintf('/uploads/books/%s/dedication-default.svg', $slug);
            }

            if ('summary' === $pageId) {
                $blueprint['pages'][$index]['default_image_path'] = sprintf('/uploads/books/%s/summary-default.svg', $slug);
            }

            $defaultImagePath = (string) ($blueprint['pages'][$index]['default_image_path'] ?? '');
            if ('' === $defaultImagePath) {
                continue;
            }

            $targetPath = $this->projectDir.'/public'.$defaultImagePath;
            if ($filesystem->exists($targetPath)) {
                continue;
            }

            $filesystem->mkdir(dirname($targetPath), 0775);
            $filesystem->dumpFile($targetPath, $this->renderDefaultPageSvg($slug, $page));
        }

        return $blueprint;
    }

    /**
     * @param array<string, mixed> $page
     */
    private function renderDefaultPageSvg(string $slug, array $page): string
    {
        $pageId = (string) ($page['id'] ?? 'page');
        $pageType = (string) ($page['type'] ?? 'story');
        $title = (string) ($page['title_template'] ?? ucfirst($pageId));
        $text = (string) ($page['text_template'] ?? '');
        $prompt = (string) ($page['prompt_template'] ?? '');
        $accent = match ($slug) {
            'aventure-enchantee' => '#F4A261',
            'voyage-des-etoiles' => '#457B9D',
            'foret-des-merveilles' => '#84A59D',
            'forest-of-lost-stars' => '#6B5B95',
            'super-heros-du-quotidien' => '#E76F51',
            'douce-nuit-etoilee' => '#6C63FF',
            'ville-ecole' => '#F4A261',
            'espace-robot' => '#1D3461',
            default => '#7A3E2B',
        };

        $safeTitle = htmlspecialchars($title !== '' ? $title : ucfirst($pageId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeType = htmlspecialchars(strtoupper($pageType), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safePrompt = htmlspecialchars($prompt !== '' ? $prompt : 'Default illustration asset', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeText = htmlspecialchars($text !== '' ? $text : 'Texte admin par defaut', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="768" height="1024" viewBox="0 0 768 1024" role="img" aria-label="{$safeTitle}">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$accent}" />
      <stop offset="100%" stop-color="#F8F5EF" />
    </linearGradient>
  </defs>
  <rect width="768" height="1024" fill="url(#bg)" />
  <rect x="48" y="48" width="672" height="928" rx="40" ry="40" fill="#FFFDF9" opacity="0.95" />
  <rect x="88" y="108" width="592" height="360" rx="28" ry="28" fill="#FFFFFF" opacity="0.85" />
  <rect x="88" y="516" width="592" height="340" rx="28" ry="28" fill="#FFFFFF" opacity="0.92" />
  <text x="112" y="170" fill="#7A3E2B" font-family="Georgia, serif" font-size="28" font-weight="700">Mon Livre Magique</text>
  <text x="112" y="228" fill="#111827" font-family="Georgia, serif" font-size="42" font-weight="700">{$safeTitle}</text>
  <text x="112" y="284" fill="#6B7280" font-family="Arial, sans-serif" font-size="22">{$safeType}</text>
  <text x="112" y="360" fill="#374151" font-family="Arial, sans-serif" font-size="24">Asset admin par defaut issu du blueprint</text>
  <text x="112" y="416" fill="#6B7280" font-family="Arial, sans-serif" font-size="18">{$safePrompt}</text>
  <text x="112" y="588" fill="#111827" font-family="Arial, sans-serif" font-size="24" font-weight="700">Texte template</text>
  <text x="112" y="640" fill="#374151" font-family="Arial, sans-serif" font-size="24">{$safeText}</text>
  <text x="112" y="906" fill="#6B7280" font-family="Arial, sans-serif" font-size="18">Produit Sylius pilote par book_blueprint_json</text>
</svg>
SVG;
    }
}

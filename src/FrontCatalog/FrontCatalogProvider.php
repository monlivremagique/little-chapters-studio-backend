<?php

declare(strict_types=1);

namespace App\FrontCatalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FrontCatalogProvider
{
    private const CHANNEL_CODE = 'MLM_BE_FR';
    private const LOCALE_CODE = 'fr_FR';
    private const CATALOGUE_ROOT_CODE = 'CATALOGUE_LIVRES';

    /** Code → theme slug mapping for known collections */
    private const CODE_TO_THEME = [
        'AVENTURES_MAGIQUES'  => 'aventure',
        'HISTOIRES_DU_SOIR'   => 'douceur',
        'AMIS_ANIMAUX'        => 'animaux',
        'FETES_CELEBRATIONS'  => 'anniversaire',
        'HEROS_DU_QUOTIDIEN'  => 'heros',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly FrontCatalogMetadata $metadata,
        private readonly UrlHelper $urlHelper,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getBooks(?string $locale = null): array
    {
        $locale = $this->resolveLocale($locale);
        $metadataBySlug = $this->metadata->books();
        $productRows = $this->fetchAllCatalogProducts($locale);
        $syliusIds = array_column($productRows, 'sylius_id');
        $attributesByProductId = $this->fetchAttributesByProductIds($syliusIds, $locale);
        $availableLocalesByProductId = $this->fetchAvailableLocalesByProductIds($syliusIds);
        $books = [];

        foreach ($productRows as $slug => $row) {
            $attributes = $attributesByProductId[$row['sylius_id']] ?? [];
            if (null === $this->decodeBookBlueprint($attributes['book_blueprint_json'] ?? null)) {
                continue;
            }
            $metadata = $metadataBySlug[$slug] ?? $this->buildDefaultBookMetadata($row, $attributes);
            $card = $this->buildBookCard($row, $attributes, $metadata);
            $card['availableLocales'] = $availableLocalesByProductId[$row['sylius_id']] ?? ['fr'];
            $books[] = $card;
        }

        return $books;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBookBySlug(string $slug, ?string $locale = null): array
    {
        $locale = $this->resolveLocale($locale);
        $metadata = $this->metadata->books()[$slug] ?? null;
        $productRows = $this->fetchProductsBySlugs([$slug], $locale);
        $row = $productRows[$slug] ?? null;

        if (null === $row) {
            throw new NotFoundHttpException(sprintf('Book with slug "%s" was not found.', $slug));
        }

        $attributesByProductId = $this->fetchAttributesByProductIds([$row['sylius_id']], $locale);
        $attributes = $attributesByProductId[$row['sylius_id']] ?? [];
        $metadata ??= $this->buildDefaultBookMetadata($row, $attributes);
        $availableLocales = $this->fetchAvailableLocalesByProductIds([$row['sylius_id']]);
        $book = $this->buildBookCard($row, $attributes, $metadata);
        $book['availableLocales'] = $availableLocales[$row['sylius_id']] ?? ['fr'];
        $bookBlueprint = $this->decodeBookBlueprint($attributes['book_blueprint_json'] ?? null);
        if (null === $bookBlueprint) {
            throw new NotFoundHttpException(sprintf('Book "%s" has no blueprint for requested locale.', $slug));
        }

        // Prefer translated Sylius attributes; metadata is an optional override, not a visibility gate.
        $description = $this->resolveText($attributes['book_description'] ?? null, $metadata['description'] ?? '');
        $longDescription = $this->resolveText($attributes['book_long_description'] ?? null, $metadata['longDescription'] ?? '');
        $emotionalPromise = $this->resolveText($attributes['book_emotional_promise'] ?? null, $metadata['emotionalPromise'] ?? '');
        $features = $this->resolveFeatures($attributes['book_features'] ?? null, $metadata['features'] ?? []);

        return array_merge($book, [
            'description' => $description,
            'longDescription' => $longDescription,
            'emotionalPromise' => $emotionalPromise,
            'features' => $features,
            'pages' => (int) ($attributes['book_pages'] ?? 0),
            'format' => (string) ($attributes['book_format'] ?? '21 x 21 cm'),
            'coverType' => $this->normalizeCoverType($attributes['book_cover_type'] ?? null),
            'bookBlueprint' => $bookBlueprint,
            'relatedBooks' => $metadata['relatedBooks'],
            'reviews' => $this->resolveReviews($attributes['book_reviews_json'] ?? null, $metadata['reviews'] ?? []),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCollections(?string $locale = null): array
    {
        $locale = $this->resolveLocale($locale);
        $collectionsMetadata = $this->metadata->collections();
        $taxonRows = $this->fetchAllActiveTaxons($locale);
        $bookCards = $this->getBooks($locale);
        $booksById = [];

        foreach ($bookCards as $bookCard) {
            $booksById[$bookCard['id']] = $bookCard;
        }

        $productIdsByTaxonId = $this->fetchBookIdsByTaxonIds(array_column($taxonRows, 'sylius_id'), $locale);
        $collections = [];

        foreach ($taxonRows as $slug => $row) {
            $meta = $collectionsMetadata[$slug] ?? $this->buildDefaultMetadata($row);
            $collections[] = $this->buildCollection(
                $row,
                $meta,
                $productIdsByTaxonId[$row['sylius_id']] ?? [],
                $booksById,
            );
        }

        return $collections;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCollectionBySlug(string $slug, ?string $locale = null): array
    {
        $locale = $this->resolveLocale($locale);
        $metadata = $this->metadata->collections()[$slug] ?? null;
        $taxonRows = $this->fetchTaxonsBySlugs([$slug], $locale);
        $row = $taxonRows[$slug] ?? null;

        if (null === $metadata || null === $row) {
            throw new NotFoundHttpException(sprintf('Collection with slug "%s" was not found.', $slug));
        }

        $bookCards = $this->getBooks($locale);
        $booksById = [];

        foreach ($bookCards as $bookCard) {
            $booksById[$bookCard['id']] = $bookCard;
        }

        $productIdsByTaxonId = $this->fetchBookIdsByTaxonIds([$row['sylius_id']], $locale);

        return $this->buildCollection(
            $row,
            $metadata,
            $productIdsByTaxonId[$row['sylius_id']] ?? [],
            $booksById,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function buildBookCard(array $row, array $attributes, array $metadata): array
    {
        $badge = (string) ($attributes['book_badge'] ?? '');
        $bookBlueprint = $this->decodeBookBlueprint($attributes['book_blueprint_json'] ?? null);
        $coverImage = '';

        if (is_array($bookBlueprint) && is_array($bookBlueprint['pages'] ?? null)) {
            foreach ($bookBlueprint['pages'] as $page) {
                if (!is_array($page) || (string) ($page['id'] ?? '') !== 'cover') {
                    continue;
                }

                $coverImage = $this->resolveBlueprintImageUrl($page['default_image_path'] ?? null);
                break;
            }
        }

        if ('' === $coverImage) {
            $coverImage = $this->absoluteMediaUrl($row['image_path']);
        }

        return [
            'id' => $metadata['id'],
            'slug' => $row['slug'],
            'title' => $row['title'],
            'subtitle' => (string) ($attributes['book_subtitle'] ?? $row['short_description'] ?? ''),
            'coverImage' => $coverImage,
            'price' => $this->minorToFloat($row['price']),
            'originalPrice' => $this->minorToFloat($row['original_price']),
            'rating' => $metadata['rating'],
            'reviewCount' => $metadata['reviewCount'],
            'ageMin' => (int) ($attributes['book_age_min'] ?? 0),
            'ageMax' => (int) ($attributes['book_age_max'] ?? 0),
            'theme' => (string) ($attributes['book_theme'] ?? 'aventure'),
            'occasion' => $metadata['occasion'],
            'badge' => '' !== $badge ? $badge : null,
            'isNew' => $metadata['isNew'],
            'isBestseller' => $metadata['isBestseller'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $metadata
     * @param list<string> $bookIds
     * @param array<string, array<string, mixed>> $booksById
     *
     * @return array<string, mixed>
     */
    private function buildCollection(array $row, array $metadata, array $bookIds, array $booksById): array
    {
        // Priority: 1. Taxon image from Sylius admin, 2. First book cover, 3. Any book cover
        $coverImage = (string) ($row['taxon_image_url'] ?? '');

        if ('' === $coverImage) {
            foreach ($bookIds as $bookId) {
                if (isset($booksById[$bookId])) {
                    $coverImage = $booksById[$bookId]['coverImage'];
                    break;
                }
            }
        }

        if ('' === $coverImage) {
            $firstBook = reset($booksById);
            $coverImage = \is_array($firstBook) ? (string) ($firstBook['coverImage'] ?? '') : '';
        }

        // Use DB description as fallback when metadata description is empty
        $description = '' !== trim((string) ($metadata['description'] ?? ''))
            ? $metadata['description']
            : (string) ($row['description'] ?? '');

        return [
            'id'          => $metadata['id'],
            'slug'        => $row['slug'],
            'title'       => $row['title'],
            'subtitle'    => $metadata['subtitle'] ?? '',
            'description' => $description,
            'coverImage'  => $coverImage,
            'imageUrl'    => (string) ($row['taxon_image_url'] ?? ''),
            'bookIds'     => $bookIds,
            'theme'       => $metadata['theme'],
            'position'    => (int) ($row['position'] ?? 0),
        ];
    }

    /**
     * @param list<string> $slugs
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchProductsBySlugs(array $slugs, string $locale): array
    {
        if ([] === $slugs) {
            return [];
        }

        // Slugs are identical across all locales (stable URL identifiers).
        // We look up by fr_FR slugs first; if the requested locale has no translation, fall back.
        $sql = <<<'SQL'
SELECT
    p.id AS sylius_id,
    p.code,
    COALESCE(pt_req.slug, pt_fb.slug) AS slug,
    COALESCE(pt_req.name, pt_fb.name) AS title,
    COALESCE(pt_req.short_description, pt_fb.short_description) AS short_description,
    img.path AS image_path,
    v.code AS variant_code,
    cp.price,
    cp.original_price
FROM sylius_product p
INNER JOIN sylius_product_translation pt_fb
    ON pt_fb.translatable_id = p.id
    AND pt_fb.locale = :fallbackLocale
LEFT JOIN sylius_product_translation pt_req
    ON pt_req.translatable_id = p.id
    AND pt_req.locale = :locale
INNER JOIN sylius_product_channels pc
    ON pc.product_id = p.id
INNER JOIN sylius_channel ch
    ON ch.id = pc.channel_id
    AND ch.code = :channelCode
LEFT JOIN LATERAL (
    SELECT path
    FROM sylius_product_image
    WHERE owner_id = p.id
    ORDER BY position ASC, id ASC
    LIMIT 1
) img ON TRUE
LEFT JOIN LATERAL (
    SELECT id, code
    FROM sylius_product_variant
    WHERE product_id = p.id
    ORDER BY position ASC, id ASC
    LIMIT 1
) v ON TRUE
LEFT JOIN sylius_channel_pricing cp
    ON cp.product_variant_id = v.id
    AND cp.channel_code = :channelCode
WHERE p.enabled = TRUE
  AND pt_fb.slug IN (:slugs)
SQL;

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            [
                'locale' => $locale,
                'fallbackLocale' => self::LOCALE_CODE,
                'channelCode' => self::CHANNEL_CODE,
                'slugs' => $slugs,
            ],
            [
                'slugs' => ArrayParameterType::STRING,
            ],
        );

        $indexedRows = [];

        foreach ($rows as $row) {
            $row['sylius_id'] = (int) $row['sylius_id'];
            $row['price'] = null !== $row['price'] ? (int) $row['price'] : null;
            $row['original_price'] = null !== $row['original_price'] ? (int) $row['original_price'] : null;
            $indexedRows[(string) $row['slug']] = $row;
        }

        return $indexedRows;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchAllCatalogProducts(string $locale): array
    {
        $sql = <<<'SQL'
SELECT
    p.id AS sylius_id,
    p.code,
    COALESCE(pt_req.slug, pt_fb.slug) AS slug,
    COALESCE(pt_req.name, pt_fb.name) AS title,
    COALESCE(pt_req.short_description, pt_fb.short_description) AS short_description,
    COALESCE(pt_req.description, pt_fb.description) AS description,
    img.path AS image_path,
    v.code AS variant_code,
    cp.price,
    cp.original_price
FROM sylius_product p
INNER JOIN sylius_product_translation pt_fb
    ON pt_fb.translatable_id = p.id
    AND pt_fb.locale = :fallbackLocale
LEFT JOIN sylius_product_translation pt_req
    ON pt_req.translatable_id = p.id
    AND pt_req.locale = :locale
INNER JOIN sylius_product_channels pc
    ON pc.product_id = p.id
INNER JOIN sylius_channel ch
    ON ch.id = pc.channel_id
    AND ch.code = :channelCode
LEFT JOIN LATERAL (
    SELECT path
    FROM sylius_product_image
    WHERE owner_id = p.id
    ORDER BY position ASC, id ASC
    LIMIT 1
) img ON TRUE
LEFT JOIN LATERAL (
    SELECT id, code
    FROM sylius_product_variant
    WHERE product_id = p.id
    ORDER BY position ASC, id ASC
    LIMIT 1
) v ON TRUE
LEFT JOIN sylius_channel_pricing cp
    ON cp.product_variant_id = v.id
    AND cp.channel_code = :channelCode
WHERE p.enabled = TRUE
ORDER BY p.id ASC
SQL;

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            [
                'locale' => $locale,
                'fallbackLocale' => self::LOCALE_CODE,
                'channelCode' => self::CHANNEL_CODE,
            ],
        );

        $indexedRows = [];

        foreach ($rows as $row) {
            $row['sylius_id'] = (int) $row['sylius_id'];
            $row['price'] = null !== $row['price'] ? (int) $row['price'] : null;
            $row['original_price'] = null !== $row['original_price'] ? (int) $row['original_price'] : null;
            $indexedRows[(string) $row['slug']] = $row;
        }

        return $indexedRows;
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, array<string, scalar|null>>
     */
    private function fetchAttributesByProductIds(array $productIds, string $locale): array
    {
        if ([] === $productIds) {
            return [];
        }

        // Fetch attributes for the requested locale AND the fallback locale in a single query.
        // In PHP we prefer the requested locale value; fall back to fr_FR when absent.
        // Non-localised attributes (locale_code IS NULL) are always included.
        $sql = <<<'SQL'
SELECT
    pav.product_id,
    pa.code,
    pav.locale_code,
    pav.text_value,
    pav.integer_value,
    pav.boolean_value,
    pav.float_value
FROM sylius_product_attribute_value pav
INNER JOIN sylius_product_attribute pa ON pa.id = pav.attribute_id
WHERE pav.product_id IN (:productIds)
  AND (pav.locale_code = :locale OR pav.locale_code = :fallbackLocale OR pav.locale_code IS NULL)
SQL;

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            [
                'productIds' => $productIds,
                'locale' => $locale,
                'fallbackLocale' => self::LOCALE_CODE,
            ],
            [
                'productIds' => ArrayParameterType::INTEGER,
            ],
        );

        // Priority: requested locale > fallback locale > no locale (NULL).
        // We accumulate all rows first, then overwrite with higher-priority values.
        $attributesByProductId = [];

        // Pass 1: nulls (locale-agnostic attributes)
        foreach ($rows as $row) {
            if (null !== $row['locale_code']) {
                continue;
            }
            $productId = (int) $row['product_id'];
            $attributesByProductId[$productId][(string) $row['code']] =
                $row['text_value'] ?? $row['integer_value'] ?? $row['boolean_value'] ?? $row['float_value'];
        }

        // Pass 2: fallback locale
        foreach ($rows as $row) {
            if ($row['locale_code'] !== self::LOCALE_CODE) {
                continue;
            }
            if ('book_blueprint_json' === (string) $row['code'] && $locale !== self::LOCALE_CODE) {
                continue;
            }
            $productId = (int) $row['product_id'];
            $attributesByProductId[$productId][(string) $row['code']] =
                $row['text_value'] ?? $row['integer_value'] ?? $row['boolean_value'] ?? $row['float_value'];
        }

        // Pass 3: requested locale (wins over all)
        foreach ($rows as $row) {
            if ($row['locale_code'] !== $locale) {
                continue;
            }
            $productId = (int) $row['product_id'];
            $attributesByProductId[$productId][(string) $row['code']] =
                $row['text_value'] ?? $row['integer_value'] ?? $row['boolean_value'] ?? $row['float_value'];
        }

        return $attributesByProductId;
    }

    /**
     * @param list<string> $slugs
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchTaxonsBySlugs(array $slugs, string $locale): array
    {
        if ([] === $slugs) {
            return [];
        }

        // Slugs are identical across all locales. Look up by fr_FR slug (stable identifier),
        // prefer requested locale name/description, fall back to fr_FR.
        $sql = <<<'SQL'
SELECT
    t.id AS sylius_id,
    t.code,
    tt_fb.slug,
    COALESCE(tt_req.name, tt_fb.name) AS title,
    COALESCE(tt_req.description, tt_fb.description) AS description
FROM sylius_taxon t
INNER JOIN sylius_taxon_translation tt_fb
    ON tt_fb.translatable_id = t.id
    AND tt_fb.locale = :fallbackLocale
LEFT JOIN sylius_taxon_translation tt_req
    ON tt_req.translatable_id = t.id
    AND tt_req.locale = :locale
WHERE tt_fb.slug IN (:slugs)
SQL;

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            [
                'locale' => $locale,
                'fallbackLocale' => self::LOCALE_CODE,
                'slugs' => $slugs,
            ],
            [
                'slugs' => ArrayParameterType::STRING,
            ],
        );

        $indexedRows = [];

        foreach ($rows as $row) {
            $row['sylius_id'] = (int) $row['sylius_id'];
            $indexedRows[(string) $row['slug']] = $row;
        }

        return $indexedRows;
    }

    /**
     * Fetch ALL enabled child taxons of CATALOGUE_LIVRES, ordered by admin position.
     * Includes taxon image URL if one is configured in Sylius admin.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchAllActiveTaxons(string $locale): array
    {
        $sql = <<<'SQL'
SELECT
    t.id AS sylius_id,
    t.code,
    t.position,
    tt.slug,
    tt.name AS title,
    tt.description,
    ti.path AS taxon_image_path
FROM sylius_taxon t
INNER JOIN sylius_taxon_translation tt
    ON tt.translatable_id = t.id
    AND tt.locale = :locale
INNER JOIN sylius_taxon parent
    ON parent.id = t.parent_id
    AND parent.code = :rootCode
LEFT JOIN LATERAL (
    SELECT sti.path
    FROM sylius_taxon_image sti
    WHERE sti.owner_id = t.id
    ORDER BY sti.id ASC
    LIMIT 1
) ti ON TRUE
WHERE t.enabled = TRUE
ORDER BY t.position ASC, t.id ASC
SQL;

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            ['locale' => $locale, 'rootCode' => self::CATALOGUE_ROOT_CODE],
        );

        // Fallback: if no rows found with requested locale, retry with default
        if ([] === $rows && $locale !== self::LOCALE_CODE) {
            $rows = $this->connection->fetchAllAssociative(
                $sql,
                ['locale' => self::LOCALE_CODE, 'rootCode' => self::CATALOGUE_ROOT_CODE],
            );
        }

        $indexedRows = [];
        foreach ($rows as $row) {
            $row['sylius_id'] = (int) $row['sylius_id'];
            $row['taxon_image_url'] = $this->absoluteMediaUrl($row['taxon_image_path'] ?? null);
            $indexedRows[(string) $row['slug']] = $row;
        }

        return $indexedRows;
    }

    /**
     * Build default metadata for a taxon not listed in FrontCatalogMetadata.
     * New collections added in Sylius admin appear automatically with these defaults.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildDefaultMetadata(array $row): array
    {
        $code = strtoupper((string) ($row['code'] ?? ''));
        $theme = self::CODE_TO_THEME[$code] ?? $this->deriveThemeFromSlug((string) ($row['slug'] ?? ''));
        static $counter = 100;

        return [
            'id'       => 'c-' . ($row['slug'] ?? ++$counter),
            'subtitle' => '',
            'description' => (string) ($row['description'] ?? ''),
            'theme'    => $theme,
        ];
    }

    /** Derive a theme key from slug when the taxon isn't in the metadata map. */
    private function deriveThemeFromSlug(string $slug): string
    {
        foreach (self::CODE_TO_THEME as $code => $theme) {
            $codeSlug = strtolower(str_replace('_', '-', $code));
            if (str_contains($slug, explode('-', $codeSlug)[0])) {
                return $theme;
            }
        }

        return 'aventure'; // generic fallback
    }

    private function resolveLocale(?string $locale): string
    {
        if (null === $locale || '' === $locale) {
            return self::LOCALE_CODE;
        }

        $normalizedLocale = str_replace('-', '_', trim($locale));

        return match ($normalizedLocale) {
            'fr', 'fr_FR' => 'fr_FR',
            'en', 'en_US' => 'en_US',
            'nl', 'nl_NL' => 'nl_NL',
            default => throw new BadRequestHttpException(sprintf('Unsupported locale "%s". Expected fr, en or nl.', $locale)),
        };
    }

    /**
     * @param list<int> $taxonIds
     *
     * @return array<int, list<string>>
     */
    private function fetchBookIdsByTaxonIds(array $taxonIds, string $locale): array
    {
        if ([] === $taxonIds) {
            return [];
        }

        // Slugs are the stable product identifiers regardless of locale.
        // Always use fr_FR slugs to look up FrontCatalogMetadata book IDs.
        $sql = <<<'SQL'
SELECT
    ptax.taxon_id,
    ptt.slug
FROM sylius_product_taxon ptax
INNER JOIN sylius_product p ON p.id = ptax.product_id AND p.enabled = TRUE
INNER JOIN sylius_product_channels pc ON pc.product_id = p.id
INNER JOIN sylius_channel ch ON ch.id = pc.channel_id AND ch.code = :channelCode
INNER JOIN sylius_product_translation ptt ON ptt.translatable_id = p.id AND ptt.locale = :fallbackLocale
WHERE ptax.taxon_id IN (:taxonIds)
ORDER BY ptax.position ASC, p.id ASC
SQL;

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            [
                'channelCode' => self::CHANNEL_CODE,
                'fallbackLocale' => self::LOCALE_CODE,
                'taxonIds' => $taxonIds,
            ],
            [
                'taxonIds' => ArrayParameterType::INTEGER,
            ],
        );

        $bookIdsByTaxonId = [];
        $bookMetadataBySlug = $this->metadata->books();

        foreach ($rows as $row) {
            $taxonId = (int) $row['taxon_id'];
            $slug = (string) $row['slug'];
            $bookIdsByTaxonId[$taxonId][] = (string) ($bookMetadataBySlug[$slug]['id'] ?? $slug);
        }

        foreach ($bookIdsByTaxonId as $taxonId => $bookIds) {
            $bookIdsByTaxonId[$taxonId] = array_values(array_unique($bookIds));
        }

        return $bookIdsByTaxonId;
    }

    /**
     * @param list<string> $reviewIds
     *
     * @return list<array<string, mixed>>
     */
    private function buildReviews(array $reviewIds): array
    {
        $reviewRegistry = $this->metadata->reviews();
        $reviews = [];

        foreach ($reviewIds as $reviewId) {
            if (isset($reviewRegistry[$reviewId])) {
                $reviews[] = $reviewRegistry[$reviewId];
            }
        }

        return $reviews;
    }

    private function absoluteMediaUrl(?string $path): string
    {
        if (null === $path || '' === $path) {
            return '';
        }

        return $this->urlHelper->getAbsoluteUrl('/media/image/' . ltrim($path, '/'));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function buildDefaultBookMetadata(array $row, array $attributes): array
    {
        $slug = (string) ($row['slug'] ?? $row['code'] ?? 'book');
        $bookBlueprint = $this->decodeBookBlueprint($attributes['book_blueprint_json'] ?? null);
        $blueprintMetadata = is_array($bookBlueprint['metadata'] ?? null) ? $bookBlueprint['metadata'] : [];

        return [
            'id' => (string) ($blueprintMetadata['bookId'] ?? $blueprintMetadata['id'] ?? $slug),
            'rating' => 0,
            'reviewCount' => 0,
            'occasion' => [],
            'isBestseller' => false,
            'isNew' => false,
            'description' => (string) ($row['short_description'] ?? ''),
            'longDescription' => (string) ($row['description'] ?? $row['short_description'] ?? ''),
            'emotionalPromise' => '',
            'features' => [],
            'relatedBooks' => [],
            'reviews' => [],
        ];
    }

    // Returns a locale-neutral code. Front translates via product.coverType.{code}
    private function normalizeCoverType(mixed $coverType): string
    {
        return match ((string) $coverType) {
            'souple' => 'softcover',
            'rigide', '' => 'hardcover',
            default => strtolower(trim((string) $coverType)),
        };
    }

    private function minorToFloat(mixed $amount): ?float
    {
        if (null === $amount || '' === $amount) {
            return null;
        }

        return round(((int) $amount) / 100, 2);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeBookBlueprint(mixed $rawBlueprint): ?array
    {
        if (!is_string($rawBlueprint) || '' === trim($rawBlueprint)) {
            return null;
        }

        $decoded = json_decode($rawBlueprint, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function resolveBlueprintImageUrl(mixed $path): string
    {
        if (!is_string($path) || '' === trim($path)) {
            return '';
        }

        return $this->urlHelper->getAbsoluteUrl($path);
    }

    /**
     * Returns the list of short locale codes (fr/en/nl) for which a book has a blueprint.
     * Used to populate availableLocales in the API response.
     *
     * @param list<int> $productIds
     * @return array<int, list<string>>
     */
    private function fetchAvailableLocalesByProductIds(array $productIds): array
    {
        if ([] === $productIds) {
            return [];
        }

        $sql = <<<'SQL'
SELECT pav.product_id, pav.locale_code
FROM sylius_product_attribute_value pav
INNER JOIN sylius_product_attribute pa ON pa.id = pav.attribute_id
WHERE pa.code = 'book_blueprint_json'
  AND pav.product_id IN (:productIds)
  AND pav.text_value IS NOT NULL
  AND pav.text_value != ''
SQL;

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            ['productIds' => $productIds],
            ['productIds' => ArrayParameterType::INTEGER],
        );

        // Map Sylius locale codes (fr_FR, en_US, nl_NL) to short codes (fr, en, nl)
        $localeMap = ['fr_FR' => 'fr', 'en_US' => 'en', 'nl_NL' => 'nl'];
        $result = [];

        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $short = $localeMap[$row['locale_code']] ?? null;
            if (null !== $short) {
                $result[$productId][] = $short;
            }
        }

        // Ensure 'fr' is always first if present
        foreach ($result as $productId => $locales) {
            $ordered = array_values(array_unique($locales));
            usort($ordered, static function (string $left, string $right): int {
                $priority = ['fr' => 0, 'en' => 1, 'nl' => 2];

                return ($priority[$left] ?? 99) <=> ($priority[$right] ?? 99);
            });
            $result[$productId] = $ordered;
        }

        // Default to ['fr'] for products with no blueprint (safety)
        foreach ($productIds as $productId) {
            if (!isset($result[$productId])) {
                $result[$productId] = ['fr'];
            }
        }

        return $result;
    }

    /**
     * Decode a JSON review array from the book_reviews_json attribute.
     * Falls back to the metadata-based review lookup when the attribute is absent.
     *
     * @param list<string> $reviewIds
     * @return list<array<string, mixed>>
     */
    private function resolveReviews(mixed $attrJson, array $reviewIds): array
    {
        if (is_string($attrJson) && '' !== trim($attrJson)) {
            $decoded = json_decode($attrJson, true);
            if (is_array($decoded)) {
                return array_values($decoded);
            }
        }

        // Fallback: resolve review IDs through the metadata registry (always FR).
        return $this->buildReviews($reviewIds);
    }

    /** Return attribute value if non-empty, otherwise the metadata fallback. */
    private function resolveText(mixed $attrValue, string $fallback): string
    {
        $val = trim((string) ($attrValue ?? ''));

        return '' !== $val ? $val : $fallback;
    }

    /**
     * Decode a JSON features array from the attribute; fall back to the metadata array.
     *
     * @param list<string> $fallback
     * @return list<string>
     */
    private function resolveFeatures(mixed $attrValue, array $fallback): array
    {
        if (!is_string($attrValue) || '' === trim($attrValue)) {
            return $fallback;
        }

        $decoded = json_decode($attrValue, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : $fallback;
    }
}

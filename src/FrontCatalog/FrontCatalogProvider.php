<?php

declare(strict_types=1);

namespace App\FrontCatalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FrontCatalogProvider
{
    private const CHANNEL_CODE = 'LITTLE_CHAPTERS_BE_FR';
    private const LOCALE_CODE = 'fr_FR';

    public function __construct(
        private readonly Connection $connection,
        private readonly FrontCatalogMetadata $metadata,
        private readonly UrlHelper $urlHelper,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getBooks(): array
    {
        $metadataBySlug = $this->metadata->books();
        $productRows = $this->fetchProductsBySlugs(array_keys($metadataBySlug));
        $attributesByProductId = $this->fetchAttributesByProductIds(array_column($productRows, 'sylius_id'));
        $books = [];

        foreach (array_keys($metadataBySlug) as $slug) {
            if (!isset($productRows[$slug])) {
                continue;
            }

            $row = $productRows[$slug];
            $books[] = $this->buildBookCard($row, $attributesByProductId[$row['sylius_id']] ?? [], $metadataBySlug[$slug]);
        }

        return $books;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBookBySlug(string $slug): array
    {
        $metadata = $this->metadata->books()[$slug] ?? null;
        $productRows = $this->fetchProductsBySlugs([$slug]);
        $row = $productRows[$slug] ?? null;

        if (null === $metadata || null === $row) {
            throw new NotFoundHttpException(sprintf('Book with slug "%s" was not found.', $slug));
        }

        $attributesByProductId = $this->fetchAttributesByProductIds([$row['sylius_id']]);
        $book = $this->buildBookCard($row, $attributesByProductId[$row['sylius_id']] ?? [], $metadata);
        $attributes = $attributesByProductId[$row['sylius_id']] ?? [];
        $bookBlueprint = $this->decodeBookBlueprint($attributes['book_blueprint_json'] ?? null);
        $galleryImages = $this->buildGalleryImages($row['image_path'], $bookBlueprint);

        return array_merge($book, [
            'description' => $metadata['description'],
            'longDescription' => $metadata['longDescription'],
            'emotionalPromise' => $metadata['emotionalPromise'],
            'features' => $metadata['features'],
            'pages' => (int) ($attributes['book_pages'] ?? 0),
            'format' => (string) ($attributes['book_format'] ?? '21 x 21 cm'),
            'coverType' => $this->normalizeCoverType($attributes['book_cover_type'] ?? null),
            'printQuality' => $metadata['printQuality'],
            'galleryImages' => $galleryImages,
            'previewPages' => $this->buildPreviewPages($galleryImages[0], $bookBlueprint),
            'bookBlueprint' => $bookBlueprint,
            'relatedBooks' => $metadata['relatedBooks'],
            'reviews' => $this->buildReviews($metadata['reviews']),
            'faq' => $this->metadata->faq(),
            'personalizationFields' => $this->metadata->personalizationFields(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCollections(): array
    {
        $collectionsMetadata = $this->metadata->collections();
        $taxonRows = $this->fetchTaxonsBySlugs(array_keys($collectionsMetadata));
        $bookCards = $this->getBooks();
        $booksById = [];

        foreach ($bookCards as $bookCard) {
            $booksById[$bookCard['id']] = $bookCard;
        }

        $productIdsByTaxonId = $this->fetchBookIdsByTaxonIds(array_column($taxonRows, 'sylius_id'));
        $collections = [];

        foreach (array_keys($collectionsMetadata) as $slug) {
            if (!isset($taxonRows[$slug])) {
                continue;
            }

            $row = $taxonRows[$slug];
            $collections[] = $this->buildCollection(
                $row,
                $collectionsMetadata[$slug],
                $productIdsByTaxonId[$row['sylius_id']] ?? [],
                $booksById,
            );
        }

        return $collections;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCollectionBySlug(string $slug): array
    {
        $metadata = $this->metadata->collections()[$slug] ?? null;
        $taxonRows = $this->fetchTaxonsBySlugs([$slug]);
        $row = $taxonRows[$slug] ?? null;

        if (null === $metadata || null === $row) {
            throw new NotFoundHttpException(sprintf('Collection with slug "%s" was not found.', $slug));
        }

        $bookCards = $this->getBooks();
        $booksById = [];

        foreach ($bookCards as $bookCard) {
            $booksById[$bookCard['id']] = $bookCard;
        }

        $productIdsByTaxonId = $this->fetchBookIdsByTaxonIds([$row['sylius_id']]);

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
            'personalizationLevel' => $this->normalizePersonalizationLevel($attributes['book_personalization_level'] ?? null),
            'language' => $this->normalizeLanguage($attributes['book_language'] ?? null),
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
        $coverImage = '';

        foreach ($bookIds as $bookId) {
            if (isset($booksById[$bookId])) {
                $coverImage = $booksById[$bookId]['coverImage'];
                break;
            }
        }

        if ('' === $coverImage) {
            $firstBook = reset($booksById);
            $coverImage = \is_array($firstBook) ? (string) ($firstBook['coverImage'] ?? '') : '';
        }

        return [
            'id' => $metadata['id'],
            'slug' => $row['slug'],
            'title' => $row['title'],
            'subtitle' => $metadata['subtitle'],
            'description' => $metadata['description'],
            'coverImage' => $coverImage,
            'bookIds' => $bookIds,
            'theme' => $metadata['theme'],
        ];
    }

    /**
     * @param list<string> $slugs
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchProductsBySlugs(array $slugs): array
    {
        if ([] === $slugs) {
            return [];
        }

        $sql = <<<'SQL'
SELECT
    p.id AS sylius_id,
    p.code,
    pt.slug,
    pt.name AS title,
    pt.short_description,
    img.path AS image_path,
    v.code AS variant_code,
    cp.price,
    cp.original_price
FROM sylius_product p
INNER JOIN sylius_product_translation pt
    ON pt.translatable_id = p.id
    AND pt.locale = :locale
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
  AND pt.slug IN (:slugs)
SQL;

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            [
                'locale' => self::LOCALE_CODE,
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
     * @param list<int> $productIds
     *
     * @return array<int, array<string, scalar|null>>
     */
    private function fetchAttributesByProductIds(array $productIds): array
    {
        if ([] === $productIds) {
            return [];
        }

        $sql = <<<'SQL'
SELECT
    pav.product_id,
    pa.code,
    pav.text_value,
    pav.integer_value,
    pav.boolean_value,
    pav.float_value
FROM sylius_product_attribute_value pav
INNER JOIN sylius_product_attribute pa ON pa.id = pav.attribute_id
WHERE pav.product_id IN (:productIds)
  AND (pav.locale_code = :locale OR pav.locale_code IS NULL)
SQL;

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            [
                'productIds' => $productIds,
                'locale' => self::LOCALE_CODE,
            ],
            [
                'productIds' => ArrayParameterType::INTEGER,
            ],
        );

        $attributesByProductId = [];

        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $attributesByProductId[$productId][(string) $row['code']] = $row['text_value']
                ?? $row['integer_value']
                ?? $row['boolean_value']
                ?? $row['float_value'];
        }

        return $attributesByProductId;
    }

    /**
     * @param list<string> $slugs
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchTaxonsBySlugs(array $slugs): array
    {
        if ([] === $slugs) {
            return [];
        }

        $sql = <<<'SQL'
SELECT
    t.id AS sylius_id,
    t.code,
    tt.slug,
    tt.name AS title,
    tt.description
FROM sylius_taxon t
INNER JOIN sylius_taxon_translation tt
    ON tt.translatable_id = t.id
    AND tt.locale = :locale
WHERE tt.slug IN (:slugs)
SQL;

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            [
                'locale' => self::LOCALE_CODE,
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
     * @param list<int> $taxonIds
     *
     * @return array<int, list<string>>
     */
    private function fetchBookIdsByTaxonIds(array $taxonIds): array
    {
        if ([] === $taxonIds) {
            return [];
        }

        $sql = <<<'SQL'
SELECT
    ptax.taxon_id,
    ptt.slug
FROM sylius_product_taxon ptax
INNER JOIN sylius_product p ON p.id = ptax.product_id AND p.enabled = TRUE
INNER JOIN sylius_product_channels pc ON pc.product_id = p.id
INNER JOIN sylius_channel ch ON ch.id = pc.channel_id AND ch.code = :channelCode
INNER JOIN sylius_product_translation ptt ON ptt.translatable_id = p.id AND ptt.locale = :locale
WHERE ptax.taxon_id IN (:taxonIds)
ORDER BY ptax.position ASC, p.id ASC
SQL;

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            [
                'channelCode' => self::CHANNEL_CODE,
                'locale' => self::LOCALE_CODE,
                'taxonIds' => $taxonIds,
            ],
            [
                'taxonIds' => ArrayParameterType::INTEGER,
            ],
        );

        $bookMetadataBySlug = $this->metadata->books();
        $bookIdsByTaxonId = [];

        foreach ($rows as $row) {
            $taxonId = (int) $row['taxon_id'];
            $slug = (string) $row['slug'];

            if (!isset($bookMetadataBySlug[$slug])) {
                continue;
            }

            $bookIdsByTaxonId[$taxonId][] = $bookMetadataBySlug[$slug]['id'];
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

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPreviewPages(string $imageUrl, ?array $bookBlueprint = null): array
    {
        if (is_array($bookBlueprint) && isset($bookBlueprint['pages']) && is_array($bookBlueprint['pages'])) {
            $pages = [];
            $pageNumber = 1;

            foreach ($bookBlueprint['pages'] as $page) {
                if (!is_array($page)) {
                    continue;
                }

                $pages[] = [
                    'id' => (string) ($page['id'] ?? sprintf('page-%d', $pageNumber)),
                    'type' => (string) ($page['type'] ?? 'story'),
                    'pageNumber' => $pageNumber,
                    'imageUrl' => $this->resolveBlueprintImageUrl($page['default_image_path'] ?? null) ?: $imageUrl,
                    'isPersonalized' => (bool) ($page['personalizable'] ?? false),
                    'label' => $this->resolveBlueprintPageLabel($page),
                    'title' => (string) ($page['title_template'] ?? ''),
                    'text' => (string) ($page['text_template'] ?? ''),
                ];

                ++$pageNumber;
            }

            if ([] !== $pages) {
                return $pages;
            }
        }

        $pages = [];

        foreach ($this->metadata->previewTemplates() as $template) {
            $pages[] = [
                'id' => $template['id'],
                'type' => 'story',
                'pageNumber' => $template['pageNumber'],
                'imageUrl' => $imageUrl,
                'isPersonalized' => $template['isPersonalized'],
                'label' => $template['label'],
                'title' => null,
                'text' => null,
            ];
        }

        return $pages;
    }

    /**
     * @return list<string>
     */
    private function buildGalleryImages(?string $imagePath, ?array $bookBlueprint = null): array
    {
        if (is_array($bookBlueprint) && isset($bookBlueprint['pages']) && is_array($bookBlueprint['pages'])) {
            $images = [];

            foreach ($bookBlueprint['pages'] as $page) {
                if (!is_array($page)) {
                    continue;
                }

                $imageUrl = $this->resolveBlueprintImageUrl($page['default_image_path'] ?? null);

                if ('' !== $imageUrl) {
                    $images[] = $imageUrl;
                }
            }

            $images = array_values(array_unique($images));

            if ([] !== $images) {
                return $images;
            }
        }

        $imageUrl = $this->absoluteMediaUrl($imagePath);
        $images = [$imageUrl];

        while (\count($images) < 3) {
            $images[] = $imageUrl;
        }

        return $images;
    }

    private function absoluteMediaUrl(?string $path): string
    {
        if (null === $path || '' === $path) {
            return '';
        }

        return $this->urlHelper->getAbsoluteUrl('/media/image/' . ltrim($path, '/'));
    }

    private function normalizePersonalizationLevel(mixed $level): string
    {
        return match ((string) $level) {
            'avancee' => 'avancée',
            'simple' => 'simple',
            default => 'premium',
        };
    }

    private function normalizeLanguage(mixed $language): string
    {
        return match ((string) $language) {
            'fr', 'fr_FR', '' => 'Français',
            default => (string) $language,
        };
    }

    private function normalizeCoverType(mixed $coverType): string
    {
        return match ((string) $coverType) {
            'souple' => 'Souple pelliculee mat',
            'rigide', '' => 'Rigide pelliculee mat',
            default => ucfirst((string) $coverType),
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
     * @param array<string, mixed> $page
     */
    private function resolveBlueprintPageLabel(array $page): string
    {
        $title = trim((string) ($page['title_template'] ?? ''));

        if ('' !== $title) {
            return $title;
        }

        return match ((string) ($page['id'] ?? '')) {
            'cover' => 'Couverture',
            'dedication' => 'Dedicace',
            'summary' => 'Resume',
            'backCover' => 'Quatrieme de couverture',
            default => ucfirst((string) ($page['id'] ?? 'Page')),
        };
    }
}

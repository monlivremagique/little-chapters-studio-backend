<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Product\Product;
use App\Entity\Product\ProductAttribute;
use App\Entity\Product\ProductAttributeValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:sync-book-blueprints',
    description: 'Sync local book_blueprint_json files into Sylius products and ensure default assets exist.',
)]
final class SyncBookBlueprintsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $attribute = $this->entityManager->getRepository(ProductAttribute::class)->findOneBy(['code' => 'book_blueprint_json']);

        if (!$attribute instanceof ProductAttribute) {
            $output->writeln('<error>Missing product attribute "book_blueprint_json".</error>');

            return Command::FAILURE;
        }

        $blueprintDirectory = $this->projectDir . '/resources/book-blueprints';
        $files = glob($blueprintDirectory . '/*.json') ?: [];
        sort($files);

        if ([] === $files) {
            $output->writeln('<error>No blueprint JSON files were found.</error>');

            return Command::FAILURE;
        }

        $filesystem = new Filesystem();
        $synced = 0;

        foreach ($files as $filePath) {
            $slug = basename($filePath, '.json');
            $rawBlueprint = (string) file_get_contents($filePath);
            $blueprint = json_decode($rawBlueprint, true);

            if (!is_array($blueprint) || !isset($blueprint['pages']) || !is_array($blueprint['pages'])) {
                throw new \RuntimeException(sprintf('Invalid blueprint JSON for "%s".', $slug));
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

            if (!$product instanceof Product) {
                throw new \RuntimeException(sprintf('No Sylius product found for blueprint slug "%s".', $slug));
            }

            $this->ensureDefaultAssets($slug, $blueprint, $filesystem);
            $normalizedBlueprint = json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (false === $normalizedBlueprint) {
                throw new \RuntimeException(sprintf('Blueprint encoding failed for "%s".', $slug));
            }

            $attributeValue = $product->getAttributeByCodeAndLocale('book_blueprint_json', 'fr_FR');

            if (!$attributeValue instanceof ProductAttributeValue) {
                $attributeValue = new ProductAttributeValue();
                $attributeValue->setAttribute($attribute);
                $attributeValue->setLocaleCode('fr_FR');
                $product->addAttribute($attributeValue);
                $this->entityManager->persist($attributeValue);
            }

            $attributeValue->setValue($normalizedBlueprint);
            $this->entityManager->persist($product);
            ++$synced;
        }

        $this->entityManager->flush();

        $output->writeln(sprintf('<info>Synced %d book blueprint(s) into Sylius products.</info>', $synced));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $blueprint
     */
    private function ensureDefaultAssets(string $slug, array $blueprint, Filesystem $filesystem): void
    {
        $bookDirectory = $this->projectDir . '/public/uploads/books/' . $slug;
        $filesystem->mkdir($bookDirectory, 0775);

        foreach ($blueprint['pages'] as $page) {
            if (!is_array($page)) {
                continue;
            }

            $defaultImagePath = (string) ($page['default_image_path'] ?? '');

            if ('' === $defaultImagePath) {
                continue;
            }

            $targetPath = $this->projectDir . '/public' . $defaultImagePath;

            if ($filesystem->exists($targetPath)) {
                continue;
            }

            $filesystem->mkdir(\dirname($targetPath), 0775);
            $filesystem->dumpFile($targetPath, $this->renderDefaultPageSvg($slug, $page));
        }
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
            'super-heros-du-quotidien' => '#E76F51',
            'douce-nuit-etoilee' => '#6C63FF',
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
  <text x="112" y="170" fill="#7A3E2B" font-family="Georgia, serif" font-size="28" font-weight="700">Little Chapters</text>
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

<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Fulfillment\FulfillmentOrder;
use App\Entity\Personalization\PdfArtifact;
use App\Entity\Personalization\PersonalizationGenerationJob;
use App\Entity\Personalization\PersonalizationOrderItemLink;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\PreviewVersion;
use App\Gelato\GelatoClientInterface;
use App\Gelato\GelatoFulfillmentService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:gelato:submit-validation-order')]
final class SubmitGelatoValidationOrderCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly GelatoFulfillmentService $gelatoFulfillmentService,
        private readonly GelatoClientInterface $gelatoClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('public-base-url', InputArgument::REQUIRED, 'Public HTTPS base URL exposing the backend to Gelato.')
            ->addOption('child-name', null, InputOption::VALUE_REQUIRED, 'Child name used for the validation order.', 'Nora')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Customer email used for the validation order.', 'gelato-validation@example.test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $publicBaseUrl = trim((string) $input->getArgument('public-base-url'));
        $childName = trim((string) $input->getOption('child-name')) ?: 'Nora';
        $email = trim((string) $input->getOption('email')) ?: 'gelato-validation@example.test';

        if (!str_starts_with($publicBaseUrl, 'https://')) {
            $io->error('The public base URL must be HTTPS and publicly reachable by Gelato.');

            return Command::INVALID;
        }

        $session = new PersonalizationSession('b1', sprintf('gelato-validation-%s', bin2hex(random_bytes(4))));
        $session->saveContent($childName, sprintf('Pour %s, validation Gelato.', $childName), [], 3);
        $job = new PersonalizationGenerationJob($session, 'replicate', 1, 'black-forest-labs/flux-2-pro', [
            'validation' => true,
        ]);
        $job->complete('succeeded', ['state' => ['totalPageCount' => 32, 'generatedPageCount' => 32]]);

        $this->entityManager->persist($session);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $previewVersion = new PreviewVersion(
            $session,
            $job,
            1,
            $childName,
            $session->getDedication(),
            $this->buildValidationSnapshotPayload($session),
        );
        $this->entityManager->persist($previewVersion);
        $session->markGenerationRequested();
        $session->markGenerating();
        $session->markPreviewReady();
        $session->approve();
        [$orderId, $orderNumber, $orderTokenValue, $orderItemId, $variantCode] = $this->insertCompletedOrderWithShippingAddress($email);
        $session->markCheckoutCompleted($orderId, $orderNumber);
        $link = new PersonalizationOrderItemLink($session, $orderItemId);
        $link->snapshotOrderItem([
            'order_item_id' => $orderItemId,
            'order_id' => $orderId,
            'order_token_value' => $orderTokenValue,
            'variant_code' => $variantCode,
            'product_name' => "L'Aventure Enchantee",
            'unit_price' => 4990,
            'quantity' => 1,
            'currency_code' => 'EUR',
        ]);
        $this->entityManager->persist($link);
        $this->entityManager->flush();

        $session->markPdfRendering();
        $pdfArtifact = $this->createProviderValidationPdfArtifact($session, $previewVersion);
        $session->markPrintReady();
        $this->entityManager->persist($pdfArtifact);
        $this->entityManager->flush();

        $fulfillmentOrder = $this->gelatoFulfillmentService->submit($session, $pdfArtifact, $publicBaseUrl);
        $this->entityManager->flush();

        if ('submitted' !== $fulfillmentOrder->getStatus() || null === $fulfillmentOrder->getProviderOrderId()) {
            $io->error(sprintf('Gelato submission failed: %s', $fulfillmentOrder->getErrorMessage() ?? 'unknown error'));

            return Command::FAILURE;
        }

        $providerOrder = $this->gelatoClient->getOrder($fulfillmentOrder->getProviderOrderId());

        /** @var FulfillmentOrder $secondSubmit */
        $secondSubmit = $this->gelatoFulfillmentService->submit($session, $pdfArtifact, $publicBaseUrl);

        $io->success('Gelato validation order submitted successfully.');
        $io->definitionList(
            ['orderNumber' => $orderNumber],
            ['sessionId' => $session->getId()],
            ['ownerToken' => $session->getGuestOwnerToken()],
            ['pdfUrl' => rtrim($publicBaseUrl, '/').$pdfArtifact->getPublicPath()],
            ['providerOrderId' => (string) $fulfillmentOrder->getProviderOrderId()],
            ['providerStatus' => (string) ($providerOrder['fulfillmentStatus'] ?? $providerOrder['status'] ?? 'unknown')],
            ['doubleSubmitProviderOrderId' => (string) $secondSubmit->getProviderOrderId()],
        );

        return Command::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function buildValidationSnapshotPayload(PersonalizationSession $session): array
    {
        $pages = [[
            'id' => 'cover',
            'type' => 'cover',
            'pageNumber' => 1,
            'title' => sprintf('%s et l\'aventure enchantée', $session->getChildName() ?? 'Votre enfant'),
            'text' => null,
            'imageUrl' => '/uploads/books/aventure-enchantee/cover-default.svg',
            'isPersonalized' => true,
            'label' => 'Couverture',
        ], [
            'id' => 'dedication',
            'type' => 'dedication',
            'pageNumber' => 2,
            'title' => null,
            'text' => $session->getDedication(),
            'imageUrl' => null,
            'isPersonalized' => false,
            'label' => 'Dedicace',
        ]];

        for ($pageNumber = 3; $pageNumber <= 30; ++$pageNumber) {
            $assetIndex = (($pageNumber - 3) % 4) + 1;
            $pages[] = [
                'id' => sprintf('story_%d', $pageNumber - 2),
                'type' => 'story',
                'pageNumber' => $pageNumber,
                'title' => sprintf('Page %d', $pageNumber - 2),
                'text' => sprintf('%s poursuit son aventure page %d.', $session->getChildName() ?? 'Votre enfant', $pageNumber - 2),
                'imageUrl' => sprintf('/uploads/books/aventure-enchantee/page-%d-default.svg', $assetIndex),
                'isPersonalized' => true,
                'label' => sprintf('Page %d', $pageNumber - 2),
            ];
        }

        $pages[] = [
            'id' => 'summary',
            'type' => 'summary',
            'pageNumber' => 31,
            'title' => null,
            'text' => sprintf('%s referme ce livre avec le sourire.', $session->getChildName() ?? 'Votre enfant'),
            'imageUrl' => null,
            'isPersonalized' => false,
            'label' => 'Resume',
        ];
        $pages[] = [
            'id' => 'backCover',
            'type' => 'backCover',
            'pageNumber' => 32,
            'title' => null,
            'text' => null,
            'imageUrl' => '/uploads/books/aventure-enchantee/back-cover-default.svg',
            'isPersonalized' => true,
            'label' => 'Quatrieme de couverture',
        ];

        return [
            'sessionId' => $session->getId(),
            'bookId' => $session->getBookId(),
            'bookSlug' => 'aventure-enchantee',
            'bookTitle' => sprintf('%s et l\'aventure enchantée', $session->getChildName() ?? 'Votre enfant'),
            'childName' => $session->getChildName(),
            'dedication' => $session->getDedication(),
            'generationJobId' => $session->getId(),
            'pages' => $pages,
        ];
    }

    private function createProviderValidationPdfArtifact(PersonalizationSession $session, PreviewVersion $previewVersion): PdfArtifact
    {
        $directory = '/srv/sylius/var/storage/personalizations/pdfs';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Failed to create validation PDF directory "%s".', $directory));
        }

        $filename = sprintf('%s-provider-validation.pdf', $session->getId());
        $storagePath = sprintf('%s/%s', $directory, $filename);
        file_put_contents($storagePath, $this->buildProviderValidationPdfBinary($session));
        $accessToken = strtolower(Uuid::v7()->toBase32());

        return new PdfArtifact(
            $session,
            $previewVersion,
            $storagePath,
            sprintf('/api/personalization/pdfs/%s', $accessToken),
            $accessToken,
            hash_file('sha256', $storagePath),
            filesize($storagePath) ?: 0,
        );
    }

    private function buildProviderValidationPdfBinary(PersonalizationSession $session): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $pageObjectIds = [];
        $contentObjectIds = [];

        for ($page = 1; $page <= 35; ++$page) {
            $pageObjectIds[] = 4 + (($page - 1) * 2);
            $contentObjectIds[] = 5 + (($page - 1) * 2);
        }

        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count 35 >>', implode(' ', array_map(static fn (int $id): string => sprintf('%d 0 R', $id), $pageObjectIds)));
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        foreach (range(1, 35) as $index) {
            $pageObjectId = $pageObjectIds[$index - 1];
            $contentObjectId = $contentObjectIds[$index - 1];
            $mediaBox = 1 === $index ? '[0 0 1298 697]' : '[0 0 595 595]';
            $titleX = 1 === $index ? 140 : 72;
            $titleY = 1 === $index ? 560 : 500;
            $title = 1 === $index
                ? sprintf('%s et l\'aventure enchantee', $session->getChildName() ?? 'Votre enfant')
                : sprintf('Validation page %d', $index);
            $subtitle = 1 === $index
                ? sprintf('Livre de validation Gelato pour %s', $session->getChildName() ?? 'Votre enfant')
                : sprintf('Session %s - page %d', $session->getId(), $index);
            $content = sprintf(
                "BT\n/F1 22 Tf\n%d %d Td\n(%s) Tj\n0 -32 Td\n/F1 14 Tf\n(%s) Tj\nET",
                $titleX,
                $titleY,
                $this->escapePdfText($title),
                $this->escapePdfText($subtitle),
            );
            $objects[$pageObjectId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox %s /Resources << /Font << /F1 3 0 R >> >> /Contents %d 0 R >>', $mediaBox, $contentObjectId);
            $objects[$contentObjectId] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content);
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];

        foreach ($objects as $objectId => $body) {
            $offsets[$objectId] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $objectId, $body);
        }

        $xrefOffset = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n", count($objects) + 1);
        $pdf .= "0000000000 65535 f \n";

        foreach (range(1, count($objects)) as $objectId) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$objectId]);
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF",
            count($objects) + 1,
            $xrefOffset,
        );

        return $pdf;
    }

    private function escapePdfText(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /** @return array{int,string,string,int,string} */
    private function insertCompletedOrderWithShippingAddress(string $email): array
    {
        $orderId = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_order') + random_int(1000, 5000);
        $customerId = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_customer') + random_int(1000, 5000);
        $addressId = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_address') + random_int(1000, 5000);
        $channelId = (int) $this->connection->fetchOne('SELECT id FROM sylius_channel ORDER BY id ASC LIMIT 1');
        $orderItemId = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_order_item') + random_int(1000, 5000);
        $variantId = (int) $this->connection->fetchOne('SELECT id FROM sylius_product_variant ORDER BY id ASC LIMIT 1');
        $variantCode = (string) $this->connection->fetchOne('SELECT code FROM sylius_product_variant WHERE id = :variantId', ['variantId' => $variantId]);
        $orderNumber = sprintf('GLTVAL-%s', bin2hex(random_bytes(3)));
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $orderTokenValue = sprintf('gltval-token-%d', $orderId);

        $this->connection->executeStatement(
            "INSERT INTO sylius_customer (id, email, email_canonical, first_name, last_name, gender, created_at, updated_at, subscribed_to_newsletter) VALUES (:id, :email, :email, 'Nora', 'Validation', 'u', :createdAt, :updatedAt, FALSE)",
            ['id' => $customerId, 'email' => $email, 'createdAt' => $now, 'updatedAt' => $now],
        );

        $this->connection->executeStatement(
            "INSERT INTO sylius_address (id, customer_id, first_name, last_name, phone_number, street, city, postcode, country_code, created_at, updated_at) VALUES (:id, :customerId, 'Nora', 'Validation', '+32470111222', 'Rue du Livre 12', 'Bruxelles', '1000', 'BE', :createdAt, :updatedAt)",
            ['id' => $addressId, 'customerId' => $customerId, 'createdAt' => $now, 'updatedAt' => $now],
        );

        $this->connection->executeStatement(
            "INSERT INTO sylius_order (id, shipping_address_id, channel_id, customer_id, number, state, items_total, adjustments_total, total, created_at, updated_at, currency_code, locale_code, checkout_state, payment_state, shipping_state, created_by_guest, abandoned_email, token_value, checkout_completed_at) VALUES (:id, :addressId, :channelId, :customerId, :number, 'new', 4990, 0, 4990, :createdAt, :updatedAt, 'EUR', 'fr_FR', 'completed', 'paid', 'ready', TRUE, FALSE, :tokenValue, :completedAt)",
            ['id' => $orderId, 'addressId' => $addressId, 'channelId' => $channelId, 'customerId' => $customerId, 'number' => $orderNumber, 'createdAt' => $now, 'updatedAt' => $now, 'tokenValue' => $orderTokenValue, 'completedAt' => $now],
        );

        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO sylius_order_item (
    id, order_id, variant_id, quantity, unit_price, units_total, adjustments_total, total,
    is_immutable, product_name, variant_name, version
) VALUES (
    :id, :orderId, :variantId, 1, 4990, 4990, 0, 4990,
    FALSE, 'L''Aventure Enchantee', 'Edition standard', 1
)
SQL,
            ['id' => $orderItemId, 'orderId' => $orderId, 'variantId' => $variantId],
        );

        return [$orderId, $orderNumber, $orderTokenValue, $orderItemId, $variantCode];
    }
}

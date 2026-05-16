<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Customer\Customer;
use App\Entity\Fulfillment\FulfillmentOrder;
use App\Entity\Personalization\PdfArtifact;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\User\ShopUser;
use App\FrontCatalog\FrontCatalogProvider;
use App\Personalization\PersonalizationOrderLinker;
use App\Personalization\PersonalizationSessionOwnershipGuard;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerOrderController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly FrontCatalogProvider $frontCatalogProvider,
        private readonly PersonalizationOrderLinker $personalizationOrderLinker,
        private readonly PersonalizationSessionOwnershipGuard $personalizationSessionOwnershipGuard,
    ) {
    }

    #[Route(
        '/api/custom/orders',
        name: 'app_custom_orders_list',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function listOrders(Request $request): JsonResponse
    {
        if (!$this->hasOwnershipContext($request)) {
            return new JsonResponse(['message' => 'A valid customer or owner context is required.'], Response::HTTP_FORBIDDEN);
        }

        $sessions = $this->findAccessibleSessions($request);
        $orderNumbers = array_values(array_unique(array_filter(array_map(
            static fn (PersonalizationSession $session): ?string => $session->getSyliusOrderNumber(),
            $sessions,
        ))));

        if ([] === $orderNumbers) {
            return new JsonResponse([]);
        }

        $orders = [];

        foreach ($orderNumbers as $orderNumber) {
            $linkedSessions = $this->personalizationOrderLinker->findSessionsByOrderNumber($orderNumber);

            if ([] === $linkedSessions) {
                continue;
            }

            $orderRow = $this->findOrderRow($orderNumber);

            if (null === $orderRow) {
                continue;
            }

            $orders[] = $this->normalizeOrder($orderRow, $linkedSessions);
        }

        return new JsonResponse($orders);
    }

    #[Route(
        '/api/custom/orders/{orderNumber}',
        name: 'app_custom_orders_read',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readOrder(string $orderNumber, Request $request): JsonResponse
    {
        if (!$this->hasOwnershipContext($request)) {
            return new JsonResponse(['message' => 'Order not found.'], Response::HTTP_NOT_FOUND);
        }

        $sessions = $this->personalizationOrderLinker->findSessionsByOrderNumber($orderNumber);
        $this->personalizationSessionOwnershipGuard->assertCanAccessSessions($sessions, $request);

        if ([] === $sessions) {
            return new JsonResponse(['message' => 'Order not found.'], Response::HTTP_NOT_FOUND);
        }

        $orderRow = $this->findOrderRow($orderNumber);

        if (null === $orderRow) {
            return new JsonResponse(['message' => 'Order not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->normalizeOrder($orderRow, $sessions));
    }

    /** @return list<PersonalizationSession> */
    private function findAccessibleSessions(Request $request): array
    {
        $customer = $this->getCurrentCustomer();

        if ($customer instanceof Customer) {
            return $this->entityManager->getRepository(PersonalizationSession::class)->findBy(
                ['ownerCustomer' => $customer],
                ['updatedAt' => 'DESC'],
            );
        }

        $ownerToken = trim((string) $request->headers->get(PersonalizationSessionOwnershipGuard::HEADER_NAME, ''));

        if ('' === $ownerToken) {
            return [];
        }

        return $this->entityManager->getRepository(PersonalizationSession::class)->findBy(
            ['guestOwnerToken' => $ownerToken],
            ['updatedAt' => 'DESC'],
        );
    }

    /** @return array<string, mixed>|null */
    private function findOrderRow(string $orderNumber): ?array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
SELECT
    o.id,
    o.number,
    o.currency_code,
    o.checkout_completed_at,
    o.items_total,
    o.adjustments_total,
    o.total,
    o.shipping_state,
    o.payment_state,
    a.first_name,
    a.last_name,
    a.street,
    a.city,
    a.postcode,
    a.country_code
FROM sylius_order o
LEFT JOIN sylius_address a ON a.id = o.shipping_address_id
WHERE o.number = :orderNumber
LIMIT 1
SQL,
            ['orderNumber' => $orderNumber],
        );

        return false === $row ? null : $row;
    }

    /**
     * @param array<string, mixed> $orderRow
     * @param list<PersonalizationSession> $sessions
     * @return array<string, mixed>
     */
    private function normalizeOrder(array $orderRow, array $sessions): array
    {
        $primarySession = $sessions[0];
        $booksById = [];

        foreach ($this->frontCatalogProvider->getBooks() as $book) {
            $booksById[(string) $book['id']] = $book;
        }

        $fulfillmentsBySessionId = [];
        $pdfsBySessionId = [];
        foreach ($sessions as $session) {
            /** @var FulfillmentOrder|null $sessionFulfillment */
            $sessionFulfillment = $this->entityManager->getRepository(FulfillmentOrder::class)->findOneBy(['session' => $session], ['id' => 'DESC']);
            /** @var PdfArtifact|null $sessionPdf */
            $sessionPdf = $this->entityManager->getRepository(PdfArtifact::class)->findOneBy(['session' => $session], ['id' => 'DESC']);
            $fulfillmentsBySessionId[$session->getId()] = $sessionFulfillment;
            $pdfsBySessionId[$session->getId()] = $sessionPdf;
        }
        /** @var FulfillmentOrder|null $fulfillment */
        $fulfillment = $fulfillmentsBySessionId[$primarySession->getId()] ?? null;
        /** @var PdfArtifact|null $pdf */
        $pdf = $pdfsBySessionId[$primarySession->getId()] ?? null;
        $status = $this->mapOrderStatus($sessions, $fulfillment?->getStatus());

        return [
            'id' => (string) $orderRow['number'],
            'orderNumber' => (string) $orderRow['number'],
            'date' => null !== $orderRow['checkout_completed_at'] ? (new \DateTimeImmutable((string) $orderRow['checkout_completed_at']))->format(DATE_ATOM) : null,
            'status' => $status,
            'subtotal' => ((int) $orderRow['items_total']) / 100,
            'shipping' => ((int) $orderRow['adjustments_total']) / 100,
            'total' => ((int) $orderRow['total']) / 100,
            'currencyCode' => (string) $orderRow['currency_code'],
            'shippingAddress' => [
                'firstName' => (string) ($orderRow['first_name'] ?? ''),
                'lastName' => (string) ($orderRow['last_name'] ?? ''),
                'street' => (string) ($orderRow['street'] ?? ''),
                'city' => (string) ($orderRow['city'] ?? ''),
                'postalCode' => (string) ($orderRow['postcode'] ?? ''),
                'country' => (string) ($orderRow['country_code'] ?? 'BE'),
            ],
            'sessions' => array_map(fn (PersonalizationSession $session): array => [
                'id' => $session->getId(),
                'bookId' => $session->getBookId(),
                'bookLocale' => $session->getBookLocale(),
                'bookTitle' => (string) (($booksById[$session->getBookId()]['title'] ?? $session->getBookId())),
                'coverImage' => (string) (($booksById[$session->getBookId()]['coverImage'] ?? '')),
                'childName' => $session->getChildName() ?? '',
                'status' => $session->getStatus()->value,
                'fulfillment' => ($fulfillmentsBySessionId[$session->getId()] ?? null) instanceof FulfillmentOrder ? [
                    'status' => $fulfillmentsBySessionId[$session->getId()]->getStatus(),
                    'providerOrderId' => $fulfillmentsBySessionId[$session->getId()]->getProviderOrderId(),
                    'trackingUrl' => $fulfillmentsBySessionId[$session->getId()]->getTrackingUrl(),
                    'trackingNumber' => $fulfillmentsBySessionId[$session->getId()]->getTrackingNumber(),
                    'errorMessage' => $fulfillmentsBySessionId[$session->getId()]->getErrorMessage(),
                ] : null,
                'pdf' => ($pdfsBySessionId[$session->getId()] ?? null) instanceof PdfArtifact ? [
                    'status' => $pdfsBySessionId[$session->getId()]->getStatus(),
                    'url' => $pdfsBySessionId[$session->getId()]->getPublicPath(),
                    'hash' => $pdfsBySessionId[$session->getId()]->getFileHash(),
                    'preflightStatus' => $pdfsBySessionId[$session->getId()]->getPreflightStatus(),
                ] : null,
            ], $sessions),
            'fulfillment' => null !== $fulfillment ? [
                'status' => $fulfillment->getStatus(),
                'providerOrderId' => $fulfillment->getProviderOrderId(),
                'trackingUrl' => $fulfillment->getTrackingUrl(),
                'trackingNumber' => $fulfillment->getTrackingNumber(),
                'errorMessage' => $fulfillment->getErrorMessage(),
            ] : null,
            'pdf' => null !== $pdf ? [
                'status' => $pdf->getStatus(),
                'url' => $pdf->getPublicPath(),
                'hash' => $pdf->getFileHash(),
            ] : null,
            'trackingSteps' => $this->buildTrackingSteps($status, $fulfillment?->getStatus()),
        ];
    }

    /** @param list<PersonalizationSession> $sessions */
    private function mapOrderStatus(array $sessions, ?string $fulfillmentStatus): string
    {
        $statuses = array_map(static fn (PersonalizationSession $session): string => $session->getStatus()->value, $sessions);

        if (in_array('delivered', $statuses, true) || 'delivered' === $fulfillmentStatus) {
            return 'delivered';
        }

        if (in_array('shipped', $statuses, true) || 'shipped' === $fulfillmentStatus) {
            return 'shipped';
        }

        if (in_array('in_production', $statuses, true) || in_array($fulfillmentStatus, ['in_production', 'production', 'printed'], true)) {
            return 'printing';
        }

        if (array_intersect($statuses, ['submitted_to_gelato', 'print_ready', 'pdf_rendering', 'checkout_completed'])) {
            return 'preparing';
        }

        return 'confirmed';
    }

    /**
     * Returns step IDs and statuses only — no label/description text.
     * The frontend translates step labels via i18n key tracking.steps.{id}.label/.desc
     *
     * @return list<array{id:string,label:string,description:string,status:string}>
     */
    private function buildTrackingSteps(string $orderStatus, ?string $fulfillmentStatus): array
    {
        $steps = [
            ['id' => 'confirmed',  'label' => '', 'description' => '', 'status' => 'completed'],
            ['id' => 'pdf',        'label' => '', 'description' => '', 'status' => 'upcoming'],
            ['id' => 'production', 'label' => '', 'description' => '', 'status' => 'upcoming'],
            ['id' => 'shipping',   'label' => '', 'description' => '', 'status' => 'upcoming'],
            ['id' => 'delivery',   'label' => '', 'description' => '', 'status' => 'upcoming'],
        ];

        if (in_array($orderStatus, ['preparing', 'printing', 'shipped', 'delivered'], true)) {
            $steps[1]['status'] = 'completed';
        }

        if (in_array($orderStatus, ['printing', 'shipped', 'delivered'], true)) {
            $steps[2]['status'] = 'current';
        }

        if ('submitted' === $fulfillmentStatus || 'in_production' === $fulfillmentStatus) {
            $steps[2]['status'] = 'current';
        }

        if (in_array($orderStatus, ['shipped', 'delivered'], true)) {
            $steps[2]['status'] = 'completed';
            $steps[3]['status'] = 'current';
        }

        if ('delivered' === $orderStatus) {
            $steps[3]['status'] = 'completed';
            $steps[4]['status'] = 'completed';
        }

        return $steps;
    }

    private function getCurrentCustomer(): ?Customer
    {
        $user = $this->security->getUser();

        if (!$user instanceof ShopUser) {
            return null;
        }

        $customer = $user->getCustomer();

        return $customer instanceof Customer ? $customer : null;
    }

    private function hasOwnershipContext(Request $request): bool
    {
        return $this->getCurrentCustomer() instanceof Customer
            || '' !== trim((string) $request->headers->get(PersonalizationSessionOwnershipGuard::HEADER_NAME, ''));
    }
}

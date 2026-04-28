<?php

declare(strict_types=1);

namespace App\Tests\Unit\Personalization;

use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\PersonalizationSessionStatus;
use App\Personalization\PersonalizationSessionTransitionMatrix;
use PHPUnit\Framework\TestCase;

final class PersonalizationSessionTransitionMatrixTest extends TestCase
{
    public function testCompleteHappyPathTransitionsAreAllowed(): void
    {
        $path = [
            PersonalizationSessionStatus::Draft,
            PersonalizationSessionStatus::PhotoUploaded,
            PersonalizationSessionStatus::ContentCompleted,
            PersonalizationSessionStatus::GenerationRequested,
            PersonalizationSessionStatus::Generating,
            PersonalizationSessionStatus::PreviewPartialReady,
            PersonalizationSessionStatus::PreviewReady,
            PersonalizationSessionStatus::Approved,
            PersonalizationSessionStatus::CartAttached,
            PersonalizationSessionStatus::CheckoutCompleted,
            PersonalizationSessionStatus::PdfRendering,
            PersonalizationSessionStatus::PrintReady,
            PersonalizationSessionStatus::SubmittedToGelato,
            PersonalizationSessionStatus::InProduction,
            PersonalizationSessionStatus::Shipped,
            PersonalizationSessionStatus::Delivered,
        ];

        for ($i = 0; $i < count($path) - 1; ++$i) {
            self::assertTrue(
                PersonalizationSessionTransitionMatrix::canTransition($path[$i], $path[$i + 1]),
                sprintf('Expected %s -> %s to be allowed.', $path[$i]->value, $path[$i + 1]->value),
            );
        }
    }

    public function testTerminalStatesCannotReturnToCommerceFlow(): void
    {
        foreach ([PersonalizationSessionStatus::Delivered, PersonalizationSessionStatus::Cancelled, PersonalizationSessionStatus::Failed] as $terminalStatus) {
            self::assertFalse(PersonalizationSessionTransitionMatrix::canTransition($terminalStatus, PersonalizationSessionStatus::CartAttached));
            self::assertFalse(PersonalizationSessionTransitionMatrix::canTransition($terminalStatus, PersonalizationSessionStatus::PdfRendering));
        }
    }

    public function testEntityRejectsIllegalTransition(): void
    {
        $session = new PersonalizationSession('b1', 'owner-token');
        $session->addPhoto($this->createStubPhoto($session));
        $session->saveContent('Nora', 'Pour toi', [], 3);

        $this->expectException(\DomainException::class);
        $session->markPdfRendering();
    }

    private function createStubPhoto(PersonalizationSession $session): \App\Entity\Personalization\UploadedPhoto
    {
        return new \App\Entity\Personalization\UploadedPhoto(
            $session,
            'photo.jpg',
            'photo.jpg',
            'image/jpeg',
            1024,
            '/private/photo.jpg',
            '/tmp/photo.jpg',
            'token',
            512,
            512,
            'hash',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Personalization;

use App\Entity\Personalization\PersonalizationSessionStatus;

final class PersonalizationSessionTransitionMatrix
{
    /** @return list<PersonalizationSessionStatus> */
    public static function allowedTargets(PersonalizationSessionStatus $from): array
    {
        return match ($from) {
            PersonalizationSessionStatus::Draft => [
                PersonalizationSessionStatus::PhotoUploaded,
                PersonalizationSessionStatus::ContentCompleted,
                PersonalizationSessionStatus::Failed,
                PersonalizationSessionStatus::Cancelled,
            ],
            PersonalizationSessionStatus::PhotoUploaded => [
                PersonalizationSessionStatus::Draft,
                PersonalizationSessionStatus::ContentCompleted,
                PersonalizationSessionStatus::GenerationRequested,
                PersonalizationSessionStatus::Failed,
                PersonalizationSessionStatus::Cancelled,
            ],
            PersonalizationSessionStatus::ContentCompleted => [
                PersonalizationSessionStatus::PhotoUploaded,
                PersonalizationSessionStatus::GenerationRequested,
                PersonalizationSessionStatus::Failed,
                PersonalizationSessionStatus::Cancelled,
            ],
            PersonalizationSessionStatus::GenerationRequested => [
                PersonalizationSessionStatus::Generating,
                PersonalizationSessionStatus::GenerationRequested,
                PersonalizationSessionStatus::Failed,
                PersonalizationSessionStatus::Cancelled,
            ],
            PersonalizationSessionStatus::Generating => [
                PersonalizationSessionStatus::Generating,
                PersonalizationSessionStatus::PreviewPartialReady,
                PersonalizationSessionStatus::PreviewReady,
                PersonalizationSessionStatus::Failed,
                PersonalizationSessionStatus::Cancelled,
            ],
            PersonalizationSessionStatus::PreviewPartialReady => [
                PersonalizationSessionStatus::Generating,
                PersonalizationSessionStatus::PreviewReady,
                PersonalizationSessionStatus::ContentCompleted,
                PersonalizationSessionStatus::PhotoUploaded,
                PersonalizationSessionStatus::Failed,
                PersonalizationSessionStatus::Cancelled,
            ],
            PersonalizationSessionStatus::PreviewReady => [
                PersonalizationSessionStatus::GenerationRequested,
                PersonalizationSessionStatus::Approved,
                PersonalizationSessionStatus::ContentCompleted,
                PersonalizationSessionStatus::PhotoUploaded,
                PersonalizationSessionStatus::Failed,
                PersonalizationSessionStatus::Cancelled,
            ],
            PersonalizationSessionStatus::Approved => [
                PersonalizationSessionStatus::CartAttached,
                PersonalizationSessionStatus::CheckoutCompleted,
                PersonalizationSessionStatus::ContentCompleted,
                PersonalizationSessionStatus::PhotoUploaded,
                PersonalizationSessionStatus::Failed,
                PersonalizationSessionStatus::Cancelled,
            ],
            PersonalizationSessionStatus::CartAttached => [
                PersonalizationSessionStatus::Approved,
                PersonalizationSessionStatus::CheckoutCompleted,
                PersonalizationSessionStatus::ContentCompleted,
                PersonalizationSessionStatus::PhotoUploaded,
                PersonalizationSessionStatus::Failed,
                PersonalizationSessionStatus::Cancelled,
            ],
            PersonalizationSessionStatus::CheckoutCompleted => [
                PersonalizationSessionStatus::PdfRendering,
                PersonalizationSessionStatus::PrintReady,
                PersonalizationSessionStatus::Failed,
                PersonalizationSessionStatus::Cancelled,
            ],
            PersonalizationSessionStatus::PdfRendering => [
                PersonalizationSessionStatus::PrintReady,
                PersonalizationSessionStatus::Failed,
            ],
            PersonalizationSessionStatus::PrintReady => [
                PersonalizationSessionStatus::SubmittedToGelato,
                PersonalizationSessionStatus::Failed,
            ],
            PersonalizationSessionStatus::SubmittedToGelato => [
                PersonalizationSessionStatus::InProduction,
                PersonalizationSessionStatus::Shipped,
                PersonalizationSessionStatus::Delivered,
                PersonalizationSessionStatus::Failed,
                PersonalizationSessionStatus::Cancelled,
            ],
            PersonalizationSessionStatus::InProduction => [
                PersonalizationSessionStatus::Shipped,
                PersonalizationSessionStatus::Delivered,
                PersonalizationSessionStatus::Failed,
                PersonalizationSessionStatus::Cancelled,
            ],
            PersonalizationSessionStatus::Shipped => [
                PersonalizationSessionStatus::Delivered,
                PersonalizationSessionStatus::Failed,
            ],
            PersonalizationSessionStatus::Delivered,
            PersonalizationSessionStatus::Failed,
            PersonalizationSessionStatus::Cancelled => [],
        };
    }

    public static function canTransition(PersonalizationSessionStatus $from, PersonalizationSessionStatus $to): bool
    {
        return $from === $to || in_array($to, self::allowedTargets($from), true);
    }

    public static function assertCanTransition(PersonalizationSessionStatus $from, PersonalizationSessionStatus $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw new \DomainException(sprintf(
                'Illegal personalization session transition from "%s" to "%s".',
                $from->value,
                $to->value,
            ));
        }
    }
}

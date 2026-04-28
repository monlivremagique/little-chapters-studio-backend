<?php

declare(strict_types=1);

namespace App\Entity\Personalization;

enum PersonalizationSessionStatus: string
{
    case Draft = 'draft';
    case PhotoUploaded = 'photo_uploaded';
    case ContentCompleted = 'content_completed';
    case GenerationRequested = 'generation_requested';
    case Generating = 'generating';
    case PreviewPartialReady = 'preview_partial_ready';
    case PreviewReady = 'preview_ready';
    case Approved = 'approved';
    case CartAttached = 'cart_attached';
    case CheckoutCompleted = 'checkout_completed';
    case PdfRendering = 'pdf_rendering';
    case PrintReady = 'print_ready';
    case SubmittedToGelato = 'submitted_to_gelato';
    case InProduction = 'in_production';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}

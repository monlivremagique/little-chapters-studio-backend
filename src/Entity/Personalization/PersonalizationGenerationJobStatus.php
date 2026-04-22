<?php

declare(strict_types=1);

namespace App\Entity\Personalization;

enum PersonalizationGenerationJobStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}

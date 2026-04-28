<?php

declare(strict_types=1);

namespace App\Entity\Personalization;

enum UploadedPhotoStatus: string
{
    case Uploaded = 'uploaded';
    case Deleted = 'deleted';
}

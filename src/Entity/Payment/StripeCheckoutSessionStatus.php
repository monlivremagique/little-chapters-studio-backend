<?php

declare(strict_types=1);

namespace App\Entity\Payment;

enum StripeCheckoutSessionStatus: string
{
    case Open = 'open';
    case Complete = 'complete';
    case Expired = 'expired';
    case Failed = 'failed';
}

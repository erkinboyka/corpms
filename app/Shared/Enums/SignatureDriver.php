<?php

declare(strict_types=1);

namespace App\Shared\Enums;

enum SignatureDriver: string
{
    case Internal = 'internal';
    case QrApproval = 'qr_approval';
    case Certificate = 'certificate';
    case CompanyCertificate = 'company_certificate';
    case StateCertificate = 'state_certificate';
    case ExternalQualified = 'external_qualified';
}

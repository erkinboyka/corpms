<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/** Единый список типов биометрических устройств (БД, валидация, UI). */
enum BiometricDeviceType: string
{
    case FaceTerminal = 'face_terminal';
    case Kiosk = 'kiosk';
    case FingerprintGate = 'fingerprint_gate';
    case Turnstile = 'turnstile';
    case QrReader = 'qr_reader';
    case RfidReader = 'rfid_reader';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::FaceTerminal => 'Терминал лица',
            self::Kiosk => 'Киоск',
            self::FingerprintGate => 'Дактило-турникет',
            self::Turnstile => 'Турникет',
            self::QrReader => 'QR-считыватель',
            self::RfidReader => 'RFID-ридер',
        };
    }
}

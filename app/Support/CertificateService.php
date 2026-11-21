<?php

declare(strict_types=1);

namespace App\Support;

class CertificateService
{
    /** Извлекает метаданные сертификата из PEM. */
    public function parse(string $pem): ?array
    {
        $cert = openssl_x509_parse($pem);
        if ($cert === false) {
            return null;
        }
        $fingerprint = openssl_x509_fingerprint($pem, 'sha256');
        if ($fingerprint === false) {
            return null;
        }
        return [
            'subject' => $cert['subject']['CN'] ?? ($cert['name'] ?? null),
            'issuer' => $cert['issuer']['CN'] ?? null,
            'serial' => $cert['serialNumberHex'] ?? ($cert['serialNumber'] ?? null),
            'fingerprint' => $fingerprint,
            'valid_from' => isset($cert['validFrom_time_t']) ? date('Y-m-d H:i:s', $cert['validFrom_time_t']) : null,
            'valid_to' => isset($cert['validTo_time_t']) ? date('Y-m-d H:i:s', $cert['validTo_time_t']) : null,
        ];
    }

    /** Проверяет, что сертификат действителен на текущий момент. */
    public function isValid(string $pem): bool
    {
        $meta = $this->parse($pem);
        if ($meta === null) {
            return false;
        }
        $now = time();
        if (isset($meta['valid_from']) && strtotime($meta['valid_from']) > $now) {
            return false;
        }
        if (isset($meta['valid_to']) && strtotime($meta['valid_to']) < $now) {
            return false;
        }
        return true;
    }

    /** Проверяет подпись (base64) над данными с помощью публичного ключа сертификата. */
    public function verifySignature(string $pem, string $data, string $signatureBase64): bool
    {
        $pubKey = openssl_pkey_get_public($pem);
        if ($pubKey === false) {
            return false;
        }
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false) {
            return false;
        }
        $result = openssl_verify($data, $signature, $pubKey, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }
}
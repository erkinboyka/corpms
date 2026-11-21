<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

class TwoFactorService
{
    public function generateSecret(): string
    {
        $bytes = random_bytes(20);
        return $this->base32Encode($bytes);
    }

    public function qrUrl(string $email, string $secret, string $issuer = 'BM Systems'): string
    {
        $label = rawurlencode($issuer.':'.$email);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    public function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $time = (int) floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->totp($secret, $time + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    public function encryptSecret(string $secret): string
    {
        return Crypt::encryptString($secret);
    }

    public function decryptSecret(string $encrypted): string
    {
        return Crypt::decryptString($encrypted);
    }

    private function totp(string $secret, int $time): string
    {
        $key = $this->base32Decode($secret);
        $binaryTime = pack('N*', 0).pack('N*', $time);
        $hash = hash_hmac('sha1', $binaryTime, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
        $otp = $truncated % 1000000;
        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $chunks = str_split($bits, 5);
        $result = '';
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $result .= $alphabet[bindec($chunk)];
        }
        return $result;
    }

    private function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper(rtrim($data, '='));
        $bits = '';
        foreach (str_split($data) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }
        return $bytes;
    }
}
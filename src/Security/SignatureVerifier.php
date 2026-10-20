<?php
declare(strict_types=1);
namespace RadioStamp\Security;

/** Verifies the exact request bytes before decoding any untrusted JSON. */
final class SignatureVerifier
{
    public function __construct(private readonly string $publicKeyHex, private readonly int $maxAgeSeconds = 300, private readonly int $futureSkewSeconds = 30) {}

    public function verify(string $timestamp, string $signatureHex, string $rawBody, int $now): bool
    {
        if (!function_exists('sodium_crypto_sign_verify_detached') ||
            !preg_match('/\A[0-9a-fA-F]{64}\z/D', $this->publicKeyHex) ||
            !preg_match('/\A[0-9a-fA-F]{128}\z/D', $signatureHex) ||
            !preg_match('/\A[1-9][0-9]{8,10}\z/D', $timestamp)) {
            return false;
        }
        $time = (int) $timestamp;
        if ($time < $now - $this->maxAgeSeconds || $time > $now + $this->futureSkewSeconds) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached(hex2bin($signatureHex), $timestamp . $rawBody, hex2bin($this->publicKeyHex));
        } catch (\Throwable) {
            return false;
        }
    }
}

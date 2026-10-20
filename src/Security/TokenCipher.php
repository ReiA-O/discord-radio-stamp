<?php
declare(strict_types=1);
namespace RadioStamp\Security;

/** The owner provisions this key outside the public document root and database. */
final class TokenCipher
{
    private readonly string $key;

    public function __construct(string $keyHex)
    {
        if (!function_exists('sodium_crypto_secretbox') || !preg_match('/\A[0-9a-fA-F]{64}\z/D', $keyHex)) {
            throw new \InvalidArgumentException('A private token encryption key is required.');
        }
        $this->key = hex2bin($keyHex);
    }

    public function encrypt(string $interactionId, string $token): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        // Bind the ciphertext to the interaction so copied DB rows fail closed.
        $plaintext = json_encode(['id' => $interactionId, 'token' => $token], JSON_THROW_ON_ERROR);
        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    public function decrypt(string $interactionId, string $encoded): string
    {
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Invalid private queue record.');
        }
        $plain = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key);
        if ($plain === false) { throw new \RuntimeException('Invalid private queue record.'); }
        $data = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['id'] ?? null) !== $interactionId || !is_string($data['token'] ?? null)) {
            throw new \RuntimeException('Invalid private queue record.');
        }
        return $data['token'];
    }
}

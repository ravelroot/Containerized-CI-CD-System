<?php

declare(strict_types=1);

namespace Vau\Support;

use Vau\Config;

/**
 * Cifra segredos em repouso (segredos de webhook, tokens de acesso e
 * variáveis secretas dos projetos) com XSalsa20-Poly1305 da libsodium.
 *
 * Formato gravado no banco: "v1:" + base64(nonce || ciphertext).
 */
final class Crypto
{
    private const PREFIX = 'v1:';

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('A chave de criptografia precisa ter 32 bytes.');
        }
    }

    /**
     * Usa VAU_APP_KEY (base64) ou cria storage/.chave na primeira execução.
     */
    public static function fromConfig(Config $config, string $root): self
    {
        $configured = $config->get('VAU_APP_KEY');
        if ($configured !== '') {
            $raw = base64_decode(str_starts_with($configured, 'base64:') ? substr($configured, 7) : $configured, true);
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \RuntimeException('VAU_APP_KEY precisa ser base64 de 32 bytes. Gere com: php bin/vau key:generate');
            }

            return new self($raw);
        }

        $file = $root . '/storage/.chave';
        if (!is_file($file)) {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
            file_put_contents($file, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), LOCK_EX);
            @chmod($file, 0600);
        }
        $raw = base64_decode(trim((string) file_get_contents($file)), true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('O arquivo storage/.chave está corrompido.');
        }

        return new self($raw);
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new \RuntimeException('Formato de segredo desconhecido.');
        }
        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Segredo corrompido.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if ($plain === false) {
            throw new \RuntimeException('Não foi possível decifrar o segredo. A chave mudou?');
        }

        return $plain;
    }

    public static function token(int $bytes = 24): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}

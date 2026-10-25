<?php

declare(strict_types=1);

namespace Vau\Integrations;

/** Cliente HTTP mínimo sobre a extensão curl, com tempo limite curto. */
class HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param array<mixed>|null $json
     * @return array{status: int, body: string, error: string|null}
     */
    public function request(string $method, string $url, array $headers = [], ?array $json = null, int $timeout = 10): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'Extensão curl ausente.'];
        }
        $ch = curl_init($url);
        $lines = ['User-Agent: Vau-CI', 'Accept: application/json'];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ];
        if ($json !== null) {
            $options[CURLOPT_POSTFIELDS] = (string) json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $lines[] = 'Content-Type: application/json';
        }
        $options[CURLOPT_HTTPHEADER] = $lines;
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $body === false ? curl_error($ch) : null;
        curl_close($ch);

        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
    }
}

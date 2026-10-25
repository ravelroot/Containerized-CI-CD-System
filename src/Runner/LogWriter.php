<?php

declare(strict_types=1);

namespace Vau\Runner;

use Vau\Database\Connection;
use Vau\Support\Text;
use Vau\Support\Time;

/**
 * Grava o log do build em trechos (va_build_log), já com os segredos
 * mascarados e sem códigos ANSI. Agrupa a saída por até meio segundo para
 * não fazer uma escrita no banco por linha, e corta o log quando ele passa
 * do limite configurado, avisando no próprio log.
 */
final class LogWriter
{
    private const MASK = '••••••';

    private string $buffer = '';
    private string $bufferStream = 'out';
    private ?int $bufferStep = null;
    private float $lastFlush;
    private int $bytes = 0;
    private bool $truncated = false;
    /** @var list<string> */
    private array $secrets = [];
    private string $pendingLine = '';

    public function __construct(private readonly Connection $db, private readonly int $buildId, private readonly int $maxBytes)
    {
        $this->lastFlush = microtime(true);
    }

    /** @param list<string> $values */
    public function addSecrets(array $values): void
    {
        foreach ($values as $value) {
            if (strlen($value) >= 4 && !in_array($value, $this->secrets, true)) {
                $this->secrets[] = $value;
                // a mesma credencial pode aparecer codificada em URL ou em base64
                $this->secrets[] = rawurlencode($value);
                $this->secrets[] = base64_encode($value);
            }
        }
        usort($this->secrets, static fn($a, $b) => strlen($b) <=> strlen($a));
    }

    public function mask(string $text): string
    {
        return $this->secrets === [] ? $text : str_replace($this->secrets, self::MASK, $text);
    }

    /** Linha do próprio Vau (cabeçalhos, avisos), destacada no painel. */
    public function system(?int $stepId, string $message): void
    {
        $this->write($stepId, 'sys', $message . "\n");
        $this->flush();
    }

    public function write(?int $stepId, string $stream, string $chunk): void
    {
        if ($this->truncated) {
            return;
        }
        if ($stepId !== $this->bufferStep || $stream !== $this->bufferStream) {
            $this->flush(true);
            $this->bufferStep = $stepId;
            $this->bufferStream = $stream;
        }
        // só mascara linhas completas, para não deixar um segredo partido escapar
        $text = $this->pendingLine . Text::stripAnsi(str_replace("\r\n", "\n", $chunk));
        $cut = strrpos($text, "\n");
        if ($cut === false) {
            $this->pendingLine = $text;
            if (strlen($this->pendingLine) > 8192) {
                $this->buffer .= $this->mask($this->pendingLine);
                $this->pendingLine = '';
            }
        } else {
            $this->buffer .= $this->mask(substr($text, 0, $cut + 1));
            $this->pendingLine = substr($text, $cut + 1);
        }
        if (strlen($this->buffer) >= 16384 || microtime(true) - $this->lastFlush >= 0.5) {
            $this->flush();
        }
    }

    public function flush(bool $includePartial = false): void
    {
        if ($includePartial && $this->pendingLine !== '') {
            $this->buffer .= $this->mask($this->pendingLine) . "\n";
            $this->pendingLine = '';
        }
        $this->lastFlush = microtime(true);
        if ($this->buffer === '') {
            return;
        }
        $content = $this->buffer;
        $this->buffer = '';
        if ($this->bytes + strlen($content) > $this->maxBytes) {
            $content = substr($content, 0, max(0, $this->maxBytes - $this->bytes));
            $content .= "\n[vau] Log cortado: o limite de " . Text::bytes($this->maxBytes) . " foi atingido. O build continua.\n";
            $this->truncated = true;
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        }
        $this->bytes += strlen($content);
        $this->db->insert('va_build_log', [
            'build_id' => $this->buildId,
            'step_id' => $this->bufferStep,
            'stream' => $this->bufferStream,
            'content' => $content,
            'created_at' => Time::now(),
        ], false);
    }

    public function bytes(): int
    {
        return $this->bytes;
    }
}

<?php

declare(strict_types=1);

namespace Vau\Support;

/**
 * Leitor de YAML para o arquivo de pipeline (.vau.yml).
 *
 * Cobre o subconjunto usado em pipelines: mapas e listas por indentação,
 * itens de lista com mapas ("- name: x"), escalares com e sem aspas,
 * blocos literais (|) e dobrados (>), listas e mapas em linha ([a, b] e
 * {a: 1}), comentários e números, booleanos e nulos. Âncoras, tags e
 * múltiplos documentos não são aceitos, de propósito: um pipeline deve
 * ser lido do mesmo jeito em qualquer servidor, sem extensões do PHP.
 */
final class Yaml
{
    /** @var list<string> */
    private array $lines = [];
    private int $i = 0;

    /**
     * @return array<mixed>
     * @throws YamlException
     */
    public static function parse(string $text): array
    {
        $parser = new self();
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        $parser->lines = explode("\n", $text);
        foreach ($parser->lines as $n => $line) {
            if (preg_match('/^\t/', $line) === 1) {
                throw new YamlException('Use espaços, não tabulação, para indentar', $n + 1);
            }
            if (rtrim($line) === '---' || rtrim($line) === '...') {
                $parser->lines[$n] = '';
            }
        }
        $indent = $parser->peekIndent();
        if ($indent === -1) {
            return [];
        }
        $value = $parser->parseBlock($indent);
        if ($parser->peekIndent() !== -1) {
            throw new YamlException('Indentação inconsistente', $parser->i + 1);
        }
        if (!is_array($value)) {
            throw new YamlException('O arquivo precisa ser um mapa (chave: valor)', 1);
        }

        return $value;
    }

    /** Indentação da próxima linha significativa, ou -1 no fim do arquivo. */
    private function peekIndent(): int
    {
        $count = count($this->lines);
        while ($this->i < $count) {
            $trimmed = trim($this->lines[$this->i]);
            if ($trimmed === '' || $trimmed[0] === '#') {
                $this->i++;
                continue;
            }

            return strlen($this->lines[$this->i]) - strlen(ltrim($this->lines[$this->i], ' '));
        }

        return -1;
    }

    private function current(): string
    {
        return ltrim($this->lines[$this->i], ' ');
    }

    private static function isSequenceItem(string $content): bool
    {
        return $content === '-' || str_starts_with($content, '- ');
    }

    private function parseBlock(int $indent): mixed
    {
        return self::isSequenceItem(rtrim($this->current()))
            ? $this->parseSequence($indent)
            : $this->parseMapping($indent);
    }

    /** @return list<mixed> */
    private function parseSequence(int $indent): array
    {
        $out = [];
        while ($this->peekIndent() === $indent) {
            $content = rtrim($this->current());
            if (!self::isSequenceItem($content)) {
                break;
            }
            $rest = $content === '-' ? '' : substr($content, 2);
            $restTrim = ltrim($rest);
            if ($restTrim === '' || $restTrim[0] === '#') {
                $this->i++;
                $next = $this->peekIndent();
                $out[] = $next > $indent ? $this->parseBlock($next) : null;
                continue;
            }
            if (self::looksLikeKey($restTrim)) {
                $child = $indent + 2 + (strlen($rest) - strlen($restTrim));
                $this->lines[$this->i] = str_repeat(' ', $child) . $restTrim;
                $out[] = $this->parseMapping($child);
                continue;
            }
            $this->i++;
            $out[] = $this->valueOrBlock($restTrim, $indent);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function parseMapping(int $indent): array
    {
        $out = [];
        while (($current = $this->peekIndent()) !== -1) {
            if ($current < $indent) {
                break;
            }
            if ($current > $indent) {
                throw new YamlException('Indentação inesperada', $this->i + 1);
            }
            $line = rtrim($this->current());
            if (self::isSequenceItem($line)) {
                break;
            }
            if (preg_match('/^("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'|[^\s#"\'][^#]*?)\s*:(?:\s+(.*))?$/', $line, $m) !== 1) {
                throw new YamlException('Esperava "chave: valor"', $this->i + 1);
            }
            $key = (string) self::scalar($m[1]);
            if (array_key_exists($key, $out)) {
                throw new YamlException("Chave repetida: {$key}", $this->i + 1);
            }
            $rest = self::stripComment($m[2] ?? '');
            $this->i++;
            if ($rest === '') {
                $next = $this->peekIndent();
                if ($next > $indent) {
                    $out[$key] = $this->parseBlock($next);
                } elseif ($next === $indent && self::isSequenceItem(rtrim($this->current()))) {
                    $out[$key] = $this->parseSequence($indent);
                } else {
                    $out[$key] = null;
                }
                continue;
            }
            $out[$key] = $this->valueOrBlock($rest, $indent);
        }

        return $out;
    }

    private function valueOrBlock(string $rest, int $parentIndent): mixed
    {
        $rest = self::stripComment($rest);
        if (preg_match('/^([|>])([+-]?)$/', $rest, $m) === 1) {
            return $this->blockScalar($parentIndent, $m[1], $m[2]);
        }

        return self::scalar($rest);
    }

    private function blockScalar(int $parentIndent, string $style, string $chomp): string
    {
        $collected = [];
        $blockIndent = null;
        $count = count($this->lines);
        while ($this->i < $count) {
            $raw = $this->lines[$this->i];
            if (trim($raw) === '') {
                $collected[] = '';
                $this->i++;
                continue;
            }
            $ind = strlen($raw) - strlen(ltrim($raw, ' '));
            if ($ind <= $parentIndent) {
                break;
            }
            $blockIndent ??= $ind;
            if ($ind < $blockIndent) {
                break;
            }
            $collected[] = substr($raw, $blockIndent);
            $this->i++;
        }
        // linhas em branco depois do bloco pertencem ao restante do documento
        $trailing = 0;
        while ($collected !== [] && end($collected) === '') {
            array_pop($collected);
            $trailing++;
        }

        if ($style === '|') {
            $text = implode("\n", $collected);
        } else {
            $text = '';
            foreach ($collected as $idx => $line) {
                if ($idx === 0) {
                    $text = $line;
                } elseif ($line === '' || str_starts_with($line, ' ')) {
                    $text .= "\n" . $line;
                } else {
                    $text .= (str_ends_with($text, "\n") || $text === '' ? '' : ' ') . $line;
                }
            }
        }

        return match ($chomp) {
            '-' => $text,
            '+' => $text . str_repeat("\n", $trailing + 1),
            default => $text === '' ? '' : $text . "\n",
        };
    }

    private static function looksLikeKey(string $value): bool
    {
        if ($value === '' || in_array($value[0], ['[', '{', '|', '>'], true)) {
            return false;
        }

        return preg_match('/^("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'|[^\s#"\'][^#]*?)\s*:(\s|$)/', $value) === 1
            && !preg_match('#^[a-z]+://#i', $value);
    }

    private static function stripComment(string $value): string
    {
        $value = trim($value);
        $quote = null;
        $len = strlen($value);
        for ($k = 0; $k < $len; $k++) {
            $c = $value[$k];
            if ($quote !== null) {
                if ($c === '\\' && $quote === '"') {
                    $k++;
                } elseif ($c === "'" && $quote === "'" && ($value[$k + 1] ?? '') === "'") {
                    $k++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                if ($k === 0 || in_array($value[$k - 1], [' ', '[', '{', ',', ':'], true)) {
                    $quote = $c;
                }
                continue;
            }
            if ($c === '#' && ($k === 0 || $value[$k - 1] === ' ')) {
                return rtrim(substr($value, 0, $k));
            }
        }

        return $value;
    }

    public static function scalar(string $value): mixed
    {
        $value = trim($value);
        if ($value === '' || $value === '~' || strtolower($value) === 'null') {
            return null;
        }
        if ($value[0] === '"') {
            if (!str_ends_with($value, '"') || strlen($value) < 2) {
                throw new YamlException('Aspas duplas sem fechamento', 0);
            }
            $decoded = json_decode($value);
            if (!is_string($decoded)) {
                $decoded = stripcslashes(substr($value, 1, -1));
            }

            return $decoded;
        }
        if ($value[0] === "'") {
            if (!str_ends_with($value, "'") || strlen($value) < 2) {
                throw new YamlException('Aspas simples sem fechamento', 0);
            }

            return str_replace("''", "'", substr($value, 1, -1));
        }
        if ($value[0] === '[' && str_ends_with($value, ']')) {
            $inner = trim(substr($value, 1, -1));

            return $inner === '' ? [] : array_map([self::class, 'scalar'], self::splitFlow($inner));
        }
        if ($value[0] === '{' && str_ends_with($value, '}')) {
            $inner = trim(substr($value, 1, -1));
            $out = [];
            foreach ($inner === '' ? [] : self::splitFlow($inner) as $pair) {
                $parts = explode(':', $pair, 2);
                if (count($parts) !== 2) {
                    throw new YamlException("Par inválido no mapa em linha: {$pair}", 0);
                }
                $out[(string) self::scalar($parts[0])] = self::scalar($parts[1]);
            }

            return $out;
        }
        $lower = strtolower($value);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if (preg_match('/^-?(0|[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }
        if (preg_match('/^-?(0|[1-9][0-9]*)\.[0-9]+$/', $value) === 1) {
            return (float) $value;
        }

        return $value;
    }

    /** @return list<string> */
    private static function splitFlow(string $inner): array
    {
        $items = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $len = strlen($inner);
        for ($k = 0; $k < $len; $k++) {
            $c = $inner[$k];
            if ($quote !== null) {
                $buffer .= $c;
                if ($c === '\\' && $quote === '"' && $k + 1 < $len) {
                    $buffer .= $inner[++$k];
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ']' || $c === '}') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $items[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $c;
        }
        if (trim($buffer) !== '') {
            $items[] = trim($buffer);
        }

        return $items;
    }
}

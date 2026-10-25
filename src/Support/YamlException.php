<?php

declare(strict_types=1);

namespace Vau\Support;

final class YamlException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $lineNumber)
    {
        parent::__construct($lineNumber > 0 ? "{$message} (linha {$lineNumber})" : $message);
    }
}

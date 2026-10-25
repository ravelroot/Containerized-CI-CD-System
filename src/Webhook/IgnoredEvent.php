<?php

declare(strict_types=1);

namespace Vau\Webhook;

/** Evento válido, mas que não deve gerar build (PR fechado, branch apagada, ping...). */
final class IgnoredEvent extends \RuntimeException {}

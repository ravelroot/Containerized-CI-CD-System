<?php

declare(strict_types=1);

namespace Vau\Runner;

/** Interrupção controlada do build (falha de clone, pipeline inválido, cancelamento). */
final class BuildStopped extends \RuntimeException {}

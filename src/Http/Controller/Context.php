<?php

declare(strict_types=1);

namespace Vau\Http\Controller;

use Vau\App;
use Vau\Auth\Auth;
use Vau\Http\Request;
use Vau\Http\Session;
use Vau\Http\View;

/** O que todo controlador recebe: app, requisição, view, sessão e usuário. */
final class Context
{
    public function __construct(
        public readonly App $app,
        public readonly Request $request,
        public readonly View $view,
        public readonly ?Session $session,
        public readonly Auth $auth,
    ) {}
}

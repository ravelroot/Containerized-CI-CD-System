<?php
/** @var Vau\Http\View $v */
$nav = [
    'Operação' => [
        ['/', 'painel', 'Painel'],
        ['/projetos', 'projetos', 'Projetos'],
        ['/builds', 'builds', 'Builds'],
        ['/entregas', 'entregas', 'Entregas de webhook'],
    ],
    'Conta' => [
        ['/sistema', 'sistema', 'Sistema e conta'],
    ],
];
$active = static function (string $href) use ($path): bool {
    return $href === '/' ? $path === '/' : str_starts_with($path, $href);
};
$initial = mb_strtoupper(mb_substr((string) ($user['name'] ?? 'A'), 0, 1));
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= $v->e(($title ?? null) ? $title . ' · ' : '') ?>Vau</title>
<link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/app.css?v=<?= $v->e($version) ?>">
<script src="/assets/theme.js?v=<?= $v->e($version) ?>"></script>
</head>
<body>
<div class="shell">
  <aside class="side">
    <a class="brand" href="/">
      <?= $v->logo(36) ?>
      <span><strong>Vau</strong><small>CI/CD em containers</small></span>
    </a>
    <nav class="nav" aria-label="Principal">
      <?php foreach ($nav as $group => $items): ?>
        <p class="nav__group"><?= $v->e($group) ?></p>
        <?php foreach ($items as [$href, $icon, $label]): ?>
          <a href="<?= $href ?>" class="nav__item<?= $active($href) ? ' is-active' : '' ?>"<?= $active($href) ? ' aria-current="page"' : '' ?>><?= $v->icon($icon) ?><span><?= $v->e($label) ?></span></a>
        <?php endforeach ?>
      <?php endforeach ?>
    </nav>
    <div class="side__foot">
      <button type="button" class="nav__item" data-theme-toggle><?= $v->icon('lua') ?><span data-theme-label>Tema escuro</span></button>
      <form method="post" action="/sair"><?= $v->csrf() ?><button class="nav__item" type="submit"><?= $v->icon('sair') ?><span>Sair</span></button></form>
      <div class="me">
        <span class="me__avatar"><?= $v->e($initial) ?></span>
        <span><strong><?= $v->e($user['name'] ?? '') ?></strong><small><?= $v->e(Vau\Repository\UserRepository::ROLES[$user['role'] ?? 'leitor'] ?? '') ?> · <?= $v->e($user['username'] ?? '') ?></small></span>
      </div>
    </div>
  </aside>
  <main class="box" id="conteudo">
    <?php foreach ($flash ?? [] as $f): ?>
      <div class="flash flash--<?= $v->e($f['type']) ?>" role="status"><?= $v->e($f['message']) ?></div>
    <?php endforeach ?>
    <?= $content ?>
  </main>
</div>
<script src="/assets/app.js?v=<?= $v->e($version) ?>" defer></script>
</body>
</html>

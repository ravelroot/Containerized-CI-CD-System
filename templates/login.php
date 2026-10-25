<?php /** @var Vau\Http\View $v */ ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>Entrar · Vau</title>
<link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/app.css?v=<?= $v->e($version) ?>">
<script src="/assets/theme.js?v=<?= $v->e($version) ?>"></script>
</head>
<body class="login">
  <section class="login__pitch">
    <div class="brand brand--inverse"><?= $v->logo(40) ?><span><strong>Vau</strong><small>CI/CD em containers</small></span></div>
    <h1>Do push ao deploy, cada commit atravessa sozinho.</h1>
    <ul class="login__facts">
      <li><strong>Webhooks</strong><span>GitHub e GitLab com assinatura conferida</span></li>
      <li><strong>Isolamento</strong><span>um container novo por etapa, volume próprio por build</span></li>
      <li><strong>Segredos</strong><span>cifrados no banco e mascarados no log</span></li>
    </ul>
  </section>
  <section class="login__form">
    <form method="post" action="/entrar" class="card card--form" novalidate>
      <?= $v->csrf() ?>
      <h2 class="fina">Entrar</h2>
      <p class="muted">Use a conta criada pelo administrador.</p>
      <?php if ($error): ?><div class="flash flash--erro" role="alert"><?= $v->e($error) ?></div><?php endif ?>
      <label class="field"><span>Usuário</span><input name="usuario" value="<?= $v->e($username) ?>" autocomplete="username" autocapitalize="none" required autofocus></label>
      <label class="field"><span>Senha</span><input name="senha" type="password" autocomplete="current-password" required></label>
      <button class="btn btn--primary btn--block" type="submit">Entrar <?= $v->icon('seta', 16) ?></button>
      <p class="hint">Primeiro acesso: <code>admin</code> / <code>Vau@2026</code>. Troque a senha em Sistema e conta.</p>
    </form>
    <p class="login__foot">Vau <?= $v->e($version) ?> · <button type="button" class="link" data-theme-toggle><span data-theme-label>Tema escuro</span></button></p>
  </section>
<script src="/assets/app.js?v=<?= $v->e($version) ?>" defer></script>
</body>
</html>

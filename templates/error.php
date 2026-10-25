<?php /** @var Vau\Http\View $v */ ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= (int) $status ?> · Vau</title>
<link rel="stylesheet" href="/assets/app.css">
<script src="/assets/theme.js"></script>
</head>
<body class="solo">
  <main class="card card--solo">
    <?= $v->logo(40) ?>
    <p class="solo__code fina"><?= (int) $status ?></p>
    <p><?= $v->e($message) ?></p>
    <a class="btn" href="/">Voltar ao painel</a>
  </main>
</body>
</html>

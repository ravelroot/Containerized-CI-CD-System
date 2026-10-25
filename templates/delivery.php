<?php
/** @var Vau\Http\View $v */
$v->share('title', 'Entrega #' . $delivery['id']);
?>
<header class="page__head">
  <div>
    <p class="eyebrow"><a href="/entregas">Entregas</a> · #<?= (int) $delivery['id'] ?></p>
    <h1><?= $v->e($delivery['provider']) ?> · <?= $v->e($delivery['event']) ?></h1>
  </div>
  <?php if ($v->can('manage') && $delivery['project_slug']): ?>
    <form method="post" action="/entregas/<?= (int) $delivery['id'] ?>/reenviar" data-confirm="Processar esta entrega de novo?"><?= $v->csrf() ?><button class="btn" type="submit"><?= $v->icon('repetir', 16) ?>Reenviar</button></form>
  <?php endif ?>
</header>
<section class="facts">
  <div class="facts__item"><span class="facts__label">Código</span><span><?= (int) $delivery['status_code'] ?></span></div>
  <div class="facts__item"><span class="facts__label">Assinatura</span><span><?= (int) $delivery['signature_ok'] ? 'válida' : 'inválida ou ausente' ?></span></div>
  <div class="facts__item"><span class="facts__label">Projeto</span><span><?= $delivery['project_slug'] ? '<a href="/projetos/' . $v->e($delivery['project_slug']) . '">' . $v->e($delivery['project_name']) . '</a>' : '—' ?></span></div>
  <div class="facts__item"><span class="facts__label">Tamanho</span><span><?= $v->e($v->bytes((int) $delivery['payload_size'])) ?></span></div>
  <div class="facts__item"><span class="facts__label">Origem</span><span class="mono"><?= $v->e($delivery['ip'] ?? '—') ?></span></div>
  <div class="facts__item"><span class="facts__label">Recebida</span><span><?= $v->e($v->date($delivery['received_at'], 'd/m/Y H:i:s')) ?></span></div>
</section>
<p class="callout callout--neutro"><?= $v->e($delivery['outcome']) ?></p>
<section class="panel">
  <header class="panel__head"><h2>Cabeçalhos</h2><span class="muted mono small"><?= $v->e($delivery['delivery_uid']) ?></span></header>
  <table class="table table--compact">
    <tbody><?php foreach ($headers as $name => $value): ?><tr><td class="mono small"><?= $v->e($name) ?></td><td class="mono small break"><?= $v->e($value) ?></td></tr><?php endforeach ?></tbody>
  </table>
</section>
<section class="panel">
  <header class="panel__head"><h2>Corpo</h2></header>
  <pre class="code code--block code--scroll"><?= $v->e($payload) ?></pre>
</section>

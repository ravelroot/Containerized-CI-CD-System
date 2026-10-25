<?php
/** @var Vau\Http\View $v */
$v->share('title', 'Painel');
?>
<header class="page__head">
  <div>
    <p class="eyebrow">Últimos 7 dias</p>
    <h1>Painel</h1>
  </div>
  <div class="page__actions">
    <a class="btn" href="/builds"><?= $v->icon('builds', 16) ?>Todos os builds</a>
    <?php if ($v->can('manage')): ?><a class="btn btn--primary" href="/projetos/novo"><?= $v->icon('mais', 16) ?>Novo projeto</a><?php endif ?>
  </div>
</header>

<section class="lid" aria-label="Resumo">
  <div class="lid__item"><span class="lid__number"><?= (int) $summary['total'] ?></span><span class="lid__label">builds</span></div>
  <div class="lid__item"><span class="lid__number"><?= $summary['rate'] === null ? '—' : (int) $summary['rate'] . '%' ?></span><span class="lid__label">de sucesso</span></div>
  <div class="lid__item"><span class="lid__number"><?= $v->e($v->duration($summary['avg_ms'])) ?></span><span class="lid__label">duração média · p95 <?= $v->e($v->duration($summary['p95_ms'])) ?></span></div>
  <div class="lid__item"><span class="lid__number"><?= (int) $summary['queued'] ?><small>/<?= (int) $summary['running'] ?></small></span><span class="lid__label">na fila / rodando</span></div>
</section>

<div class="grid grid--dash">
  <section class="panel">
    <header class="panel__head"><h2>Builds por dia</h2><span class="legend"><i class="legend__ok"></i>sucesso <i class="legend__bad"></i>falha <i class="legend__other"></i>outros</span></header>
    <?= $chart ?>
  </section>
  <section class="panel">
    <header class="panel__head"><h2>Workers</h2><span class="muted"><?= (int) $workersAlive ?> ativo(s) · runtime <?= $v->e($runtime) ?></span></header>
    <?php if ($workers === []): ?>
      <div class="empty empty--small">
        <p class="muted">Nenhum worker conectado. Rode <code>php bin/vau worker</code> no servidor com Docker.</p>
      </div>
    <?php else: ?>
      <ul class="workers">
        <?php foreach (array_slice($workers, 0, 5) as $w): ?>
          <li>
            <?= $v->dot($w['status'] === 'busy' ? 'running' : ($w['status'] === 'idle' ? 'success' : 'canceled')) ?>
            <span class="workers__name mono"><?= $v->e($w['id']) ?></span>
            <span class="muted"><?= $w['status'] === 'busy' && $w['current_build_id'] ? 'build <a href="/builds/' . (int) $w['current_build_id'] . '">#' . (int) $w['current_build_id'] . '</a>' : $v->e(['idle' => 'livre', 'stopped' => 'parado'][$w['status']] ?? $w['status']) ?> · <?= (int) $w['builds_done'] ?> feitos · sinal <?= $v->e($v->ago($w['heartbeat_at'])) ?></span>
          </li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
  </section>
</div>

<section class="panel">
  <header class="panel__head"><h2>Builds recentes</h2><a class="link" href="/builds">Ver todos</a></header>
  <?= $v->partial('partials/build_rows', ['builds' => $recent]) ?>
</section>

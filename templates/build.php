<?php
/** @var Vau\Http\View $v */
$v->share('title', $build['project_name'] . ' #' . $build['number']);
$finished = in_array($build['status'], Vau\Repository\BuildRepository::FINISHED, true);
$kindLabel = ['clone' => 'clone', 'step' => 'etapa', 'deploy' => 'deploy'];
?>
<header class="page__head page__head--build">
  <div>
    <p class="eyebrow"><a href="/projetos/<?= $v->e($build['project_slug']) ?>"><?= $v->e($build['project_name']) ?></a> · build #<?= (int) $build['number'] ?></p>
    <h1><?= $v->e($v->truncate($build['commit_message'] ?: 'Build #' . $build['number'], 120)) ?></h1>
  </div>
  <div class="page__actions">
    <a class="btn" href="/builds/<?= (int) $build['id'] ?>/log.txt" target="_blank" rel="noopener"><?= $v->icon('baixar', 16) ?>Log bruto</a>
    <?php if ($v->can('build')): ?>
      <?php if (!$finished): ?>
        <form method="post" action="/builds/<?= (int) $build['id'] ?>/cancelar" data-confirm="Cancelar este build? O container em execução será encerrado."><?= $v->csrf() ?><button class="btn btn--danger" type="submit"><?= $v->icon('parar', 16) ?>Cancelar</button></form>
      <?php else: ?>
        <form method="post" action="/builds/<?= (int) $build['id'] ?>/repetir"><?= $v->csrf() ?><button class="btn btn--primary" type="submit"><?= $v->icon('repetir', 16) ?>Executar de novo</button></form>
      <?php endif ?>
    <?php endif ?>
  </div>
</header>

<section class="facts" data-build="<?= (int) $build['id'] ?>" data-status="<?= $v->e($build['status']) ?>">
  <div class="facts__item facts__item--status"><span class="facts__label">Status</span><span class="badge badge--<?= $v->e($build['status']) ?>" data-build-status><?= $v->dot($build['status']) ?><span data-build-status-label><?= $v->e($v->status($build['status'])) ?></span></span></div>
  <div class="facts__item"><span class="facts__label"><?= $build['tag'] ? 'Tag' : 'Branch' ?></span><span><?= $v->icon($build['tag'] ? 'commit' : 'branch', 15) ?><?= $v->e($build['tag'] ?? $build['branch'] ?? '—') ?></span></div>
  <div class="facts__item"><span class="facts__label">Commit</span><span class="mono"><?php if ($build['commit_url']): ?><a href="<?= $v->e($build['commit_url']) ?>" target="_blank" rel="noopener noreferrer"><?= $v->e($v->sha($build['commit_sha'])) ?></a><?php else: ?><?= $v->e($v->sha($build['commit_sha'])) ?><?php endif ?></span></div>
  <div class="facts__item"><span class="facts__label">Origem</span><span><?= $v->e($v->trigger($build['trigger_kind'])) ?><?= $build['pr_number'] ? ' #' . (int) $build['pr_number'] : '' ?><?= $build['commit_author'] ? ' · ' . $v->e($build['commit_author']) : '' ?><?= $build['requested_by_name'] ? ' · ' . $v->e($build['requested_by_name']) : '' ?></span></div>
  <div class="facts__item"><span class="facts__label">Duração</span><span data-build-duration data-started="<?= $v->e($build['started_at'] ? str_replace(' ', 'T', $build['started_at']) . 'Z' : '') ?>"><?= $v->e($build['status'] === 'running' ? 'em andamento' : $v->duration($build['duration_ms'])) ?></span></div>
  <div class="facts__item"><span class="facts__label">Na fila em</span><span><?= $v->e($v->date($build['queued_at'], 'd/m/Y H:i:s')) ?></span></div>
</section>

<?php if ($build['error_message']): ?>
  <p class="callout callout--<?= $build['status'] === 'canceled' ? 'neutro' : 'erro' ?>" data-build-error><?= $v->e($build['error_message']) ?></p>
<?php else: ?>
  <p class="callout callout--erro" data-build-error hidden></p>
<?php endif ?>
<?php if ($retryOf): ?><p class="muted small">Repetição do <a href="/builds/<?= (int) $retryOf['id'] ?>">build #<?= (int) $retryOf['number'] ?></a>.</p><?php endif ?>
<?php if ((int) $build['is_fork']): ?><p class="muted small">Pull request de fork: segredos do projeto não foram entregues a este build.</p><?php endif ?>

<div class="split">
  <section class="panel panel--steps">
    <header class="panel__head"><h2>Etapas</h2><?php if ($build['pipeline_file']): ?><span class="muted mono small"><?= $v->e($build['pipeline_file']) ?></span><?php endif ?></header>
    <ol class="steps" data-steps>
      <?php foreach ($steps as $s): ?>
        <li class="step step--<?= $v->e($s['status']) ?>" data-step="<?= (int) $s['id'] ?>">
          <a href="#log-<?= (int) $s['id'] ?>" class="step__link">
            <span class="step__dot"><?= $v->dot($s['status']) ?></span>
            <span class="step__body">
              <span class="step__name"><?= $v->e($s['name']) ?></span>
              <span class="step__meta"><span data-step-label><?= $v->e($v->status($s['status'])) ?></span> · <?= $v->e($kindLabel[$s['kind']] ?? $s['kind']) ?><?= $s['image'] ? ' · <span class="mono">' . $v->e($s['image']) . '</span>' : '' ?></span>
              <span class="step__note" data-step-note><?= $v->e($s['note'] ?? '') ?></span>
            </span>
            <span class="step__time" data-step-time><?= $v->e($s['duration_ms'] !== null ? $v->duration($s['duration_ms']) : '') ?></span>
          </a>
        </li>
      <?php endforeach ?>
      <?php if ($steps === []): ?><li class="muted small">As etapas aparecem quando o worker começar o build.</li><?php endif ?>
    </ol>
  </section>
  <section class="panel panel--log">
    <header class="panel__head">
      <h2>Log</h2>
      <span class="log__tools">
        <label class="switch"><input type="checkbox" data-follow checked><span>Acompanhar</span></label>
        <button type="button" class="btn btn--ghost btn--small" data-copy-log><?= $v->icon('copiar', 14) ?>Copiar</button>
      </span>
    </header>
    <div class="log" data-log data-url="/builds/<?= (int) $build['id'] ?>/log" data-finished="<?= $finished ? '1' : '0' ?>" tabindex="0" aria-live="polite" aria-label="Saída do build">
      <p class="log__waiting" data-log-waiting><?= $build['status'] === 'queued' ? 'Aguardando um worker livre…' : 'Carregando log…' ?></p>
    </div>
  </section>
</div>

<?php if ($delivery): ?>
  <p class="muted small">Disparado pela <a href="/entregas/<?= (int) $delivery['id'] ?>">entrega de webhook #<?= (int) $delivery['id'] ?></a> (<?= $v->e($delivery['provider']) ?> · <?= $v->e($delivery['event']) ?>).</p>
<?php endif ?>

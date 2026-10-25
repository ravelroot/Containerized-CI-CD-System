<?php
/** @var Vau\Http\View $v */
$showProject = $showProject ?? true;
?>
<?php if ($builds === []): ?>
  <div class="empty">
    <p class="empty__title fina">Nenhum build ainda</p>
    <p class="muted">Faça um push no repositório ou use <strong>Executar</strong> na página do projeto.</p>
  </div>
<?php else: ?>
<ol class="runs">
  <?php foreach ($builds as $b): ?>
    <li class="run run--<?= $v->e($b['status']) ?>">
      <a href="/builds/<?= (int) $b['id'] ?>" class="run__link">
        <span class="run__status"><?= $v->dot($b['status']) ?><span><?= $v->e($v->status($b['status'])) ?></span></span>
        <span class="run__main">
          <span class="run__title"><?php if ($showProject): ?><strong><?= $v->e($b['project_name']) ?></strong> <?php endif ?><span class="run__num">#<?= (int) $b['number'] ?></span> <?= $v->e($v->truncate($b['commit_message'] ?: 'Sem mensagem', 90)) ?></span>
          <span class="run__meta">
            <span><?= $v->icon($b['tag'] ? 'commit' : 'branch', 14) ?><?= $v->e($b['tag'] ? $b['tag'] : ($b['branch'] ?? '—')) ?></span>
            <span class="mono"><?= $v->e($v->sha($b['commit_sha'])) ?></span>
            <span><?= $v->e($v->trigger($b['trigger_kind'])) ?><?= $b['pr_number'] ? ' #' . (int) $b['pr_number'] : '' ?></span>
            <?php if ((int) $b['is_deploy']): ?><span class="tag">deploy</span><?php endif ?>
            <?php if ($b['commit_author']): ?><span><?= $v->e($b['commit_author']) ?></span><?php endif ?>
          </span>
        </span>
        <span class="run__time">
          <span><?= $v->icon('relogio', 14) ?><?= $v->e($b['status'] === 'running' ? 'em andamento' : $v->duration($b['duration_ms'])) ?></span>
          <small title="<?= $v->e($v->date($b['queued_at'], 'd/m/Y H:i:s')) ?>"><?= $v->e($v->ago($b['queued_at'])) ?></small>
        </span>
      </a>
    </li>
  <?php endforeach ?>
</ol>
<?php endif ?>

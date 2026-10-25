<?php
/** @var Vau\Http\View $v */
$showProject = $showProject ?? true;
$codeClass = static fn (int $c) => $c === 202 ? 'success' : ($c < 300 ? 'neutral' : 'failed');
?>
<?php if ($deliveries === []): ?>
  <p class="muted">Nenhuma entrega recebida ainda.</p>
<?php else: ?>
  <table class="table table--hover">
    <thead><tr><th>Código</th><?php if ($showProject): ?><th>Projeto</th><?php endif ?><th>Evento</th><th>Resultado</th><th>Recebida</th></tr></thead>
    <tbody>
      <?php foreach ($deliveries as $d): ?>
        <tr data-href="/entregas/<?= (int) $d['id'] ?>">
          <td><a href="/entregas/<?= (int) $d['id'] ?>" class="code-pill code-pill--<?= $codeClass((int) $d['status_code']) ?>"><?= (int) $d['status_code'] ?></a></td>
          <?php if ($showProject): ?><td><?= $v->e($d['project_name'] ?? '—') ?></td><?php endif ?>
          <td><span class="mono small"><?= $v->e($d['provider']) ?> · <?= $v->e($d['event']) ?></span></td>
          <td><?= $v->e($v->truncate($d['outcome'], 80)) ?><?= $d['build_id'] ? ' <a class="link" href="/builds/' . (int) $d['build_id'] . '">ver build</a>' : '' ?></td>
          <td title="<?= $v->e($v->date($d['received_at'], 'd/m/Y H:i:s')) ?>"><?= $v->e($v->ago($d['received_at'])) ?></td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>

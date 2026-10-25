<?php
/** @var Vau\Http\View $v */
$v->share('title', 'Projetos');
?>
<header class="page__head">
  <div>
    <p class="eyebrow"><?= count($projects) ?> cadastrado(s)</p>
    <h1>Projetos</h1>
  </div>
  <?php if ($v->can('manage')): ?><div class="page__actions"><a class="btn btn--primary" href="/projetos/novo"><?= $v->icon('mais', 16) ?>Novo projeto</a></div><?php endif ?>
</header>

<?php if ($projects === []): ?>
  <section class="panel">
    <div class="empty">
      <p class="empty__title fina">Nenhum projeto ainda</p>
      <p class="muted">Cadastre um repositório do GitHub ou do GitLab. O Vau gera a URL e o segredo do webhook.</p>
      <?php if ($v->can('manage')): ?><a class="btn btn--primary" href="/projetos/novo"><?= $v->icon('mais', 16) ?>Cadastrar o primeiro</a><?php endif ?>
    </div>
  </section>
<?php else: ?>
  <section class="cards">
    <?php foreach ($projects as $p): $last = $p['last_build']; ?>
      <a class="card card--project<?= (int) $p['active'] ? '' : ' is-off' ?>" href="/projetos/<?= $v->e($p['slug']) ?>">
        <span class="card__top">
          <span class="card__title"><?= $v->dot($last['status'] ?? 'none') ?><strong><?= $v->e($p['name']) ?></strong></span>
          <span class="tag"><?= $v->e(Vau\Repository\ProjectRepository::PROVIDERS[$p['provider']] ?? $p['provider']) ?></span>
        </span>
        <span class="card__repo mono"><?= $v->e(preg_replace('#^https://#', '', (string) $p['repo_url'])) ?></span>
        <span class="card__strip"><?= $strips[(int) $p['id']] ?? '' ?></span>
        <span class="card__stats">
          <span><strong class="fina"><?= $p['success_rate'] === null ? '—' : (int) $p['success_rate'] . '%' ?></strong><small>sucesso em 30 dias</small></span>
          <span><strong class="fina"><?= (int) $p['builds_30d'] ?></strong><small>builds em 30 dias</small></span>
          <span><strong class="fina"><?= $last ? '#' . (int) $last['number'] : '—' ?></strong><small><?= $last ? $v->e($v->status($last['status']) . ' · ' . $v->ago($last['queued_at'])) : 'sem builds' ?></small></span>
        </span>
        <?php if (!(int) $p['active']): ?><span class="muted small">Desativado: webhooks são recusados.</span><?php endif ?>
      </a>
    <?php endforeach ?>
  </section>
<?php endif ?>

<?php
/** @var Vau\Http\View $v */
$v->share('title', 'Builds');
$query = static fn (array $extra) => '?' . http_build_query(array_filter(array_merge(['projeto' => $filterProject, 'status' => $filterStatus], $extra)));
?>
<header class="page__head">
  <div>
    <p class="eyebrow"><?= (int) $total ?> no histórico</p>
    <h1>Builds</h1>
  </div>
  <form class="filters" method="get" action="/builds">
    <label class="sr-only" for="f-projeto">Projeto</label>
    <select id="f-projeto" name="projeto" data-autosubmit>
      <option value="">Todos os projetos</option>
      <?php foreach ($projects as $p): ?><option value="<?= $v->e($p['slug']) ?>"<?= $filterProject === $p['slug'] ? ' selected' : '' ?>><?= $v->e($p['name']) ?></option><?php endforeach ?>
    </select>
    <label class="sr-only" for="f-status">Status</label>
    <select id="f-status" name="status" data-autosubmit>
      <option value="">Todos os status</option>
      <?php foreach (Vau\Repository\BuildRepository::STATUS as $key => $label): ?><option value="<?= $key ?>"<?= $filterStatus === $key ? ' selected' : '' ?>><?= $v->e($label) ?></option><?php endforeach ?>
    </select>
    <noscript><button class="btn" type="submit">Filtrar</button></noscript>
  </form>
</header>
<section class="panel">
  <?= $v->partial('partials/build_rows', ['builds' => $builds]) ?>
  <?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Páginas">
      <?php if ($pageNumber > 1): ?><a class="btn" href="<?= $v->e($query(['pagina' => $pageNumber - 1])) ?>">Anterior</a><?php endif ?>
      <span class="muted">Página <?= (int) $pageNumber ?> de <?= (int) $pages ?></span>
      <?php if ($pageNumber < $pages): ?><a class="btn" href="<?= $v->e($query(['pagina' => $pageNumber + 1])) ?>">Próxima</a><?php endif ?>
    </nav>
  <?php endif ?>
</section>

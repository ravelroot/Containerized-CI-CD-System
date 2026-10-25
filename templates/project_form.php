<?php
/** @var Vau\Http\View $v */
$v->share('title', 'Novo projeto');
?>
<header class="page__head">
  <div>
    <p class="eyebrow"><a href="/projetos">Projetos</a></p>
    <h1>Novo projeto</h1>
  </div>
</header>
<?php if ($error): ?><div class="flash flash--erro" role="alert"><?= $v->e($error) ?></div><?php endif ?>
<form method="post" action="/projetos" class="form">
  <?= $v->csrf() ?>
  <?= $v->partial('partials/project_fields', ['form' => $form]) ?>
  <fieldset class="group">
    <legend>Pipeline padrão <small>(opcional)</small></legend>
    <p class="muted small">Usado quando o repositório não tem <code>.vau.yml</code> na raiz.</p>
    <textarea name="pipeline_yaml" class="code" rows="10" spellcheck="false" placeholder="image: node:22-alpine&#10;steps:&#10;  - name: Dependências&#10;    run: npm ci&#10;  - name: Testes&#10;    run: npm test"><?= $v->e($form['pipeline_yaml'] ?? '') ?></textarea>
  </fieldset>
  <div class="form__actions">
    <a class="btn btn--ghost" href="/projetos">Cancelar</a>
    <button class="btn btn--primary" type="submit">Criar projeto <?= $v->icon('seta', 16) ?></button>
  </div>
</form>

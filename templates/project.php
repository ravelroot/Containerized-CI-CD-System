<?php
/** @var Vau\Http\View $v */
$v->share('title', $project['name']);
$slug = $v->e($project['slug']);
?>
<header class="page__head">
  <div>
    <p class="eyebrow"><a href="/projetos">Projetos</a> · <span class="mono"><?= $slug ?></span><?= (int) $project['active'] ? '' : ' · <strong>desativado</strong>' ?></p>
    <h1><?= $v->e($project['name']) ?></h1>
    <p class="muted mono small"><?= $v->e($project['repo_url']) ?> · <?= $v->e($project['default_branch']) ?></p>
  </div>
  <?php if ($v->can('build')): ?>
    <form class="run-form" method="post" action="/projetos/<?= $slug ?>/executar">
      <?= $v->csrf() ?>
      <label class="sr-only" for="run-branch">Branch</label>
      <input id="run-branch" name="branch" placeholder="<?= $v->e($project['default_branch']) ?>" aria-label="Branch">
      <label class="sr-only" for="run-commit">Commit</label>
      <input id="run-commit" name="commit" placeholder="commit (opcional)" class="mono" aria-label="Commit">
      <button class="btn btn--primary" type="submit"><?= $v->icon('play', 15) ?>Executar</button>
    </form>
  <?php endif ?>
</header>

<nav class="tabs" aria-label="Seções do projeto">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="/projetos/<?= $slug ?>?aba=<?= $key ?>" class="tabs__item<?= $tab === $key ? ' is-active' : '' ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= $v->e($label) ?><?= $key === 'segredos' && $secretCount ? ' <span class="count">' . (int) $secretCount . '</span>' : '' ?></a>
  <?php endforeach ?>
</nav>

<?php if ($error): ?><div class="flash flash--erro" role="alert"><?= $v->e($error) ?></div><?php endif ?>

<?php if ($tab === 'builds'): ?>
  <section class="panel">
    <?= $v->partial('partials/build_rows', ['builds' => $builds, 'showProject' => false]) ?>
  </section>

<?php elseif ($tab === 'webhook'): ?>
  <section class="panel">
    <header class="panel__head"><h2>Cadastre no <?= $project['provider'] === 'gitlab' ? 'GitLab' : 'GitHub' ?></h2></header>
    <ol class="howto">
      <?php if ($project['provider'] === 'gitlab'): ?>
        <li>No projeto do GitLab, abra <strong>Settings → Webhooks → Add new webhook</strong>.</li>
        <li>Cole a URL e o segredo abaixo (o segredo vai em <strong>Secret token</strong>).</li>
        <li>Marque <strong>Push events</strong>, <strong>Tag push events</strong> e <strong>Merge request events</strong> e salve.</li>
      <?php else: ?>
        <li>No repositório do GitHub, abra <strong>Settings → Webhooks → Add webhook</strong>.</li>
        <li>Cole a URL em <strong>Payload URL</strong>, escolha <strong>application/json</strong> e cole o segredo em <strong>Secret</strong>.</li>
        <li>Em eventos, escolha <strong>Pushes</strong> e <strong>Pull requests</strong> e salve. O GitHub manda um ping; ele aparece na lista abaixo.</li>
      <?php endif ?>
    </ol>
    <div class="copyrow"><span class="copyrow__label">URL</span><code data-copy-source><?= $v->e($webhookUrl) ?></code><button type="button" class="btn btn--ghost btn--small" data-copy><?= $v->icon('copiar', 14) ?>Copiar</button></div>
    <?php if ($secret !== null): ?>
      <div class="copyrow"><span class="copyrow__label">Segredo</span><code data-copy-source class="is-secret" data-reveal><?= $v->e($secret) ?></code><button type="button" class="btn btn--ghost btn--small" data-copy><?= $v->icon('copiar', 14) ?>Copiar</button></div>
      <form method="post" action="/projetos/<?= $slug ?>/segredo-webhook" data-confirm="Trocar o segredo? O webhook atual deixa de funcionar até você atualizar no provedor."><?= $v->csrf() ?><button class="link" type="submit">Trocar segredo</button></form>
    <?php endif ?>
    <div class="copyrow"><span class="copyrow__label">Selo</span><code data-copy-source>![vau](<?= $v->e($v->appUrl()) ?>/badge/<?= $slug ?>.svg)</code><button type="button" class="btn btn--ghost btn--small" data-copy><?= $v->icon('copiar', 14) ?>Copiar</button></div>
  </section>
  <section class="panel">
    <header class="panel__head"><h2>Últimas entregas</h2><a class="link" href="/entregas">Todas</a></header>
    <?= $v->partial('partials/delivery_rows', ['deliveries' => $deliveries, 'showProject' => false]) ?>
  </section>

<?php elseif ($tab === 'pipeline'): ?>
  <section class="panel">
    <header class="panel__head"><h2>Pipeline padrão</h2></header>
    <p class="muted">O Vau procura <code>.vau.yml</code>, <code>.vau.yaml</code> ou <code>.vau.json</code> na raiz do commit. Se não achar, usa o texto abaixo. Cada etapa roda num container novo, com o código em <code>/workspace</code>.</p>
    <form method="post" action="/projetos/<?= $slug ?>" class="form">
      <?= $v->csrf() ?>
      <input type="hidden" name="_so_pipeline" value="1">
      <input type="hidden" name="aba" value="pipeline">
      <textarea name="pipeline_yaml" class="code" rows="16" spellcheck="false" <?= $v->can('manage') ? '' : 'readonly' ?> placeholder="image: php:8.3-cli&#10;steps:&#10;  - name: Testes&#10;    run: vendor/bin/phpunit"><?= $v->e($form['pipeline_yaml'] ?? '') ?></textarea>
      <?php if ($v->can('manage')): ?><div class="form__actions"><button class="btn btn--primary" type="submit">Salvar pipeline</button></div><?php endif ?>
    </form>
  </section>
  <section class="panel">
    <header class="panel__head"><h2>Referência rápida</h2></header>
<pre class="code code--block">image: php:8.3-cli          # imagem padrão das etapas
timeout: 20                 # minutos (o menor entre este e o do projeto vale)
env:
  APP_ENV: testing
steps:
  - name: Dependências
    run: composer install --no-interaction --prefer-dist
  - name: Testes
    run: vendor/bin/phpunit
  - name: Lint do front
    image: node:22-alpine   # imagem própria da etapa
    run: |
      npm ci
      npm run lint
    allow_failure: true     # falha aqui não derruba o build
deploy:                     # só roda se tudo passar e a branch estiver na lista
  branches: [main, "tag:v*"]
  image: alpine:3.20
  run: ./scripts/deploy.sh   # segredos "somente deploy" chegam só aqui</pre>
  </section>

<?php elseif ($tab === 'segredos'): ?>
  <section class="panel">
    <header class="panel__head"><h2>Segredos</h2><span class="muted">Cifrados no banco, injetados como variáveis de ambiente e mascarados no log.</span></header>
    <?php if ($secrets === []): ?>
      <p class="muted">Nenhum segredo. Use para tokens de deploy, chaves de registry e senhas de serviços.</p>
    <?php else: ?>
      <table class="table">
        <thead><tr><th>Nome</th><th>Escopo</th><th>Atualizado</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($secrets as $s): ?>
            <tr>
              <td class="mono"><?= $v->e($s['name']) ?></td>
              <td><?= (int) $s['deploy_only'] ? '<span class="tag">somente deploy</span>' : 'todas as etapas' ?></td>
              <td><?= $v->e($v->date($s['updated_at'])) ?></td>
              <td class="table__actions"><?php if ($v->can('manage')): ?><form method="post" action="/projetos/<?= $slug ?>/segredos/<?= (int) $s['id'] ?>/excluir" data-confirm="Remover o segredo <?= $v->e($s['name']) ?>?"><?= $v->csrf() ?><button class="btn btn--ghost btn--small" type="submit"><?= $v->icon('lixo', 14) ?>Remover</button></form><?php endif ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </section>
  <?php if ($v->can('manage')): ?>
    <section class="panel">
      <header class="panel__head"><h2>Novo segredo</h2><span class="muted">Mesmo nome substitui o valor.</span></header>
      <form method="post" action="/projetos/<?= $slug ?>/segredos" class="form">
        <?= $v->csrf() ?>
        <div class="row">
          <label class="field field--small"><span>Nome</span><input name="nome" class="mono" required pattern="[A-Za-z_][A-Za-z0-9_]*" placeholder="DEPLOY_TOKEN"></label>
          <label class="field"><span>Valor</span><input name="valor" type="password" autocomplete="new-password" required></label>
        </div>
        <label class="check"><input type="checkbox" name="somente_deploy" value="1"><span>Somente na etapa de deploy</span></label>
        <div class="form__actions"><button class="btn btn--primary" type="submit"><?= $v->icon('chave', 15) ?>Gravar segredo</button></div>
      </form>
    </section>
  <?php endif ?>

<?php else: ?>
  <form method="post" action="/projetos/<?= $slug ?>" class="form">
    <?= $v->csrf() ?>
    <input type="hidden" name="aba" value="configuracoes">
    <?= $v->partial('partials/project_fields', ['form' => $form]) ?>
    <fieldset class="group">
      <legend>Situação</legend>
      <label class="check"><input type="checkbox" name="active" value="1"<?= (int) ($form['active'] ?? 1) ? ' checked' : '' ?>><span>Projeto ativo (desmarque para recusar webhooks sem apagar o histórico)</span></label>
    </fieldset>
    <?php if ($v->can('manage')): ?><div class="form__actions"><button class="btn btn--primary" type="submit">Salvar alterações</button></div><?php endif ?>
  </form>
  <?php if ($v->can('admin')): ?>
    <section class="panel panel--danger">
      <header class="panel__head"><h2>Excluir projeto</h2></header>
      <p class="muted">Apaga o projeto, os segredos e todo o histórico de builds e logs. Não há como desfazer.</p>
      <form method="post" action="/projetos/<?= $slug ?>/excluir" class="row row--end">
        <?= $v->csrf() ?>
        <label class="field"><span>Digite <strong class="mono"><?= $slug ?></strong> para confirmar</span><input name="confirmar" autocomplete="off"></label>
        <button class="btn btn--danger" type="submit"><?= $v->icon('lixo', 15) ?>Excluir</button>
      </form>
    </section>
  <?php endif ?>
<?php endif ?>

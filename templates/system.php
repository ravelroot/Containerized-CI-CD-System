<?php
/** @var Vau\Http\View $v */
$v->share('title', 'Sistema e conta');
$labels = Vau\Repository\AuditRepository::ACTIONS;
?>
<header class="page__head">
  <div>
    <p class="eyebrow">Vau <?= $v->e($version) ?> · PHP <?= $v->e($php) ?> · banco <?= $v->e($driver) ?></p>
    <h1>Sistema e conta</h1>
  </div>
</header>

<section class="lid lid--system" aria-label="Execução">
  <div class="lid__item"><span class="lid__number"><?= $v->e($runtimeName) ?></span><span class="lid__label">runtime de builds</span></div>
  <div class="lid__item lid__item--wide"><span class="lid__status"><?= $v->dot($runtimeCheck['ok'] ? 'success' : 'failed') ?><?= $v->e($runtimeCheck['detail']) ?></span><span class="lid__label">diagnóstico do runtime</span></div>
  <div class="lid__item"><span class="lid__number"><?= count(array_filter($workers, static fn ($w) => $w['status'] !== 'stopped')) ?></span><span class="lid__label">worker(s) registrados</span></div>
</section>

<div class="grid grid--two">
  <section class="panel" id="conta">
    <header class="panel__head"><h2>Trocar senha</h2></header>
    <form method="post" action="/sistema/senha" class="form">
      <?= $v->csrf() ?>
      <label class="field"><span>Senha atual</span><input type="password" name="atual" autocomplete="current-password" required></label>
      <div class="row">
        <label class="field"><span>Nova senha</span><input type="password" name="nova" autocomplete="new-password" minlength="8" required></label>
        <label class="field"><span>Confirmação</span><input type="password" name="confirmacao" autocomplete="new-password" minlength="8" required></label>
      </div>
      <p class="hint">Mínimo de 8 caracteres, com letras e números.</p>
      <div class="form__actions"><button class="btn btn--primary" type="submit">Trocar senha</button></div>
    </form>
  </section>

  <section class="panel" id="tokens">
    <header class="panel__head"><h2>Tokens da API</h2><span class="muted">Authorization: Bearer</span></header>
    <?php if ($newToken): ?>
      <div class="copyrow copyrow--new"><span class="copyrow__label">Novo</span><code data-copy-source><?= $v->e($newToken) ?></code><button type="button" class="btn btn--ghost btn--small" data-copy><?= $v->icon('copiar', 14) ?>Copiar</button></div>
    <?php endif ?>
    <?php if ($tokens !== []): ?>
      <table class="table table--compact">
        <thead><tr><th>Nome</th><th>Prefixo</th><th>Último uso</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($tokens as $t): ?>
            <tr>
              <td><?= $v->e($t['name']) ?><?= $t['expires_at'] ? '<br><small class="muted">expira ' . $v->e($v->date($t['expires_at'], 'd/m/Y')) . '</small>' : '' ?></td>
              <td class="mono small"><?= $v->e($t['token_prefix']) ?>…</td>
              <td><?= $v->e($v->ago($t['last_used_at'])) ?></td>
              <td class="table__actions"><form method="post" action="/sistema/tokens/<?= (int) $t['id'] ?>/revogar" data-confirm="Revogar este token?"><?= $v->csrf() ?><button class="btn btn--ghost btn--small" type="submit">Revogar</button></form></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
    <form method="post" action="/sistema/tokens" class="row row--end">
      <?= $v->csrf() ?>
      <label class="field"><span>Nome</span><input name="nome" placeholder="pipeline-externo" required></label>
      <label class="field field--tiny"><span>Dias</span><input name="dias" type="number" min="0" placeholder="∞"></label>
      <button class="btn" type="submit"><?= $v->icon('chave', 15) ?>Criar</button>
    </form>
  </section>
</div>

<?php if ($v->can('admin')): ?>
  <section class="panel" id="usuarios">
    <header class="panel__head"><h2>Usuários</h2><span class="muted">admin: tudo · operador: projetos e builds · leitor: consulta</span></header>
    <table class="table">
      <thead><tr><th>Nome</th><th>Perfil</th><th>Último acesso</th><th>Situação</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): $locked = $u['locked_until'] && $v->ago($u['locked_until']) === 'agora'; ?>
          <tr>
            <td><strong><?= $v->e($u['name']) ?></strong><br><span class="muted mono small"><?= $v->e($u['username']) ?></span></td>
            <td colspan="4">
              <form method="post" action="/sistema/usuarios/<?= (int) $u['id'] ?>" class="inline-form">
                <?= $v->csrf() ?>
                <select name="perfil" aria-label="Perfil"><?php foreach (Vau\Repository\UserRepository::ROLES as $k => $l): ?><option value="<?= $k ?>"<?= $u['role'] === $k ? ' selected' : '' ?>><?= $v->e($l) ?></option><?php endforeach ?></select>
                <span class="muted small"><?= $v->e($v->ago($u['last_login_at'])) ?></span>
                <label class="check"><input type="checkbox" name="ativo" value="1"<?= (int) $u['active'] ? ' checked' : '' ?>><span>ativo</span></label>
                <?php if ($u['locked_until']): ?><label class="check"><input type="checkbox" name="desbloquear" value="1"><span>desbloquear</span></label><?php endif ?>
                <input type="password" name="senha" placeholder="nova senha (opcional)" autocomplete="new-password" aria-label="Nova senha">
                <button class="btn btn--small" type="submit">Salvar</button>
              </form>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
    <form method="post" action="/sistema/usuarios" class="row row--end">
      <?= $v->csrf() ?>
      <label class="field"><span>Usuário</span><input name="usuario" required autocapitalize="none"></label>
      <label class="field"><span>Nome</span><input name="nome" required></label>
      <label class="field field--small"><span>Perfil</span><select name="perfil"><?php foreach (Vau\Repository\UserRepository::ROLES as $k => $l): ?><option value="<?= $k ?>"<?= $k === 'operador' ? ' selected' : '' ?>><?= $v->e($l) ?></option><?php endforeach ?></select></label>
      <label class="field"><span>Senha inicial</span><input name="senha" type="password" autocomplete="new-password" required minlength="8"></label>
      <button class="btn btn--primary" type="submit"><?= $v->icon('mais', 15) ?>Criar</button>
    </form>
  </section>

  <div class="grid grid--two">
    <section class="panel">
      <header class="panel__head"><h2>Configuração ativa</h2><span class="muted">.env e ambiente</span></header>
      <table class="table table--compact">
        <tbody><?php foreach ($config as $key => $value): ?><tr><td class="mono small"><?= $v->e($key) ?></td><td class="mono small break"><?= $v->e($value) ?></td></tr><?php endforeach ?></tbody>
      </table>
    </section>
    <section class="panel">
      <header class="panel__head"><h2>Auditoria</h2><span class="muted">últimas 25 ações</span></header>
      <ul class="audit">
        <?php foreach ($audit as $a): ?>
          <li><span><strong><?= $v->e($a['user_name'] ?? 'sistema') ?></strong> <?= $v->e(mb_strtolower($labels[$a['action']] ?? $a['action'])) ?><?= $a['target_type'] ? ' <span class="muted">' . $v->e($a['target_type'] . ' ' . $a['target_id']) . '</span>' : '' ?></span><small class="muted"><?= $v->e($v->ago($a['created_at'])) ?> · <?= $v->e($a['ip'] ?? '') ?></small></li>
        <?php endforeach ?>
      </ul>
    </section>
  </div>
<?php endif ?>

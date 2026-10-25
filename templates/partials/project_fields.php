<?php
/** @var Vau\Http\View $v */
$events = is_array($form['events'] ?? null) ? $form['events'] : array_filter(explode(',', (string) ($form['events'] ?? '')));
$checked = static fn ($value) => !empty($value) ? ' checked' : '';
$isNew = !isset($form['id']);
?>
<fieldset class="group">
  <legend>Repositório</legend>
  <div class="row">
    <label class="field"><span>Nome</span><input name="name" value="<?= $v->e($form['name'] ?? '') ?>" maxlength="120" required placeholder="API de pagamentos"></label>
    <label class="field field--small"><span>Provedor</span>
      <select name="provider">
        <?php foreach (Vau\Repository\ProjectRepository::PROVIDERS as $key => $label): ?><option value="<?= $key ?>"<?= ($form['provider'] ?? '') === $key ? ' selected' : '' ?>><?= $v->e($label) ?></option><?php endforeach ?>
      </select>
    </label>
  </div>
  <label class="field"><span>URL de clonagem (HTTPS)</span><input name="repo_url" value="<?= $v->e($form['repo_url'] ?? '') ?>" required placeholder="https://github.com/sua-org/seu-repo.git" inputmode="url"></label>
  <div class="row">
    <label class="field field--small"><span>Branch padrão</span><input name="default_branch" value="<?= $v->e($form['default_branch'] ?? 'main') ?>" required></label>
    <label class="field"><span>Token de acesso <small>(repositório privado e status no commit)</small></span><input name="access_token" type="password" autocomplete="new-password" placeholder="<?= $isNew || empty($form['access_token_enc']) ? 'ghp_… ou glpat-…' : 'guardado · deixe vazio para manter, - para remover' ?>"></label>
  </div>
</fieldset>

<fieldset class="group">
  <legend>Gatilhos</legend>
  <div class="checks">
    <?php foreach (Vau\Repository\ProjectRepository::EVENTS as $key => $label): ?>
      <label class="check"><input type="checkbox" name="events[]" value="<?= $key ?>"<?= in_array($key, $events, true) ? ' checked' : '' ?>><span><?= $v->e($label) ?></span></label>
    <?php endforeach ?>
  </div>
  <label class="field"><span>Filtro de branches <small>(vazio aceita todas; ex.: main, release/*, !wip/*)</small></span><input name="branch_filter" value="<?= $v->e($form['branch_filter'] ?? '') ?>" placeholder="main, develop, feature/*"></label>
  <label class="check"><input type="checkbox" name="auto_cancel" value="1"<?= $checked($form['auto_cancel'] ?? 1) ?>><span>Cancelar builds antigos da mesma branch quando chegar um commit novo</span></label>
</fieldset>

<fieldset class="group">
  <legend>Limites do container</legend>
  <div class="row row--4">
    <label class="field"><span>Tempo limite (min)</span><input name="timeout_minutes" type="number" min="1" max="360" value="<?= (int) ($form['timeout_minutes'] ?? 30) ?>"></label>
    <label class="field"><span>CPUs</span><input name="cpus" value="<?= $v->e($form['cpus'] ?? '1.0') ?>" inputmode="decimal"></label>
    <label class="field"><span>Memória</span><input name="memory" value="<?= $v->e($form['memory'] ?? '1g') ?>"></label>
    <label class="field"><span>Rede</span>
      <select name="network">
        <option value="bridge"<?= ($form['network'] ?? 'bridge') === 'bridge' ? ' selected' : '' ?>>Com internet</option>
        <option value="none"<?= ($form['network'] ?? '') === 'none' ? ' selected' : '' ?>>Sem rede</option>
      </select>
    </label>
  </div>
</fieldset>

<fieldset class="group">
  <legend>Deploy e avisos</legend>
  <label class="check"><input type="checkbox" name="allow_deploy" value="1"<?= $checked($form['allow_deploy'] ?? 1) ?>><span>Permitir a etapa de deploy do pipeline</span></label>
  <label class="check"><input type="checkbox" name="report_status" value="1"<?= $checked($form['report_status'] ?? 1) ?>><span>Publicar o status no commit (precisa do token)</span></label>
  <div class="row">
    <label class="field"><span>Webhook de chat <small>(Slack, Discord, Google Chat)</small></span><input name="notify_url" value="<?= $v->e($form['notify_url'] ?? '') ?>" placeholder="https://hooks.slack.com/services/…" inputmode="url"></label>
    <label class="field field--small"><span>Avisar em</span>
      <select name="notify_on">
        <?php foreach (Vau\Repository\ProjectRepository::NOTIFY as $key => $label): ?><option value="<?= $key ?>"<?= ($form['notify_on'] ?? 'falhas') === $key ? ' selected' : '' ?>><?= $v->e($label) ?></option><?php endforeach ?>
      </select>
    </label>
  </div>
</fieldset>

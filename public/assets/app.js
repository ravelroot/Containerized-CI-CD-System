// Vau · comportamento do painel (sem dependências).
(function () {
  'use strict';

  var raiz = document.documentElement;

  // ---------- tema claro/escuro ----------
  function rotularTema() {
    var escuro = raiz.getAttribute('data-theme') === 'dark';
    document.querySelectorAll('[data-theme-label]').forEach(function (el) {
      el.textContent = escuro ? 'Tema claro' : 'Tema escuro';
    });
  }
  document.querySelectorAll('[data-theme-toggle]').forEach(function (botao) {
    botao.addEventListener('click', function () {
      var novo = raiz.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      raiz.setAttribute('data-theme', novo);
      try { localStorage.setItem('vau-tema', novo); } catch (e) {}
      rotularTema();
    });
  });
  rotularTema();

  // ---------- confirmação, filtros e linhas clicáveis ----------
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.confirm(form.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });
  document.querySelectorAll('[data-autosubmit]').forEach(function (campo) {
    campo.addEventListener('change', function () { campo.form.submit(); });
  });
  document.querySelectorAll('tr[data-href]').forEach(function (linha) {
    linha.addEventListener('click', function (ev) {
      if (ev.target.closest('a, button, input, select, form')) return;
      window.location.href = linha.getAttribute('data-href');
    });
  });

  // ---------- copiar e revelar ----------
  function copiar(texto, botao) {
    var feito = function () {
      if (!botao) return;
      var original = botao.innerHTML;
      botao.textContent = 'Copiado';
      setTimeout(function () { botao.innerHTML = original; }, 1400);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(texto).then(feito, function () { copiarAntigo(texto); feito(); });
    } else {
      copiarAntigo(texto);
      feito();
    }
  }
  function copiarAntigo(texto) {
    var area = document.createElement('textarea');
    area.value = texto;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(area);
  }
  document.querySelectorAll('[data-copy]').forEach(function (botao) {
    botao.addEventListener('click', function () {
      var fonte = botao.parentElement.querySelector('[data-copy-source]');
      if (fonte) copiar(fonte.textContent.trim(), botao);
    });
  });
  document.querySelectorAll('[data-reveal]').forEach(function (el) {
    el.addEventListener('click', function () { el.classList.add('is-revealed'); });
  });

  // ---------- log ao vivo do build ----------
  var log = document.querySelector('[data-log]');
  if (!log) return;

  var url = log.getAttribute('data-url');
  var ultimo = 0;
  var terminado = log.getAttribute('data-finished') === '1';
  var seguir = document.querySelector('[data-follow]');
  var espera = log.querySelector('[data-log-waiting]');
  var secoes = {};
  var nomes = {};
  var rotulos = { pending: 'Aguardando', running: 'Executando', success: 'Sucesso', failed: 'Falhou', skipped: 'Pulado', canceled: 'Cancelado', queued: 'Na fila', error: 'Erro' };
  var intervalo = 1000;
  var resto = {};

  document.querySelectorAll('[data-step]').forEach(function (li) {
    nomes[li.getAttribute('data-step')] = li.querySelector('.step__name').textContent;
  });

  function duracao(ms) {
    if (ms === null || ms === undefined) return '';
    var s = Math.floor(ms / 1000);
    if (s < 1) return (ms / 1000).toFixed(1).replace('.', ',') + ' s';
    if (s < 60) return s + ' s';
    var m = Math.floor(s / 60);
    if (m < 60) return m + ' min ' + String(s % 60).padStart(2, '0') + ' s';
    return Math.floor(m / 60) + ' h ' + String(m % 60).padStart(2, '0') + ' min';
  }

  var ultimaEtapa;
  function cabecalho(passo) {
    var chave = passo === null ? 'geral' : String(passo);
    var cab = document.createElement('div');
    cab.className = 'log__section';
    cab.setAttribute('data-section', chave);
    if (!document.getElementById('log-' + chave)) cab.id = 'log-' + chave;
    cab.textContent = passo === null ? 'Vau' : (nomes[chave] || 'Etapa');
    log.appendChild(cab);
    (secoes[chave] = secoes[chave] || []).push(cab);
  }

  function anexar(trecho) {
    var chave = (trecho.step === null ? 'g' : trecho.step) + ':' + trecho.stream;
    var texto = (resto[chave] || '') + trecho.text;
    var linhas = texto.split('\n');
    resto[chave] = linhas.pop();
    if (!linhas.length) return;
    // o log segue a ordem real; um cabeçalho novo aparece sempre que a etapa muda
    if (trecho.step !== ultimaEtapa) {
      cabecalho(trecho.step);
      ultimaEtapa = trecho.step;
    }
    var frag = document.createDocumentFragment();
    linhas.forEach(function (linha) {
      var div = document.createElement('div');
      div.className = 'log__line log__line--' + trecho.stream;
      div.textContent = linha;
      frag.appendChild(div);
    });
    log.appendChild(frag);
  }

  function atualizarEtapas(etapas) {
    etapas.forEach(function (e) {
      var li = document.querySelector('[data-step="' + e.id + '"]');
      if (!li) { novaPagina(); return; }
      li.className = 'step step--' + e.status;
      var ponto = li.querySelector('.dot');
      if (ponto) ponto.className = 'dot dot--' + e.status;
      li.querySelector('[data-step-label]').textContent = rotulos[e.status] || e.status;
      li.querySelector('[data-step-time]').textContent = duracao(e.duration_ms);
      li.querySelector('[data-step-note]').textContent = e.note || '';
    });
  }

  var recarregando = false;
  function novaPagina() {
    // apareceram etapas novas (o pipeline foi lido): recarrega a lista uma vez
    if (recarregando) return;
    recarregando = true;
    fetch(window.location.pathname, { headers: { Accept: 'text/html' } }).then(function (r) { return r.text(); }).then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var novas = doc.querySelector('[data-steps]');
      var atuais = document.querySelector('[data-steps]');
      if (novas && atuais) {
        atuais.innerHTML = novas.innerHTML;
        atuais.querySelectorAll('[data-step]').forEach(function (li) {
          nomes[li.getAttribute('data-step')] = li.querySelector('.step__name').textContent;
        });
        Object.keys(secoes).forEach(function (k) {
          if (nomes[k]) secoes[k].forEach(function (cab) { cab.textContent = nomes[k]; });
        });
      }
      recarregando = false;
    }).catch(function () { recarregando = false; });
  }

  function atualizarBuild(dados) {
    var status = document.querySelector('[data-build-status]');
    if (status) {
      status.className = 'badge badge--' + dados.status;
      status.querySelector('.dot').className = 'dot dot--' + dados.status;
      status.querySelector('[data-build-status-label]').textContent = rotulos[dados.status] || dados.status;
    }
    var dur = document.querySelector('[data-build-duration]');
    if (dur) {
      if (dados.finished) dur.textContent = duracao(dados.duration_ms);
      else if (dados.started_at) dur.textContent = duracao(Date.now() - Date.parse(dados.started_at.replace(' ', 'T') + 'Z'));
    }
    var erro = document.querySelector('[data-build-error]');
    if (erro && dados.error) { erro.hidden = false; erro.textContent = dados.error; }
  }

  function buscar() {
    fetch(url + '?depois=' + ultimo, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (dados) {
        if (espera && (dados.chunks.length || dados.finished)) { espera.remove(); espera = null; }
        dados.chunks.forEach(function (c) { anexar(c); ultimo = c.id; });
        atualizarEtapas(dados.steps);
        atualizarBuild(dados);
        if (seguir && seguir.checked && dados.chunks.length) log.scrollTop = log.scrollHeight;
        intervalo = 1000;
        if (dados.chunks.length === 400) return buscar();
        if (dados.finished && dados.chunks.length === 0) {
          if (!terminado) {
            terminado = true;
            var acoes = document.querySelector('.page__actions');
            if (acoes) setTimeout(function () { window.location.reload(); }, 900);
          }
          if (!log.querySelector('.log__line') && !log.querySelector('.log__waiting')) {
            log.insertAdjacentHTML('beforeend', '<p class="log__waiting">Sem saída registrada.</p>');
          }
          return;
        }
        setTimeout(buscar, dados.finished ? 300 : intervalo);
      })
      .catch(function () {
        intervalo = Math.min(intervalo * 2, 15000);
        setTimeout(buscar, intervalo);
      });
  }

  document.querySelector('[data-copy-log]')?.addEventListener('click', function (ev) {
    var linhas = Array.prototype.map.call(log.querySelectorAll('.log__section, .log__line'), function (el) {
      return el.classList.contains('log__section') ? '\n== ' + el.textContent + ' ==' : el.textContent;
    });
    copiar(linhas.join('\n').trim(), ev.currentTarget);
  });
  log.addEventListener('scroll', function () {
    if (!seguir) return;
    var noFim = log.scrollHeight - log.scrollTop - log.clientHeight < 40;
    if (!noFim && seguir.checked) seguir.checked = false;
  });

  buscar();
})();

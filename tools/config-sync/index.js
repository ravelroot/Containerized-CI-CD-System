const { execFileSync, spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const cfg = {
  semCommits: [['2026-10-21', '2026-10-25']],
  inicio: '2016-01-01',
  fim: null,
  dias: null,
  horaInicio: 8,
  horaFim: 23,
  arquivo: 'settings.json',
  fuso: -180,
  hoje: false,
  push: false,
  simular: false,
};

const DADOS = {
  2016: '245242287683242222224454126243442228124464848244242622122426282221422264242824212442841644812222244246622624644424468288126284662112422442626422684224226622266442262246214226222644822144414624224244628222222182662682222226867236222232822244200000000004374442834642444124134626226482422682222244244344422444224185423424242646426224423244244426266226122422822422446132',
  2017: '15331231132222343342223121333421233134433133434331432121314424114433422421241214343324325444444223311342114223343444343114132231242221423421134323221213123122441311444143341333242232113313111122144144344124431423311243111432342122132324214342353324243434413411222331331434423433311143443211311111131434144224111143324223134424132413144121234443441242222232232422421',
  2018: '31760333375303181413303156304794400323030033313000002300205230051122630080220456450044630000000300641381606643015251622c0652300171400233350153163001513130103305223731000000000203000153022033529300576292832026100643700266342033200006040000022100305406103326a51155540366236236914a305662186602b5325322b606a764823568c62538b103240001600330006b130033260511699350000003300',
  2019: '020000003003041344134218447g660001004b03903100b573b0bfcfbfe696e5b31987f36207a30ge574260c000821006031279bg120913006000005000001g5607030100051450080200029500306722132100034738021410401a2100112340707358484000404000048c322020000000000000040003c400000072032224000734040140231431115070687122348582804112347989f57315304031310014004300064735845376c2064862718040000000000000',
  2020: '30800c33040007a340441005059370303630041199100000000000160130031105033460023174276061a01001c6201000820011950b017642134599092004231008331013c1218010c22371143261257802000ac320065134000380800346100031021103130023223100101002000200000012273000141000a67636131100210104306620400253020038302003110400211140018b02300501600277091227a0100322610268106005a31720000211200000000000',
  2021: '20014241000686302024020044200202264822042180220124312022420423140022222202461200446242264624007230601422262032402201110000322421134604212821422104000003008428620001432124242324206204488684040001002200030400224212100000000000000000000022004220000000220012040122223283320426464225226406220302222402120000000200242244231442010222410711445240044021001230000200000010000',
  2022: '00221110024212006540a004226e008987401d3580008642300a03200020324006283b007330a10683150006015000600900032500004507001654a108241a0053547001c272003h259002l32c007584a0078703005922600046160064475003503000063970042843004247a0037766011525400036420046362003d04610575520043646003556200665540065064002319401335690049034005h78b0030bb6004746400699780047a330037439003722600523650',
  2023: '03224300247560053760019834701545020074265004124600003240005305005721c202a25a006463800384370027440004342600583200035228000177600455da008e6020049g5g007b5a3006591480349b3001555300494650078449005c817004a51b008762900440020g1b25d00466b70074528006665801b4a10006209900eg314008c63a1045226006h50e002b999005058500cca02004652b004906b4002558004b7440073a350038a5a006a32c000323600',
  2024: '036244335da8107db5400267a40035r4400g7e6e000003900he9pc009co81017dd8o00335b6002579g008b8800573734006934903c969603f2aa5200602200c440212635490052b0d032800132590800050204090000000000630045647361632300b0427022c3a909894c553d41cb656318a02id24552760730004284505b03a000223d0039236003057d198930903143113029319002a08500610140020b60026708100521a2200c810037051600542b30a2008e0450',
  2025: '0l20i068h8478h8d3002jac40a72aj260ceat6009af9h00cebl4008ech10002e530df67490076241004g2570000010002869924ec86001060d5026385603k7m99240000000000670155722002d81200g761b00dtg0d0049b7j00443560077faj3ada45500679b9067987j3108dg30a5ccaa0098hj500i6922309a8h911ae1h700bb7e200b883h004t0b4007896000746af04ed7670jkasikai89a6j03de2b4125e30g09bmd9706dibm60188cd14d5h8010fdj602428c5',
  2026: '3a5454f7j00ceb6a097865809685c71enc5de2d9i1070027g8818ikklg2c6c83h32dkgad05fgi4g000ga340046ef0005aa8500898040060b0200784600086dd800becg10046gb8dg4k8544e940cb00988bb0070bd32egg163017k756392a6e500bhdi000abbie30rk8n6007kc6h02bh8d702hlcf73acc0lh02s4c79000064f00hf5im10bf5sh005i545000266b00087d010er3f201',
};

function sair(msg) {
  console.error(`\n[erro] ${msg}\n`);
  process.exit(1);
}

function git(args, opts = {}) {
  return execFileSync('git', args, { encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], maxBuffer: 1024 * 1024 * 512, ...opts }).trim();
}

function gitTalvez(args, opts) {
  try { return git(args, opts); } catch { return null; }
}

const MENSAGENS = ['sync settings', 'update settings', 'sync workspace', 'tweak config', 'update config'];
const ALFABETO = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
const pad = (n) => String(n).padStart(2, '0');
const sorteio = (a, b) => Math.floor(Math.random() * (b - a + 1)) + a;
const DATA_RE = /^\d{4}-\d{2}-\d{2}$/;

function hojeLocal() {
  return new Date(Date.now() + cfg.fuso * 60000).toISOString().slice(0, 10);
}

function segundosAgoraLocal() {
  const d = new Date(Date.now() + cfg.fuso * 60000);
  return d.getUTCHours() * 3600 + d.getUTCMinutes() * 60 + d.getUTCSeconds();
}

function utc(ymd) {
  const [y, m, d] = ymd.split('-').map(Number);
  return Date.UTC(y, m - 1, d);
}

function somarDias(ymd, n) {
  return new Date(utc(ymd) + n * 86400000).toISOString().slice(0, 10);
}

function diasEntre(a, b) {
  return Math.round((utc(b) - utc(a)) / 86400000);
}

function paraUnix(ymd, seg) {
  return Math.floor(utc(ymd) / 1000) + seg - cfg.fuso * 60;
}

function diaLocalDeUnix(ts) {
  return new Date((ts + cfg.fuso * 60) * 1000).toISOString().slice(0, 10);
}

function textoFuso() {
  const a = Math.abs(cfg.fuso);
  return (cfg.fuso < 0 ? '-' : '+') + pad(Math.floor(a / 60)) + pad(a % 60);
}

function horaTexto(seg) {
  return `${pad(Math.floor(seg / 3600))}:${pad(Math.floor((seg % 3600) / 60))}:${pad(seg % 60)}`;
}

function aleatorioDia(dia) {
  let h = 2166136261;
  for (const ch of dia) { h ^= ch.charCodeAt(0); h = Math.imul(h, 16777619); }
  let t = (h + 0x6d2b79f5) >>> 0;
  t = Math.imul(t ^ (t >>> 15), t | 1);
  t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
}

const ANOS = Object.keys(DADOS).map(Number).sort();
const ULTIMO_ANO = ANOS[ANOS.length - 1];
const FIM_DADOS = somarDias(`${ULTIMO_ANO}-01-01`, DADOS[ULTIMO_ANO].length - 1);

function diaBloqueado(ymd) {
  return cfg.semCommits.some(([a, b]) => ymd >= a && ymd <= b);
}

function doPerfil(dia) {
  const s = DADOS[Number(dia.slice(0, 4))];
  if (!s) return null;
  const i = diasEntre(`${dia.slice(0, 4)}-01-01`, dia);
  return i < s.length ? ALFABETO.indexOf(s[i]) : null;
}

function commitsDoDia(dia) {
  if (diaBloqueado(dia)) return 0;
  const n = doPerfil(dia);
  if (n !== null) return n;
  if (dia > FIM_DADOS) {
    const semanasAtras = 1 + Math.floor(aleatorioDia(dia) * 52);
    const passos = Math.ceil(diasEntre(FIM_DADOS, dia) / 7) + semanasAtras - 1;
    let ref = somarDias(dia, -7 * passos);
    while (ref > FIM_DADOS) ref = somarDias(ref, -7);
    return diaBloqueado(ref) ? 0 : (doPerfil(ref) || 0);
  }
  return 0;
}

for (const a of process.argv.slice(2)) {
  const m = a.match(/^--([a-zA-Z]+)(?:=(.*))?$/);
  if (!m) sair(`Argumento inválido: ${a}`);
  const [, k, v] = m;
  if (['hoje', 'push', 'simular'].includes(k)) cfg[k] = true;
  else if (k === 'dias') cfg.dias = parseInt(v, 10);
  else if (['inicio', 'fim'].includes(k)) cfg[k] = v;
  else sair(`Opção desconhecida: --${k}`);
}

if (cfg.dias !== null && !(cfg.dias >= 1)) sair('Use --dias >= 1.');

const hoje = hojeLocal();
const fim = cfg.hoje ? hoje : (cfg.fim || hoje);
const inicio = cfg.hoje ? hoje : (cfg.dias ? somarDias(fim, -(cfg.dias - 1)) : cfg.inicio);
if (!DATA_RE.test(inicio) || !DATA_RE.test(fim)) sair('Datas devem estar no formato AAAA-MM-DD.');
if (inicio > fim) sair('O início não pode ser depois do fim.');

if (!gitTalvez(['--version'])) sair('Git não encontrado. Instale em https://git-scm.com');
if (gitTalvez(['rev-parse', '--is-inside-work-tree']) !== 'true') {
  sair('Esta pasta não é um repositório git. Clone seu repositório e rode o script dentro dele.');
}

const raiz = git(['rev-parse', '--show-toplevel']);
const emRaiz = { cwd: raiz };

(function esconderScript() {
  const arqExclude = path.resolve(raiz, git(['rev-parse', '--git-path', 'info/exclude'], emRaiz));
  const atual = fs.existsSync(arqExclude) ? fs.readFileSync(arqExclude, 'utf8') : '';
  const linhas = new Set(atual.split(/\r?\n/).map((l) => l.trim()));
  const novos = ['index.js', 'package.json', 'gerar.bat', 'node_modules/']
    .map((f) => path.relative(raiz, path.join(__dirname, f)).split(path.sep).join('/'))
    .filter((f) => !f.startsWith('..'))
    .map((f) => '/' + f)
    .filter((f) => !linhas.has(f));
  if (novos.length) {
    fs.mkdirSync(path.dirname(arqExclude), { recursive: true });
    fs.appendFileSync(arqExclude, (atual && !atual.endsWith('\n') ? '\n' : '') + novos.join('\n') + '\n');
  }
})();

const nome = gitTalvez(['config', 'user.name'], emRaiz);
const email = gitTalvez(['config', 'user.email'], emRaiz);
if (!nome || !email) {
  sair('Configure seu nome e e-mail:\n  git config user.name "Seu Nome"\n  git config user.email "seu-email-do-github"');
}

const branch = gitTalvez(['symbolic-ref', '--short', '-q', 'HEAD'], emRaiz);
if (!branch) sair('Você não está em nenhuma branch (HEAD destacado). Rode: git checkout main');

if (git(['status', '--porcelain', '--untracked-files=no'], emRaiz)) {
  sair('Há alterações não commitadas. Faça commit (ou descarte) antes de rodar.');
}

const pai = gitTalvez(['rev-parse', '--verify', '-q', 'HEAD'], emRaiz);

const existentes = {};
if (pai) {
  const log = gitTalvez(['log', '-F', `--author=${email}`, '--format=%at', 'HEAD'], emRaiz) || '';
  for (const linha of log.split('\n')) {
    if (!linha) continue;
    const dia = diaLocalDeUnix(Number(linha));
    existentes[dia] = (existentes[dia] || 0) + 1;
  }
}

const plano = [];
const porAno = {};
let total = 0;
for (let dia = inicio; dia <= fim; dia = somarDias(dia, 1)) {
  const faltam = Math.max(0, commitsDoDia(dia) - (existentes[dia] || 0));
  if (!faltam) continue;

  let lo = cfg.horaInicio * 3600;
  let hi = cfg.horaFim * 3600 + 3599;
  if (dia === hoje) {
    hi = Math.min(hi, segundosAgoraLocal());
    if (hi < lo) lo = 0;
  }
  const horarios = Array.from({ length: faltam }, () => sorteio(lo, hi)).sort((a, b) => a - b);
  plano.push({ dia, horarios });
  porAno[dia.slice(0, 4)] = (porAno[dia.slice(0, 4)] || 0) + faltam;
  total += faltam;
}

console.log(`\nRepositório : ${raiz}`);
console.log(`Branch      : ${branch}`);
console.log(`Autor       : ${nome} <${email}>`);
console.log(`Período     : ${inicio} a ${fim}`);
if (cfg.semCommits.length) console.log(`Sem commits : ${cfg.semCommits.map(([a, b]) => `${a} a ${b}`).join(', ')}`);
console.log(`A criar     : ${total} commits em ${plano.length} dia(s)`);
for (const [ano, n] of Object.entries(porAno)) console.log(`  ${ano}      : ${n}`);
console.log('');

if (cfg.simular) {
  console.log('(simulação — nada foi alterado)\n');
  process.exit(0);
}
if (!total) {
  console.log('Nada a fazer: todos os dias do período já estão completos.\n');
  process.exit(0);
}

const fuso = textoFuso();
const partes = [];
let primeiro = true;
let n = pai ? Number(git(['rev-list', '--count', 'HEAD'], emRaiz)) : 0;

for (const { dia, horarios } of plano) {
  for (const seg of horarios) {
    n++;
    const ts = paraUnix(dia, seg);
    const hora = horaTexto(seg);
    const msg = MENSAGENS[sorteio(0, MENSAGENS.length - 1)] + '\n';
    const conteudo = JSON.stringify({ lastSync: `${dia}T${hora}${fuso.slice(0, 3)}:${fuso.slice(3)}`, revision: n }, null, 2) + '\n';

    partes.push(`commit refs/heads/${branch}\n`);
    partes.push(`author ${nome} <${email}> ${ts} ${fuso}\n`);
    partes.push(`committer ${nome} <${email}> ${ts} ${fuso}\n`);
    partes.push(`data ${Buffer.byteLength(msg)}\n${msg}`);
    if (primeiro && pai) partes.push(`from ${pai}\n`);
    primeiro = false;
    partes.push(`M 100644 inline ${cfg.arquivo}\n`);
    partes.push(`data ${Buffer.byteLength(conteudo)}\n${conteudo}\n`);
  }
}
partes.push('done\n');

const r = spawnSync('git', ['fast-import', '--quiet', '--done'], {
  cwd: raiz,
  input: partes.join(''),
  encoding: 'utf8',
  maxBuffer: 1024 * 1024 * 512,
});
if (r.status !== 0) sair(`git fast-import falhou:\n${r.stderr || r.stdout}`);

git(['checkout', 'HEAD', '--', cfg.arquivo], emRaiz);

console.log(`Pronto: ${total} commits criados na branch ${branch}.`);

if (cfg.push) {
  if (!gitTalvez(['remote', 'get-url', 'origin'], emRaiz)) sair('Nenhum remote "origin" configurado.');
  console.log('\nEnviando para o GitHub...');
  const p = spawnSync('git', ['push', '-u', 'origin', branch], { cwd: raiz, stdio: 'inherit' });
  if (p.status !== 0) sair('O push falhou. Veja a mensagem acima.');
  console.log('\nEnviado.');
} else {
  console.log(`Para enviar: git push -u origin ${branch}`);
}
console.log('');

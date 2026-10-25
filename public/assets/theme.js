// Aplica o tema salvo antes da primeira pintura, para a tela não piscar.
(function () {
  var tema = null;
  try { tema = localStorage.getItem('vau-tema'); } catch (e) {}
  if (!tema && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) tema = 'dark';
  document.documentElement.setAttribute('data-theme', tema === 'dark' ? 'dark' : 'light');
})();

/* =====================================================================
   Eu Digo X — Site institucional: header e footer compartilhados
   ===================================================================== */

const SITE_NAV = [
  { id: 'inicio',     label: 'Início',              href: 'index.html' },
  { id: 'sindrome',   label: 'Sobre a síndrome',    href: 'sobre-sindrome.html' },
  { id: 'programa',   label: 'Como funciona',       href: 'como-funciona.html' },
  { id: 'quem-somos', label: 'Quem somos',          href: 'quem-somos.html' },
  { id: 'parceiros',  label: 'Parceiros',           href: 'parceiros.html' },
  { id: 'produtos',   label: 'Produtos',            href: 'produtos.html' },
  { id: 'doacoes',    label: 'Doações',             href: 'doacoes.html' },
  { id: 'contato',    label: 'Contato',             href: 'contato.html' },
];

function renderSiteHeader(activeId) {
  const links = SITE_NAV.map(item =>
    `<a href="${item.href}" class="${item.id === activeId ? 'active' : ''}">${item.label}</a>`
  ).join('');

  return `
    <header class="site-header">
      <div class="container site-header-inner">
        <a href="index.html" class="site-logo">
          <img src="../assets/img/logo-eudigox.svg" alt="Eu Digo X">
        </a>
        <nav class="site-nav">
          <div class="site-nav-links" id="siteNavLinks">
            ${links}
            <a href="login.html" class="btn btn-primary btn-sm" style="color:#fff;">Entrar no sistema</a>
          </div>
          <button class="site-nav-toggle" onclick="toggleSiteNav()" aria-label="Menu">☰</button>
        </nav>
      </div>
    </header>
  `;
}

function toggleSiteNav() {
  document.getElementById('siteNavLinks').classList.toggle('open');
}

function renderSiteFooter() {
  return `
    <footer class="site-footer">
      <div class="container">
        <div class="site-footer-grid">
          <div>
            <div class="institute-logo">
              <img src="../assets/img/logo-instituto.png" alt="Instituto Buko Kaesemodel">
            </div>
            <p style="font-size: var(--fs-sm); line-height: var(--lh-normal); opacity:0.85; margin:0;">
              O programa <strong style="color:#fff;">Eu Digo X</strong> é uma iniciativa do
              Instituto Buko Kaesemodel, dedicada ao diagnóstico precoce e ao apoio às
              famílias da Síndrome do X Frágil.
            </p>
          </div>
          <div>
            <h4>Navegação</h4>
            ${SITE_NAV.map(i => `<a href="${i.href}">${i.label}</a>`).join('')}
          </div>
          <div>
            <h4>Contato</h4>
            <a href="tel:+554131560309">41 3156-0309</a>
            <a href="mailto:contato@eudigox.com.br">contato@eudigox.com.br</a>
            <a href="https://instagram.com/programaeudigox" target="_blank" rel="noopener">Instagram: @programaeudigox</a>
            <a href="contato.html">Rua Fernando Simas, 172 — Curitiba/PR</a>
          </div>
        </div>
        <div class="footer-bottom">
          © ${new Date().getFullYear()} Instituto Buko Kaesemodel — Programa Eu Digo X. Todos os direitos reservados.<br>
          Conteúdo sobre a síndrome adaptado da cartilha do Instituto · Elaboração: Luz María Romero e Vanessa Schubert · Revisão técnica: Dr. Roberto H. Herai.
        </div>
      </div>
    </footer>
  `;
}

document.addEventListener('DOMContentLoaded', () => {
  const h = document.getElementById('site-header');
  const f = document.getElementById('site-footer');
  if (h) h.outerHTML = renderSiteHeader(h.dataset.active || '');
  if (f) f.outerHTML = renderSiteFooter();
});

/**
 * SGX — Componentes compartilhados de dashboard
 * Renderiza a sidebar correta baseada no perfil do usuário
 */

const SIDEBAR_NAVS = {
  doctor: [
    { section: 'Atendimento' },
    { id: 'dashboard',    label: 'Triagens recebidas', icon: '📋', href: 'clinic-dashboard.html' },
    { id: 'appointments', label: 'Minhas consultas',   icon: '📅', href: 'doctor-appointments.html' },
  ],
  nurse: [
    { section: 'Atendimento' },
    { id: 'dashboard',    label: 'Triagens recebidas', icon: '📋', href: 'clinic-dashboard.html' },
    { id: 'appointments', label: 'Minhas consultas',   icon: '📅', href: 'doctor-appointments.html' },
  ],
  patient: [
    { section: 'Minha jornada' },
    { id: 'dashboard', label: 'Status',          icon: '🏠', href: 'patient-dashboard.html' },
    { id: 'history',   label: 'Minhas triagens', icon: '📋', href: 'patient-screenings.html' },
    { id: 'exams',     label: 'Meus exames',     icon: '🧬', href: 'patient-exams.html' },
  ],
  admin: [
    { section: 'Gestão' },
    { id: 'users',         label: 'Profissionais', icon: '👨‍⚕️', href: 'admin-users.html' },
    { id: 'appointments',  label: 'Agenda',        icon: '📅',  href: 'admin-appointments.html' },
    { section: 'Operação' },
    { id: 'dashboard',     label: 'Triagens',      icon: '📋',  href: 'clinic-dashboard.html' },
  ],
  receptionist: [
    { section: 'Recepção' },
    { id: 'calendar',  label: 'Calendário',         icon: '📆', href: 'reception-calendar.html' },
    { id: 'schedule',  label: 'Agendar consulta',   icon: '📅', href: 'reception-schedule.html' },
    { id: 'consultas', label: 'Consultas',          icon: '📋', href: 'reception-consultas.html' },
  ],
};

const ROLE_LABELS = {
  doctor:       'Médico(a)',
  nurse:        'Enfermeiro(a)',
  patient:      'Paciente',
  admin:        'Administrador',
  receptionist: 'Recepção',
};

const ROLE_HOME = {
  doctor:       'clinic-dashboard.html',
  nurse:        'clinic-dashboard.html',
  patient:      'patient-dashboard.html',
  admin:        'admin-users.html',
  receptionist: 'reception-calendar.html',
};

function renderSidebar(activeId = '') {
  const user = api.getUser();
  if (!user) return '';

  const items = SIDEBAR_NAVS[user.role] || [];
  const initials = user.full_name.split(' ').slice(0, 2).map(s => s[0]).join('').toUpperCase();

  return `
    <aside class="sidebar" id="sidebar">
      <a href="${ROLE_HOME[user.role] || 'login.html'}" class="logo">
        <span class="logo-mark">🦋</span> Eu Digo X
      </a>

      <nav class="sidebar-nav">
        ${items.map(item => {
          if (item.section) {
            return `<div class="sidebar-section">${item.section}</div>`;
          }
          return `
            <a href="${item.href}" class="nav-item ${activeId === item.id ? 'active' : ''}">
              <span class="nav-item-icon">${item.icon}</span>
              ${item.label}
            </a>
          `;
        }).join('')}
      </nav>

      <div class="sidebar-footer">
        <div class="user-avatar">${escapeHtmlGlobal(initials)}</div>
        <div class="user-meta">
          <div class="name">${escapeHtmlGlobal(user.full_name)}</div>
          <div class="role">${ROLE_LABELS[user.role] || user.role}</div>
        </div>
        <button onclick="doLogout()" class="btn-ghost" title="Sair" style="background:none;border:none;padding:8px;cursor:pointer;color:var(--color-text-subtle);">
          ⏻
        </button>
      </div>
    </aside>
  `;
}

async function doLogout() {
  try { await api.post('/auth/logout', {}); } catch {}
  api.clearSession();
  location.href = 'login.html';
}

/* ===== Helpers compartilhados ===== */
function escapeHtmlGlobal(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function formatDateBR(dateStr) {
  if (!dateStr) return '—';
  if (dateStr.includes('T') || dateStr.includes(' ')) {
    const d = new Date(dateStr.replace(' ', 'T'));
    return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'});
  }
  const [y, m, d] = dateStr.split('-');
  return `${d}/${m}/${y}`;
}

function formatDateOnly(dateStr) {
  if (!dateStr) return '—';
  const d = new Date(dateStr.includes('T') || dateStr.includes(' ')
    ? dateStr.replace(' ', 'T') : dateStr);
  return d.toLocaleDateString('pt-BR');
}

function priorityBadge(priority) {
  if (!priority) return '<span class="badge">Pendente</span>';
  const labels = { high: 'Alta', medium: 'Média', low: 'Baixa' };
  return `<span class="badge badge-${priority}">${labels[priority] || priority}</span>`;
}

function statusLabel(status) {
  const map = {
    draft:     'Rascunho',
    submitted: 'Enviada',
    reviewed:  'Revisada',
    closed:    'Encerrada',
  };
  return map[status] || status;
}

function recommendationLabel(rec) {
  const map = {
    refer_molecular: 'Encaminhar exame',
    monitor:         'Monitorar',
    no_action:       'Sem ação imediata',
  };
  return map[rec] || rec || '—';
}

function getPatientInitials(name) {
  return String(name || '?').split(' ').filter(Boolean).slice(0, 2)
    .map(s => s[0].toUpperCase()).join('') || '?';
}

function calcAge(birth) {
  if (!birth) return null;
  const b = new Date(birth);
  const now = new Date();
  let age = now.getFullYear() - b.getFullYear();
  const m = now.getMonth() - b.getMonth();
  if (m < 0 || (m === 0 && now.getDate() < b.getDate())) age--;
  return age;
}

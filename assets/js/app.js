/**
 * SmartSchool Hub — JavaScript Principal
 * Gère : navigation, panneaux, recherche, notifications, formulaires, etc.
 */

'use strict';

/* ═══════════════════════════════════════════════════
   CONFIG
═══════════════════════════════════════════════════ */
const BASE_URL = document.documentElement.dataset.baseUrl || window.location.origin;

/* ═══════════════════════════════════════════════════
   SIDEBAR MOBILE
═══════════════════════════════════════════════════ */
function toggleSidebar() {
  const sidebar   = document.getElementById('sidebar');
  const burgerBtn = document.getElementById('burger-btn');
  if (!sidebar) return;

  sidebar.classList.toggle('open');

  // Overlay sombre derrière la sidebar
  let overlay = document.getElementById('sidebar-overlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'sidebar-overlay';
    overlay.style.cssText = `
      position:fixed;inset:0;background:rgba(11,31,58,.5);
      z-index:99;backdrop-filter:blur(2px);
    `;
    overlay.addEventListener('click', closeSidebar);
    document.body.appendChild(overlay);
  }

  if (sidebar.classList.contains('open')) {
    overlay.style.display = 'block';
  } else {
    overlay.style.display = 'none';
  }
}

function closeSidebar() {
  const sidebar  = document.getElementById('sidebar');
  const overlay  = document.getElementById('sidebar-overlay');
  if (sidebar) sidebar.classList.remove('open');
  if (overlay) overlay.style.display = 'none';
}

/* ═══════════════════════════════════════════════════
   PANNEAUX FLOTTANTS (notifications / user-panel)
═══════════════════════════════════════════════════ */
function togglePanel(id) {
  const panel   = document.getElementById(id);
  const overlay = document.getElementById('panel-overlay');
  if (!panel) return;

  const isOpen = panel.style.display === 'block';

  // Ferme tous les panneaux d'abord
  closePanels();

  if (!isOpen) {
    panel.style.display = 'block';
    if (overlay) overlay.style.display = 'block';

    // Charge les notifications si c'est le panel notif
    if (id === 'notif-panel') loadNotifications();
  }
}

function closePanels() {
  ['notif-panel', 'user-panel'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
  });
  const overlay = document.getElementById('panel-overlay');
  if (overlay) overlay.style.display = 'none';
}

// Ferme les panneaux avec Echap
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closePanels();
});

/* ═══════════════════════════════════════════════════
   NOTIFICATIONS
═══════════════════════════════════════════════════ */
async function loadNotifications() {
  const list = document.getElementById('notif-list');
  if (!list) return;

  try {
    const res  = await fetch(`${BASE_URL}/api/notifications.php?limit=10`);
    const data = await res.json();

    if (!data.notifications || data.notifications.length === 0) {
      list.innerHTML = `
        <div style="padding:40px 20px;text-align:center;color:var(--muted);font-size:14px;">
          🎉 Aucune nouvelle notification
        </div>`;
      return;
    }

    list.innerHTML = data.notifications.map(n => `
      <div class="notif-item" style="padding:12px 20px;cursor:pointer;
        ${!n.is_read ? 'background:#F8FAFF;' : ''}"
        onclick="openNotif(${n.id}, '${encodeURIComponent(n.link || '')}')">
        <div class="notif-dot-icon" style="background:${notifBg(n.type)};">
          ${notifIcon(n.type)}
        </div>
        <div style="flex:1;min-width:0;">
          <div class="notif-title" style="${!n.is_read ? 'font-weight:700;' : ''}">
            ${escapeHtml(n.title)}
          </div>
          <div class="notif-body">${escapeHtml(n.body.substring(0,90))}…</div>
          <div class="notif-time">${n.time_ago}</div>
        </div>
        ${!n.is_read ? '<div style="width:8px;height:8px;border-radius:50%;background:var(--blue);flex-shrink:0;margin-top:6px;"></div>' : ''}
      </div>
    `).join('');

  } catch (err) {
    list.innerHTML = `<div style="padding:20px;text-align:center;color:var(--rose);">
      ⚠️ Impossible de charger les notifications.
    </div>`;
  }
}

async function openNotif(id, link) {
  // Marquer comme lu
  await fetch(`${BASE_URL}/api/notifications.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'read', id }),
  });

  // Mettre à jour le badge
  const dot = document.querySelector('.topbar-icon .notif-dot');
  if (dot) {
    const count = parseInt(dot.textContent || '1') - 1;
    if (count <= 0) dot.remove();
    else dot.textContent = count > 9 ? '9+' : count;
  }

  // Redirection si lien
  const href = link ? decodeURIComponent(link) : null;
  if (href) window.location.href = href;
}

async function markAllRead() {
  await fetch(`${BASE_URL}/api/notifications.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'read_all' }),
  });
  // Vider le badge
  const dot = document.querySelector('.topbar-icon .notif-dot');
  if (dot) dot.remove();
  // Recharger la liste
  loadNotifications();
}

function notifIcon(type) {
  const icons = {
    absence: '🚫', grade: '📝', payment: '💰',
    announcement: '📢', ai_alert: '🤖', message: '💬', default: '🔔',
  };
  return icons[type] || icons.default;
}

function notifBg(type) {
  const bgs = {
    absence: '#FEE2E2', grade: '#D1FAE5', payment: '#FEF3C7',
    announcement: '#EDE9FE', ai_alert: '#DBEAFE', message: '#CFFAFE',
    default: '#F1F5F9',
  };
  return bgs[type] || bgs.default;
}

/* ═══════════════════════════════════════════════════
   RECHERCHE GLOBALE
═══════════════════════════════════════════════════ */
let searchTimeout;

function globalSearch(query) {
  const resultsEl = document.getElementById('search-results');
  if (!resultsEl) return;

  clearTimeout(searchTimeout);

  if (query.length < 2) {
    resultsEl.style.display = 'none';
    return;
  }

  searchTimeout = setTimeout(async () => {
    try {
      const res  = await fetch(`${BASE_URL}/api/search.php?q=${encodeURIComponent(query)}`);
      const data = await res.json();

      if (!data.results || data.results.length === 0) {
        resultsEl.style.display = 'none';
        return;
      }

      resultsEl.innerHTML = data.results.map(r => `
        <a href="${r.url}" style="display:flex;align-items:center;gap:12px;
          padding:12px 16px;text-decoration:none;transition:background .15s;"
          onmouseover="this.style.background='var(--light)'"
          onmouseout="this.style.background=''"
        >
          <div class="avatar" style="background:${r.color || 'var(--grad2)'};
            width:34px;height:34px;font-size:12px;flex-shrink:0;">
            ${escapeHtml(r.initials || '?')}
          </div>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--navy);">${escapeHtml(r.name)}</div>
            <div style="font-size:11px;color:var(--muted);">${escapeHtml(r.subtitle || '')}</div>
          </div>
          <div style="margin-left:auto;">
            <span class="badge badge-gray" style="font-size:10px;">${escapeHtml(r.type)}</span>
          </div>
        </a>
      `).join('');

      resultsEl.style.display = 'block';
    } catch {
      resultsEl.style.display = 'none';
    }
  }, 300);
}

// Fermer les résultats de recherche si click ailleurs
document.addEventListener('click', e => {
  const searchEl  = document.getElementById('global-search');
  const resultsEl = document.getElementById('search-results');
  if (resultsEl && searchEl && !searchEl.contains(e.target) && !resultsEl.contains(e.target)) {
    resultsEl.style.display = 'none';
  }
});

/* ═══════════════════════════════════════════════════
   APPEL (PRÉSENCE / ABSENCE)
═══════════════════════════════════════════════════ */
function setAttendance(btn, status) {
  const row = btn.closest('.att-row');
  if (!row) return;

  // Retirer les états actifs de cette ligne
  row.querySelectorAll('.att-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  // Mémoriser dans un champ caché
  const studentId = row.dataset.studentId;
  if (!studentId) return;

  let hidden = row.querySelector(`input[name="attendance[${studentId}]"]`);
  if (!hidden) {
    hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = `attendance[${studentId}]`;
    row.appendChild(hidden);
  }
  hidden.value = status;
}

/* ═══════════════════════════════════════════════════
   SAISIE DES NOTES — couleur dynamique
═══════════════════════════════════════════════════ */
function colorizeNote(input) {
  const val = parseFloat(input.value);
  const max = parseFloat(input.dataset.max || 20);
  if (isNaN(val) || isNaN(max)) { input.style.color = ''; return; }

  const pct = (val / max) * 100;
  if (pct >= 70)      input.style.color = '#059669'; // vert
  else if (pct >= 50) input.style.color = '#92400E'; // orange
  else                input.style.color = '#991B1B'; // rouge
}

// Appliquer sur tous les champs de note au chargement
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.note-input').forEach(inp => {
    colorizeNote(inp);
    inp.addEventListener('input', () => colorizeNote(inp));
  });

  // Injecter burger en mobile
  const burgerBtn = document.getElementById('burger-btn');
  if (burgerBtn && window.innerWidth <= 900) {
    burgerBtn.style.display = 'flex';
  }
  window.addEventListener('resize', () => {
    if (burgerBtn) burgerBtn.style.display = window.innerWidth <= 900 ? 'flex' : 'none';
  });

  // Auto-dismiss flash après 6s
  const flash = document.getElementById('flash-msg');
  if (flash) {
    setTimeout(() => {
      flash.style.opacity  = '0';
      flash.style.transition = 'opacity .5s';
      setTimeout(() => flash.remove(), 600);
    }, 6000);
  }
});

/* ═══════════════════════════════════════════════════
   MODAL
═══════════════════════════════════════════════════ */
function openModal(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.add('open'); document.body.style.overflow = 'hidden'; }
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.remove('open'); document.body.style.overflow = ''; }
}

// Fermer la modal en cliquant sur l'overlay
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('open');
    document.body.style.overflow = '';
  }
});

/* ═══════════════════════════════════════════════════
   CONFIRM DELETE
═══════════════════════════════════════════════════ */
function confirmDelete(form, label = 'cet élément') {
  if (confirm(`Êtes-vous sûr de vouloir supprimer ${label} ?\nCette action est irréversible.`)) {
    form.submit();
  }
}

/* ═══════════════════════════════════════════════════
   GRAPHIQUES Chart.js — helpers
═══════════════════════════════════════════════════ */
function makeBarChart(canvasId, labels, data, label = '', colorStart = '#1A56DB', colorEnd = '#06B6D4') {
  const ctx = document.getElementById(canvasId);
  if (!ctx) return;

  return new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label,
        data,
        backgroundColor: data.map(() => colorStart + 'CC'),
        borderColor:     data.map(() => colorStart),
        borderWidth: 2,
        borderRadius: 8,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: !!label },
        tooltip: { callbacks: {
          label: ctx => ' ' + ctx.parsed.y
        }},
      },
      scales: {
        x: { grid: { display: false }, ticks: { font: { family: 'Plus Jakarta Sans', size: 12 } } },
        y: { beginAtZero: true, grid: { color: '#E2E8F0' },
             ticks: { font: { family: 'Plus Jakarta Sans', size: 12 } } },
      }
    }
  });
}

function makeLineChart(canvasId, labels, datasets) {
  const ctx = document.getElementById(canvasId);
  if (!ctx) return;

  return new Chart(ctx, {
    type: 'line',
    data: { labels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { labels: { font: { family: 'Plus Jakarta Sans' } } } },
      scales: {
        x: { grid: { display: false }, ticks: { font: { family: 'Plus Jakarta Sans', size: 12 } } },
        y: { beginAtZero: false, grid: { color: '#E2E8F0' },
             ticks: { font: { family: 'Plus Jakarta Sans', size: 12 } } },
      }
    }
  });
}

function makeDoughnutChart(canvasId, labels, data, colors) {
  const ctx = document.getElementById(canvasId);
  if (!ctx) return;

  return new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{ data, backgroundColor: colors, borderWidth: 3, borderColor: '#fff' }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '70%',
      plugins: {
        legend: {
          position: 'bottom',
          labels: { font: { family: 'Plus Jakarta Sans', size: 12 }, padding: 16, usePointStyle: true }
        }
      }
    }
  });
}

/* ═══════════════════════════════════════════════════
   AJAX POST avec CSRF
═══════════════════════════════════════════════════ */
async function apiPost(url, data) {
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const csrf     = csrfMeta ? csrfMeta.content : '';

  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
    body: JSON.stringify(data),
  });

  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.json();
}

/* ═══════════════════════════════════════════════════
   TOAST (mini notification temporaire)
═══════════════════════════════════════════════════ */
function showToast(message, type = 'success') {
  const toast = document.createElement('div');
  const bg = { success: '#D1FAE5', error: '#FEE2E2', info: '#DBEAFE', warning: '#FEF3C7' };
  const fg = { success: '#065F46', error: '#991B1B', info: '#1E40AF', warning: '#92400E' };
  const ic = { success: '✅', error: '⚠️', info: 'ℹ️', warning: '⚠️' };

  toast.style.cssText = `
    position:fixed;bottom:24px;right:24px;z-index:9999;
    background:${bg[type] || bg.info};color:${fg[type] || fg.info};
    border:1px solid rgba(0,0,0,.08);
    border-radius:12px;padding:14px 20px;
    font-size:14px;font-weight:600;font-family:'Plus Jakarta Sans',sans-serif;
    display:flex;align-items:center;gap:10px;
    box-shadow:0 8px 32px rgba(0,0,0,.12);
    animation:slideUp .3s ease;
    max-width:380px;
  `;
  toast.innerHTML = `${ic[type] || ic.info} ${escapeHtml(message)}`;
  document.body.appendChild(toast);

  const style = document.createElement('style');
  style.textContent = '@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}';
  document.head.appendChild(style);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transition = 'opacity .4s';
    setTimeout(() => { toast.remove(); style.remove(); }, 400);
  }, 3500);
}

/* ═══════════════════════════════════════════════════
   PAGINATION — helper URL
═══════════════════════════════════════════════════ */
function goToPage(page) {
  const url = new URL(window.location.href);
  url.searchParams.set('page', page);
  window.location.href = url.toString();
}

/* ═══════════════════════════════════════════════════
   EXPORT TABLE → CSV
═══════════════════════════════════════════════════ */
function exportTableCSV(tableId, filename = 'export.csv') {
  const table = document.getElementById(tableId);
  if (!table) return;

  const rows  = Array.from(table.querySelectorAll('tr'));
  const csv   = rows.map(row =>
    Array.from(row.querySelectorAll('th,td'))
      .map(cell => '"' + cell.innerText.replace(/"/g, '""').trim() + '"')
      .join(',')
  ).join('\n');

  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
  const a    = document.createElement('a');
  a.href     = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
  showToast('Export CSV généré !', 'success');
}

/* ═══════════════════════════════════════════════════
   PRINT
═══════════════════════════════════════════════════ */
function printSection(id) {
  const el = document.getElementById(id);
  if (!el) { window.print(); return; }

  const w = window.open('', '_blank');
  w.document.write(`
    <!DOCTYPE html><html><head>
    <meta charset="UTF-8">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&family=Sora:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="${BASE_URL}/assets/css/style.css">
    <style>body{padding:20px;background:#fff;} .sidebar,.topbar,.btn{display:none!important;} .main-content{margin:0;}</style>
    </head><body>
    ${el.outerHTML}
    </body></html>
  `);
  w.document.close();
  setTimeout(() => w.print(), 800);
}

/* ═══════════════════════════════════════════════════
   UTILITAIRES
═══════════════════════════════════════════════════ */
function escapeHtml(text) {
  if (!text) return '';
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function formatNumber(n) {
  return Number(n).toLocaleString('fr-FR');
}

function debounce(fn, delay = 300) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), delay); };
}

// Exposer à la fenêtre globale pour les appels inline
Object.assign(window, {
  toggleSidebar, closeSidebar,
  togglePanel, closePanels,
  setAttendance, colorizeNote,
  openModal, closeModal,
  confirmDelete, globalSearch,
  markAllRead, showToast,
  exportTableCSV, printSection,
  makeBarChart, makeLineChart, makeDoughnutChart,
  goToPage,
});

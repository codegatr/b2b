/* CODEGA B2B — Main JavaScript */
'use strict';

// ── Dropdown ──────────────────────────────────────────────────
document.addEventListener('click', e => {
  const trigger = e.target.closest('[data-dropdown]');
  if (trigger) {
    e.stopPropagation();
    const menu = document.getElementById(trigger.dataset.dropdown);
    if (menu) {
      document.querySelectorAll('.dropdown-menu.show').forEach(m => { if (m !== menu) m.classList.remove('show'); });
      menu.classList.toggle('show');
    }
  } else {
    document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
  }
});

// ── Modal ─────────────────────────────────────────────────────
function openModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.add('open');
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('open');
}
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) e.target.classList.remove('open');
  if (e.target.closest('[data-modal-close]')) {
    const modal = e.target.closest('.modal-overlay');
    if (modal) modal.classList.remove('open');
  }
  if (e.target.closest('[data-modal-open]')) {
    openModal(e.target.closest('[data-modal-open]').dataset.modalOpen);
  }
});

// ── Sidebar toggle (mobile) ───────────────────────────────────
const sidebarToggle = document.getElementById('sidebar-toggle');
const sidebar = document.querySelector('.sidebar');
if (sidebarToggle && sidebar) {
  sidebarToggle.addEventListener('click', () => sidebar.classList.toggle('open'));
}

// ── Alert otomatik kapat ──────────────────────────────────────
document.querySelectorAll('.alert[data-auto-close]').forEach(el => {
  setTimeout(() => el.style.display = 'none', 5000);
});

// ── AJAX helper ──────────────────────────────────────────────
async function apiPost(url, data = {}) {
  const form = new FormData();
  form.append('csrf_token', document.querySelector('meta[name=csrf]')?.content || '');
  Object.entries(data).forEach(([k,v]) => form.append(k, v));
  const res = await fetch(url, { method: 'POST', body: form,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  });
  return res.json();
}

// ── Sepet ─────────────────────────────────────────────────────
const Cart = {
  async add(productId, qty = 1) {
    const r = await apiPost('/api/cart.php', { action: 'add', product_id: productId, qty });
    if (r.ok) {
      Cart.updateBadge(r.count);
      Toast.show(r.msg || 'Sepete eklendi', 'success');
    } else {
      Toast.show(r.msg || 'Hata oluştu', 'danger');
    }
    return r;
  },
  async update(productId, qty) {
    return apiPost('/api/cart.php', { action: 'update', product_id: productId, qty });
  },
  async remove(productId) {
    const r = await apiPost('/api/cart.php', { action: 'remove', product_id: productId });
    Cart.updateBadge(r.count);
    return r;
  },
  updateBadge(count) {
    document.querySelectorAll('.cart-badge').forEach(el => {
      el.textContent = count;
      el.style.display = count > 0 ? '' : 'none';
    });
  }
};

// ── Toast bildirimi ───────────────────────────────────────────
const Toast = {
  container: null,
  init() {
    if (!this.container) {
      this.container = document.createElement('div');
      this.container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px;max-width:320px';
      document.body.appendChild(this.container);
    }
  },
  show(msg, type = 'info', duration = 4000) {
    this.init();
    const colors = { success:'#22c55e', danger:'#ef4444', warning:'#f59e0b', info:'#3b82f6' };
    const el = document.createElement('div');
    el.style.cssText = `background:#1a1e2e;border:1px solid ${colors[type]||'#2a2f45'};border-left:3px solid ${colors[type]||'#6366f1'};border-radius:8px;padding:12px 16px;font-size:13px;color:#e2e8f0;box-shadow:0 4px 16px rgba(0,0,0,.4);animation:slideIn .2s ease`;
    el.textContent = msg;
    this.container.appendChild(el);
    setTimeout(() => { el.style.opacity='0'; el.style.transition='opacity .3s'; setTimeout(()=>el.remove(), 300); }, duration);
  }
};

// ── Confirm dialog ────────────────────────────────────────────
function confirmAction(msg, fn) {
  if (confirm(msg)) fn();
}

// ── Form: decimal input düzeltici ────────────────────────────
document.querySelectorAll('input[data-decimal]').forEach(el => {
  el.addEventListener('blur', () => {
    const v = parseFloat(el.value.replace(',','.'));
    if (!isNaN(v)) el.value = v.toFixed(2).replace('.',',');
  });
});

// ── Qty spinner ───────────────────────────────────────────────
document.addEventListener('click', e => {
  const btn = e.target.closest('[data-qty-btn]');
  if (!btn) return;
  const input = document.getElementById(btn.dataset.target);
  if (!input) return;
  const step = parseInt(btn.dataset.qtyBtn) || 1;
  const min  = parseInt(input.min) || 1;
  const max  = parseInt(input.max) || 99999;
  const val  = parseInt(input.value) || min;
  input.value = Math.max(min, Math.min(max, val + step));
  input.dispatchEvent(new Event('change'));
});

// ── Arama (client-side tablo filtresi) ───────────────────────
const searchInput = document.getElementById('table-search');
if (searchInput) {
  searchInput.addEventListener('input', () => {
    const q = searchInput.value.toLowerCase();
    document.querySelectorAll('tbody tr').forEach(row => {
      row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
}

// ── Stok animasyonu ───────────────────────────────────────────
document.querySelectorAll('[data-stock-bar]').forEach(el => {
  const pct = parseFloat(el.dataset.stockBar);
  el.style.width = Math.min(100, pct) + '%';
});

// ── Slide-in keyframe ─────────────────────────────────────────
const styleSheet = document.createElement('style');
styleSheet.textContent = '@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:none;opacity:1}}';
document.head.appendChild(styleSheet);

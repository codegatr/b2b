<?php
// admin/pages/stock_scan.php — QR/Barkod ile Stok Giriş/Çıkış/Sayım
requireAdmin();

$action = $_GET['action'] ?? 'scan';
$success = $error = '';

// ── AJAX: Barkod / SKU / ID ile ürün lookup ───────────────────────
if ($action === 'lookup' && !empty($_GET['code'])) {
    header('Content-Type: application/json');
    $code = trim($_GET['code']);

    // Önce barkodla, sonra SKU ile, son olarak ID ile dene
    $product = null;
    try {
        $product = dbRow(
            "SELECT id, name, sku, barcode, stock, stock_critical, unit, image
             FROM b2b_products WHERE barcode=? AND is_active=1 LIMIT 1",
            [$code]
        );
    } catch (\Throwable $e) { /* barcode kolonu yoksa atla */ }

    if (!$product) {
        $product = dbRow(
            "SELECT id, name, sku, stock, stock_critical, unit, image
             FROM b2b_products WHERE sku=? AND is_active=1 LIMIT 1",
            [$code]
        );
    }
    if (!$product && ctype_digit($code)) {
        $product = dbRow(
            "SELECT id, name, sku, stock, stock_critical, unit, image
             FROM b2b_products WHERE id=? AND is_active=1 LIMIT 1",
            [(int)$code]
        );
    }

    if (!$product) {
        echo json_encode(['ok' => false, 'message' => 'Ürün bulunamadı: ' . $code]);
        exit;
    }
    echo json_encode(['ok' => true, 'product' => $product]);
    exit;
}

// ── POST: Stok hareket kaydı (hızlı mod tek tek, toplu mod array olarak) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';

    // Hızlı mod — tek ürün, tek hareket
    if ($act === 'apply_single') {
        $pid    = (int)($_POST['product_id'] ?? 0);
        $change = (int)($_POST['change'] ?? 0);
        $type   = $_POST['change_type'] ?? 'manuel'; // giris/cikis/sayim/manuel
        $note   = trim($_POST['note'] ?? '');

        if (!$pid || $change === 0) {
            $error = 'Geçersiz parametre.';
        } else {
            $p = dbRow("SELECT name FROM b2b_products WHERE id=?", [$pid]);
            if (!$p) {
                $error = 'Ürün bulunamadı.';
            } else {
                stockUpdate($pid, $change, $type === 'sayim' ? 'duzeltme' : ($change > 0 ? 'giris' : 'cikis'),
                            'qr_scan', 0, ($note ?: 'QR/Barkod tarama') . ' — ' . $type, adminId());
                $newStock = (int)dbVal("SELECT stock FROM b2b_products WHERE id=?", [$pid]);
                header('Content-Type: application/json');
                echo json_encode(['ok'=>true, 'new_stock'=>$newStock, 'product'=>$p['name']]);
                exit;
            }
        }
        if ($error) {
            header('Content-Type: application/json');
            echo json_encode(['ok'=>false, 'message'=>$error]);
            exit;
        }
    }

    // Toplu mod — birden fazla ürün, tek seferde kaydet
    if ($act === 'apply_batch') {
        $items = $_POST['items'] ?? []; // [{product_id, change, type}, ...]
        $type  = $_POST['batch_type'] ?? 'manuel';
        $count = 0; $errors = [];

        foreach ($items as $item) {
            $pid    = (int)($item['product_id'] ?? 0);
            $change = (int)($item['change'] ?? 0);
            if (!$pid || $change === 0) continue;
            try {
                stockUpdate($pid, $change,
                    $type === 'sayim' ? 'duzeltme' : ($change > 0 ? 'giris' : 'cikis'),
                    'qr_scan_batch', 0,
                    'QR/Barkod toplu — ' . $type,
                    adminId());
                $count++;
            } catch (\Throwable $e) {
                $errors[] = "Ürün $pid: " . $e->getMessage();
            }
        }
        $_SESSION['flash_admin'] = $errors
            ? ['type'=>'warning', 'msg'=>"$count başarılı, " . count($errors) . " hata: " . implode('; ', $errors)]
            : ['type'=>'success', 'msg'=>"$count ürün için stok hareketi kaydedildi."];
        redirect('?page=stock_scan');
    }
}

// ── A4 ETİKET BASKI SAYFASI ──────────────────────────────────────
if ($action === 'labels') {
    $catId = (int)($_GET['cat'] ?? 0);
    $where = ['p.is_active=1'];
    $params = [];
    if ($catId) { $where[] = 'p.category_id=?'; $params[] = $catId; }
    $w = implode(' AND ', $where);

    try {
        $products = dbRows(
            "SELECT p.id, p.name, p.sku, p.barcode, p.stock, p.unit, c.name AS cat_name
             FROM b2b_products p
             LEFT JOIN b2b_categories c ON c.id=p.category_id
             WHERE $w
             ORDER BY c.name, p.name",
            $params
        );
    } catch (\Throwable $e) {
        // barcode kolonu yoksa
        $products = dbRows(
            "SELECT p.id, p.name, p.sku, p.stock, p.unit, c.name AS cat_name
             FROM b2b_products p
             LEFT JOIN b2b_categories c ON c.id=p.category_id
             WHERE $w
             ORDER BY c.name, p.name",
            $params
        );
        foreach ($products as $i => $row) $products[$i]['barcode'] = null;
    }

    require __DIR__ . '/stock_scan/_labels.php';
    exit;
}

// ── ANA SAYFA: Tarama UI ─────────────────────────────────────────
?>
<style>
  .scan-container { max-width: 720px; margin: 0 auto; }
  .scan-tabs { display: flex; gap: 6px; margin-bottom: 16px; border-bottom: 2px solid var(--border); }
  .scan-tab { padding: 10px 18px; background: none; border: none; cursor: pointer; font-weight: 600; color: var(--text-2); border-bottom: 3px solid transparent; margin-bottom: -2px; transition: .15s; }
  .scan-tab.active { color: #c1272d; border-bottom-color: #c1272d; }
  .scan-pane { display: none; }
  .scan-pane.active { display: block; }

  .video-wrap {
    position: relative; width: 100%; max-width: 420px; aspect-ratio: 1/1;
    margin: 0 auto; background: #000; border-radius: 12px; overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,.15);
  }
  .video-wrap video, .video-wrap canvas { width: 100%; height: 100%; object-fit: cover; }
  .video-overlay {
    position: absolute; inset: 0; pointer-events: none;
    display: flex; align-items: center; justify-content: center;
  }
  .video-frame {
    width: 70%; aspect-ratio: 1/1;
    border: 3px solid rgba(193,39,45,.85);
    border-radius: 12px;
    box-shadow: 0 0 0 9999px rgba(0,0,0,.3);
    position: relative;
  }
  .video-frame::before, .video-frame::after,
  .video-frame > .corner-tl, .video-frame > .corner-br {
    content: ''; position: absolute; width: 22px; height: 22px;
    border: 4px solid #fff;
  }
  .video-frame::before { top: -4px; left: -4px; border-right: none; border-bottom: none; border-top-left-radius: 6px; }
  .video-frame::after { bottom: -4px; right: -4px; border-left: none; border-top: none; border-bottom-right-radius: 6px; }
  .corner-tl { top: -4px; right: -4px; border-left: none; border-bottom: none; border-top-right-radius: 6px; }
  .corner-br { bottom: -4px; left: -4px; border-right: none; border-top: none; border-bottom-left-radius: 6px; }

  .video-status {
    position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%);
    background: rgba(0,0,0,.8); color: #fff; padding: 6px 14px; border-radius: 99px;
    font-size: 12px; font-weight: 600;
  }

  .scanner-controls { display: flex; gap: 10px; justify-content: center; margin-top: 12px; flex-wrap: wrap; }
  .scanner-controls button {
    background: #c1272d; color: #fff; border: none; padding: 11px 20px;
    border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer;
  }
  .scanner-controls button.secondary { background: #fff; color: #333; border: 1px solid #ccc; }
  .scanner-controls button:disabled { opacity: .5; cursor: not-allowed; }

  .manual-input-row {
    display: flex; gap: 8px; margin: 14px auto; max-width: 420px;
  }
  .manual-input-row input {
    flex: 1; padding: 11px 14px; border: 1px solid var(--border-2); border-radius: 8px;
    font-family: ui-monospace, monospace; font-size: 14px;
  }
  .manual-input-row button {
    background: #1f2937; color: #fff; border: none; padding: 11px 18px;
    border-radius: 8px; font-weight: 600; cursor: pointer;
  }

  .product-card {
    background: #fff; border: 1px solid var(--border); border-radius: 12px;
    padding: 18px; margin-top: 14px; display: flex; gap: 14px; align-items: center;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
  }
  .product-card img, .product-card .ph {
    width: 60px; height: 60px; object-fit: cover; border-radius: 8px; flex-shrink: 0; background: #fef2f2;
    display: flex; align-items: center; justify-content: center; font-size: 24px;
  }
  .product-card .info { flex: 1; min-width: 0; }
  .product-card .name { font-weight: 700; font-size: 15px; margin-bottom: 2px; }
  .product-card .sku { font-family: ui-monospace, monospace; font-size: 11px; color: var(--text-muted); }
  .product-card .stock-badge { font-weight: 700; font-size: 16px; padding: 6px 12px; border-radius: 8px; white-space: nowrap; }
  .stock-badge.ok { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
  .stock-badge.low { background: #fffbeb; color: #d97706; border: 1px solid #fcd34d; }
  .stock-badge.empty { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

  .qty-controls { display: flex; align-items: center; gap: 8px; margin: 14px 0; justify-content: center; }
  .qty-controls .qty-btn {
    width: 44px; height: 44px; border: 1px solid var(--border-2); background: #fff;
    font-size: 22px; font-weight: 700; cursor: pointer; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; transition: .15s;
  }
  .qty-controls .qty-btn:hover { background: #fef2f2; border-color: #c1272d; color: #c1272d; }
  .qty-controls .qty-btn.minus:hover { background: #fef2f2; border-color: #dc2626; color: #dc2626; }
  .qty-controls .qty-btn.plus:hover  { background: #f0fdf4; border-color: #16a34a; color: #16a34a; }
  .qty-input { width: 100px; height: 50px; text-align: center; font-size: 24px; font-weight: 700; border: 2px solid var(--border-2); border-radius: 10px; }

  .action-buttons { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-top: 12px; }
  .action-buttons button {
    padding: 14px 8px; border: none; border-radius: 10px; font-weight: 700; font-size: 13px;
    cursor: pointer; transition: .15s; display: flex; flex-direction: column; align-items: center; gap: 3px;
  }
  .action-buttons button .icon { font-size: 20px; }
  .btn-giris { background: #f0fdf4; color: #15803d; border: 1px solid #86efac; }
  .btn-cikis { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; }
  .btn-sayim { background: #eff6ff; color: #1d4ed8; border: 1px solid #93c5fd; }
  .btn-giris:hover { background: #15803d; color: #fff; }
  .btn-cikis:hover { background: #b91c1c; color: #fff; }
  .btn-sayim:hover { background: #1d4ed8; color: #fff; }

  .batch-list { margin-top: 14px; }
  .batch-row {
    display: flex; align-items: center; gap: 10px; padding: 10px 12px;
    background: #fff; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 6px;
  }
  .batch-row .name { flex: 1; min-width: 0; font-weight: 600; font-size: 13px; }
  .batch-row .qty { font-weight: 700; font-family: ui-monospace, monospace; font-size: 14px; min-width: 50px; text-align: right; }
  .batch-row .qty.in  { color: #16a34a; }
  .batch-row .qty.out { color: #dc2626; }
  .batch-row .remove { background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 18px; padding: 4px 8px; }

  .toast {
    position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
    background: #1f2937; color: #fff; padding: 12px 24px; border-radius: 8px;
    font-weight: 600; z-index: 9999; box-shadow: 0 4px 14px rgba(0,0,0,.3);
    animation: slideDown .3s, fadeOut .3s 2.7s forwards;
  }
  .toast.success { background: #15803d; }
  .toast.error { background: #b91c1c; }
  @keyframes slideDown { from { transform: translate(-50%, -100%); opacity: 0; } }
  @keyframes fadeOut { to { transform: translate(-50%, -20px); opacity: 0; } }

  @media (max-width: 480px) {
    .video-wrap { max-width: 100%; }
    .product-card { flex-wrap: wrap; }
    .product-card .stock-badge { width: 100%; text-align: center; }
  }
</style>

<div class="page-header" style="margin-bottom:14px">
  <div>
    <h1 class="page-title">📷 QR/Barkod Stok Tarama</h1>
    <p class="page-sub">Telefonun kamerasıyla ürün QR/barkodunu tarayın, stok girişi/çıkışı yapın.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="?page=stock_scan&action=labels" target="_blank" class="btn btn-secondary" style="background:#1f2937;border-color:#1f2937;color:#fff">📄 QR Etiketler Yazdır</a>
    <a href="?page=stock" class="btn btn-ghost">Stok Yönetimi</a>
  </div>
</div>

<?php
// Flash mesaj
if (!empty($_SESSION['flash_admin'])) {
  $f = $_SESSION['flash_admin']; unset($_SESSION['flash_admin']);
  $alertClass = $f['type'] === 'success' ? 'alert-success' : ($f['type'] === 'warning' ? 'alert-warning' : 'alert-danger');
  echo '<div class="alert ' . $alertClass . '" style="margin-bottom:14px">' . h($f['msg']) . '</div>';
}
?>

<div class="scan-container">

  <!-- Mod sekmeleri -->
  <div class="scan-tabs">
    <button class="scan-tab active" data-tab="quick">⚡ Hızlı Mod</button>
    <button class="scan-tab" data-tab="batch">📦 Toplu Mod</button>
  </div>

  <!-- ──────── HIZLI MOD ──────── -->
  <div class="scan-pane active" id="pane-quick">
    <p style="font-size:13px;color:var(--text-2);text-align:center;margin-bottom:12px">
      Tara → +/− ile miktarı seç → <strong>Giriş/Çıkış/Sayım</strong> butonuna bas.<br>
      <span style="font-size:11px;color:var(--text-muted)">Her hareket anında kaydedilir.</span>
    </p>

    <div class="video-wrap" id="video-wrap-q">
      <div id="reader-q"></div>
      <div class="video-overlay">
        <div class="video-frame"><span class="corner-tl"></span><span class="corner-br"></span></div>
      </div>
      <div class="video-status" id="status-q">Kamera başlatılıyor…</div>
    </div>
    <div class="scanner-controls">
      <button id="btn-start-q">📷 Kamerayı Başlat</button>
      <button class="secondary" id="btn-stop-q" disabled>⏸ Durdur</button>
    </div>

    <div class="manual-input-row">
      <input type="text" id="manual-code-q" placeholder="Manuel kod gir (Enter)" inputmode="text">
      <button id="btn-manual-q">Ara</button>
    </div>

    <!-- Tarama sonrası ürün kartı buraya gelir -->
    <div id="product-result-q"></div>
  </div>

  <!-- ──────── TOPLU MOD ──────── -->
  <div class="scan-pane" id="pane-batch">
    <p style="font-size:13px;color:var(--text-2);text-align:center;margin-bottom:12px">
      Peş peşe tara → liste birikir → tek seferde Giriş/Çıkış olarak kaydet.
    </p>

    <div class="video-wrap" id="video-wrap-b">
      <div id="reader-b"></div>
      <div class="video-overlay">
        <div class="video-frame"><span class="corner-tl"></span><span class="corner-br"></span></div>
      </div>
      <div class="video-status" id="status-b">Kamera başlatılıyor…</div>
    </div>
    <div class="scanner-controls">
      <button id="btn-start-b">📷 Kamerayı Başlat</button>
      <button class="secondary" id="btn-stop-b" disabled>⏸ Durdur</button>
    </div>

    <div class="manual-input-row">
      <input type="text" id="manual-code-b" placeholder="Manuel kod gir (Enter)" inputmode="text">
      <button id="btn-manual-b">Ekle</button>
    </div>

    <!-- Toplu liste -->
    <div class="batch-list" id="batch-list">
      <div style="text-align:center;color:var(--text-muted);padding:24px;font-size:13px;background:#fafafa;border-radius:8px;border:1px dashed var(--border)">
        Henüz tarama yok. Üstteki kameradan veya manuel kodla ürün ekleyin.
      </div>
    </div>

    <!-- Toplu kaydet butonları -->
    <form method="post" id="batch-form" style="display:none;margin-top:14px">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="apply_batch">
      <input type="hidden" name="batch_type" id="batch_type" value="">
      <div id="batch-hidden-fields"></div>

      <div style="background:#f9fafb;border:1px solid var(--border);border-radius:10px;padding:14px">
        <div style="font-weight:700;margin-bottom:8px;font-size:14px">Toplu Kaydet</div>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
          Tüm tarama listesi <strong>tek hareket</strong> tipiyle kaydedilir.
        </div>
        <div class="action-buttons">
          <button type="button" class="btn-giris" onclick="submitBatch('giris')">
            <span class="icon">⬇</span><span>Toplu Giriş</span>
          </button>
          <button type="button" class="btn-cikis" onclick="submitBatch('cikis')">
            <span class="icon">⬆</span><span>Toplu Çıkış</span>
          </button>
          <button type="button" class="btn-sayim" onclick="submitBatch('sayim')">
            <span class="icon">📋</span><span>Sayım/Düzelt</span>
          </button>
        </div>
      </div>
    </form>
  </div>

</div>

<!-- HTML5-QRCode kütüphanesi (CDN) -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<script>
// ─── Yardımcı: Toast ─────────────────────────────────────────────
function toast(msg, type) {
  const el = document.createElement('div');
  el.className = 'toast' + (type ? ' ' + type : '');
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 3000);
}

// ─── Tab geçişi ──────────────────────────────────────────────────
document.querySelectorAll('.scan-tab').forEach(t => {
  t.addEventListener('click', () => {
    document.querySelectorAll('.scan-tab').forEach(x => x.classList.remove('active'));
    document.querySelectorAll('.scan-pane').forEach(x => x.classList.remove('active'));
    t.classList.add('active');
    document.getElementById('pane-' + t.dataset.tab).classList.add('active');
    // Aktif olmayan kameraları durdur
    if (t.dataset.tab === 'quick') stopScanner('b');
    if (t.dataset.tab === 'batch') stopScanner('q');
  });
});

// ─── Scanner manager — iki ayrı reader (q ve b) ──────────────────
const scanners = { q: null, b: null };

function startScanner(suffix) {
  if (scanners[suffix]) return;
  const reader = new Html5Qrcode('reader-' + suffix);
  scanners[suffix] = reader;
  document.getElementById('status-' + suffix).textContent = 'Kamera açılıyor…';

  reader.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: { width: 250, height: 250 } },
    (decodedText) => {
      // Başarılı tarama — tarayıcı titreşim (mobile)
      if (navigator.vibrate) navigator.vibrate(80);
      onCodeDetected(decodedText, suffix);
    },
    (errMsg) => { /* Her frame fail olabilir, sessiz geç */ }
  ).then(() => {
    document.getElementById('status-' + suffix).textContent = '🔴 Tarıyor…';
    document.getElementById('btn-start-' + suffix).disabled = true;
    document.getElementById('btn-stop-' + suffix).disabled = false;
  }).catch(err => {
    document.getElementById('status-' + suffix).textContent = 'Kamera erişimi reddedildi';
    toast('Kamera açılamadı: ' + err, 'error');
    scanners[suffix] = null;
  });
}

function stopScanner(suffix) {
  if (!scanners[suffix]) return;
  scanners[suffix].stop().then(() => {
    scanners[suffix].clear();
    scanners[suffix] = null;
    const status = document.getElementById('status-' + suffix);
    if (status) status.textContent = 'Durduruldu';
    const btnS = document.getElementById('btn-start-' + suffix);
    const btnT = document.getElementById('btn-stop-' + suffix);
    if (btnS) btnS.disabled = false;
    if (btnT) btnT.disabled = true;
  }).catch(() => { scanners[suffix] = null; });
}

document.getElementById('btn-start-q').addEventListener('click', () => startScanner('q'));
document.getElementById('btn-stop-q').addEventListener('click',  () => stopScanner('q'));
document.getElementById('btn-start-b').addEventListener('click', () => startScanner('b'));
document.getElementById('btn-stop-b').addEventListener('click',  () => stopScanner('b'));

// Manuel kod girişi
function manualHandler(suffix) {
  const inp = document.getElementById('manual-code-' + suffix);
  inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); document.getElementById('btn-manual-' + suffix).click(); } });
  document.getElementById('btn-manual-' + suffix).addEventListener('click', () => {
    const code = inp.value.trim();
    if (code) { onCodeDetected(code, suffix); inp.value = ''; }
  });
}
manualHandler('q'); manualHandler('b');

// ─── Aynı kodu 2 saniye içinde tekrar okumayı engelle ───────────
const lastScan = { q: { code: null, time: 0 }, b: { code: null, time: 0 } };

async function onCodeDetected(code, suffix) {
  const now = Date.now();
  if (lastScan[suffix].code === code && (now - lastScan[suffix].time) < 2000) return;
  lastScan[suffix] = { code, time: now };

  try {
    const res  = await fetch('?page=stock_scan&action=lookup&code=' + encodeURIComponent(code));
    const data = await res.json();
    if (!data.ok) { toast(data.message || 'Bulunamadı', 'error'); return; }

    if (suffix === 'q') showQuickProduct(data.product);
    else                addToBatch(data.product);
  } catch (e) {
    toast('Bağlantı hatası: ' + e.message, 'error');
  }
}

// ─── HIZLI MOD: Ürün kartı + +/- + Giriş/Çıkış/Sayım ──────────────
function showQuickProduct(p) {
  const stockClass = p.stock <= 0 ? 'empty' : (p.stock <= p.stock_critical ? 'low' : 'ok');
  const stockText  = p.stock + ' ' + (p.unit || 'adet');
  const html = `
    <div class="product-card">
      ${p.image ? `<img src="/uploads/products/${p.image}" alt="">` : `<div class="ph">📦</div>`}
      <div class="info">
        <div class="name">${escapeHtml(p.name)}</div>
        <div class="sku">${escapeHtml(p.sku || '')} ${p.barcode ? '· '+escapeHtml(p.barcode) : ''}</div>
      </div>
      <div class="stock-badge ${stockClass}">${stockText}</div>
    </div>

    <div class="qty-controls">
      <button class="qty-btn minus" onclick="adjustQty(-1)">−</button>
      <input type="number" id="quick-qty" class="qty-input" value="1" min="1" inputmode="numeric">
      <button class="qty-btn plus" onclick="adjustQty(1)">+</button>
    </div>

    <div class="action-buttons">
      <button class="btn-giris" onclick="applyQuick(${p.id}, 'giris')">
        <span class="icon">⬇</span><span>Giriş Yap</span>
      </button>
      <button class="btn-cikis" onclick="applyQuick(${p.id}, 'cikis')">
        <span class="icon">⬆</span><span>Çıkış Yap</span>
      </button>
      <button class="btn-sayim" onclick="applyQuick(${p.id}, 'sayim')">
        <span class="icon">📋</span><span>Sayım Düzelt</span>
      </button>
    </div>
    <div style="font-size:11px;color:var(--text-muted);text-align:center;margin-top:8px">
      <strong>Sayım:</strong> Mevcut stok yerine yazdığınız değer geçerli olur (mutlak set).
    </div>
  `;
  document.getElementById('product-result-q').innerHTML = html;
  document.getElementById('quick-qty').focus();
  document.getElementById('quick-qty').select();
}

function adjustQty(delta) {
  const inp = document.getElementById('quick-qty');
  const v = Math.max(1, (parseInt(inp.value) || 1) + delta);
  inp.value = v;
}

async function applyQuick(productId, type) {
  const qty = parseInt(document.getElementById('quick-qty').value) || 0;
  if (qty <= 0) { toast('Miktar 1\'den büyük olmalı', 'error'); return; }

  // 'sayim' → mutlak set: önceki stok ile farkı al
  // (Sayım modunda kullanıcı 10 yazıp mevcut stok 5 ise, +5 hareket eklenir)
  let change = qty;
  if (type === 'cikis') change = -qty;
  else if (type === 'sayim') {
    // Mevcut ürün kartından stoğu oku
    const stockBadge = document.querySelector('#product-result-q .stock-badge');
    const currentStock = stockBadge ? parseInt(stockBadge.textContent) || 0 : 0;
    change = qty - currentStock;
    if (change === 0) { toast('Stok zaten ' + qty, 'error'); return; }
  }

  const fd = new FormData();
  fd.append('csrf_token', '<?= csrfToken() ?>');
  fd.append('form_action', 'apply_single');
  fd.append('product_id', productId);
  fd.append('change', change);
  fd.append('change_type', type);

  try {
    const res = await fetch('?page=stock_scan', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      toast(`✓ ${data.product} — Yeni stok: ${data.new_stock}`, 'success');
      // Stok rozetini güncelle
      const stockBadge = document.querySelector('#product-result-q .stock-badge');
      if (stockBadge) {
        const unit = stockBadge.textContent.replace(/[\d.,]+/g, '').trim();
        stockBadge.textContent = data.new_stock + ' ' + unit;
      }
    } else {
      toast(data.message || 'Hata', 'error');
    }
  } catch (e) {
    toast('Bağlantı hatası', 'error');
  }
}

// ─── TOPLU MOD: Liste birikir, tek seferde kaydedilir ──────────
const batchItems = []; // [{product_id, name, sku, qty}]

function addToBatch(p) {
  // Aynı ürün varsa miktarı 1 artır
  const existing = batchItems.find(x => x.product_id === p.id);
  if (existing) {
    existing.qty += 1;
  } else {
    batchItems.push({ product_id: p.id, name: p.name, sku: p.sku || '', qty: 1, current_stock: p.stock });
  }
  toast(`✓ ${p.name} eklendi (${existing ? existing.qty : 1})`, 'success');
  renderBatchList();
}

function renderBatchList() {
  const list = document.getElementById('batch-list');
  const form = document.getElementById('batch-form');

  if (batchItems.length === 0) {
    list.innerHTML = `<div style="text-align:center;color:var(--text-muted);padding:24px;font-size:13px;background:#fafafa;border-radius:8px;border:1px dashed var(--border)">
      Henüz tarama yok. Üstteki kameradan veya manuel kodla ürün ekleyin.</div>`;
    form.style.display = 'none';
    return;
  }

  let html = '';
  batchItems.forEach((it, idx) => {
    html += `
      <div class="batch-row">
        <div class="name">${escapeHtml(it.name)}<br><span style="font-size:11px;color:var(--text-muted)">${escapeHtml(it.sku)} · Mevcut: ${it.current_stock}</span></div>
        <button class="qty-btn" style="width:32px;height:32px" onclick="batchAdjust(${idx}, -1)">−</button>
        <input type="number" value="${it.qty}" min="1" style="width:60px;padding:6px;text-align:center;border:1px solid var(--border-2);border-radius:6px" onchange="batchSetQty(${idx}, this.value)">
        <button class="qty-btn" style="width:32px;height:32px" onclick="batchAdjust(${idx}, 1)">+</button>
        <button class="remove" onclick="batchRemove(${idx})" title="Kaldır">×</button>
      </div>
    `;
  });
  list.innerHTML = html;
  form.style.display = 'block';
}

function batchAdjust(idx, delta) { batchItems[idx].qty = Math.max(1, batchItems[idx].qty + delta); renderBatchList(); }
function batchSetQty(idx, v) { batchItems[idx].qty = Math.max(1, parseInt(v) || 1); renderBatchList(); }
function batchRemove(idx) { batchItems.splice(idx, 1); renderBatchList(); }

function submitBatch(type) {
  if (batchItems.length === 0) { toast('Liste boş', 'error'); return; }
  const confirmTxt = type === 'giris' ? 'Tüm liste için stok GİRİŞİ yapılacak. Onaylıyor musunuz?'
                   : type === 'cikis' ? 'Tüm liste için stok ÇIKIŞI yapılacak. Onaylıyor musunuz?'
                   : 'Tüm liste için sayım düzeltmesi yapılacak. Onaylıyor musunuz?';
  if (!confirm(confirmTxt)) return;

  const hidden = document.getElementById('batch-hidden-fields');
  hidden.innerHTML = '';
  batchItems.forEach((it, i) => {
    const change = type === 'cikis' ? -it.qty : it.qty;
    hidden.insertAdjacentHTML('beforeend', `
      <input type="hidden" name="items[${i}][product_id]" value="${it.product_id}">
      <input type="hidden" name="items[${i}][change]" value="${change}">
    `);
  });
  document.getElementById('batch_type').value = type;
  document.getElementById('batch-form').submit();
}

function escapeHtml(s) {
  if (!s) return '';
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// İlk açılışta otomatik kamera başlat (mobil için kolay UX)
window.addEventListener('load', () => {
  // Kullanıcı ilk açtığında izin sorulduğunda açılsın
  setTimeout(() => startScanner('q'), 500);
});
</script>

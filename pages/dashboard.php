<?php
// pages/dashboard.php — Bayi Dashboard
requireDealer();
$dealer = currentDealer();

// İstatistikler
$totalOrders   = (int)dbVal("SELECT COUNT(*) FROM b2b_orders WHERE dealer_id=?", [$dealer['id']]);
$pendingOrders = (int)dbVal("SELECT COUNT(*) FROM b2b_orders WHERE dealer_id=? AND status='bekliyor'", [$dealer['id']]);
$openBalance   = (float)dbVal("SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0", [$dealer['id']]);
$overdueAmount = (float)dbVal("SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0 AND due_date < CURDATE()", [$dealer['id']]);
$cartCount     = (int)dbVal("SELECT COALESCE(SUM(qty),0) FROM b2b_cart WHERE dealer_id=?", [$dealer['id']]);

// Son siparişler
$recentOrders = dbRows(
    "SELECT * FROM b2b_orders WHERE dealer_id=? ORDER BY created_at DESC LIMIT 5",
    [$dealer['id']]
);

// Bildirimler — DEDUPE: aynı sipariş için sadece son durum güncellemesi gösterilir
// (5 kez statü değişikliği olmuşsa 5 ayrı bildirim yerine sadece sonuncu)
$rawNotifs = dbRows(
    "SELECT * FROM b2b_notifications WHERE dealer_id=? ORDER BY created_at DESC LIMIT 20",
    [$dealer['id']]
);
$seenUrls = [];
$notifications = [];
foreach ($rawNotifs as $n) {
    $key = $n['url'] ?: ('id_' . $n['id']);
    if (isset($seenUrls[$key])) continue;
    $seenUrls[$key] = true;
    $notifications[] = $n;
    if (count($notifications) >= 5) break;
}

$unreadCount = (int)dbVal("SELECT COUNT(*) FROM b2b_notifications WHERE dealer_id=? AND is_read=0", [$dealer['id']]);
// Okundu işaretle
dbExec("UPDATE b2b_notifications SET is_read=1 WHERE dealer_id=?", [$dealer['id']]);

// Vadesi yaklaşan (7 gün)
$upcomingDue = dbRows(
    "SELECT * FROM b2b_ledger WHERE dealer_id=? AND is_closed=0 AND type='borc' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) ORDER BY due_date",
    [$dealer['id']]
);

// Kampanyalı ürünler — bayi dashboard slider'ında gösterilecek
// Admin panelinden 'is_featured=1' ile işaretlenenler
$featuredProducts = [];
try {
    $featuredProducts = dbRows(
        "SELECT p.id, p.name, p.sku, p.image, p.base_price, p.vat_rate, p.unit, p.stock
         FROM b2b_products p
         WHERE p.is_active=1 AND p.is_featured=1
         ORDER BY p.id DESC
         LIMIT 20",
        []
    );
    // Bayinin price_list'ine göre indirimli fiyatı hesapla
    $plId = (int)($dealer['price_list_id'] ?? 0);
    foreach ($featuredProducts as $i => $p) {
        $dp = dealerPrice($p['id'], $plId);
        $featuredProducts[$i]['final_price'] = $dp['price']; // net
        $featuredProducts[$i]['discount']    = $dp['discount'];
    }
} catch (\Throwable $e) {
    // is_featured kolonu yoksa (migration_015 çalışmamış) sessizce boş döner
    $featuredProducts = [];
}

// Aktif Duyurular — dışarıdan ($announcements) gelmediyse direkt çek
// (defansif: index.php'deki blok exception'a düştüyse veya değişken pas geçilmediyse)
if (empty($announcements)) {
    try {
        $announcements = dbRows(
            "SELECT a.*, IF(r.id IS NOT NULL,1,0) AS is_read
             FROM b2b_announcements a
             LEFT JOIN b2b_announcement_reads r
               ON r.announcement_id=a.id AND r.dealer_id=?
             WHERE a.is_active=1
               AND (a.starts_at IS NULL OR a.starts_at <= NOW())
               AND (a.ends_at IS NULL OR a.ends_at >= NOW())
             ORDER BY a.created_at DESC LIMIT 5",
            [$dealer['id']]
        );
    } catch (\Throwable $e) { $announcements = []; }
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Merhaba, <?= h(trim(($dealer['first_name']??'').' '.($dealer['last_name']??'')) ?: ($dealer['company_name']??'Bayi')) ?> 👋</h1>
        <p class="page-sub"><?= fmtDate(date('Y-m-d H:i:s')) ?></p>
    </div>
    <a href="?page=products" class="btn btn-primary">🛒 Sipariş Ver</a>
</div>

<!-- ════════════════════ KAMPANYA SLİDER ════════════════════ -->
<?php if (!empty($featuredProducts)): ?>
<div class="campaign-slider" style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px 18px 16px;margin-bottom:20px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:8px">
    <div style="display:flex;align-items:center;gap:8px">
      <span style="font-size:18px">🔥</span>
      <strong style="font-size:14px;color:var(--text)">Kampanyalı Ürünler</strong>
      <span style="background:#fef2f2;color:#c1272d;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px"><?= count($featuredProducts) ?></span>
    </div>
    <div style="display:flex;gap:6px">
      <button type="button" id="camp-prev" aria-label="Önceki" style="width:32px;height:32px;border-radius:50%;border:1px solid var(--border-2);background:#fff;cursor:pointer;font-size:16px;font-weight:700;color:var(--text-2);display:flex;align-items:center;justify-content:center;transition:.15s">‹</button>
      <button type="button" id="camp-next" aria-label="Sonraki" style="width:32px;height:32px;border-radius:50%;border:1px solid var(--border-2);background:#fff;cursor:pointer;font-size:16px;font-weight:700;color:var(--text-2);display:flex;align-items:center;justify-content:center;transition:.15s">›</button>
    </div>
  </div>

  <div id="camp-viewport" style="position:relative;overflow:hidden;padding:2px">
    <div id="camp-track" style="display:flex;gap:12px;transition:transform .45s cubic-bezier(.4,0,.2,1);will-change:transform">
      <?php foreach ($featuredProducts as $p):
        $vat   = (float)$p['vat_rate'];
        $vatM  = 1 + $vat/100;
        $base  = (float)$p['base_price'];
        $final = (float)$p['final_price'];
        $hasDisc = $p['discount'] > 0;
        $baseGross  = $base * $vatM;
        $finalGross = $final * $vatM;
        $inStock = $p['stock'] > 0;
      ?>
      <a href="?page=product&id=<?= $p['id'] ?>" class="camp-card" style="flex:0 0 200px;width:200px;background:linear-gradient(135deg,#fff5f5,#fff);border:1px solid #fecaca;border-radius:10px;padding:10px;position:relative;text-decoration:none;color:inherit;transition:.2s;cursor:pointer;display:block">
        <?php if ($hasDisc): ?>
        <div style="position:absolute;top:6px;right:6px;background:#c1272d;color:#fff;font-size:9px;font-weight:700;padding:2px 6px;border-radius:4px;letter-spacing:.3px;z-index:2">%<?= number_format($p['discount'],0) ?></div>
        <?php else: ?>
        <div style="position:absolute;top:6px;right:6px;background:#16a34a;color:#fff;font-size:9px;font-weight:700;padding:2px 6px;border-radius:4px;letter-spacing:.3px;z-index:2">FIRSAT</div>
        <?php endif; ?>

        <div style="width:100%;aspect-ratio:1/1;background:#fee2e2;border-radius:6px;margin-bottom:8px;overflow:hidden;display:flex;align-items:center;justify-content:center">
          <?php if (!empty($p['image'])): ?>
            <img src="/uploads/products/<?= h($p['image']) ?>" alt="" style="width:100%;height:100%;object-fit:cover">
          <?php else: ?>
            <span style="font-size:24px;opacity:.5">📦</span>
          <?php endif; ?>
        </div>

        <div style="font-size:12px;font-weight:600;line-height:1.3;margin-bottom:4px;min-height:30px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
          <?= h($p['name']) ?>
        </div>

        <?php if ($hasDisc): ?>
        <div style="font-size:9px;color:#999;text-decoration:line-through;line-height:1"><?= money($baseGross) ?></div>
        <?php endif; ?>
        <div style="font-size:13px;font-weight:800;color:#c1272d;line-height:1.2;margin-top:1px"><?= money($finalGross) ?></div>
        <div style="font-size:9px;color:var(--text-muted);margin-top:1px">KDV Dahil · <?= h($p['unit'] ?? 'adet') ?></div>

        <?php if (!$inStock): ?>
        <div style="position:absolute;inset:0;background:rgba(255,255,255,.85);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#dc2626">TÜKENDİ</div>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div id="camp-dots" style="display:flex;justify-content:center;gap:5px;margin-top:12px"></div>
</div>

<style>
  .camp-card:hover { transform: translateY(-2px); border-color: #f87171 !important; box-shadow: 0 4px 14px rgba(193,39,45,.12); }
  #camp-prev:hover, #camp-next:hover { background: #fef2f2 !important; border-color: #f87171 !important; color: #c1272d !important; }
  #camp-prev:disabled, #camp-next:disabled { opacity: .35; cursor: not-allowed; }
  .camp-dot { width: 6px; height: 6px; border-radius: 50%; background: #ddd; border: none; cursor: pointer; padding: 0; transition: .2s; }
  .camp-dot.active { width: 18px; border-radius: 3px; background: #c1272d; }
</style>

<script>
(function() {
  var track  = document.getElementById('camp-track');
  var view   = document.getElementById('camp-viewport');
  var prev   = document.getElementById('camp-prev');
  var next   = document.getElementById('camp-next');
  var dots   = document.getElementById('camp-dots');
  if (!track || !view) return;

  var cards = track.querySelectorAll('.camp-card');
  var total = cards.length;
  var page  = 0;
  var autoTimer = null;

  function perPage() {
    if (!cards[0]) return 1;
    var viewW = view.getBoundingClientRect().width;
    var cardW = 200; // .camp-card sabit genişliği
    var gap   = 12;
    var n = Math.floor((viewW + gap) / (cardW + gap));
    return Math.max(1, Math.min(n, total));
  }

  function pages() { return Math.max(1, Math.ceil(total / perPage())); }

  function buildDots() {
    dots.innerHTML = '';
    var n = pages();
    if (n <= 1) return;
    for (var i = 0; i < n; i++) {
      var d = document.createElement('button');
      d.type = 'button';
      d.className = 'camp-dot' + (i === page ? ' active' : '');
      d.setAttribute('aria-label', 'Sayfa ' + (i+1));
      (function(idx) {
        d.addEventListener('click', function() { page = idx; render(); resetAuto(); });
      })(i);
      dots.appendChild(d);
    }
  }

  function render() {
    var pp = perPage();
    var maxPage = Math.max(0, pages() - 1);
    if (page > maxPage) page = maxPage;
    var offset = page * pp;
    if (offset >= total) offset = Math.max(0, total - pp);
    if (cards[0]) {
      var cardW = cards[0].getBoundingClientRect().width;
      var gap = 12;
      track.style.transform = 'translateX(' + (-(cardW + gap) * offset) + 'px)';
    }
    var ds = dots.querySelectorAll('.camp-dot');
    ds.forEach(function(d, i) { d.classList.toggle('active', i === page); });
    prev.disabled = page === 0;
    next.disabled = page >= maxPage;
  }

  function go(dir) {
    var maxPage = pages() - 1;
    page += dir;
    if (page > maxPage) page = 0;       // sona gelince başa döner (loop)
    if (page < 0) page = maxPage;       // başa gelince sona döner
    render();
  }

  function startAuto() {
    if (pages() <= 1) return;
    autoTimer = setInterval(function() { go(1); }, 5000);
  }
  function stopAuto() { if (autoTimer) clearInterval(autoTimer); autoTimer = null; }
  function resetAuto() { stopAuto(); startAuto(); }

  prev.addEventListener('click', function() { go(-1); resetAuto(); });
  next.addEventListener('click', function() { go(1);  resetAuto(); });

  // Hover'da otomatik kayma durur
  view.addEventListener('mouseenter', stopAuto);
  view.addEventListener('mouseleave', startAuto);

  // Touch swipe (mobil)
  var startX = 0, dx = 0;
  view.addEventListener('touchstart', function(e) {
    if (e.touches.length === 1) { startX = e.touches[0].clientX; dx = 0; stopAuto(); }
  }, {passive:true});
  view.addEventListener('touchmove', function(e) {
    if (e.touches.length === 1) dx = e.touches[0].clientX - startX;
  }, {passive:true});
  view.addEventListener('touchend', function() {
    if (Math.abs(dx) > 50) { go(dx < 0 ? 1 : -1); }
    startAuto();
  });

  // Resize ile yeniden hesaplama
  var resizeTimer;
  window.addEventListener('resize', function() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function() { buildDots(); render(); }, 150);
  });

  // İlk yükleme
  buildDots();
  setTimeout(render, 50);  // CSS yüklenmesini bekle
  startAuto();
})();
</script>
<?php endif; ?>

<!-- Duyurular -->
<?php if (!empty($announcements)): ?>
<div style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:0;margin-bottom:20px;overflow:hidden">
  <div style="background:linear-gradient(135deg,#1e3a5f,#2c5282);padding:14px 20px;display:flex;align-items:center;gap:10px;color:#fff">
    <span style="font-size:18px">📢</span>
    <strong style="font-size:14px">Duyurular</strong>
    <span style="background:rgba(255,255,255,.2);color:#fff;font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;margin-left:6px"><?= count($announcements) ?></span>
    <a href="?page=announcements" style="margin-left:auto;color:#fff;font-size:12px;font-weight:600;text-decoration:none;opacity:.9">Tümü →</a>
  </div>
  <div>
    <?php foreach ($announcements as $i => $ann):
      $type = $ann['type'] ?? 'bilgi';
      $cfg = match($type) {
          'onemli' => ['icon'=>'🔴', 'badge'=>'ÖNEMLİ',  'color'=>'#dc2626', 'bg'=>'#fef2f2'],
          'uyari'  => ['icon'=>'⚠️', 'badge'=>'UYARI',   'color'=>'#d97706', 'bg'=>'#fffbeb'],
          default  => ['icon'=>'ℹ️', 'badge'=>'BİLGİ',   'color'=>'#0369a1', 'bg'=>'#eff6ff'],
      };
      $isUnread = empty($ann['is_read']);
    ?>
    <div style="padding:14px 20px;border-top:1px solid var(--border);<?= $isUnread ? 'background:'.$cfg['bg'] : '' ?>;display:flex;gap:14px;align-items:flex-start">
      <div style="font-size:20px;line-height:1.2;flex:0 0 auto;margin-top:2px"><?= $cfg['icon'] ?></div>
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap">
          <span style="font-size:10px;font-weight:700;color:<?= $cfg['color'] ?>;background:#fff;border:1px solid <?= $cfg['color'] ?>40;padding:2px 7px;border-radius:4px;letter-spacing:.4px"><?= $cfg['badge'] ?></span>
          <strong style="font-size:13px;color:var(--text)"><?= h($ann['title']) ?></strong>
          <?php if ($isUnread): ?><span style="width:6px;height:6px;background:<?= $cfg['color'] ?>;border-radius:50%"></span><?php endif; ?>
        </div>
        <?php if (!empty($ann['content'])): ?>
        <div style="font-size:12px;color:var(--text-2);line-height:1.6;margin-bottom:6px"><?= nl2br(h(mb_substr($ann['content'], 0, 200))) ?><?= mb_strlen($ann['content']) > 200 ? '...' : '' ?></div>
        <?php endif; ?>
        <div style="font-size:11px;color:var(--text-muted)">
          <?= fmtDate($ann['created_at']) ?>
          <?php if (!empty($ann['ends_at'])): ?>
          · <span style="color:#d97706"><?= date('d.m.Y', strtotime($ann['ends_at'])) ?>'e kadar</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Stat Kartları — modern, ikonlu, kompakt -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:20px">
    <a href="?page=orders" style="text-decoration:none;color:inherit">
      <div style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:18px;transition:.15s;height:100%" onmouseover="this.style.borderColor='var(--red)';this.style.boxShadow='0 2px 8px rgba(237,41,57,.08)'" onmouseout="this.style.borderColor='var(--border)';this.style.boxShadow=''">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Toplam Sipariş</div>
          <div style="width:32px;height:32px;background:#dbeafe;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px">📦</div>
        </div>
        <div style="font-size:24px;font-weight:700;color:#111827;line-height:1.1"><?= $totalOrders ?></div>
        <?php if ($pendingOrders): ?>
        <div style="font-size:11px;color:#d97706;font-weight:600;margin-top:4px"><?= $pendingOrders ?> tanesi onay bekliyor</div>
        <?php else: ?>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px"><?= $totalOrders > 0 ? 'Tümü işlemde ✓' : 'Henüz sipariş yok' ?></div>
        <?php endif; ?>
      </div>
    </a>

    <a href="?page=account" style="text-decoration:none;color:inherit">
      <div style="background:#fff;border:1px solid <?= $openBalance>0?'#fecaca':'var(--border)' ?>;border-radius:12px;padding:18px;transition:.15s;height:100%" onmouseover="this.style.boxShadow='0 2px 8px rgba(0,0,0,.08)'" onmouseout="this.style.boxShadow=''">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Açık Bakiye</div>
          <div style="width:32px;height:32px;background:<?= $openBalance>0?'#fee2e2':'#d1fae5' ?>;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px"><?= $openBalance>0?'⚠️':'✓' ?></div>
        </div>
        <div style="font-size:24px;font-weight:700;color:<?= $openBalance>0?'#dc2626':'#16a34a' ?>;line-height:1.1"><?= money($openBalance) ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px"><?= $openBalance>0 ? ($overdueAmount>0 ? money($overdueAmount).' vadesi geçti' : 'Borçlu hesap') : 'Hesap temiz' ?></div>
      </div>
    </a>

    <div style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:18px;height:100%">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Kredi Limiti</div>
        <div style="width:32px;height:32px;background:#fef3c7;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px">💳</div>
      </div>
      <div style="font-size:24px;font-weight:700;color:#111827;line-height:1.1"><?= money($dealer['credit_limit'] ?? 0) ?></div>
      <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Vade: <?= (int)($dealer['payment_term_days'] ?? 0) ?> gün</div>
    </div>

    <a href="?page=cart" style="text-decoration:none;color:inherit">
      <div style="background:#fff;border:1px solid <?= $cartCount>0?'rgba(237,41,57,.3)':'var(--border)' ?>;border-radius:12px;padding:18px;transition:.15s;height:100%" onmouseover="this.style.borderColor='var(--red)';this.style.boxShadow='0 2px 8px rgba(237,41,57,.08)'" onmouseout="this.style.borderColor='<?= $cartCount>0?'rgba(237,41,57,.3)':'var(--border)' ?>';this.style.boxShadow=''">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Sepet</div>
          <div style="width:32px;height:32px;background:#fce7f3;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px">🛒</div>
        </div>
        <div style="font-size:24px;font-weight:700;color:#111827;line-height:1.1"><?= $cartCount ?> <span style="font-size:14px;font-weight:500;color:var(--text-muted)">ürün</span></div>
        <?php if ($cartCount): ?>
        <div style="font-size:11px;color:var(--red);font-weight:600;margin-top:4px">Siparişi tamamla →</div>
        <?php else: ?>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Sepetiniz boş</div>
        <?php endif; ?>
      </div>
    </a>
</div>

<!-- Vade Uyarısı -->
<?php if ($upcomingDue): ?>
<div style="background:linear-gradient(135deg,#fffbeb,#fef3c7);border:1px solid #fcd34d;border-radius:10px;padding:14px 18px;margin-bottom:20px">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
    <span style="font-size:18px">⏰</span>
    <strong style="color:#92400e;font-size:14px">Yaklaşan Vade Tarihleri</strong>
    <a href="?page=account" style="margin-left:auto;color:#92400e;font-size:12px;font-weight:600;text-decoration:none">Ekstre →</a>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:8px">
    <?php foreach ($upcomingDue as $u): ?>
    <div style="background:#fff;border:1px solid #fde68a;border-radius:6px;padding:6px 10px;font-size:12px">
      <span style="color:#78350f"><?= h(mb_substr($u['description'] ?? '', 0, 40)) ?></span>
      <strong style="color:#92400e"> <?= money($u['amount']) ?></strong>
      <span style="color:#a16207">· <?= fmtDate($u['due_date']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Son Siparişler + Bildirimler — 2 sütun -->
<div style="display:grid;grid-template-columns:1.3fr 1fr;gap:18px">

  <!-- Son Siparişler -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <h3 style="margin:0;font-size:14px;font-weight:700;color:var(--text)">Son Siparişler</h3>
      <a href="?page=orders" style="font-size:12px;color:var(--red);text-decoration:none;font-weight:600">Tümü →</a>
    </div>
    <?php if (empty($recentOrders)): ?>
      <div style="padding:32px 18px;text-align:center;color:var(--text-muted)">
        <div style="font-size:32px;margin-bottom:6px;opacity:.4">📦</div>
        <p style="margin:0;font-size:13px">Henüz sipariş yok.</p>
        <a href="?page=products" style="display:inline-block;margin-top:8px;font-size:12px;color:var(--red);font-weight:600;text-decoration:none">Ürünleri incele →</a>
      </div>
    <?php else: ?>
      <table style="width:100%;border-collapse:collapse">
        <thead>
          <tr style="background:#f9fafb">
            <th style="padding:9px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.5px">SİPARİŞ NO</th>
            <th style="padding:9px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.5px">TARİH</th>
            <th style="padding:9px 14px;text-align:right;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.5px">TUTAR</th>
            <th style="padding:9px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.5px">DURUM</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recentOrders as $o): ?>
          <tr style="border-top:1px solid var(--border)">
            <td style="padding:11px 14px">
              <a href="?page=orders&action=detail&id=<?= (int)$o['id'] ?>" style="color:var(--red);text-decoration:none;font-weight:600;font-size:12px;font-family:ui-monospace,monospace"><?= h($o['order_no']) ?></a>
            </td>
            <td style="padding:11px 14px;font-size:12px;color:var(--text-2)"><?= fmtDate($o['created_at']) ?></td>
            <td style="padding:11px 14px;text-align:right;font-size:12px;font-weight:700;color:var(--text)"><?= money($o['grand_total']) ?></td>
            <td style="padding:11px 14px"><?= orderStatusLabel($o['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Bildirimler -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <h3 style="margin:0;font-size:14px;font-weight:700;color:var(--text)">
        Bildirimler
        <?php if ($unreadCount > 0): ?><span style="background:var(--red);color:#fff;font-size:10px;font-weight:700;padding:1px 7px;border-radius:99px;margin-left:4px"><?= $unreadCount ?></span><?php endif; ?>
      </h3>
      <a href="?page=notifications" style="font-size:12px;color:var(--red);text-decoration:none;font-weight:600">Tümü →</a>
    </div>
    <?php if (empty($notifications)): ?>
      <div style="padding:32px 18px;text-align:center;color:var(--text-muted)">
        <div style="font-size:32px;margin-bottom:6px;opacity:.4">🔔</div>
        <p style="margin:0;font-size:13px">Bildirim yok.</p>
      </div>
    <?php else: ?>
      <div>
      <?php foreach ($notifications as $n): ?>
        <?php
        $type   = $n['type'] ?? 'system';
        $icons  = ['order'=>'📦','payment'=>'💰','stock'=>'📊','dealer'=>'🏢','ticket'=>'🎫','application'=>'📝','system'=>'⚙️'];
        $icon   = $icons[$type] ?? '🔔';
        $title  = (string)($n['title'] ?? '');
        $body   = (string)($n['body']  ?? '');
        $url    = $n['url'] ?: '?page=notifications';
        $isUnread = empty($n['is_read']);
        ?>
        <a href="<?= h($url) ?>" style="display:flex;gap:10px;padding:12px 18px;border-top:1px solid var(--border);text-decoration:none;color:inherit;<?= $isUnread ? 'background:#fefce8' : '' ?>">
          <div style="font-size:18px;line-height:1.2;flex:0 0 auto"><?= $icon ?></div>
          <div style="flex:1;min-width:0">
            <div style="font-size:12px;font-weight:600;color:var(--text);line-height:1.3;margin-bottom:2px"><?= h($title) ?></div>
            <?php if ($body !== ''): ?>
            <div style="font-size:11px;color:var(--text-2);line-height:1.4;margin-bottom:3px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical"><?= h($body) ?></div>
            <?php endif; ?>
            <div style="font-size:10px;color:var(--text-muted)"><?= fmtDateTime($n['created_at']) ?></div>
          </div>
        </a>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

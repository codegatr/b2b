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

<!-- ─── Sipariş Penceresi Durumu ─── -->
<?php $orderWin = orderWindowStatus(); ?>
<?php if ($orderWin['enabled']): ?>
<?php if (!$orderWin['open']): ?>
<!-- KAPALI - kırmızı bant -->
<div style="background:linear-gradient(135deg,#fef2f2,#fee2e2);border:1px solid #fca5a5;border-left:4px solid #dc2626;border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:14px">
  <div style="font-size:28px">🔒</div>
  <div style="flex:1">
    <div style="font-weight:700;font-size:14px;color:#b91c1c">Sipariş Sistemi Kapalı</div>
    <div style="font-size:12px;color:#7f1d1d;margin-top:2px;line-height:1.5"><?= h($orderWin['message']) ?></div>
  </div>
  <?php if (!empty($orderWin['next_open'])): ?>
  <div style="text-align:right;background:#fff;border:1px solid #fca5a5;border-radius:8px;padding:8px 12px;min-width:120px">
    <div style="font-size:10px;color:#991b1b;text-transform:uppercase;letter-spacing:.5px;font-weight:600">Sonraki Açılış</div>
    <div style="font-size:13px;color:#b91c1c;font-weight:700;margin-top:2px"><?= h($orderWin['next_open']) ?></div>
  </div>
  <?php endif; ?>
</div>
<?php else: ?>
<!-- AÇIK - kısa yeşil bilgi -->
<div style="background:#f0fdf4;border:1px solid #86efac;border-left:4px solid #16a34a;border-radius:10px;padding:10px 16px;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-size:12px">
  <span style="font-size:16px">✅</span>
  <div style="color:#15803d;font-weight:600">Sipariş kabul açık · Bugün <?= h($orderWin['end']) ?>'e kadar</div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ─── Açık Bakiye / Borç Bildirimi ─── -->
<?php
// Tediye makbuzları (alacak/ödeme kayıtları) zaten formüle dahil:
// SUM(CASE WHEN borc THEN +amount ELSE -amount END) is_closed=0
// Yani: kalan borç = açık borç - ödenmiş tediye
$cardEnabled = setting('rubikpara_enabled') === '1';
if ($openBalance > 0):
    // Durum belirleme
    if ($overdueAmount > 0) {
        $severity = 'danger'; // Kırmızı: vadesi geçmiş
        $bg = '#fef2f2'; $border = '#fca5a5'; $accent = '#dc2626'; $titleColor = '#b91c1c'; $textColor = '#7f1d1d';
        $icon = '🚨'; $title = 'Vadesi Geçmiş Borcunuz Var';
        $sub = number_format($overdueAmount, 2, ',', '.') . ' ₺ vadesi geçti — lütfen en kısa sürede ödeme yapın';
    } else {
        $severity = 'warning'; // Sarı: borç var ama vadesi geçmemiş
        $bg = '#fffbeb'; $border = '#fcd34d'; $accent = '#d97706'; $titleColor = '#92400e'; $textColor = '#78350f';
        $icon = '💰'; $title = 'Açık Bakiye Bildirimi';
        $sub = 'Cari hesabınızda ödenmemiş borç bulunmaktadır';
    }
?>
<div style="background:linear-gradient(135deg,<?= $bg ?>,#ffffff);border:1px solid <?= $border ?>;border-left:5px solid <?= $accent ?>;border-radius:12px;padding:18px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.06)">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
    <div style="font-size:36px"><?= $icon ?></div>
    <div style="flex:1;min-width:240px">
      <div style="font-weight:700;font-size:15px;color:<?= $titleColor ?>"><?= h($title) ?></div>
      <div style="font-size:12px;color:<?= $textColor ?>;margin-top:2px;line-height:1.5"><?= h($sub) ?></div>
      <div style="margin-top:10px;display:flex;gap:18px;flex-wrap:wrap">
        <div>
          <div style="font-size:10px;color:<?= $textColor ?>;text-transform:uppercase;letter-spacing:.5px;font-weight:600">Toplam Açık Bakiye</div>
          <div style="font-size:22px;font-weight:800;color:<?= $accent ?>;font-family:system-ui;letter-spacing:-.5px"><?= money($openBalance) ?></div>
        </div>
        <?php if ($overdueAmount > 0): ?>
        <div>
          <div style="font-size:10px;color:<?= $textColor ?>;text-transform:uppercase;letter-spacing:.5px;font-weight:600">Vadesi Geçmiş</div>
          <div style="font-size:22px;font-weight:800;color:#dc2626;font-family:system-ui;letter-spacing:-.5px"><?= money($overdueAmount) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;min-width:200px">
      <?php if ($cardEnabled): ?>
      <a href="?page=payment-card&balance=1&amount=<?= number_format((float)$openBalance, 2, '.', '') ?>"
         class="btn"
         style="background:#16a34a;color:#fff;border:none;padding:12px 20px;font-weight:700;font-size:13px;border-radius:10px;text-align:center;white-space:nowrap;box-shadow:0 2px 8px rgba(22,163,74,.3)">
        💳 Kart ile Hemen Öde
      </a>
      <?php endif; ?>
      <a href="?page=payments&action=new"
         class="btn btn-secondary"
         style="padding:10px 20px;font-weight:600;font-size:12px;border-radius:10px;text-align:center;white-space:nowrap">
        🏦 Havale ile Öde
      </a>
      <a href="?page=account"
         style="font-size:11px;color:<?= $textColor ?>;text-decoration:underline;text-align:center;font-weight:500">
        Cari hesap detayları →
      </a>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ════════════════════ KAMPANYALI ÜRÜNLER — Düz grid ════════════════════ -->
<?php if (!empty($featuredProducts)): ?>
<div style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px 18px 16px;margin-bottom:20px">
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
    <span style="font-size:18px">🔥</span>
    <strong style="font-size:14px;color:var(--text)">Kampanyalı Ürünler</strong>
    <span style="background:#fef2f2;color:#c1272d;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px"><?= count($featuredProducts) ?></span>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px">
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
    <a href="?page=product&id=<?= $p['id'] ?>" class="camp-card" style="background:linear-gradient(135deg,#fff5f5,#fff);border:1px solid #fecaca;border-radius:10px;padding:10px;position:relative;text-decoration:none;color:inherit;transition:.2s;cursor:pointer;display:block">
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

<style>
  .camp-card:hover { transform: translateY(-2px); border-color: #f87171 !important; box-shadow: 0 4px 14px rgba(193,39,45,.12); }
</style>
<?php endif; ?>

<!-- Duyurular bloğu kaldırıldı — sidebar'da "Duyurular" linkinde rozet olarak görünür. -->

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

<?php
// admin/pages/ledger.php — Cari Hesap Yönetimi
requireAdmin();

$dealerId = intval($_GET['dealer_id'] ?? 0);
$action   = $_GET['action'] ?? 'list'; // list | detail | tahsilat | tediye
$curPage  = max(1, intval($_GET['p'] ?? 1));
$perPage  = 50;
$offset   = ($curPage - 1) * $perPage;

$success = ''; $error = '';

// ── PDF Export ───────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'pdf' && $dealerId) {
    $dlr     = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$dealerId]);
    $bal     = (float)dbVal("SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0", [$dealerId]);
    $ents    = dbRows("SELECT * FROM b2b_ledger WHERE dealer_id=? ORDER BY created_at DESC", [$dealerId]);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8">
    <title>Cari Ekstre — '.htmlspecialchars($dlr['company_name']??'').'</title>
    <style>
      body{font-family:Arial,sans-serif;font-size:12px;margin:20px}
      h2{margin:0 0 4px}
      .meta{color:#666;margin-bottom:16px}
      table{width:100%;border-collapse:collapse;margin-top:12px}
      th{background:#f4f4f4;padding:6px 8px;text-align:left;border:1px solid #ddd;font-size:11px}
      td{padding:6px 8px;border:1px solid #eee;font-size:11px}
      .borc{color:#dc2626;font-weight:600}
      .alacak{color:#16a34a;font-weight:600}
      .closed{opacity:.5}
      .total{background:#f9f9f9;font-weight:700}
      @media print{body{margin:0}}
    </style></head><body>';
    echo '<h2>'.htmlspecialchars($dlr['company_name']??'').'</h2>';
    echo '<div class="meta">Cari Hesap Ekstresi &nbsp;·&nbsp; '.date('d.m.Y H:i').'</div>';
    echo '<table><tr><th>Tarih</th><th>Açıklama</th><th style="text-align:right">Borç</th><th style="text-align:right">Alacak</th><th>Vade</th><th>Durum</th></tr>';
    $sub = $alc = 0;
    foreach ($ents as $e) {
        $cls = $e['is_closed'] ? ' class="closed"' : '';
        echo '<tr'.$cls.'>';
        echo '<td>'.date('d.m.Y', strtotime($e['created_at'])).'</td>';
        echo '<td>'.htmlspecialchars($e['description']).'</td>';
        if ($e['type']==='borc') { echo '<td class="borc" style="text-align:right">'.number_format($e['amount'],2,',','.').'</td><td></td>'; $sub+=$e['amount']; }
        else { echo '<td></td><td class="alacak" style="text-align:right">'.number_format($e['amount'],2,',','.').'</td>'; $alc+=$e['amount']; }
        echo '<td>'.($e['due_date']?date('d.m.Y',strtotime($e['due_date'])):'—').'</td>';
        echo '<td>'.($e['is_closed']?'Kapalı':'Açık').'</td>';
        echo '</tr>';
    }
    echo '<tr class="total"><td colspan="2">TOPLAM</td>';
    echo '<td style="text-align:right;color:#dc2626">'.number_format($sub,2,',','.').'</td>';
    echo '<td style="text-align:right;color:#16a34a">'.number_format($alc,2,',','.').'</td>';
    echo '<td colspan="2">Net: '.number_format($bal,2,',','.').' ₺</td></tr>';
    echo '</table><script>window.print()</script></body></html>';
    exit;
}

// ── POST Handler ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';

    // Tahsilat (ödeme al)
    if ($act === 'tahsilat') {
        $did    = intval($_POST['dealer_id']);
        $amount = floatval($_POST['amount']);
        $desc   = trim($_POST['description'] ?: 'Tahsilat');
        $due    = $_POST['due_date'] ?: null;
        if ($did && $amount > 0) {
            ledgerAdd($did, 'alacak', $amount, $desc, 'manuel', 0, $due);
            auditLog('tahsilat', 'b2b_ledger', 0, ['dealer_id'=>$did,'amount'=>$amount]);
            $success = number_format($amount,2,',','.').' ₺ tahsilat kaydedildi.';
        } else { $error = 'Tutar ve bayi zorunludur.'; }
    }

    // Tediye (ödeme yap / alacak ekle)
    if ($act === 'tediye') {
        $did    = intval($_POST['dealer_id']);
        $amount = floatval($_POST['amount']);
        $desc   = trim($_POST['description'] ?: 'Tediye');
        $due    = $_POST['due_date'] ?: null;
        if ($did && $amount > 0) {
            ledgerAdd($did, 'borc', $amount, $desc, 'manuel', 0, $due);
            auditLog('tediye', 'b2b_ledger', 0, ['dealer_id'=>$did,'amount'=>$amount]);
            $success = number_format($amount,2,',','.').' ₺ tediye kaydedildi.';
        } else { $error = 'Tutar ve bayi zorunludur.'; }
    }

    // Kaydı kapat
    if ($act === 'close_entry') {
        $eid = intval($_POST['entry_id']);
        dbExec("UPDATE b2b_ledger SET is_closed=1, closed_at=NOW() WHERE id=?", [$eid]);
        $success = 'Kayıt kapatıldı.';
    }

    // Cari kodu inline güncelleme
    if ($act === 'update_dealer_code') {
        $did  = intval($_POST['dealer_id']);
        $code = trim($_POST['dealer_code'] ?? '');
        // Boş bırakılmasına izin ver (NULL set edilir), yoksa max 50 char
        $code = $code === '' ? null : mb_substr($code, 0, 50);
        if ($did) {
            // Aynı kodu başka bayide kullanılmasın
            if ($code !== null) {
                $exists = dbVal("SELECT id FROM b2b_dealers WHERE dealer_code=? AND id<>?",
                    [$code, $did]);
                if ($exists) { $error = "Bu cari kodu zaten kullanılıyor (Bayi #$exists)."; }
            }
            if (!$error) {
                dbExec("UPDATE b2b_dealers SET dealer_code=? WHERE id=?", [$code, $did]);
                auditLog('dealer_code_updated', 'b2b_dealers', $did, ['code'=>$code]);
                $success = 'Cari kodu güncellendi.';
            }
        }
    }

    if ($error)        $_SESSION['flash_admin'] = ['type'=>'danger','msg'=>$error];
    elseif ($success)  $_SESSION['flash_admin'] = ['type'=>'success','msg'=>$success];

    if ($dealerId) {
        redirect("?page=ledger&dealer_id=$dealerId");
    } else {
        redirect("?page=ledger");
    }
}

// ── Veri ──────────────────────────────────────────────────────
$dealers = dbRows("SELECT id, dealer_code, company_name FROM b2b_dealers WHERE is_active=1 ORDER BY company_name");

// Genel cari listesi — fotoğraftaki gibi
$cariList = dbRows(
    "SELECT d.id, d.dealer_code, d.company_name,
        COALESCE(SUM(CASE WHEN l.type='borc' AND l.is_closed=0 THEN l.amount ELSE 0 END),0)    AS toplam_borc,
        COALESCE(SUM(CASE WHEN l.type='alacak' AND l.is_closed=0 THEN l.amount ELSE 0 END),0)  AS toplam_alacak,
        COALESCE(SUM(CASE WHEN l.is_closed=0 AND l.type='borc' THEN l.amount WHEN l.is_closed=0 AND l.type='alacak' THEN -l.amount ELSE 0 END),0) AS net_bakiye,
        MAX(CASE WHEN l.type='borc' AND l.is_closed=0 THEN l.due_date ELSE NULL END)           AS son_vade
     FROM b2b_dealers d
     LEFT JOIN b2b_ledger l ON l.dealer_id=d.id
     WHERE d.is_active=1
     GROUP BY d.id, d.dealer_code, d.company_name
     ORDER BY d.company_name"
);

// Seçili bayi detayı
$dealer = null;
$entries = [];
$balance = 0; $overdue = 0;

if ($dealerId) {
    $dealer  = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$dealerId]);
    $balance = (float)dbVal("SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0", [$dealerId]);
    $overdue = (float)dbVal("SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0 AND due_date < CURDATE()", [$dealerId]);
    $total   = (int)dbVal("SELECT COUNT(*) FROM b2b_ledger WHERE dealer_id=?", [$dealerId]);
    $entries = dbRows("SELECT * FROM b2b_ledger WHERE dealer_id=? ORDER BY created_at DESC LIMIT $perPage OFFSET $offset", [$dealerId]);
    $pager   = pagination($total, $perPage, $curPage, "?page=ledger&dealer_id=$dealerId&p=");
}

// Genel toplamlar
$genelBorc   = array_sum(array_column($cariList, 'toplam_borc'));
$genelAlacak = array_sum(array_column($cariList, 'toplam_alacak'));
?>

<!-- Üst Menü -->
<div class="page-header" style="margin-bottom:16px">
  <div>
    <h1 class="page-title">Cari Hesap Yönetimi</h1>
    <?php if ($dealerId && $dealer): ?>
    <p class="page-sub"><?= h($dealer['dealer_code']??'') ?> — <?= h($dealer['company_name']) ?></p>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($dealerId): ?>
    <a href="?page=ledger" class="btn btn-ghost">← Tümü</a>
    <?php endif; ?>
    <!-- Tahsilat -->
    <button class="btn btn-primary" onclick="openModal('modal-tahsilat')"
            style="background:#16a34a;border-color:#16a34a">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Tahsilat
    </button>
    <!-- Tediye -->
    <button class="btn btn-primary" onclick="openModal('modal-tediye')"
            style="background:#dc2626;border-color:#dc2626">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Tediye
    </button>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success" style="margin-bottom:16px"><?= h($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"  style="margin-bottom:16px"><?= h($error) ?></div><?php endif; ?>

<?php if (!$dealerId): ?>
<!-- ══════════ GENEL CARİ LİSTESİ ══════════ -->

<!-- Özet Satırı -->
<div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap">
  <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 20px;flex:1;min-width:140px">
    <div style="font-size:11px;color:#b91c1c;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Toplam Borç</div>
    <div style="font-size:20px;font-weight:800;color:#dc2626;margin-top:2px"><?= money($genelBorc) ?></div>
  </div>
  <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 20px;flex:1;min-width:140px">
    <div style="font-size:11px;color:#15803d;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Toplam Alacak</div>
    <div style="font-size:20px;font-weight:800;color:#16a34a;margin-top:2px"><?= money($genelAlacak) ?></div>
  </div>
  <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 20px;flex:1;min-width:140px">
    <div style="font-size:11px;color:#1d4ed8;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Net Pozisyon</div>
    <div style="font-size:20px;font-weight:800;color:<?= ($genelBorc-$genelAlacak)>0?'#dc2626':'#16a34a' ?>;margin-top:2px"><?= money(abs($genelBorc-$genelAlacak)) ?></div>
  </div>
  <div style="background:#f4f5f7;border:1px solid #e4e6ea;border-radius:8px;padding:12px 20px;min-width:120px">
    <div style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Cari Sayısı</div>
    <div style="font-size:20px;font-weight:800;color:var(--text);margin-top:2px"><?= count($cariList) ?></div>
  </div>
</div>

<!-- Tablo -->
<div class="card" style="overflow:hidden">
<div class="table-wrap">
<table class="table" style="min-width:700px">
<thead>
  <tr style="background:#f4f5f7">
    <th style="width:90px;font-size:11px;letter-spacing:.4px">CARİ KODU</th>
    <th style="font-size:11px;letter-spacing:.4px">TİCARİ ÜNVANI</th>
    <th style="width:130px;text-align:right;font-size:11px;letter-spacing:.4px">BAKİYE</th>
    <th style="width:60px;text-align:center;font-size:11px;letter-spacing:.4px">B/A/S</th>
    <th style="width:110px;text-align:center;font-size:11px;letter-spacing:.4px">SON VADE</th>
    <th style="width:155px;text-align:right;font-size:11px;letter-spacing:.4px;padding-right:14px">İŞLEM</th>
  </tr>
</thead>
<tbody>
<?php foreach ($cariList as $row):
    $net    = (float)$row['net_bakiye'];
    $bas    = $net > 0.005 ? 'Borç' : ($net < -0.005 ? 'Alacak' : 'Sıfır');
    $rowBg  = $bas === 'Alacak' ? '#f0fdf4' : ($bas === 'Sıfır' ? '#fafafa' : '#fff');
    $valCol = $bas === 'Borç' ? '#dc2626' : ($bas === 'Alacak' ? '#16a34a' : '#9aa5b4');
    $badgeSt= $bas === 'Borç'
        ? 'background:#fef2f2;color:#dc2626;border:1px solid #fecaca'
        : ($bas === 'Alacak'
            ? 'background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0'
            : 'background:#f4f5f7;color:#9aa5b4;border:1px solid #e4e6ea');
    $vadeStr = $row['son_vade'] ? date('d.m.Y', strtotime($row['son_vade'])) : '—';
    $vadeColor = ($row['son_vade'] && $row['son_vade'] < date('Y-m-d') && $bas==='Borç') ? '#dc2626' : 'var(--text-2)';
?>
<tr style="background:<?= $rowBg ?>;border-bottom:1px solid #e4e6ea" class="hover-row">
  <td style="padding:6px 12px">
    <!-- Inline cari kodu düzenleme -->
    <form method="post" style="margin:0;display:inline-flex;align-items:center;gap:4px" onclick="event.stopPropagation()">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="update_dealer_code">
      <input type="hidden" name="dealer_id" value="<?= (int)$row['id'] ?>">
      <input type="text" name="dealer_code"
             value="<?= h($row['dealer_code'] ?? '') ?>"
             placeholder="Kod yok"
             maxlength="50"
             onfocus="this.dataset.orig=this.value"
             onblur="if(this.value!==this.dataset.orig)this.form.submit()"
             onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur()}else if(event.key==='Escape'){this.value=this.dataset.orig||''}"
             style="width:90px;padding:5px 8px;font-family:ui-monospace,monospace;font-size:12px;border:1px solid transparent;background:transparent;color:var(--text-2);border-radius:4px"
             onmouseover="this.style.background='#fff';this.style.borderColor='#e4e6ea'"
             onmouseout="if(document.activeElement!==this){this.style.background='transparent';this.style.borderColor='transparent'}"
             onfocus="this.style.background='#fff';this.style.borderColor='var(--red)'">
    </form>
  </td>
  <td style="font-weight:500;font-size:13px;padding:9px 12px;cursor:pointer" onclick="location='?page=ledger&dealer_id=<?= $row['id'] ?>'">
    <a href="?page=ledger&dealer_id=<?= $row['id'] ?>" style="color:var(--text);text-decoration:none"><?= h($row['company_name']) ?></a>
  </td>
  <td style="text-align:right;font-weight:700;font-size:13px;color:<?= $valCol ?>;padding:9px 12px;cursor:pointer" onclick="location='?page=ledger&dealer_id=<?= $row['id'] ?>'"><?= number_format(abs($net),4,',','.') ?></td>
  <td style="text-align:center;padding:9px 8px;cursor:pointer" onclick="location='?page=ledger&dealer_id=<?= $row['id'] ?>'">
    <span style="<?= $badgeSt ?>;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700"><?= $bas ?></span>
  </td>
  <td style="text-align:center;font-size:12px;color:<?= $vadeColor ?>;font-weight:<?= $vadeColor==='#dc2626'?'700':'400' ?>;padding:9px 8px;cursor:pointer" onclick="location='?page=ledger&dealer_id=<?= $row['id'] ?>'"><?= $vadeStr ?></td>
  <td style="padding:9px 8px;text-align:right;white-space:nowrap">
    <a href="?page=dealers&action=detail&id=<?= $row['id'] ?>"
       title="Bayi düzenle"
       style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;background:#f4f5f7;border:1px solid #e4e6ea;border-radius:6px;color:var(--text-2);text-decoration:none;font-size:11px;font-weight:600"
       onmouseover="this.style.background='#fff';this.style.borderColor='var(--red)';this.style.color='var(--red)'"
       onmouseout="this.style.background='#f4f5f7';this.style.borderColor='#e4e6ea';this.style.color='var(--text-2)'">
      ✏️ Düzenle
    </a>
    <a href="?page=ledger&dealer_id=<?= $row['id'] ?>"
       title="Cari detay"
       style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;background:#f4f5f7;border:1px solid #e4e6ea;border-radius:6px;color:var(--text-2);text-decoration:none;font-size:14px;margin-left:4px"
       onmouseover="this.style.background='#fff';this.style.borderColor='var(--red)';this.style.color='var(--red)'"
       onmouseout="this.style.background='#f4f5f7';this.style.borderColor='#e4e6ea';this.style.color='var(--text-2)'">›</a>
  </td>
</tr>
<?php endforeach; ?>
<?php if (empty($cariList)): ?>
<tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted)">Kayıt bulunamadı.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<?php else: ?>
<!-- ══════════ BAYİ CARİ DETAY ══════════ -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px">
  <?php $cl = (float)($dealer['credit_limit']??0); $pct = $cl>0?min(100,round($balance/$cl*100)):0; ?>
  <div style="background:<?= $balance>0?'#fef2f2':'#f0fdf4' ?>;border:1px solid <?= $balance>0?'#fecaca':'#bbf7d0' ?>;border-radius:8px;padding:16px">
    <div style="font-size:11px;color:var(--text-muted);font-weight:600;margin-bottom:4px">AÇIK BAKİYE</div>
    <div style="font-size:22px;font-weight:800;color:<?= $balance>0?'#dc2626':'#16a34a' ?>"><?= money(abs($balance)) ?></div>
    <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= $balance>0?'Borçlu':($balance<0?'Alacaklı':'Sıfır') ?></div>
  </div>
  <div style="background:<?= $overdue>0?'#fffbeb':'#f4f5f7' ?>;border:1px solid <?= $overdue>0?'#fed7aa':'#e4e6ea' ?>;border-radius:8px;padding:16px">
    <div style="font-size:11px;color:var(--text-muted);font-weight:600;margin-bottom:4px">VADESİ GEÇEN</div>
    <div style="font-size:22px;font-weight:800;color:<?= $overdue>0?'#d97706':'#9aa5b4' ?>"><?= money($overdue) ?></div>
  </div>
  <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:16px">
    <div style="font-size:11px;color:var(--text-muted);font-weight:600;margin-bottom:4px">KREDİ LİMİTİ</div>
    <div style="font-size:22px;font-weight:800;color:var(--text)"><?= money($dealer['credit_limit']??0) ?></div>
    <?php if ($cl>0): ?>
    <div style="margin-top:6px;background:#e4e6ea;border-radius:4px;height:5px;overflow:hidden">
      <div style="height:5px;width:<?= $pct ?>%;background:<?= $pct>80?'#dc2626':($pct>60?'#f59e0b':'#16a34a') ?>;border-radius:4px"></div>
    </div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:3px">%<?= $pct ?> kullanıldı · <?= $dealer['payment_term_days']??0 ?> gün vade</div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3 class="card-title">Cari Hareketler</h3>
    <div style="display:flex;gap:8px">
      <button onclick="window.print()" class="btn btn-secondary btn-sm" title="Yazdır">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Yazdır
      </button>
      <a href="?page=ledger&dealer_id=<?= $dealerId ?>&export=pdf" class="btn btn-secondary btn-sm" title="PDF İndir">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        PDF
      </a>
    </div>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Tarih</th>
          <th>Açıklama</th>
          <th style="text-align:right;color:#dc2626">Borç</th>
          <th style="text-align:right;color:#16a34a">Alacak</th>
          <th>Vade</th>
          <th style="text-align:center">Durum</th>
          <th style="width:40px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($entries as $e):
          $isOverdue = $e['due_date'] && $e['due_date'] < date('Y-m-d') && !$e['is_closed'];
      ?>
      <tr style="<?= $isOverdue?'background:#fffbeb':($e['is_closed']?'opacity:.6':'') ?>">
        <td style="font-size:12px;color:var(--text-muted)"><?= fmtDate($e['created_at']) ?></td>
        <td style="font-size:13px"><?= h($e['description']) ?></td>
        <td style="text-align:right;font-weight:600;color:#dc2626"><?= $e['type']==='borc'?number_format($e['amount'],4,',','.'):'' ?></td>
        <td style="text-align:right;font-weight:600;color:#16a34a"><?= $e['type']==='alacak'?number_format($e['amount'],4,',','.'):'' ?></td>
        <td style="font-size:12px;color:<?= $isOverdue?'#dc2626':'var(--text-2)' ?>;font-weight:<?= $isOverdue?'700':'400' ?>"><?= $e['due_date']?date('d.m.Y',strtotime($e['due_date'])):'—' ?></td>
        <td style="text-align:center">
          <?php if ($e['is_closed']): ?>
          <span style="font-size:11px;background:#f4f5f7;color:#9aa5b4;border:1px solid #e4e6ea;border-radius:4px;padding:2px 7px">Kapalı</span>
          <?php elseif ($isOverdue): ?>
          <span style="font-size:11px;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:4px;padding:2px 7px">Vadesi Geçti</span>
          <?php else: ?>
          <span style="font-size:11px;background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;border-radius:4px;padding:2px 7px">Açık</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!$e['is_closed']): ?>
          <form method="post" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="close_entry">
            <input type="hidden" name="entry_id" value="<?= $e['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" title="Kapat" style="padding:3px 8px">✓</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($entries)): ?>
      <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted)">Hareket yok.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if (!empty($pager)): ?><div style="margin-top:16px"><?= $pager ?></div><?php endif; ?>

<?php endif; ?>

<!-- ══ MODAL: TAHSİLAT ══ -->
<div id="modal-tahsilat" class="modal-overlay">
<div class="modal">
  <div class="modal-header" style="color:#16a34a">+ Tahsilat Ekle</div>
  <div class="modal-body">
    <form method="post" id="form-tahsilat">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="tahsilat">
      <div class="form-group">
        <label class="form-label">Bayi *</label>
        <select name="dealer_id" class="form-control" required>
          <option value="">— Seçiniz —</option>
          <?php foreach ($dealers as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $dealerId==$d['id']?'selected':'' ?>><?= h($d['dealer_code']?'['.$d['dealer_code'].'] ':'') ?><?= h($d['company_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Tutar (₺) *</label>
        <input type="number" step="0.01" name="amount" class="form-control" required placeholder="0,00">
      </div>
      <div class="form-group">
        <label class="form-label">Açıklama</label>
        <input type="text" name="description" class="form-control" value="Tahsilat" placeholder="Ödeme açıklaması">
      </div>
      <div class="form-group">
        <label class="form-label">Vade Tarihi</label>
        <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d') ?>">
      </div>
    </form>
  </div>
  <div class="modal-footer">
    <button class="btn btn-ghost" onclick="closeModal('modal-tahsilat')">İptal</button>
    <button class="btn btn-primary" form="form-tahsilat" type="submit" style="background:#16a34a;border-color:#16a34a">Tahsilat Kaydet</button>
  </div>
</div>
</div>

<!-- ══ MODAL: TEDİYE ══ -->
<div id="modal-tediye" class="modal-overlay">
<div class="modal">
  <div class="modal-header" style="color:#dc2626">− Tediye Ekle</div>
  <div class="modal-body">
    <form method="post" id="form-tediye">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="tediye">
      <div class="form-group">
        <label class="form-label">Bayi *</label>
        <select name="dealer_id" class="form-control" required>
          <option value="">— Seçiniz —</option>
          <?php foreach ($dealers as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $dealerId==$d['id']?'selected':'' ?>><?= h($d['dealer_code']?'['.$d['dealer_code'].'] ':'') ?><?= h($d['company_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Tutar (₺) *</label>
        <input type="number" step="0.01" name="amount" class="form-control" required placeholder="0,00">
      </div>
      <div class="form-group">
        <label class="form-label">Açıklama</label>
        <input type="text" name="description" class="form-control" value="Tediye" placeholder="Ödeme açıklaması">
      </div>
      <div class="form-group">
        <label class="form-label">Vade Tarihi</label>
        <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d') ?>">
      </div>
    </form>
  </div>
  <div class="modal-footer">
    <button class="btn btn-ghost" onclick="closeModal('modal-tediye')">İptal</button>
    <button class="btn btn-primary" form="form-tediye" type="submit" style="background:#dc2626;border-color:#dc2626">Tediye Kaydet</button>
  </div>
</div>
</div>

<style>
.hover-row { cursor:pointer; transition:filter .1s; }
.hover-row:hover { filter:brightness(.97); }
</style>

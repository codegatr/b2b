<?php
/**
 * Admin — Ciro Primi (Aylık Komisyon) Yönetimi
 *
 * Akış:
 *   1. Ay/Yıl seç → "Hesapla" butonu → tüm bayiler için taslak primler oluşturulur
 *   2. Liste görüntülenir (bayi · alış toplamı · oran · prim tutarı · durum)
 *   3. Tek tek veya toplu "Yansıt" → b2b_ledger'a alacak kaydı eklenir
 *   4. PDF / yazıcı çıktısı alınabilir
 */
requireAdmin();

$msg = '';
$success = $_SESSION['flash']['msg'] ?? null;
$flashType = $_SESSION['flash']['type'] ?? 'success';
if (isset($_SESSION['flash'])) unset($_SESSION['flash']);

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? max(1, (int)date('n') - 1)); // default: önceki ay
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }
$dealerFilter = (int)($_GET['dealer_id'] ?? 0);

$monthNames = [
    1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',
    7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'
];

// ─── POST: Hesapla / Yansıt / İptal / Sil ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';

    // 1) Hesapla — seçili ay için TÜM bayilerin primlerini taslak olarak yaz
    if ($act === 'calculate') {
        $py = (int)$_POST['year'];
        $pm = (int)$_POST['month'];
        $startDate = sprintf('%04d-%02d-01', $py, $pm);
        $endDate   = date('Y-m-d', strtotime("$startDate +1 month"));

        // Ciro primi tanımlı (oran > 0) bayileri al
        $dealers = dbRows(
            "SELECT id, company_name, commission_rate, commission_min_amount
               FROM b2b_dealers
              WHERE is_active=1 AND commission_rate > 0
              ORDER BY company_name"
        );

        $created = 0; $skipped = 0; $existing = 0;
        foreach ($dealers as $d) {
            // O ay teslim edilmiş veya kargoda/hazırlanan tüm siparişlerin toplam tutarı
            // (Sayım için sipariş bazında, iade hariç)
            $stat = dbRow(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS total
                   FROM b2b_orders
                  WHERE dealer_id=?
                    AND created_at >= ? AND created_at < ?
                    AND status NOT IN ('iptal','iade')",
                [$d['id'], $startDate, $endDate]
            );
            $total = (float)$stat['total'];
            $rate  = (float)$d['commission_rate'];
            $minA  = (float)$d['commission_min_amount'];

            // Min eşik altındaysa atla
            if ($total < $minA && $minA > 0) {
                $skipped++;
                continue;
            }
            if ($total <= 0) {
                $skipped++;
                continue;
            }

            // Mevcut kayıt var mı?
            $existRow = dbRow(
                "SELECT id, status FROM b2b_dealer_commissions
                  WHERE dealer_id=? AND period_year=? AND period_month=?",
                [$d['id'], $py, $pm]
            );

            $commAmount = round($total * $rate / 100, 2);

            if ($existRow) {
                // Yansıtılmamış (taslak) ise güncelle, yansıtılmışsa dokunma
                if ($existRow['status'] === 'taslak') {
                    dbExec(
                        "UPDATE b2b_dealer_commissions
                            SET total_purchases=?, order_count=?, commission_rate=?,
                                min_amount=?, commission_amount=?,
                                calculated_at=NOW(), calculated_by=?
                          WHERE id=?",
                        [$total, (int)$stat['cnt'], $rate, $minA, $commAmount, adminId(), $existRow['id']]
                    );
                    $existing++;
                } else {
                    $skipped++;
                }
            } else {
                dbInsertRow('b2b_dealer_commissions', [
                    'dealer_id'         => $d['id'],
                    'period_year'       => $py,
                    'period_month'      => $pm,
                    'total_purchases'   => $total,
                    'order_count'       => (int)$stat['cnt'],
                    'commission_rate'   => $rate,
                    'min_amount'        => $minA,
                    'commission_amount' => $commAmount,
                    'status'            => 'taslak',
                    'calculated_at'     => date('Y-m-d H:i:s'),
                    'calculated_by'     => adminId(),
                ]);
                $created++;
            }
        }

        auditLog('commission_calculated', 'b2b_dealer_commissions', 0, [
            'year' => $py, 'month' => $pm, 'created' => $created, 'updated' => $existing, 'skipped' => $skipped
        ]);

        $_SESSION['flash'] = [
            'type' => 'success',
            'msg'  => "Ciro primleri hesaplandı: {$created} yeni · {$existing} güncellendi · {$skipped} atlandı (eşik altı veya yansıtılmış)."
        ];
        redirect("?page=commissions&year=$py&month=$pm");
    }

    // 2) Yansıt — taslak primi cari hesaba ekle (ledger)
    if ($act === 'apply') {
        $cid = (int)$_POST['comm_id'];
        $c = dbRow("SELECT * FROM b2b_dealer_commissions WHERE id=?", [$cid]);
        if ($c && $c['status'] === 'taslak') {
            $mName = $monthNames[(int)$c['period_month']] ?? '?';
            $desc = "{$c['period_year']} {$mName} Ciro Primi (%{$c['commission_rate']}) — alış: " . number_format((float)$c['total_purchases'], 2, ',', '.') . " ₺";

            // Bayiye alacak (admin perspektifinde bayinin lehine)
            $ledgerId = ledgerAdd(
                (int)$c['dealer_id'],
                'alacak',
                (float)$c['commission_amount'],
                $desc,
                'commission',
                $cid
            );

            dbExec(
                "UPDATE b2b_dealer_commissions
                    SET status='yansitildi', applied_at=NOW(), applied_by=?, ledger_id=?
                  WHERE id=?",
                [adminId(), $ledgerId ?: null, $cid]
            );
            auditLog('commission_applied', 'b2b_dealer_commissions', $cid, ['ledger' => $ledgerId]);
            $_SESSION['flash'] = ['type'=>'success', 'msg'=>'Ciro primi cari hesaba yansıtıldı: ' . number_format((float)$c['commission_amount'], 2, ',', '.') . ' ₺'];
        }
        redirect("?page=commissions&year=$year&month=$month");
    }

    // 3) Toplu yansıt
    if ($act === 'apply_all') {
        $py = (int)$_POST['year'];
        $pm = (int)$_POST['month'];
        $list = dbRows(
            "SELECT * FROM b2b_dealer_commissions
              WHERE period_year=? AND period_month=? AND status='taslak'",
            [$py, $pm]
        );
        $applied = 0;
        foreach ($list as $c) {
            $mName = $monthNames[(int)$c['period_month']] ?? '?';
            $desc = "{$c['period_year']} {$mName} Ciro Primi (%{$c['commission_rate']}) — alış: " . number_format((float)$c['total_purchases'], 2, ',', '.') . " ₺";
            $ledgerId = ledgerAdd(
                (int)$c['dealer_id'], 'alacak', (float)$c['commission_amount'],
                $desc, 'commission', (int)$c['id']
            );
            dbExec(
                "UPDATE b2b_dealer_commissions
                    SET status='yansitildi', applied_at=NOW(), applied_by=?, ledger_id=?
                  WHERE id=?",
                [adminId(), $ledgerId ?: null, $c['id']]
            );
            $applied++;
        }
        auditLog('commission_apply_all', 'b2b_dealer_commissions', 0, ['year'=>$py,'month'=>$pm,'applied'=>$applied]);
        $_SESSION['flash'] = ['type'=>'success', 'msg'=>"$applied bayinin primi toplu olarak yansıtıldı."];
        redirect("?page=commissions&year=$py&month=$pm");
    }

    // 4) İptal / Sil
    if ($act === 'cancel') {
        $cid = (int)$_POST['comm_id'];
        $c = dbRow("SELECT * FROM b2b_dealer_commissions WHERE id=?", [$cid]);
        if ($c) {
            if ($c['status'] === 'yansitildi' && !empty($c['ledger_id'])) {
                // Ledger kaydını da geri al (ters hareket)
                $mName = $monthNames[(int)$c['period_month']] ?? '?';
                ledgerAdd((int)$c['dealer_id'], 'borc', (float)$c['commission_amount'],
                    "İPTAL: {$c['period_year']} {$mName} Ciro Primi düzeltmesi",
                    'commission_cancel', $cid);
            }
            dbExec("UPDATE b2b_dealer_commissions SET status='iptal' WHERE id=?", [$cid]);
            auditLog('commission_cancelled', 'b2b_dealer_commissions', $cid, []);
            $_SESSION['flash'] = ['type'=>'success', 'msg'=>'Ciro primi iptal edildi.'];
        }
        redirect("?page=commissions&year=$year&month=$month");
    }
}

// ─── Veri ───
$where = ['c.period_year=?', 'c.period_month=?'];
$params = [$year, $month];
if ($dealerFilter > 0) {
    $where[] = 'c.dealer_id=?';
    $params[] = $dealerFilter;
}
$w = implode(' AND ', $where);

$commissions = dbRows(
    "SELECT c.*, d.company_name, d.first_name, d.last_name
       FROM b2b_dealer_commissions c
       LEFT JOIN b2b_dealers d ON d.id=c.dealer_id
      WHERE $w
      ORDER BY c.commission_amount DESC, d.company_name",
    $params
);

// İstatistik
$stats = dbRow(
    "SELECT
       COUNT(*) AS total,
       SUM(CASE WHEN status='taslak' THEN 1 ELSE 0 END) AS draft,
       SUM(CASE WHEN status='yansitildi' THEN 1 ELSE 0 END) AS applied,
       SUM(CASE WHEN status='iptal' THEN 1 ELSE 0 END) AS cancelled,
       COALESCE(SUM(CASE WHEN status='taslak' THEN commission_amount ELSE 0 END),0) AS draft_amount,
       COALESCE(SUM(CASE WHEN status='yansitildi' THEN commission_amount ELSE 0 END),0) AS applied_amount,
       COALESCE(SUM(total_purchases),0) AS total_purchases
     FROM b2b_dealer_commissions c
     WHERE $w",
    $params
);

// Tüm bayiler (filter dropdown için)
$allDealers = dbRows("SELECT id, company_name FROM b2b_dealers WHERE is_active=1 ORDER BY company_name");

// PDF / Yazdırma modu?
$printMode = isset($_GET['print']) && $_GET['print'] === '1';
?>

<?php if ($printMode): ?>
<!-- ───────── YAZICI ÇIKTI MODU ───────── -->
<style>
  @media print {
    .no-print, .sidebar, .topbar, .nav-section, .mobile-bottom-nav { display:none !important; }
    body { background:#fff; margin:0; padding:0 }
    .main-content { margin:0; padding:0 }
    .print-page { padding:20mm }
  }
  .print-page { padding:30px; max-width:900px; margin:0 auto; background:#fff; color:#000; font-family:Arial, sans-serif }
  .print-header { text-align:center; border-bottom:2px solid #000; padding-bottom:10px; margin-bottom:20px }
  .print-header h1 { margin:0; font-size:22px }
  .print-meta { display:flex; justify-content:space-between; margin-bottom:15px; font-size:12px; color:#444 }
  table.print-table { width:100%; border-collapse:collapse; font-size:12px }
  table.print-table th { background:#f3f4f6; padding:8px; text-align:left; border:1px solid #ccc; font-size:11px }
  table.print-table td { padding:8px; border:1px solid #ccc }
  table.print-table .num { text-align:right; font-family:monospace }
  .totals-row { font-weight:700; background:#fef3c7 }
  .print-footer { margin-top:30px; padding-top:15px; border-top:1px solid #ccc; font-size:11px; color:#666; display:flex; justify-content:space-between }
</style>
<div class="print-page">
  <div class="no-print" style="margin-bottom:20px;display:flex;gap:10px;justify-content:flex-end">
    <button onclick="window.print()" class="btn btn-primary">🖨 Yazdır</button>
    <a href="?page=commissions&year=<?= $year ?>&month=<?= $month ?>" class="btn btn-ghost">← Geri Dön</a>
  </div>
  <div class="print-header">
    <h1><?= h(setting('site_name', 'Le Monde Du Tacos B2B')) ?></h1>
    <div style="font-size:14px;font-weight:600;margin-top:6px">Ciro Primi Raporu — <?= $monthNames[$month] ?> <?= $year ?></div>
  </div>
  <div class="print-meta">
    <div>Rapor Tarihi: <strong><?= date('d.m.Y H:i') ?></strong></div>
    <div>Toplam Kayıt: <strong><?= (int)$stats['total'] ?></strong></div>
  </div>
  <table class="print-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Bayi</th>
        <th class="num">Sipariş Adedi</th>
        <th class="num">Toplam Alış (₺)</th>
        <th class="num">Oran</th>
        <th class="num">Prim Tutarı (₺)</th>
        <th>Durum</th>
      </tr>
    </thead>
    <tbody>
      <?php $i=1; foreach ($commissions as $c): ?>
        <?php if ($c['status']==='iptal') continue; ?>
        <?php $bn = trim($c['company_name'] ?: ($c['first_name'].' '.$c['last_name'])); ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><?= h($bn) ?></td>
          <td class="num"><?= (int)$c['order_count'] ?></td>
          <td class="num"><?= number_format((float)$c['total_purchases'], 2, ',', '.') ?></td>
          <td class="num">%<?= number_format((float)$c['commission_rate'], 2, ',', '.') ?></td>
          <td class="num"><strong><?= number_format((float)$c['commission_amount'], 2, ',', '.') ?></strong></td>
          <td><?= $c['status']==='yansitildi' ? '✓ Yansıtıldı' : 'Taslak' ?></td>
        </tr>
      <?php endforeach; ?>
      <tr class="totals-row">
        <td colspan="3" style="text-align:right">TOPLAM</td>
        <td class="num"><?= number_format((float)$stats['total_purchases'], 2, ',', '.') ?></td>
        <td></td>
        <td class="num"><?= number_format((float)$stats['draft_amount'] + (float)$stats['applied_amount'], 2, ',', '.') ?></td>
        <td></td>
      </tr>
    </tbody>
  </table>
  <div class="print-footer">
    <div>Bu rapor otomatik olarak oluşturulmuştur.</div>
    <div>Sayfa 1/1</div>
  </div>
</div>
<script>
  // Otomatik yazdır
  // setTimeout(() => window.print(), 500);
</script>
<?php return; endif; ?>

<!-- ───────── NORMAL EKRAN ───────── -->
<div class="page-header">
  <div>
    <h1 class="page-title">💰 Ciro Primi Yönetimi</h1>
    <p class="page-sub">Aylık alış tutarı üzerinden bayi komisyonu hesapla ve cari hesaba yansıt</p>
  </div>
</div>

<?php if (!empty($success)): ?>
<div class="alert alert-<?= $flashType ?>"><?= $success ?></div>
<?php endif; ?>

<!-- Dönem Seçici -->
<div class="card" style="margin-bottom:14px">
  <div class="card-body" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="page" value="commissions">
      <label style="font-size:12px;color:var(--text-muted);margin:0">Dönem:</label>
      <select name="month" class="form-control" style="width:auto;font-size:13px;padding:6px 30px 6px 10px">
        <?php foreach ($monthNames as $mn => $ml): ?>
          <option value="<?= $mn ?>" <?= $mn === $month ? 'selected' : '' ?>><?= h($ml) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="year" class="form-control" style="width:auto;font-size:13px;padding:6px 30px 6px 10px">
        <?php for ($y = (int)date('Y')+1; $y >= (int)date('Y')-3; $y--): ?>
          <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
      <select name="dealer_id" class="form-control" style="width:auto;font-size:13px;padding:6px 30px 6px 10px">
        <option value="0">— Tüm Bayiler —</option>
        <?php foreach ($allDealers as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $d['id'] == $dealerFilter ? 'selected' : '' ?>><?= h($d['company_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm" style="height:36px">Filtrele</button>
    </form>

    <div style="flex:1"></div>

    <!-- Hesapla butonu -->
    <form method="post" onsubmit="return confirm('<?= $monthNames[$month] ?> <?= $year ?> dönemi için tüm bayilerin ciro primleri taslak olarak yeniden hesaplanacak. Devam edilsin mi?\\n\\nNot: Yansıtılmış primler değişmez.');">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="calculate">
      <input type="hidden" name="year" value="<?= $year ?>">
      <input type="hidden" name="month" value="<?= $month ?>">
      <button type="submit" class="btn btn-primary" style="background:#f59e0b;border-color:#f59e0b">🧮 Hesapla / Yeniden Hesapla</button>
    </form>

    <?php if ($stats['draft'] > 0): ?>
    <form method="post" onsubmit="return confirm('<?= $stats['draft'] ?> taslak prim cari hesaplara YANSITILACAK. Geri almak için her birini ayrıca iptal etmeniz gerekir. Devam?');">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="apply_all">
      <input type="hidden" name="year" value="<?= $year ?>">
      <input type="hidden" name="month" value="<?= $month ?>">
      <button type="submit" class="btn btn-success">✓ Tümünü Yansıt (<?= $stats['draft'] ?>)</button>
    </form>
    <?php endif; ?>

    <a href="?page=commissions&year=<?= $year ?>&month=<?= $month ?>&print=1" target="_blank" class="btn btn-ghost">🖨 PDF / Yazdır</a>
  </div>
</div>

<!-- İstatistik kartları -->
<div class="stats-grid" style="margin-bottom:14px">
  <div class="stat-card">
    <div class="stat-icon blue">📊</div>
    <div class="stat-info">
      <div class="stat-value"><?= (int)$stats['total'] ?></div>
      <div class="stat-label">Toplam Kayıt</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon amber">📝</div>
    <div class="stat-info">
      <div class="stat-value"><?= (int)$stats['draft'] ?></div>
      <div class="stat-label">Taslak (yansıtılmadı)</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">✓</div>
    <div class="stat-info">
      <div class="stat-value"><?= (int)$stats['applied'] ?></div>
      <div class="stat-label">Yansıtılmış</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple">💰</div>
    <div class="stat-info">
      <div class="stat-value" style="font-size:16px"><?= number_format((float)$stats['draft_amount'] + (float)$stats['applied_amount'], 2, ',', '.') ?> ₺</div>
      <div class="stat-label">Toplam Prim Tutarı</div>
    </div>
  </div>
</div>

<!-- Liste -->
<div class="card">
  <div class="card-header">
    <h3 class="card-title"><?= $monthNames[$month] ?> <?= $year ?> · <?= count($commissions) ?> kayıt</h3>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Bayi</th>
          <th style="text-align:right">Sipariş</th>
          <th style="text-align:right">Toplam Alış</th>
          <th style="text-align:right">Oran</th>
          <th style="text-align:right">Prim Tutarı</th>
          <th>Durum</th>
          <th>İşlem</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($commissions as $c):
          $bn = trim($c['company_name'] ?: ($c['first_name'].' '.$c['last_name']));
          $rowStyle = match($c['status']) {
              'yansitildi' => 'background:#f0fdf4',
              'iptal'      => 'background:#fef2f2;opacity:.6',
              default      => '',
          };
      ?>
        <tr style="<?= $rowStyle ?>">
          <td>
            <div class="fw-600"><?= h($bn) ?></div>
            <div style="font-size:10px;color:var(--text-muted)">
              Hesaplandı: <?= date('d.m.Y H:i', strtotime($c['calculated_at'])) ?>
            </div>
          </td>
          <td style="text-align:right;font-size:13px"><?= (int)$c['order_count'] ?></td>
          <td style="text-align:right;font-weight:600"><?= number_format((float)$c['total_purchases'], 2, ',', '.') ?> ₺</td>
          <td style="text-align:right;font-size:13px">%<?= number_format((float)$c['commission_rate'], 2, ',', '.') ?></td>
          <td style="text-align:right;font-weight:700;color:#15803d;font-size:14px"><?= number_format((float)$c['commission_amount'], 2, ',', '.') ?> ₺</td>
          <td>
            <?php if ($c['status'] === 'taslak'): ?>
              <span class="badge" style="background:#fef3c7;color:#92400e;font-size:11px">📝 Taslak</span>
            <?php elseif ($c['status'] === 'yansitildi'): ?>
              <span class="badge" style="background:#dcfce7;color:#15803d;font-size:11px">✓ Yansıtıldı</span>
              <?php if ($c['applied_at']): ?>
              <div style="font-size:10px;color:var(--text-muted);margin-top:2px"><?= date('d.m.Y', strtotime($c['applied_at'])) ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span class="badge" style="background:#fee2e2;color:#b91c1c;font-size:11px">İptal</span>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap">
            <?php if ($c['status'] === 'taslak'): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Bu prim cari hesaba yansıtılsın mı?\\n\\n<?= addslashes($bn) ?> — <?= number_format((float)$c['commission_amount'], 2, ',', '.') ?> ₺');">
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="apply">
                <input type="hidden" name="comm_id" value="<?= (int)$c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-success">✓ Yansıt</button>
              </form>
            <?php elseif ($c['status'] === 'yansitildi'): ?>
              <?php
                // Bu prim için zaten manuel fatura kaydedildi mi?
                $linkedInvoice = null;
                try {
                  $linkedInvoice = dbRow(
                    "SELECT id, invoice_no, amount_gross, status FROM b2b_manual_invoices
                     WHERE related_commission_id=? AND status!='iptal' LIMIT 1",
                    [(int)$c['id']]
                  );
                } catch (\Throwable $e) {}
              ?>
              <?php if ($linkedInvoice): ?>
                <a href="?page=manual-invoices&action=detail&id=<?= (int)$linkedInvoice['id'] ?>"
                   class="btn btn-sm" style="background:#dcfce7;color:#15803d;border:1px solid #86efac"
                   title="Bayinin kestiği fatura kaydı">
                  📥 Fatura: <?= h($linkedInvoice['invoice_no'] ?: '#'.$linkedInvoice['id']) ?>
                </a>
              <?php else: ?>
                <a href="?page=manual-invoices&action=new&direction=ALIS&dealer_id=<?= (int)$c['dealer_id'] ?>&commission_id=<?= (int)$c['id'] ?>"
                   class="btn btn-sm" style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d"
                   title="Bayi bu prim için fatura kestiğinde tıkla">
                  📥 Bayi Fatura Kesti
                </a>
              <?php endif; ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Yansıtılan prim iptal edilecek ve cari hesaba TERS hareket eklenecek. Devam?');">
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="cancel">
                <input type="hidden" name="comm_id" value="<?= (int)$c['id'] ?>">
                <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5">✕ İptal</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($commissions)): ?>
        <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted)">
          Bu dönem için kayıt yok. <strong>"🧮 Hesapla"</strong> butonuna basarak başlatın.
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
requireAdmin();

$report = $_GET['report'] ?? 'sales';
$from   = $_GET['from'] ?? date('Y-m-01');
$to     = $_GET['to']   ?? date('Y-m-d');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Raporlar</h1>
        <p class="page-sub"><?= date('d.m.Y', strtotime($from)) ?> – <?= date('d.m.Y', strtotime($to)) ?></p>
    </div>
    <button class="btn btn-secondary" onclick="window.print()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Yazdır
    </button>
</div>

<!-- Filtre -->
<div class="card" style="padding:1.25rem;margin-bottom:1.5rem">
    <form method="GET" style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="page" value="reports">
        <div class="form-group" style="margin:0;flex:1;min-width:140px">
            <label class="form-label">Başlangıç</label>
            <input type="date" name="from" class="form-control" value="<?= $from ?>">
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:140px">
            <label class="form-label">Bitiş</label>
            <input type="date" name="to" class="form-control" value="<?= $to ?>">
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:140px">
            <label class="form-label">Rapor</label>
            <select name="report" class="form-control">
                <option value="sales"   <?= $report==='sales'   ? 'selected':'' ?>>Satış Raporu</option>
                <option value="dealers" <?= $report==='dealers' ? 'selected':'' ?>>Bayi Raporu</option>
                <option value="stock"   <?= $report==='stock'   ? 'selected':'' ?>>Stok Raporu</option>
                <option value="ledger"  <?= $report==='ledger'  ? 'selected':'' ?>>Cari Raporu</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filtrele</button>
        <a href="?page=reports&report=<?= $report ?>&from=<?= $from ?>&to=<?= $to ?>&export=csv" class="btn btn-secondary">CSV İndir</a>
    </form>
</div>

<!-- Hızlı Özet Kartları -->
<?php
$s = dbRow("SELECT COUNT(*) as order_count,
        COALESCE(SUM(grand_total),0) as total_sales,
        COALESCE(AVG(grand_total),0) as avg_order,
        COUNT(DISTINCT dealer_id) as dealer_count
    FROM b2b_orders
    WHERE DATE(created_at) BETWEEN ? AND ?
      AND status NOT IN ('iptal','iade')", [$from, $to]);
?>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem">
    <div class="stat-card">
        <div class="stat-label">Sipariş Sayısı</div>
        <div class="stat-value"><?= number_format($s['order_count']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Toplam Satış</div>
        <div class="stat-value"><?= fmtPrice($s['total_sales']) ?> ₺</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Ortalama Sipariş</div>
        <div class="stat-value"><?= fmtPrice($s['avg_order']) ?> ₺</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Aktif Bayi</div>
        <div class="stat-value"><?= $s['dealer_count'] ?></div>
    </div>
</div>

<?php
// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rapor-'.$report.'-'.$from.'-'.$to.'.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    $out = fopen('php://output', 'w');
}

if ($report === 'sales'): ?>
<!-- Satış Raporu -->
<?php
$rows = dbRows("SELECT o.id, o.order_no, o.created_at, d.company_name, o.grand_total, o.status, o.payment_status, COUNT(oi.id) as item_count FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id LEFT JOIN b2b_order_items oi ON oi.order_id=o.id WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status NOT IN ('iptal','iade') GROUP BY o.id ORDER BY o.created_at DESC", [$from, $to]);
if (isset($out)) {
    fputcsv($out, ['Sipariş No','Tarih','Bayi','Tutar','Durum','Ödeme']);
    foreach ($rows as $r) fputcsv($out, [$r['order_no'], $r['created_at'], $r['company_name'], $r['grand_total'], $r['status'], $r['payment_status']]);
    fclose($out); exit;
}
?>
<div class="card">
    <div class="card-header"><h3 class="card-title">Satış Raporu</h3></div>
    <table class="table">
        <thead><tr><th>Sipariş No</th><th>Tarih</th><th>Bayi</th><th>Kalem</th><th>Tutar</th><th>Durum</th><th>Ödeme</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><a href="?page=orders&action=detail&id=<?= (int)$r['id'] ?>" style="color:var(--primary)"><?= htmlspecialchars($r['order_no']) ?></a></td>
            <td><?= fmtDate($r['created_at']) ?></td>
            <td><?= htmlspecialchars($r['company_name']) ?></td>
            <td><?= $r['item_count'] ?></td>
            <td><strong><?= fmtPrice($r['grand_total']) ?> ₺</strong></td>
            <td><?= $r['status'] ?></td>
            <td><?= $r['payment_status'] ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:2rem">Bu dönemde sipariş bulunamadı.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php elseif ($report === 'dealers'): ?>
<!-- Bayi Raporu -->
<?php
$rows = dbRows(
    "SELECT d.company_name, d.city,
            COALESCE(o.order_count,0) AS order_count,
            COALESCE(o.total,0) AS total,
            COALESCE(l.balance,0) AS balance
     FROM b2b_dealers d
     LEFT JOIN (
        SELECT dealer_id, COUNT(*) AS order_count, COALESCE(SUM(grand_total),0) AS total
        FROM b2b_orders
        WHERE DATE(created_at) BETWEEN ? AND ?
          AND status NOT IN ('iptal','iade')
        GROUP BY dealer_id
     ) o ON o.dealer_id=d.id
     LEFT JOIN (
        SELECT dealer_id,
               COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) AS balance
        FROM b2b_ledger
        WHERE is_closed=0
        GROUP BY dealer_id
     ) l ON l.dealer_id=d.id
     WHERE d.is_active=1
     ORDER BY total DESC",
    [$from, $to]
);
if (isset($out)) {
    fputcsv($out, ['Firma','Şehir','Sipariş','Ciro','Bakiye']);
    foreach ($rows as $r) fputcsv($out, [$r['company_name'],$r['city'],$r['order_count'],$r['total'],$r['balance']]);
    fclose($out); exit;
}
?>
<div class="card">
    <div class="card-header"><h3 class="card-title">Bayi Raporu</h3></div>
    <table class="table">
        <thead><tr><th>Firma</th><th>Şehir</th><th>Sipariş</th><th>Ciro</th><th>Cari Bakiye</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong><?= htmlspecialchars($r['company_name']) ?></strong></td>
            <td><?= htmlspecialchars($r['city'] ?? '-') ?></td>
            <td><?= $r['order_count'] ?></td>
            <td><?= fmtPrice($r['total']) ?> ₺</td>
            <td style="color:<?= $r['balance'] > 0 ? '#ef4444' : '#10b981' ?>">
                <?= fmtPrice(abs($r['balance'])) ?> ₺
                <?= $r['balance'] > 0 ? '(Borçlu)' : ($r['balance'] < 0 ? '(Alacaklı)' : '') ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php elseif ($report === 'stock'): ?>
<!-- Stok Raporu -->
<?php
$rows = dbRows(
    "SELECT p.sku, p.name, p.stock, p.stock_critical, p.base_price,
            COALESCE(s.sold_qty,0) as sold_qty,
            COALESCE(s.sold_amount,0) as sold_amount
     FROM b2b_products p
     LEFT JOIN (
        SELECT oi.product_id,
               COALESCE(SUM(oi.qty),0) AS sold_qty,
               COALESCE(SUM(oi.line_total),0) AS sold_amount
        FROM b2b_order_items oi
        JOIN b2b_orders o ON o.id=oi.order_id
        WHERE DATE(o.created_at) BETWEEN ? AND ?
          AND o.status NOT IN ('iptal','iade')
        GROUP BY oi.product_id
     ) s ON s.product_id=p.id
     WHERE p.is_active=1
     ORDER BY sold_qty DESC",
    [$from, $to]
);
if (isset($out)) {
    fputcsv($out, ['SKU','Ürün','Stok','Kritik Stok','Satış Adedi','Satış Tutarı']);
    foreach ($rows as $r) fputcsv($out, [$r['sku'],$r['name'],$r['stock'],$r['stock_critical'],$r['sold_qty'],$r['sold_amount']]);
    fclose($out); exit;
}
?>
<div class="card">
    <div class="card-header"><h3 class="card-title">Stok Raporu</h3></div>
    <table class="table">
        <thead><tr><th>SKU</th><th>Ürün</th><th>Stok</th><th>Durum</th><th>Satış Adedi</th><th>Satış Tutarı</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td style="font-family:monospace;font-size:.85rem"><?= htmlspecialchars($r['sku']) ?></td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= $r['stock'] ?></td>
            <td><?= stockBadge($r['stock'], $r['stock_critical']) ?></td>
            <td><?= $r['sold_qty'] ?></td>
            <td><?= fmtPrice($r['sold_amount']) ?> ₺</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php elseif ($report === 'ledger'): ?>
<!-- Cari Raporu -->
<?php
$rows = dbRows("SELECT d.company_name, SUM(IF(l.type='borc', l.amount, 0)) as total_borc, SUM(IF(l.type='alacak', l.amount, 0)) as total_alacak, SUM(IF(l.type='borc', l.amount, 0)) - SUM(IF(l.type='alacak', l.amount, 0)) as net, SUM(IF(l.due_date IS NOT NULL AND l.due_date < NOW() AND l.is_closed=0, l.amount, 0)) as overdue FROM b2b_dealers d LEFT JOIN b2b_ledger l ON l.dealer_id=d.id AND DATE(l.created_at) BETWEEN ? AND ? WHERE d.is_active=1 GROUP BY d.id HAVING total_borc > 0 OR total_alacak > 0 ORDER BY net DESC", [$from, $to]);
if (isset($out)) {
    fputcsv($out, ['Firma','Borç','Alacak','Net','Vadesi Geçen']);
    foreach ($rows as $r) fputcsv($out, [$r['company_name'],$r['total_borc'],$r['total_alacak'],$r['net'],$r['overdue']]);
    fclose($out); exit;
}
?>
<div class="card">
    <div class="card-header"><h3 class="card-title">Cari Raporu</h3></div>
    <table class="table">
        <thead><tr><th>Firma</th><th>Toplam Borç</th><th>Toplam Alacak</th><th>Net Bakiye</th><th>Vadesi Geçen</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong><?= htmlspecialchars($r['company_name']) ?></strong></td>
            <td style="color:#ef4444"><?= fmtPrice($r['total_borc']) ?> ₺</td>
            <td style="color:#10b981"><?= fmtPrice($r['total_alacak']) ?> ₺</td>
            <td style="font-weight:700;color:<?= $r['net'] > 0 ? '#ef4444' : '#10b981' ?>">
                <?= fmtPrice(abs($r['net'])) ?> ₺
            </td>
            <td style="color:<?= $r['overdue'] > 0 ? '#ef4444' : 'var(--text-muted)' ?>">
                <?= fmtPrice($r['overdue']) ?> ₺
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php
// pages/orders.php — Bayi Siparişleri
requireDealer();
$dealer = currentDealer();

$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);

// Sipariş detay
$order = null;
if ($action === 'detail' && $id) {
    $order = dbRow("SELECT * FROM b2b_orders WHERE id=? AND dealer_id=?", [$id, $dealer['id']]);
    if (!$order) { $action = 'list'; $id = 0; }
}

if ($action === 'detail' && $order) {
    $orderItems = dbRows(
        "SELECT oi.*, p.image FROM b2b_order_items oi LEFT JOIN b2b_products p ON p.id=oi.product_id WHERE oi.order_id=?",
        [$id]
    );
    $payments = dbRows("SELECT * FROM b2b_payments WHERE order_id=? ORDER BY created_at DESC", [$id]);
}

// Liste
if ($action === 'list') {
    $status  = $_GET['status'] ?? '';
    $perPage = 15;
    $page    = max(1, intval($_GET['p'] ?? 1));
    $offset  = ($page-1)*$perPage;

    $where = ['dealer_id=?']; $params = [$dealer['id']];
    if ($status) { $where[] = 'status=?'; $params[] = $status; }

    $w = implode(' AND ', $where);
    $total  = dbVal("SELECT COUNT(*) FROM b2b_orders WHERE $w", $params);
    $orders = dbRows("SELECT * FROM b2b_orders WHERE $w ORDER BY created_at DESC LIMIT $perPage OFFSET $offset", $params);
    $pager  = pagination($total, $perPage, $page, "?page=orders&status=$status&p=");
}
?>

<?php if ($action === 'list'): ?>
<div class="page-header">
    <div>
        <h1 class="page-title">Siparişlerim</h1>
        <p class="page-sub">Toplam <?= $total ?> sipariş</p>
    </div>
    <a href="?page=products" class="btn btn-primary">＋ Yeni Sipariş</a>
</div>

<?php if (isset($_GET['placed'])): ?>
<div class="alert alert-success mb-4">✅ Siparişiniz alındı! Durumu aşağıdan takip edebilirsiniz.</div>
<?php endif; ?>

<!-- Durum Sekmeleri -->
<div class="tab-bar mb-4">
    <?php foreach ([''=> 'Tümü','bekliyor'=>'Bekleyen','onaylandi'=>'Onaylanan','kargoda'=>'Kargoda','teslim_edildi'=>'Teslim','iptal'=>'İptal'] as $v=>$l): ?>
    <a href="?page=orders&status=<?= $v ?>" class="tab-item <?= $status===$v?'active':'' ?>"><?= $l ?></a>
    <?php endforeach; ?>
</div>

<div class="card">
<table class="table">
    <thead><tr><th>Sipariş No</th><th>Tarih</th><th>Tutar</th><th>Ürün</th><th>Durum</th><th>Ödeme</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
    <tr>
        <td class="font-medium"><a href="?page=orders&action=detail&id=<?= $o['id'] ?>"><?= h($o['order_number']) ?></a></td>
        <td class="text-sm"><?= fmtDate($o['created_at']) ?></td>
        <td><?= money($o['grand_total']) ?></td>
        <td class="text-sm text-muted"><?= dbVal("SELECT COUNT(*) FROM b2b_order_items WHERE order_id=?",[$o['id']]) ?> kalem</td>
        <td><?= orderStatusLabel($o['status']) ?></td>
        <td>
            <span class="badge badge-<?= $o['payment_status']==='odendi'?'green':($o['payment_status']==='bekliyor'?'yellow':'blue') ?>">
                <?= $o['payment_status']==='odendi'?'Ödendi':($o['payment_status']==='kismi_odeme'?'Kısmen':'Bekliyor') ?>
            </span>
        </td>
        <td><a href="?page=orders&action=detail&id=<?= $o['id'] ?>" class="btn btn-xs btn-ghost">Detay →</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($orders)): ?><tr><td colspan="7" class="text-center text-muted py-8">Sipariş bulunamadı.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?= $pager ?>

<?php elseif ($action === 'detail' && $order): ?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= h($order['order_number']) ?></h1>
        <p class="page-sub"><?= fmtDate($order['created_at']) ?> — <?= orderStatusLabel($order['status']) ?></p>
    </div>
    <div class="btn-group">
        <a href="?page=orders" class="btn btn-ghost">← Geri</a>
        <?php if ($order['payment_status'] !== 'odendi' && in_array($order['status'],['onaylandi','hazirlaniyor','kargoda','teslim_edildi'])): ?>
        <a href="?page=payments&action=new&order_id=<?= $id ?>" class="btn btn-primary">💳 Ödeme Yap</a>
        <?php endif; ?>
    </div>
</div>

<!-- Durum Adımları -->
<?php
$steps = ['bekliyor','onaylandi','hazirlaniyor','kargoda','teslim_edildi'];
$currentStep = array_search($order['status'], $steps);
if ($order['status'] !== 'iptal' && $order['status'] !== 'iade'):
?>
<div class="order-steps card mb-6">
    <div class="steps-wrapper">
    <?php foreach ($steps as $i=>$step):
        $done    = $currentStep !== false && $i <= $currentStep;
        $labels  = ['Sipariş Alındı','Onaylandı','Hazırlanıyor','Kargoya Verildi','Teslim Edildi'];
    ?>
    <div class="step <?= $done?'done':'' ?> <?= $i===$currentStep?'current':'' ?>">
        <div class="step-dot"><?= $done && $i < $currentStep ? '✓' : ($i+1) ?></div>
        <div class="step-label text-xs"><?= $labels[$i] ?></div>
    </div>
    <?php if ($i < count($steps)-1): ?><div class="step-line <?= $i<$currentStep?'done':'' ?>"></div><?php endif; ?>
    <?php endforeach; ?>
    </div>
</div>
<?php elseif ($order['status'] === 'iptal'): ?>
<div class="alert alert-error mb-6">
    ❌ Sipariş iptal edildi.<?= $order['cancel_reason'] ? ' Neden: '.h($order['cancel_reason']) : '' ?>
</div>
<?php endif; ?>

<!-- Kargo Bilgisi -->
<?php if ($order['cargo_company']): ?>
<div class="card mb-6">
    <div class="card-body" style="display:flex;align-items:center;gap:16px">
        <div style="font-size:32px">🚚</div>
        <div>
            <div class="font-medium"><?= h($order['cargo_company']) ?></div>
            <?php if ($order['tracking_number']): ?><div class="text-muted text-sm">Takip: <strong><?= h($order['tracking_number']) ?></strong></div><?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Ürünler -->
<div class="card mb-6">
    <div class="card-header"><h3>Sipariş Kalemleri</h3></div>
    <table class="table">
        <thead><tr><th>Ürün</th><th>Birim Fiyat</th><th>Miktar</th><th>Toplam</th></tr></thead>
        <tbody>
        <?php $sub=0; $taxTotal=0; foreach ($orderItems as $it):
            $lineTotal = $it['unit_price'] * $it['quantity'];
            $lineTax   = $lineTotal * ($it['tax_rate']/100);
            $sub += $lineTotal; $taxTotal += $lineTax;
        ?>
        <tr>
            <td><?= h($it['product_name']) ?></td>
            <td><?= money($it['unit_price']) ?> / <?= h($it['unit']) ?></td>
            <td><?= $it['quantity'] ?> <?= h($it['unit']) ?></td>
            <td class="font-medium"><?= money($lineTotal) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="table-footer"><td colspan="3" class="text-right">Ara Toplam</td><td><?= money($sub) ?></td></tr>
        <tr class="table-footer"><td colspan="3" class="text-right">KDV</td><td><?= money($taxTotal) ?></td></tr>
        <tr class="table-footer font-bold"><td colspan="3" class="text-right">Toplam</td><td><?= money($order['grand_total']) ?></td></tr>
        </tbody>
    </table>
</div>

<!-- Vade -->
<?php if ($order['due_date']): ?>
<div class="card mb-6">
    <div class="card-body" style="display:flex;justify-content:space-between">
        <div>
            <div class="text-muted text-sm">Ödeme Vadesi</div>
            <div class="font-medium <?= $order['due_date'] < date('Y-m-d') && $order['payment_status']!=='odendi' ? 'text-danger' : '' ?>"><?= fmtDate($order['due_date']) ?></div>
        </div>
        <div>
            <div class="text-muted text-sm">Ödeme Durumu</div>
            <div class="font-medium"><?= $order['payment_status']==='odendi'?'✅ Ödendi':($order['payment_status']==='kismi_odeme'?'⚠ Kısmen Ödendi':'⏳ Ödeme Bekleniyor') ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Yapılan Ödemeler -->
<?php if (!empty($payments)): ?>
<div class="card">
    <div class="card-header"><h3>Ödeme Geçmişi</h3></div>
    <table class="table">
        <thead><tr><th>Tarih</th><th>Tutar</th><th>Yöntem</th><th>Durum</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $py): ?>
        <tr>
            <td><?= fmtDate($py['created_at']) ?></td>
            <td class="text-success font-medium"><?= money($py['amount']) ?></td>
            <td><?= h($py['payment_method']) ?></td>
            <td><span class="badge badge-<?= $py['status']==='onaylandi'?'green':($py['status']==='bekliyor'?'yellow':'red') ?>"><?= h($py['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php endif; ?>

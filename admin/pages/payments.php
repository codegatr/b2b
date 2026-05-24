<?php
// admin/pages/payments.php — Tahsilat Yönetimi
requireAdmin();

$action   = $_GET['action'] ?? 'list';
$id       = intval($_GET['id'] ?? 0);
$dealerId = intval($_GET['dealer_id'] ?? 0);

// ── AJAX: Bayinin açık (ödenmemiş) siparişlerini JSON döndür ──
if ($action === 'dealer_orders') {
    header('Content-Type: application/json');
    $did = intval($_GET['dealer_id'] ?? 0);
    if (!$did) {
        echo json_encode(['ok' => false, 'orders' => []]);
        exit;
    }

    $orders = dbRows(
        "SELECT o.id, o.order_no, o.grand_total, o.status, o.payment_status,
                COALESCE(SUM(CASE WHEN p.status='onaylandi' THEN p.amount ELSE 0 END), 0) AS paid
         FROM b2b_orders o
         LEFT JOIN b2b_payments p ON p.order_id=o.id
         WHERE o.dealer_id=?
           AND o.status NOT IN ('iptal','iade')
           AND o.payment_status IN ('odenmedi','kismi_odeme')
         GROUP BY o.id
         ORDER BY o.created_at DESC
         LIMIT 50",
        [$did]
    );

    $statusMap = [
        'bekliyor'      => 'Sipariş Alındı',
        'onaylandi'     => 'Onaylandı',
        'hazirlaniyor'  => 'Hazırlanıyor',
        'kargoda'       => 'Teslimata Çıktı',
        'teslim_edildi' => 'Teslim Edildi',
    ];

    $result = [];
    foreach ($orders as $o) {
        $total   = (float)$o['grand_total'];
        $paid    = (float)$o['paid'];
        $balance = round($total - $paid, 2);
        if ($balance <= 0.01) continue; // Tamamen ödenmiş, atla
        $result[] = [
            'id'                => (int)$o['id'],
            'order_no'          => $o['order_no'],
            'grand_total'       => $total,
            'paid'              => $paid,
            'balance'           => $balance,
            'balance_formatted' => 'Kalan: ' . number_format($balance, 2, ',', '.') . ' ₺',
            'status'            => $o['status'],
            'status_label'      => $statusMap[$o['status']] ?? $o['status'],
        ];
    }

    echo json_encode(['ok' => true, 'orders' => $result]);
    exit;
}

if ($action !== 'list') {
    $action = 'list';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';
    $pid = intval($_POST['payment_id'] ?? 0);

    // ─── Eksik ledger kayıtlarını yeniden oluştur ───────────────
    // Onaylanmış tahsilatlar var ama b2b_ledger'da eşleşen kaydı yok →
    // manuel senkron butonu. Eski bug'lardan kalan eksik kayıtları onarır.
    if ($act === 'resync_ledger') {
        $orphans = dbRows(
            "SELECT p.*
             FROM b2b_payments p
             LEFT JOIN b2b_ledger l ON l.reference_type='payment' AND l.reference_id=p.id
             WHERE p.status='onaylandi' AND l.id IS NULL
             ORDER BY p.id"
        );
        $fixed = 0;
        foreach ($orphans as $op) {
            $orderRef = '';
            if (!empty($op['order_id'])) {
                $ono = dbVal("SELECT order_no FROM b2b_orders WHERE id=?", [$op['order_id']]);
                if ($ono) $orderRef = " — Sipariş #{$ono}";
            }
            $methodLabel = function_exists('paymentMethodLabel') ? paymentMethodLabel($op['type'] ?? '') : ($op['type'] ?? '');
            $desc = "Ödeme onaylandı: {$methodLabel}{$orderRef}";
            ledgerAdd((int)$op['dealer_id'], 'alacak', (float)$op['amount'], $desc, 'payment', (int)$op['id']);
            $fixed++;
        }
        if ($fixed > 0) {
            auditLog('ledger_resync', 'b2b_payments', 0, ['orphan_count'=>$fixed]);
            $success = "{$fixed} eksik ledger kaydı tamamlandı.";
        } else {
            $success = 'Eksik ledger kaydı bulunmadı. Tüm onaylı tahsilatlar zaten cari hesapta.';
        }
        $action = 'list';
    }

    if ($act === 'approve') {
        $p = dbRow("SELECT * FROM b2b_payments WHERE id=?", [$pid]);
        if ($p && $p['status'] === 'bekliyor') {
            dbExec("UPDATE b2b_payments SET status='onaylandi', approved_by=?, approved_at=NOW() WHERE id=?", [adminId(), $pid]);
            // Cari alacak yaz
            ledgerAdd($p['dealer_id'], 'alacak', $p['amount'], "Ödeme onaylandı: " . h(paymentMethodLabel($p["type"] ?? "")), 'payment', $pid);
            // Sipariş ödeme durumu güncelle — SİPARİŞ BAZLI (bayi bazlı değil)
            if ($p['order_id']) {
                $order = dbRow("SELECT grand_total FROM b2b_orders WHERE id=?", [$p['order_id']]);
                if ($order) {
                    // Bu siparişe ait toplam ONAYLANMIŞ ödeme tutarı
                    $totalPaid = (float)dbVal(
                        "SELECT COALESCE(SUM(amount),0) FROM b2b_payments
                         WHERE order_id=? AND status='onaylandi'",
                        [$p['order_id']]
                    );
                    $orderTotal = (float)$order['grand_total'];
                    if ($totalPaid >= $orderTotal - 0.01) {
                        // Tamamen ödendi (yarım kuruş tolerans)
                        dbExec("UPDATE b2b_orders SET payment_status='odendi' WHERE id=?", [$p['order_id']]);
                        closeOrderLedgerIfPaid((int)$p['order_id']);
                    } elseif ($totalPaid > 0) {
                        // Kısmi ödeme
                        dbExec("UPDATE b2b_orders SET payment_status='kismi_odeme' WHERE id=?", [$p['order_id']]);
                    }
                }
            }
            // Paraşüt ödeme
            if (!empty($p['order_id']) && function_exists('parasut')) {
                try {
                    $order = dbRow("SELECT id, parasut_invoice_id FROM b2b_orders WHERE id=?", [(int)$p['order_id']]);
                    $invoiceId = $order['parasut_invoice_id'] ?? null;
                    if (!$invoiceId) {
                        $invoiceId = parasut()->syncInvoice((int)$p['order_id']);
                        if ($invoiceId) {
                            dbExec("UPDATE b2b_orders SET parasut_invoice_id=? WHERE id=?", [$invoiceId, (int)$p['order_id']]);
                        }
                    }
                    if ($invoiceId) {
                        $parasutPaymentId = parasut()->createPayment(
                            $invoiceId,
                            (float)$p['amount'],
                            $p['payment_date'] ?: date('Y-m-d'),
                            paymentMethodLabel($p["type"] ?? "") . ' tahsilati'
                        );
                        if ($parasutPaymentId) {
                            dbExec("UPDATE b2b_payments SET parasut_payment_id=? WHERE id=?", [$parasutPaymentId, $pid]);
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('Parasut payment sync error: ' . $e->getMessage());
                }
            }
            notifyDealer($p['dealer_id'], 'payment', 'Ödemeniz Onaylandı', money($p['amount']).' tutarındaki ödemeniz sisteme işlendi.', '?page=payments');
            auditLog('payment_approved', 'b2b_payments', $pid, ['amount'=>$p['amount']]);
            $success = 'Ödeme onaylandı ve cari hesaba işlendi.';
        }
        $action = 'list';
    }

    if ($act === 'reject') {
        $reason = trim($_POST['admin_note'] ?? '');
        $p = dbRow("SELECT * FROM b2b_payments WHERE id=?", [$pid]);
        if ($p && $p['status'] === 'bekliyor') {
            dbExec("UPDATE b2b_payments SET status='reddedildi', admin_note=? WHERE id=?", [$reason, $pid]);
            notifyDealer($p['dealer_id'], 'payment', 'Ödemeniz Reddedildi', 'Ödemeniz reddedildi.' . ($reason ? " Neden: $reason" : ''), '?page=payments');
            $success = 'Ödeme reddedildi.';
        }
        $action = 'list';
    }

    // Tahsilat kaydını + bağlı cari hareketini KALICI sil
    if ($act === 'delete_payment') {
        $p = dbRow("SELECT * FROM b2b_payments WHERE id=?", [$pid]);
        if ($p) {
            // Bağlı ledger kayıtlarını bul ve sil:
            // 1) reference_type=payment ve reference_id=payment_id (yeni doğru kayıtlar)
            $linkedLedger = dbRows("SELECT id FROM b2b_ledger WHERE reference_type='payment' AND reference_id=?", [$pid]);
            // 2) Eski/yanlış: reference_type=payment, reference_id=order_id veya hiç ref olmayan ama
            //    aynı bayi+tutar+tarihte alacak kaydı (fallback)
            if (empty($linkedLedger)) {
                $linkedLedger = dbRows(
                    "SELECT id FROM b2b_ledger
                     WHERE dealer_id=? AND type='alacak' AND amount=? AND DATE(created_at)=DATE(?)",
                    [(int)$p['dealer_id'], (float)$p['amount'], $p['created_at']]
                );
            }
            foreach ($linkedLedger as $lg) {
                dbExec("DELETE FROM b2b_ledger WHERE id=?", [(int)$lg['id']]);
            }
            // Sipariş ödeme statüsünü geri al (payment silindi → odenmedi)
            if (!empty($p['order_id'])) {
                dbExec("UPDATE b2b_orders SET payment_status='odenmedi' WHERE id=?", [(int)$p['order_id']]);
            }
            dbExec("DELETE FROM b2b_payments WHERE id=?", [$pid]);
            auditLog('payment_deleted', 'b2b_payments', $pid, [
                'dealer_id' => $p['dealer_id'],
                'order_id'  => $p['order_id'] ?? null,
                'amount'    => $p['amount'],
                'type'      => $p['type'],
                'status'    => $p['status'],
                'ledger_deleted_count' => count($linkedLedger),
            ]);
            $success = 'Tahsilat ve bağlı cari hareketleri silindi.';
        } else {
            $error = 'Tahsilat kaydı bulunamadı.';
        }
        $action = 'list';
    }

    // Manuel tahsilat girişi
    if ($act === 'manual') {
        $did    = intval($_POST['dealer_id']);
        $orderId = intval($_POST['order_id'] ?? 0);
        $amount = floatval($_POST['amount']);
        $method = $_POST['type'] ?? 'nakit';
        $note   = trim($_POST['note']);
        $date   = $_POST['payment_date'] ?: date('Y-m-d');
        if ($did > 0 && $amount > 0) {
            $newId = dbInsertRow('b2b_payments', [
                'dealer_id'      => $did,
                'order_id'       => $orderId ?: null,
                'amount'         => $amount,
                'type'           => $method,
                'payment_date'   => $date,
                'dealer_note'    => $note,
                'status'         => 'onaylandi',
                'approved_by'    => adminId(),
                'approved_at'    => date('Y-m-d H:i:s'),
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            // Sipariş referansı varsa açıklamayı zenginleştir
            $ledgerDesc = $orderId
                ? "Manuel tahsilat — Sipariş #" . dbVal("SELECT order_no FROM b2b_orders WHERE id=?", [$orderId]) . ($note ? " ($note)" : "")
                : "Manuel tahsilat" . ($note ? ": $note" : "");
            ledgerAdd($did, 'alacak', $amount, $ledgerDesc, 'payment', $newId);

            // Sipariş ödeme durumunu güncelle (sipariş bazlı hesap)
            if ($orderId) {
                $order = dbRow("SELECT grand_total FROM b2b_orders WHERE id=?", [$orderId]);
                if ($order) {
                    $totalPaid = (float)dbVal(
                        "SELECT COALESCE(SUM(amount),0) FROM b2b_payments
                         WHERE order_id=? AND status='onaylandi'",
                        [$orderId]
                    );
                    $orderTotal = (float)$order['grand_total'];
                    if ($totalPaid >= $orderTotal - 0.01) {
                        dbExec("UPDATE b2b_orders SET payment_status='odendi' WHERE id=?", [$orderId]);
                        closeOrderLedgerIfPaid((int)$orderId);
                    } elseif ($totalPaid > 0) {
                        dbExec("UPDATE b2b_orders SET payment_status='kismi_odeme' WHERE id=?", [$orderId]);
                    }
                }
            }

            auditLog('payment_manual', 'b2b_payments', $newId, ['dealer_id'=>$did,'order_id'=>$orderId,'amount'=>$amount]);
            $success = 'Manuel tahsilat kaydedildi' . ($orderId ? ' ve sipariş ödeme durumu güncellendi.' : '.');
        } else { $error = 'Bayi ve tutar zorunludur.'; }
    }
}

// Liste
if ($action === 'list') {
    $status  = $_GET['status'] ?? 'bekliyor';
    $perPage = 25;
    $page    = max(1, intval($_GET['p'] ?? 1));
    $offset  = ($page-1)*$perPage;

    $where = ['1=1']; $params = [];
    if ($status)   { $where[] = 'p.status=?'; $params[] = $status; }
    if ($dealerId) { $where[] = 'p.dealer_id=?'; $params[] = $dealerId; }

    $w = implode(' AND ', $where);
    $total    = dbVal("SELECT COUNT(*) FROM b2b_payments p WHERE $w", $params);
    $payments = dbRows(
        "SELECT p.*, d.company_name, o.order_no, o.grand_total AS order_total
         FROM b2b_payments p
         JOIN b2b_dealers d ON d.id=p.dealer_id
         LEFT JOIN b2b_orders o ON o.id=p.order_id
         WHERE $w
         ORDER BY p.created_at DESC LIMIT $perPage OFFSET $offset",
        $params
    );
    // Duplicate tespiti: aynı bayi + tutar + status (bekliyor) + son 7 gün
    $dupKeys = [];
    foreach ($payments as $p) {
        if (($p['status'] ?? '') !== 'bekliyor') continue;
        $k = $p['dealer_id'] . '|' . round((float)$p['amount'], 2) . '|' . ($p['order_id'] ?: 'noord');
        $dupKeys[$k] = ($dupKeys[$k] ?? 0) + 1;
    }
    $dupFlags = [];
    foreach ($payments as $p) {
        $k = $p['dealer_id'] . '|' . round((float)$p['amount'], 2) . '|' . ($p['order_id'] ?: 'noord');
        $dupFlags[$p['id']] = ($dupKeys[$k] ?? 0) > 1;
    }
    $pager = pagination($total, $perPage, $page, "?page=payments&status=$status&dealer_id=$dealerId&p=");
    $pendingSum = dbVal("SELECT COALESCE(SUM(amount),0) FROM b2b_payments WHERE status='bekliyor'", []);
    $dealers    = dbRows("SELECT id, company_name FROM b2b_dealers WHERE is_active=1 ORDER BY company_name");
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Tahsilat Yönetimi</h1>
        <?php if (!empty($pendingSum) && $pendingSum > 0): ?>
        <p class="page-sub text-warning"><?= money($pendingSum) ?> onay bekliyor</p>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php
        // Onaylı ama ledger'a yazılmamış tahsilat sayısı
        $orphanCount = (int)dbVal(
            "SELECT COUNT(*)
             FROM b2b_payments p
             LEFT JOIN b2b_ledger l ON l.reference_type='payment' AND l.reference_id=p.id
             WHERE p.status='onaylandi' AND l.id IS NULL"
        );
        ?>
        <?php if ($orphanCount > 0): ?>
        <form method="post" onsubmit="return confirm('<?= $orphanCount ?> onaylı tahsilatın ledger kaydı eksik. Şimdi oluşturulsun mu?\n\nBu işlem cari hesabı düzeltir.');">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="resync_ledger">
            <button type="submit" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none" title="Onaylı tahsilatlardan ledger'a yazılmamış olanları tamamla">
                🔧 Ledger Eksik (<?= $orphanCount ?>) - Düzelt
            </button>
        </form>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="openModal('modal-manual')">＋ Manuel Tahsilat</button>
    </div>
</div>

<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<!-- Durum filtreleri -->
<?php
$_counts = [
    'bekliyor'    => (int)dbVal("SELECT COUNT(*) FROM b2b_payments WHERE status='bekliyor'"),
    'onaylandi'   => (int)dbVal("SELECT COUNT(*) FROM b2b_payments WHERE status='onaylandi'"),
    'reddedildi'  => (int)dbVal("SELECT COUNT(*) FROM b2b_payments WHERE status='reddedildi'"),
    ''            => (int)dbVal("SELECT COUNT(*) FROM b2b_payments"),
];
$_tabs = ['bekliyor'=>'Bekleyen','onaylandi'=>'Onaylanan','reddedildi'=>'Reddedilen',''=>'Tümü'];
?>
<div class="tab-bar">
    <?php foreach ($_tabs as $val=>$label): ?>
    <a href="?page=payments&status=<?= $val ?>" class="tab-item <?= $status===$val?'active':'' ?>">
        <?= $label ?>
        <?php if ($_counts[$val] > 0): ?>
        <span class="tab-count <?= $val==='bekliyor'?'warn':'' ?>"><?= $_counts[$val] ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="card">
<table class="table">
    <thead><tr><th>Tarih</th><th>Bayi</th><th>Sipariş</th><th>Tutar</th><th>Yöntem</th><th>Dekont</th><th>Durum</th><th>İşlem</th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p):
        $isDup = !empty($dupFlags[$p['id']]);
    ?>
    <tr style="<?= $isDup ? 'background:#fef3c7' : '' ?>">
        <td><?= fmtDate($p['created_at']) ?></td>
        <td><a href="?page=dealers&action=detail&id=<?= $p['dealer_id'] ?>"><?= h($p['company_name']) ?></a></td>
        <td>
            <?php if (!empty($p['order_no'])): ?>
                <a href="?page=orders&id=<?= (int)$p['order_id'] ?>&action=detail" style="font-family:monospace;font-size:12px"><?= h($p['order_no']) ?></a>
                <?php if (!empty($p['order_total']) && abs((float)$p['order_total'] - (float)$p['amount']) > 0.01): ?>
                    <div style="font-size:10px;color:var(--text-muted)">Sipariş toplamı: <?= money($p['order_total']) ?></div>
                <?php endif; ?>
            <?php else: ?>
                <span class="text-muted text-sm">Sipariş yok</span>
            <?php endif; ?>
            <?php if ($isDup): ?>
                <div style="margin-top:3px"><span class="badge" style="background:#fcd34d;color:#78350f;font-size:9px;font-weight:700">⚠️ ŞÜPHE: DUPLICATE</span></div>
            <?php endif; ?>
        </td>
        <td class="font-medium text-success"><?= money($p['amount']) ?></td>
        <td><?= h(paymentMethodLabel($p["type"] ?? "")) ?></td>
        <td>
            <?php if ($p['receipt_file']): ?>
            <a href="<?= h($p['receipt_file']) ?>" target="_blank" class="btn btn-xs btn-ghost">📄 Dekont</a>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td>
            <span class="badge badge-<?= $p['status']==='onaylandi'?'green':($p['status']==='bekliyor'?'yellow':'red') ?>">
                <?= h($p['status']) ?>
            </span>
        </td>
        <td>
            <?php if ($p['status'] === 'bekliyor'): ?>
            <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="approve">
                <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                <button class="btn btn-xs btn-success">✓ Onayla</button>
            </form>
            <button class="btn btn-xs btn-danger" onclick="rejectPayment(<?= $p['id'] ?>)">✕ Reddet</button>
            <?php else: ?>
            <span class="text-muted text-sm" style="margin-right:4px"><?= $p['approved_at'] ? fmtDate($p['approved_at']) : '' ?></span>
            <?php endif; ?>
            <!-- Tahsilat KALICI silme — onaylı/red/bekleyen tüm durumlarda -->
            <form method="post" style="display:inline" onsubmit="return confirm('Bu tahsilatı KALICI olarak silinsin mi?\n\n<?= addslashes(paymentMethodLabel($p['type']) . ' — ' . number_format($p['amount'],2,',','.') . ' ₺ — ' . $p['company_name']) ?>\n\nBağlı cari hareket de temizlenecek. Bu işlem geri alınamaz!');">
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="delete_payment">
                <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                <button class="btn btn-xs btn-ghost" type="submit" title="Tahsilatı sil" style="color:#dc2626">🗑</button>
            </form>
        </td>
    </tr>
    <?php if ($p['dealer_note']): ?>
    <tr class="row-sub">
        <td colspan="8" class="text-sm text-muted pl-6">Not: <?= h($p['dealer_note']) ?> <?= $p['admin_note'] ? '| Red: '.h($p['admin_note']) : '' ?></td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php if (empty($payments)): ?><tr><td colspan="8" class="text-center text-muted py-8">Kayıt yok.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?= $pager ?>

<!-- Modal: Manuel Tahsilat -->
<div id="modal-manual" class="modal-overlay">
<div class="modal">
    <div class="modal-header"><h3>Manuel Tahsilat Girişi</h3></div>
    <div class="modal-body">
        <form method="post" id="form-manual">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="manual">
            <div class="form-group">
                <label>Bayi *</label>
                <select name="dealer_id" id="manual-dealer-select" class="form-control" required onchange="loadDealerOrders(this.value)">
                    <option value="">— Bayi Seç —</option>
                    <?php foreach ($dealers ?? [] as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $dealerId==$d['id']?'selected':'' ?>><?= h($d['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>
                    Hangi Siparişe?
                    <span style="font-size:11px;color:var(--text-muted);font-weight:400">— sipariş seçilirse o siparişin ödeme durumu otomatik güncellenir</span>
                </label>
                <select name="order_id" id="manual-order-select" class="form-control">
                    <option value="">— Genel tahsilat (siparişe bağlı değil) —</option>
                </select>
                <div id="manual-order-loading" style="display:none;font-size:11px;color:var(--text-muted);margin-top:4px">Siparişler yükleniyor…</div>
            </div>
            <div class="form-group">
                <label>Tutar (₺) *</label>
                <input type="number" step="0.01" name="amount" id="manual-amount" class="form-control" required placeholder="0.00">
            </div>
            <div class="form-group">
                <label>Ödeme Yöntemi</label>
                <select name="type" class="form-control">
                    <option value="havale_eft">Havale/EFT</option>
                    <option value="nakit">Nakit</option>
                    <option value="kredi_karti">Kredi Kartı</option>
                    <option value="cek">Çek</option>
                    <option value="senet">Senet</option>
                </select>
            </div>
            <div class="form-group">
                <label>Ödeme Tarihi</label>
                <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" class="form-control">
            </div>
            <div class="form-group">
                <label>Not</label>
                <input type="text" name="note" class="form-control" placeholder="Açıklama…">
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost" type="button" onclick="closeModal('modal-manual')">İptal</button>
        <button class="btn btn-primary" type="submit" form="form-manual">Kaydet</button>
    </div>
</div>
</div>

<script>
// Bayi seçildiğinde o bayinin ödenmemiş siparişlerini getir
async function loadDealerOrders(dealerId) {
    const sel = document.getElementById('manual-order-select');
    const loading = document.getElementById('manual-order-loading');
    sel.innerHTML = '<option value="">— Genel tahsilat (siparişe bağlı değil) —</option>';
    if (!dealerId) return;

    loading.style.display = 'block';
    try {
        const res = await fetch('?page=payments&action=dealer_orders&dealer_id=' + dealerId);
        const data = await res.json();
        if (data.ok && Array.isArray(data.orders)) {
            data.orders.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.id;
                opt.dataset.balance = o.balance;
                opt.textContent = `${o.order_no} — ${o.balance_formatted} (${o.status_label})`;
                sel.appendChild(opt);
            });
        }
    } catch (e) { console.error(e); }
    loading.style.display = 'none';
}

// Sipariş seçildiğinde tutarı otomatik doldur (kalan bakiye)
document.getElementById('manual-order-select').addEventListener('change', function() {
    const opt = this.selectedOptions[0];
    if (opt && opt.dataset.balance) {
        document.getElementById('manual-amount').value = opt.dataset.balance;
    }
});

// Eğer URL'de dealer_id ile geldiyse otomatik aç
<?php if ($dealerId > 0): ?>
loadDealerOrders(<?= $dealerId ?>);
<?php endif; ?>
</script>

<!-- Modal: Reddet -->
<div id="modal-reject" class="modal-overlay">
<div class="modal">
    <div class="modal-header"><h3>Ödemeyi Reddet</h3></div>
    <div class="modal-body">
        <form method="post" id="form-reject">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="reject">
            <input type="hidden" name="payment_id" id="reject-payment-id" value="">
            <div class="form-group">
                <label>Red Nedeni</label>
                <textarea name="admin_note" class="form-control" rows="3" placeholder="Bayi bilgilendirilecektir…"></textarea>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost" type="button" onclick="closeModal('modal-reject')">Vazgeç</button>
        <button class="btn btn-danger" type="submit" form="form-reject">Reddet</button>
    </div>
</div>
</div>

<script>
function rejectPayment(id) {
    document.getElementById('reject-payment-id').value = id;
    openModal('modal-reject');
}
</script>

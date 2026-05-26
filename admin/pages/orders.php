<?php
// admin/pages/orders.php — Sipariş Yönetimi
requireAdmin();

$action   = $_GET['action'] ?? 'list';
$id       = intval($_GET['id'] ?? 0);
$dealerId = intval($_GET['dealer_id'] ?? 0);

// ── PRINT/İRSALİYE ENDPOINT (layout'suz, doğrudan HTML çıktı) ──
// admin/index.php'deki export intercept üzerinden gelir (?print=irsaliye)
if (!empty($_GET['print']) && $_GET['print'] === 'irsaliye' && $id) {
    $order = dbRow(
        "SELECT o.*, d.company_name, d.first_name, d.last_name, d.phone, d.mobile, d.email,
                d.address, d.city, d.district, d.zip, d.tax_office, d.tax_number
         FROM b2b_orders o
         JOIN b2b_dealers d ON d.id=o.dealer_id
         WHERE o.id=?", [$id]
    );
    if (!$order) { http_response_code(404); exit('Sipariş bulunamadı'); }
    $items = dbRows("SELECT * FROM b2b_order_items WHERE order_id=? ORDER BY id", [$id]);
    require __DIR__ . '/orders/_irsaliye.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? ($_POST['act'] ?? '');
    $oid = intval($_POST['order_id'] ?? ($_POST['oid'] ?? 0));

    // ─── Fatura no manuel kaydetme ───
    if ($act === 'set_invoice_no') {
        $oid = intval($_POST['order_id'] ?? 0);
        $invNo = trim($_POST['invoice_no'] ?? '');
        if ($oid) {
            if ($invNo === '') {
                // Boş gönderildi → sil
                dbExec("UPDATE b2b_orders SET invoice_no=NULL, invoice_no_source=NULL, invoice_no_updated_at=NULL, invoice_no_updated_by=NULL WHERE id=?", [$oid]);
                $success = 'Fatura numarası silindi.';
            } else {
                // Doldur (manuel kaynaklı olarak işaretle)
                dbExec(
                    "UPDATE b2b_orders SET invoice_no=?, invoice_no_source='manual', invoice_no_updated_at=NOW(), invoice_no_updated_by=? WHERE id=?",
                    [$invNo, adminId(), $oid]
                );
                auditLog('invoice_no_set', 'b2b_orders', $oid, ['invoice_no' => $invNo, 'source' => 'manual']);
                $success = 'Fatura numarası kaydedildi: ' . $invNo;
                if (function_exists('parasut')) {
                    try {
                        $link = parasut()->linkInvoiceByNumber($oid, $invNo);
                        $success .= $link['ok']
                            ? ' Paraşüt faturası bağlandı.'
                            : ' Paraşüt notu: ' . ($link['msg'] ?? 'fatura bulunamadı.');
                    } catch (\Throwable $e) {
                        $success .= ' Paraşüt bağlantısı denenemedi: ' . $e->getMessage();
                    }
                }
            }
        }
        // Liste veya detay sayfasına geri dön.
        $back = !empty($_POST['return_detail']) && $oid
            ? '?page=orders&action=detail&id=' . $oid
            : '?page=orders';
        if (!empty($_POST['return_q']))      $back .= '&q='      . urlencode($_POST['return_q']);
        if (!empty($_POST['return_status'])) $back .= '&status=' . urlencode($_POST['return_status']);
        $_SESSION['flash'] = ['type'=>'success', 'msg'=>$success];
        redirect($back);
    }

    // Teslim miktarlarını güncelle (her kalem için ayrı veya hepsi tek seferde)
    if ($act === 'update_delivered_qty') {
        $oid = intval($_POST['order_id'] ?? 0);
        $qtyMap = $_POST['delivered_qty'] ?? []; // ['item_id' => 'qty', ...]
        if ($oid && is_array($qtyMap)) {
            foreach ($qtyMap as $itemId => $qty) {
                $itemId = (int)$itemId;
                $qty = $qty === '' ? null : (float)$qty;
                // Önce kolonun var olduğundan emin ol (defansif)
                try {
                    if ($qty === null) {
                        dbExec("UPDATE b2b_order_items SET delivered_qty=NULL WHERE id=? AND order_id=?", [$itemId, $oid]);
                    } else {
                        dbExec("UPDATE b2b_order_items SET delivered_qty=? WHERE id=? AND order_id=?", [$qty, $itemId, $oid]);
                    }
                } catch (\Throwable $e) {
                    // delivered_qty kolonu yoksa migration koşmamış — kullanıcıya söyle
                    $error = 'Teslim miktarı kolonu eksik. Lütfen güncellemeyi (migration_014) çalıştırın.';
                    break;
                }
            }
            if (empty($error)) {
                auditLog('order_delivered_qty_updated', 'b2b_orders', $oid, ['count' => count($qtyMap)]);

                // Eğer sipariş zaten 'teslim_edildi' statüsündeyse ve daha önce
                // ledger borç kaydı oluşturulmuşsa, yeni teslim miktarlarına göre
                // borç tutarını GÜNCELLE. Aksi halde ledger eski toplamla kalır.
                $ord = dbRow("SELECT status FROM b2b_orders WHERE id=?", [$oid]);
                if ($ord && $ord['status'] === 'teslim_edildi') {
                    $existing = dbRow(
                        "SELECT id FROM b2b_ledger
                         WHERE reference_type='order' AND reference_id=? AND type='borc'
                         ORDER BY id DESC LIMIT 1",
                        [$oid]
                    );
                    if ($existing) {
                        // Yeniden hesapla: kalemlerden delivered_qty toplamı
                        $items = dbRows(
                            "SELECT qty, delivered_qty, unit_price, vat_rate FROM b2b_order_items WHERE order_id=?",
                            [$oid]
                        );
                        $newTotal = 0.0;
                        foreach ($items as $it) {
                            $effQty = isset($it['delivered_qty']) && $it['delivered_qty'] !== null
                                ? (float)$it['delivered_qty']
                                : (float)$it['qty'];
                            $newTotal += $effQty * (float)$it['unit_price'] * (1 + ((float)$it['vat_rate'])/100);
                        }
                        $newTotal = round($newTotal, 2);
                        if ($newTotal > 0) {
                            dbExec("UPDATE b2b_ledger SET amount=? WHERE id=?", [$newTotal, $existing['id']]);
                            auditLog('order_ledger_recalculated', 'b2b_orders', $oid, ['ledger_id'=>$existing['id'], 'new_amount'=>$newTotal]);
                        }
                    }
                }

                $success = 'Teslim miktarları güncellendi.';
            }
        }
        if (!empty($_POST['return_to_list'])) {
            redirect('?page=orders');
        }
        $action = 'detail'; $id = $oid;
    }

    // ── Sipariş Sil ───────────────────────────────────────────
    if ($act === 'delete_order') {
        $oid = intval($_POST['order_id'] ?? 0);
        $ord = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
        if ($ord) {
            // Stok iade — sipariş silinmeden önce kalemlerden iade et
            // (cart.php her sipariş statüsünde stok düşürdüğü için iadeyi
            // statüye bağlamıyoruz; iptal/iade siparişlerde zaten iade
            // edilmişse bir daha iade edilmesin diye check ediyoruz).
            if (!in_array($ord['status'] ?? '', ['iptal', 'iade'], true)) {
                $items = dbRows("SELECT product_id, qty FROM b2b_order_items WHERE order_id=?", [$oid]);
                foreach ($items as $it) {
                    $qty = (int)($it['qty'] ?? 0);
                    if ($qty > 0 && $it['product_id']) {
                        dbExec("UPDATE b2b_products SET stock=stock+? WHERE id=?",
                               [$qty, $it['product_id']]);
                    }
                }
            }
            dbExec("DELETE FROM b2b_order_items WHERE order_id=?", [$oid]);
            dbExec("DELETE FROM b2b_orders WHERE id=?", [$oid]);
            // Ledger kayıtlarını da sil
            dbExec("DELETE FROM b2b_ledger WHERE reference_type='order' AND reference_id=?", [$oid]);
            auditLog('order_deleted', 'b2b_orders', $oid, ['order_no' => $ord['order_no']]);
            $_SESSION['flash_admin'] = ['type' => 'success', 'msg' => "#{$ord['order_no']} siparişi, stoklar geri yüklendi ve cari kaydı silindi."];
        }
        redirect('?page=orders');
    }

    // ── İptal onayla ─────────────────────────────────────────
    if ($act === 'approve_cancel') {
        $oid = intval($_POST['order_id'] ?? 0);
        $ord = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
        if ($ord && $ord['cancel_requested']) {
            // Cari ledger kapat
            dbExec("UPDATE b2b_ledger SET is_closed=1 WHERE reference_id=? AND reference_type='order'", [$oid]);
            // Stok geri yükle — cart.php tüm sipariş statülerinde stok
            // düşürüyor, dolayısıyla iptal'de de her zaman iade etmeliyiz.
            // Mevcut statü 'iptal' veya 'iade' ise zaten iade edilmiş, atla.
            if (!in_array($ord['status'] ?? '', ['iptal', 'iade'], true)) {
                $items = dbRows("SELECT product_id, qty FROM b2b_order_items WHERE order_id=?", [$oid]);
                foreach ($items as $it) {
                    $qty = (int)($it['qty'] ?? 0);
                    if ($qty > 0 && $it['product_id']) {
                        dbExec("UPDATE b2b_products SET stock=stock+? WHERE id=?",
                               [$qty, $it['product_id']]);
                    }
                }
            }
            // Sipariş güncelle
            dbExec("UPDATE b2b_orders SET status='iptal', cancel_requested=0,
                    cancel_reviewed_by=?, cancel_reviewed_at=NOW() WHERE id=?",
                   [adminId(), $oid]);
            auditLog('order_cancelled', 'b2b_orders', $oid, ['by'=>'admin']);
            $success = 'Sipariş iptal edildi, stoklar geri yüklendi.';
        }
        $action = 'detail'; $id = $oid;
    }

    // ── İptal reddet ─────────────────────────────────────────
    if ($act === 'reject_cancel') {
        $oid = intval($_POST['order_id'] ?? 0);
        dbExec("UPDATE b2b_orders SET cancel_requested=0,
                cancel_reviewed_by=?, cancel_reviewed_at=NOW() WHERE id=?",
               [adminId(), $oid]);
        auditLog('cancel_rejected', 'b2b_orders', $oid, []);
        $success = 'İptal talebi reddedildi.';
        $action = 'detail'; $id = $oid;
    }
    // Sipariş Onayla
    if ($act === 'approve') {
        $order = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
        if ($order && $order['status'] === 'bekliyor') {
            dbExec("UPDATE b2b_orders SET status='onaylandi', approved_by=?, approved_at=NOW() WHERE id=?", [adminId(), $oid]);
            // Stok düş
            $items = dbRows("SELECT * FROM b2b_order_items WHERE order_id=?", [$oid]);
            foreach ($items as $it) {
                stockUpdate($it['product_id'], -$it['qty'], 'siparis', 'order', $oid);
            }
            // NOT: Cari borç ARTIK ONAY anında EKLENMEZ. 'Teslim Edildi'
            // statüsüne geçişte applyOrderLedger() ile eklenir
            // (eksik teslim olursa o kadar borçlandırma için).
            // Paraşüt fatura
            try { parasut()->fullInvoiceFlow($oid); } catch (\Throwable $e) {}
            // Bildirim + e-posta
            notifyDealer($order['dealer_id'], 'order', 'Siparişiniz Onaylandı', "#{$order['order_no']} numaralı siparişiniz onaylandı.", '?page=orders&action=detail&id='.$oid);
            sendOrderStatusEmail($oid, 'onaylandi');
            auditLog('order_approved', 'b2b_orders', $oid, []);
            $success = 'Sipariş onaylandı.';
        }
        $action = 'detail'; $id = $oid;
    }

    // Sipariş Reddet / İptal
    if ($act === 'cancel') {
        $order = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
        $reason = trim($_POST['cancel_reason'] ?? '');
        if ($order && in_array($order['status'], ['bekliyor','onaylandi','hazirlaniyor','kargoda'])) {
            // Cari ledger — her durumda kapat
            dbExec("UPDATE b2b_ledger SET is_closed=1 WHERE reference_id=? AND reference_type='order'", [$oid]);
            // Stok iade — cart.php tüm statülerde stok düşürdüğü için her
            // statüde iade etmeliyiz. Zaten 'iptal'/'iade' olanları hariç tut.
            if (!in_array($order['status'] ?? '', ['iptal','iade'], true)) {
                $items = dbRows("SELECT product_id, qty FROM b2b_order_items WHERE order_id=?", [$oid]);
                foreach ($items as $it) {
                    $_qty = (int)($it['qty'] ?? 0);
                    if ($_qty > 0 && $it['product_id']) {
                        dbExec("UPDATE b2b_products SET stock=stock+? WHERE id=?", [$_qty, $it['product_id']]);
                    }
                }
            }
            dbExec("UPDATE b2b_orders SET status='iptal', cancel_reason=? WHERE id=?", [$reason, $oid]);
            notifyDealer($order['dealer_id'], 'order', 'Sipariş İptal Edildi', "#{$order['order_no']} numaralı siparişiniz iptal edildi." . ($reason ? " Neden: $reason" : ''), '?page=orders&action=detail&id='.$oid);
            sendOrderStatusEmail($oid, 'iptal', $reason);
            $success = 'Sipariş iptal edildi, stoklar geri yüklendi.';
        }
        $action = 'detail'; $id = $oid;
    }

    // Kargo / Durum güncelle — tüm transition'lara izin ver (ileri/geri)
    if ($act === 'update_status') {
        $status = $_POST['new_status'];
        $cargo  = trim($_POST['cargo_company'] ?? '');
        $track  = trim($_POST['tracking_number'] ?? '');
        $allowed = ['bekliyor','onaylandi','hazirlaniyor','kargoda','teslim_edildi'];
        if (in_array($status, $allowed)) {
            $oldOrder = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
            $oldStatus = $oldOrder['status'] ?? '';

            // ÖZEL TRANSITION: bekliyor → onaylandi (ilk onay)
            // Stok düşür + paraşüt faturayı yansıt + mail gönder.
            // NOT: Cari borç ARTIK ONAY anında EKLENMEZ. Aşağıda
            // teslim_edildi geçişinde applyOrderLedger() çağrılır.
            if ($oldStatus === 'bekliyor' && $status === 'onaylandi') {
                dbExec("UPDATE b2b_orders SET status='onaylandi', approved_by=?, approved_at=NOW() WHERE id=?", [adminId(), $oid]);
                try { parasut()->fullInvoiceFlow($oid); } catch (\Throwable $e) {}
                notifyDealer($oldOrder['dealer_id'], 'order', 'Siparişiniz Onaylandı',
                    "#{$oldOrder['order_no']} numaralı siparişiniz onaylandı.",
                    '?page=orders&action=detail&id='.$oid);
                sendOrderStatusEmail($oid, 'onaylandi');
                auditLog('order_approved', 'b2b_orders', $oid, ['via'=>'inline_status_change']);
                $success = 'Sipariş onaylandı.';
            } else {
                // Normal status güncelleme (ileri ya da geri arası geçiş)
                if ($status === 'kargoda') {
                    dbExec("UPDATE b2b_orders SET status=?, cargo_company=?, tracking_number=? WHERE id=?",
                        [$status, $cargo, $track, $oid]);
                } else {
                    dbExec("UPDATE b2b_orders SET status=? WHERE id=?", [$status, $oid]);
                }

                // ── Teslim Edildi geçişinde CARİ BORÇLANDIRMA ──
                // X → teslim_edildi: borç eklenir (idempotent, eksik teslim hesaba katılır)
                if ($status === 'teslim_edildi' && $oldStatus !== 'teslim_edildi') {
                    dbExec("UPDATE b2b_orders SET delivered_at=NOW() WHERE id=?", [$oid]);
                    $ledgerId = applyOrderLedger($oid, adminId());
                    if ($ledgerId > 0) {
                        auditLog('order_delivered_ledger_added', 'b2b_orders', $oid, ['ledger_id' => $ledgerId]);
                    }
                }
                // teslim_edildi → X: borç geri alınır
                elseif ($oldStatus === 'teslim_edildi' && $status !== 'teslim_edildi') {
                    dbExec("UPDATE b2b_orders SET delivered_at=NULL WHERE id=?", [$oid]);
                    $reverseResult = reverseOrderLedger($oid, adminId());
                    auditLog('order_delivery_reverted', 'b2b_orders', $oid, ['ledger_action' => $reverseResult]);
                }

                // bekliyor'a geri dönüş: onay zincirini sıfırla
                if ($status === 'bekliyor' && $oldStatus !== 'bekliyor') {
                    dbExec("UPDATE b2b_orders SET approved_by=NULL, approved_at=NULL WHERE id=?", [$oid]);
                }
                $order = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
                notifyDealer($order['dealer_id'], 'order',
                    'Sipariş Durumu Güncellendi',
                    "#{$order['order_no']}: " . orderStatusText($status),
                    '?page=orders&action=detail&id='.$oid);
                $extra = '';
                if ($status === 'kargoda' && ($cargo || $track)) {
                    $extra = ($cargo ? $cargo : '') . ($track ? ' — Takip No: ' . $track : '');
                }
                sendOrderStatusEmail($oid, $status, $extra);
                $success = 'Durum güncellendi: ' . orderStatusText($status) . '.';
            }
        }
        if (!empty($_POST['return_to_list'])) {
            redirect('?page=orders' . (!empty($_POST['return_q']) ? '&q='.urlencode($_POST['return_q']) : '') . (!empty($_POST['return_status']) ? '&status='.urlencode($_POST['return_status']) : ''));
        }
        $action = 'detail'; $id = $oid;
    }

    // Not ekle
    if ($act === 'add_note') {
        $note = trim($_POST['admin_note'] ?? '');
        if ($note) {
            dbExec("UPDATE b2b_orders SET admin_note=? WHERE id=?", [$note, $oid]);
            $success = 'Not kaydedildi.';
        }
        $action = 'detail'; $id = $oid;
    }

    // ── Arşivle / Arşivden Çıkar ──────────────────────────────
    if ($act === 'archive') {
        $postedIds = $_POST['order_ids'] ?? ($_POST['order_id'] ?? ($_POST['oid'] ?? $oid));
        $oids = array_values(array_filter(array_map('intval', (array)$postedIds)));
        foreach ($oids as $aid) {
            $o = dbRow("SELECT status, parasut_invoice_id FROM b2b_orders WHERE id=?", [$aid]);
            if ($o && (in_array($o['status'], ['iptal','teslim_edildi','iade'], true) || !empty($o['parasut_invoice_id']))) {
                dbExec("UPDATE b2b_orders SET is_archived=1, archived_by=?, archived_at=NOW() WHERE id=?",
                    [adminId(), $aid]);
                auditLog('order_archived', 'b2b_orders', $aid);
            }
        }
        $success = count($oids) === 1 ? 'Sipariş arşivlendi.' : count($oids).' sipariş arşivlendi.';
        $action = 'list';
    }

    if ($act === 'unarchive') {
        $oid = intval($_POST['order_id'] ?? 0);
        dbExec("UPDATE b2b_orders SET is_archived=0, archived_by=NULL, archived_at=NULL WHERE id=?", [$oid]);
        auditLog('order_unarchived', 'b2b_orders', $oid);
        $success = 'Sipariş arşivden çıkarıldı.';
        $action = 'archive_list';
    }

    // ── Ödendi olarak işaretle — Tahsilat oluşturmadan hızlı işaretleme ──
    if ($act === 'mark_paid') {
        $oid = intval($_POST['order_id'] ?? 0);
        $o = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
        if ($o) {
            dbExec("UPDATE b2b_orders SET payment_status='odendi' WHERE id=?", [$oid]);
            auditLog('order_marked_paid', 'b2b_orders', $oid, ['old_status' => $o['payment_status'] ?? 'odenmedi']);
            $success = 'Sipariş ÖDENDİ olarak işaretlendi.';
            $action = 'detail'; $id = $oid;
        }
    }

    // ── Tamamlanmış (teslim/iptal/iade) tüm siparişleri toplu arşivle ──
    if ($act === 'archive_completed_bulk') {
        $count = (int)dbVal(
            "SELECT COUNT(*) FROM b2b_orders
             WHERE status IN ('teslim_edildi','iptal','iade') AND is_archived=0"
        );
        if ($count > 0) {
            dbExec(
                "UPDATE b2b_orders
                 SET is_archived=1, archived_by=?, archived_at=NOW()
                 WHERE status IN ('teslim_edildi','iptal','iade') AND is_archived=0",
                [adminId()]
            );
            auditLog('orders_bulk_archived', 'b2b_orders', 0, ['count'=>$count]);
            $success = "$count tamamlanmış sipariş arşive kaldırıldı.";
        } else {
            $error = 'Arşivlenecek tamamlanmış sipariş yok.';
        }
        $action = 'list';
    }

    // ── İptal edilen siparişi yeniden işleme al ───────────────
    if ($act === 'reactivate') {
        $order = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
        if ($order && $order['status'] === 'iptal') {
            // Stokta yeterlilil kontrolü
            $items    = dbRows("SELECT * FROM b2b_order_items WHERE order_id=?", [$oid]);
            $stockOk  = true;
            $stockMsg = [];
            foreach ($items as $it) {
                $avail = (int)dbVal("SELECT stock FROM b2b_products WHERE id=?", [$it['product_id']]);
                if ($avail < $it['qty']) {
                    $stockOk = false;
                    $stockMsg[] = "{$it['product_name']}: mevcut {$avail}, gerekli {$it['qty']}";
                }
            }
            if (!$stockOk) {
                $error = 'Yetersiz stok: ' . implode('; ', $stockMsg);
            } else {
                // Siparişi bekliyor'a al, iptal bilgilerini temizle
                dbExec("UPDATE b2b_orders SET status='bekliyor', cancel_reason=NULL,
                        cancel_requested=0, cancel_requested_at=NULL,
                        cancel_reviewed_by=NULL, cancel_reviewed_at=NULL
                        WHERE id=?", [$oid]);
                // Eğer daha önce cari kaydı kapatıldıysa yeniden aç
                dbExec("UPDATE b2b_ledger SET is_closed=0 WHERE reference_id=? AND reference_type='order'", [$oid]);
                notifyDealer($order['dealer_id'], 'order', 'Siparişiniz Yeniden İşleme Alındı',
                    "#{$order['order_no']} numaralı iptal edilmiş siparişiniz yeniden işleme alındı.",
                    '?page=orders&action=detail&id='.$oid);
                auditLog('order_reactivated', 'b2b_orders', $oid, ['by'=>adminId()]);
                $success = 'Sipariş yeniden "Bekliyor" durumuna alındı.';
            }
        }
        $action = 'detail'; $id = $oid;
    }

    // ─────── PARAŞÜT FATURA AKSİYONLARI ───────
    if ($act === 'parasut_full_flow') {
        // Fatura yoksa oluştur + e-arşiv/e-fatura resmileştir + PDF al
        try {
            $r = parasut()->fullInvoiceFlow($oid);
            if ($r['ok']) {
                $msg = 'Paraşüt: ' . $r['msg'];
                if ($r['einvoice_id'] && $r['einvoice_type']) {
                    $tName = $r['einvoice_type'] === 'e_invoice' ? 'E-Fatura' : 'E-Arşiv';
                    $msg .= " {$tName} olarak resmileştirildi (ID: {$r['einvoice_id']}).";
                }
                $success = $msg;
            } else {
                $error = 'Paraşüt hatası: ' . $r['msg'];
            }
        } catch (\Throwable $e) {
            $error = 'Paraşüt hatası: ' . $e->getMessage();
        }
        $action = 'detail'; $id = $oid;
    }

    if ($act === 'parasut_full_flow_archive') {
        try {
            $r = parasut()->fullInvoiceFlow($oid);
            if ($r['ok']) {
                dbExec("UPDATE b2b_orders SET is_archived=1, archived_by=?, archived_at=NOW() WHERE id=?", [adminId(), $oid]);
                auditLog('order_invoiced_and_archived', 'b2b_orders', $oid, [
                    'invoice_id' => $r['invoice_id'] ?? null,
                    'einvoice_id' => $r['einvoice_id'] ?? null,
                    'einvoice_type' => $r['einvoice_type'] ?? null,
                ]);
                $_SESSION['flash_admin'] = ['type'=>'success', 'msg'=>'Fatura kesildi ve sipariş arşive kaldırıldı.'];
                redirect('?page=orders&action=archive_list');
            } else {
                $error = 'Paraşüt hatası: ' . $r['msg'];
            }
        } catch (\Throwable $e) {
            $error = 'Paraşüt hatası: ' . $e->getMessage();
        }
        $action = 'detail'; $id = $oid;
    }

    if ($act === 'parasut_link_invoice_no') {
        $ord = dbRow("SELECT invoice_no FROM b2b_orders WHERE id=?", [$oid]);
        $invNo = trim($ord['invoice_no'] ?? '');
        if ($invNo === '') {
            $error = 'Bu siparişte manuel fatura numarası yok.';
        } else {
            try {
                $r = parasut()->linkInvoiceByNumber($oid, $invNo);
                if ($r['ok']) {
                    $success = $r['msg'];
                } else {
                    $error = $r['msg'];
                }
            } catch (\Throwable $e) {
                $error = 'Paraşüt faturası bağlanamadı: ' . $e->getMessage();
            }
        }
        $action = 'detail'; $id = $oid;
    }
    if ($act === 'parasut_refresh_pdf') {
        // Sadece PDF URL'sini yeniden çek (signed URL süresi dolduğunda)
        $ord = dbRow("SELECT parasut_invoice_id FROM b2b_orders WHERE id=?", [$oid]);
        if (!empty($ord['parasut_invoice_id'])) {
            $url = parasut()->getInvoicePdfUrl($ord['parasut_invoice_id']);
            if ($url) {
                dbExec("UPDATE b2b_orders SET parasut_invoice_pdf_url=?, parasut_synced_at=NOW() WHERE id=?", [$url, $oid]);
                $success = 'Fatura PDF URL yenilendi.';
            } else {
                $error = 'PDF URL alınamadı (fatura henüz resmileştirilmemiş olabilir).';
            }
        } else {
            $error = 'Bu sipariş için henüz Paraşüt faturası yok.';
        }
        $action = 'detail'; $id = $oid;
    }

    if ($act === 'parasut_sync_status') {
        // Fatura durumunu Paraşüt'ten oku ve DB'ye yaz
        $ord = dbRow("SELECT parasut_invoice_id FROM b2b_orders WHERE id=?", [$oid]);
        if (!empty($ord['parasut_invoice_id'])) {
            $status = parasut()->getInvoiceStatus($ord['parasut_invoice_id']);
            if ($status) {
                dbExec("UPDATE b2b_orders SET parasut_invoice_status=?, parasut_synced_at=NOW() WHERE id=?", [$status, $oid]);
                $success = 'Fatura durumu güncellendi: ' . $status;
            } else {
                $error = 'Fatura durumu alınamadı.';
            }
        } else {
            $error = 'Paraşüt faturası yok.';
        }
        $action = 'detail'; $id = $oid;
    }

    if ($act === 'parasut_cancel_invoice') {
        // Fatura iptal (Paraşüt'te)
        $ord = dbRow("SELECT parasut_invoice_id FROM b2b_orders WHERE id=?", [$oid]);
        if (!empty($ord['parasut_invoice_id'])) {
            $ok = parasut()->cancelInvoice($ord['parasut_invoice_id']);
            if ($ok) {
                dbExec("UPDATE b2b_orders SET parasut_invoice_status='cancelled', parasut_synced_at=NOW() WHERE id=?", [$oid]);
                auditLog('parasut_invoice_cancelled', 'b2b_orders', $oid, ['invoice_id'=>$ord['parasut_invoice_id']]);
                $success = 'Paraşüt faturası iptal edildi.';
            } else {
                $error = 'Fatura iptali başarısız.';
            }
        } else {
            $error = 'Paraşüt faturası yok.';
        }
        $action = 'detail'; $id = $oid;
    }
}

// Detay yükle
$order = null;
if ($action === 'detail' && $id) {
    $order = dbRow(
        "SELECT o.*, d.company_name,
                COALESCE(NULLIF(d.company_name,''), CONCAT(TRIM(d.first_name),' ',TRIM(d.last_name))) AS contact_name,
                d.email AS dealer_email, d.phone AS dealer_phone,
                d.address, d.city, d.tax_number, d.payment_term_days
         FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id WHERE o.id=?",
        [$id]
    );
    if (!$order) { $action = 'list'; $id = 0; }
    elseif (!empty($order['invoice_no']) && empty($order['parasut_invoice_id']) && function_exists('parasut')) {
        try {
            parasut()->linkInvoiceByNumber((int)$order['id'], (string)$order['invoice_no']);
            $order = dbRow(
                "SELECT o.*, d.company_name,
                        COALESCE(NULLIF(d.company_name,''), CONCAT(TRIM(d.first_name),' ',TRIM(d.last_name))) AS contact_name,
                        d.email AS dealer_email, d.phone AS dealer_phone,
                        d.address, d.city, d.tax_number, d.payment_term_days
                 FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id WHERE o.id=?",
                [$id]
            );
        } catch (\Throwable $e) {}
    }
}
$orderItems = [];
if ($order) {
    // delivered_qty kolonu migration_014 ile geldi — eski DB'lerde yoksa fallback
    try {
        $orderItems = dbRows(
            "SELECT oi.id, oi.order_id, oi.product_id, oi.product_name,
                    oi.product_sku, oi.qty, oi.delivered_qty, oi.unit_price,
                    oi.vat_rate, oi.discount_percent, oi.line_total,
                    COALESCE(p.unit, 'adet') AS unit
             FROM b2b_order_items oi
             LEFT JOIN b2b_products p ON p.id=oi.product_id
             WHERE oi.order_id=?",
            [$order['id']]
        );
    } catch (\Throwable $e) {
        // delivered_qty kolonu yok — eski sürüm
        $orderItems = dbRows(
            "SELECT oi.id, oi.order_id, oi.product_id, oi.product_name,
                    oi.product_sku, oi.qty, oi.unit_price,
                    oi.vat_rate, oi.discount_percent, oi.line_total,
                    COALESCE(p.unit, 'adet') AS unit
             FROM b2b_order_items oi
             LEFT JOIN b2b_products p ON p.id=oi.product_id
             WHERE oi.order_id=?",
            [$order['id']]
        );
        // Manuel olarak delivered_qty=null ekle
        foreach ($orderItems as $i => $row) $orderItems[$i]['delivered_qty'] = null;
    }
}

// Liste
if ($action === 'list') {
    $search  = trim($_GET['q'] ?? '');
    $status  = $_GET['status'] ?? '';
    $perPage = 25;
    $page    = max(1, intval($_GET['p'] ?? 1));
    $offset  = ($page-1)*$perPage;

    $where = ['1=1', 'o.is_archived=0']; $params = [];
    if ($search) {
        $where[] = '(o.order_no LIKE ? OR d.company_name LIKE ?)';
        $s = "%$search%"; $params[] = $s; $params[] = $s;
    }
    if ($status)   { $where[] = 'o.status=?'; $params[] = $status; }
    if ($dealerId) { $where[] = 'o.dealer_id=?'; $params[] = $dealerId; }

    $w = implode(' AND ', $where);
    $total  = dbVal("SELECT COUNT(*) FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id WHERE $w", $params);
    $orders = dbRows(
        "SELECT o.*, d.company_name FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id WHERE $w ORDER BY o.created_at DESC LIMIT $perPage OFFSET $offset",
        $params
    );
    $pager  = pagination($total, $perPage, $page, "?page=orders&q=".urlencode($search)."&status=$status&dealer_id=$dealerId&p=");
    $pendingCount  = dbVal("SELECT COUNT(*) FROM b2b_orders WHERE status='bekliyor' AND is_archived=0", []);
    $archiveCount  = dbVal("SELECT COUNT(*) FROM b2b_orders WHERE is_archived=1", []);
    $archivableCount = dbVal("SELECT COUNT(*) FROM b2b_orders WHERE is_archived=0 AND status IN('iptal','teslim_edildi','iade')", []);
    // Filter butonlarındaki sayım için durum bazlı counts (search query'sini de hesaba kat)
    $statusCountsRaw = dbRows(
        "SELECT o.status, COUNT(*) AS c FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id "
        . "WHERE o.is_archived=0 "
        . ($search ? " AND (o.order_no LIKE ? OR d.company_name LIKE ?)" : '')
        . " GROUP BY o.status",
        $search ? ["%$search%", "%$search%"] : []
    );
    $statusCounts = ['_all' => 0];
    foreach ($statusCountsRaw as $r) { $statusCounts[$r['status']] = (int)$r['c']; $statusCounts['_all'] += (int)$r['c']; }
}

if ($action === 'archive_list') {
    $page   = max(1, intval($_GET['p'] ?? 1));
    $perPage = 25;
    $offset  = ($page-1)*$perPage;
    $search  = trim($_GET['q'] ?? '');
    $where = ['o.is_archived=1']; $params = [];
    if ($search) { $where[]='(o.order_no LIKE ? OR d.company_name LIKE ?)'; $s="%$search%"; $params[]=$s; $params[]=$s; }
    $w = implode(' AND ',$where);
    $total        = dbVal("SELECT COUNT(*) FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id WHERE $w",$params);
    $archivedOrders = dbRows("SELECT o.*,d.company_name FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id WHERE $w ORDER BY o.archived_at DESC LIMIT $perPage OFFSET $offset",$params);
    $pager = pagination($total,$perPage,$page,"?page=orders&action=archive_list&q=".urlencode($search)."&p=");
}

$statuses = ['bekliyor','onaylandi','hazirlaniyor','kargoda','teslim_edildi','iptal','iade'];
?>

<?php if ($action === 'list'): ?>
<?php
// Tamamlanmış (teslim/iptal/iade) ama arşivlenmemiş sipariş sayısı
$completedNotArchived = (int)dbVal(
    "SELECT COUNT(*) FROM b2b_orders
     WHERE status IN ('teslim_edildi','iptal','iade') AND is_archived=0"
);
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Siparişler<?php if ($pendingCount): ?> <span class="badge badge-yellow"><?= $pendingCount ?> bekliyor</span><?php endif; ?></h1>
    <p class="page-sub">Toplam <?= $total ?? 0 ?> sipariş</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($completedNotArchived > 0): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('<?= $completedNotArchived ?> tamamlanmış sipariş (Teslim Edildi / İptal / İade) toplu olarak arşive kaldırılacak.\n\nDevam etmek istiyor musunuz?');">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="archive_completed_bulk">
      <button type="submit" class="btn" style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;font-weight:600">
        📦 Tamamlananları Arşivle (<?= $completedNotArchived ?>)
      </button>
    </form>
    <?php endif; ?>
    <a href="?page=orders&action=archive_list" class="btn btn-ghost">
      🗄 Arşiv<?php if ($archiveCount): ?> <span class="badge badge-gray"><?= $archiveCount ?></span><?php endif; ?>
    </a>
  </div>
</div>

<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<!-- Filtre — Pill-style butonlar + arama -->
<div class="card" style="padding:14px 16px;margin-bottom:16px">
  <form method="get" style="display:flex;gap:10px;align-items:center;margin-bottom:12px">
    <input type="hidden" name="page" value="orders">
    <?php if (!empty($status)): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
    <input type="text" name="q" value="<?= h($search ?? '') ?>" class="form-control"
           placeholder="🔍 Sipariş no veya bayi adı ile ara..."
           style="flex:1;min-width:200px;height:38px">
    <button type="submit" class="btn btn-secondary" style="height:38px">Ara</button>
    <?php if (!empty($search)): ?><a href="?page=orders<?= !empty($status) ? '&status='.urlencode($status) : '' ?>" class="btn btn-ghost" style="height:38px">✕ Aramayı Temizle</a><?php endif; ?>
  </form>

  <!-- Status Pill Filtreleri -->
  <?php
  $statusFilters = [
      ''             => ['label'=>'Tümü',           'color'=>'#475569', 'bg'=>'#f1f5f9'],
      'bekliyor'     => ['label'=>'Sipariş Alındı', 'color'=>'#d97706', 'bg'=>'#fef3c7'],
      'onaylandi'    => ['label'=>'Onaylandı',      'color'=>'#0369a1', 'bg'=>'#dbeafe'],
      'hazirlaniyor' => ['label'=>'Hazırlanıyor',   'color'=>'#b45309', 'bg'=>'#fed7aa'],
      'kargoda'      => ['label'=>'Teslimata Çıktı','color'=>'#0e7490', 'bg'=>'#cffafe'],
      'teslim_edildi'=> ['label'=>'Teslim Edildi',  'color'=>'#15803d', 'bg'=>'#d1fae5'],
      'iptal'        => ['label'=>'İptal',          'color'=>'#b91c1c', 'bg'=>'#fee2e2'],
  ];
  ?>
  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <?php foreach ($statusFilters as $sk => $sf):
      $count = $sk === '' ? ($statusCounts['_all'] ?? 0) : ($statusCounts[$sk] ?? 0);
      $active = ($status ?? '') === $sk;
      $url = '?page=orders' . ($search ? '&q='.urlencode($search) : '') . ($sk ? '&status='.urlencode($sk) : '');
    ?>
      <a href="<?= h($url) ?>"
         style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:99px;text-decoration:none;font-size:12px;font-weight:600;transition:.15s;<?php
           if ($active) {
             echo "background:{$sf['color']};color:#fff;border:1px solid {$sf['color']};box-shadow:0 1px 4px rgba(0,0,0,.1)";
           } else {
             echo "background:{$sf['bg']};color:{$sf['color']};border:1px solid transparent";
           }
         ?>">
        <?= h($sf['label']) ?>
        <span style="<?= $active ? 'background:rgba(255,255,255,.25);color:#fff' : "background:#fff;color:{$sf['color']}" ?>;padding:1px 7px;border-radius:99px;font-size:10px;font-weight:700;min-width:18px;text-align:center"><?= $count ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Tablo -->
<div class="card">
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Sipariş No</th><th>Bayi</th><th>Tarih</th><th style="text-align:right">Tutar</th><th style="min-width:170px">Durum</th><th style="min-width:160px">Fatura No</th><th>Ödeme</th><th></th></tr></thead>
  <tbody>
  <?php
  // Durum hızlı geçiş için tüm status'ler ve label'ları
  $quickStatuses = [
      'bekliyor'      => 'Sipariş Alındı',
      'onaylandi'     => 'Onaylandı',
      'hazirlaniyor'  => 'Hazırlanıyor',
      'kargoda'       => 'Teslimata Çıktı',
      'teslim_edildi' => 'Teslim Edildi',
  ];
  ?>
  <?php foreach ($orders as $o): ?>
  <tr style="<?= !empty($o['cancel_requested'])?'background:rgba(245,158,11,.05)':'' ?>">
    <td class="fw-600"><a href="?page=orders&action=detail&id=<?= $o['id'] ?>"><?= h($o['order_no']) ?></a></td>
    <td><?= h($o['company_name']) ?></td>
    <td style="font-size:12px;color:var(--text-muted)"><?= fmtDate($o['created_at']) ?></td>
    <td style="text-align:right;font-weight:600"><?= money($o['grand_total']) ?></td>
    <td>
      <!-- Inline durum güncelleme dropdown (iptal/iade hariç) -->
      <?php if (in_array($o['status'], ['iptal','iade'])): ?>
        <?= orderStatusLabel($o['status']) ?>
      <?php else: ?>
        <form method="post" style="margin:0;display:inline-block">
          <?= csrfField() ?>
          <input type="hidden" name="form_action" value="update_status">
          <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
          <input type="hidden" name="return_to_list" value="1">
          <input type="hidden" name="return_q" value="<?= h($search ?? '') ?>">
          <input type="hidden" name="return_status" value="<?= h($status ?? '') ?>">
          <?php
          $sf = $statusFilters[$o['status']] ?? ['color'=>'#475569', 'bg'=>'#f1f5f9'];
          ?>
          <select name="new_status" onchange="if(confirm('Durumu \''+this.options[this.selectedIndex].text+'\' olarak güncellensin mi?'))this.form.submit();else this.value=this.dataset.orig"
                  data-orig="<?= h($o['status']) ?>"
                  style="padding:5px 28px 5px 10px;border-radius:6px;font-size:12px;font-weight:600;background:<?= $sf['bg'] ?>;color:<?= $sf['color'] ?>;border:1px solid <?= $sf['color'] ?>33;cursor:pointer">
            <?php foreach ($quickStatuses as $sv => $sl): ?>
              <option value="<?= $sv ?>" <?= $o['status']===$sv?'selected':'' ?>><?= h($sl) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
      <?php if (!empty($o['cancel_requested'])): ?>
      <div style="font-size:10px;background:#fffbeb;color:#d97706;border:1px solid #fed7aa;border-radius:4px;padding:1px 6px;margin-top:3px;width:fit-content">⏳ İptal Talebi</div>
      <?php endif; ?>
    </td>
    <td>
      <!-- Fatura No - inline edit -->
      <?php
        $invNo = trim($o['invoice_no'] ?? '');
        $invSrc = $o['invoice_no_source'] ?? '';
        $hasParasut = !empty($o['parasut_invoice_id']);
      ?>
      <?php if ($invNo !== ''): ?>
        <!-- Fatura no DOLU -->
        <div style="display:flex;align-items:center;gap:6px">
          <span class="badge" style="background:<?= $invSrc==='parasut'?'#dcfce7':'#dbeafe' ?>;color:<?= $invSrc==='parasut'?'#15803d':'#1e40af' ?>;font-size:11px;font-weight:700;padding:3px 8px;border-radius:4px;font-family:monospace">
            <?= $invSrc==='parasut'?'📄':'✏️' ?> <?= h($invNo) ?>
          </span>
          <button type="button" onclick="document.getElementById('invForm-<?= (int)$o['id'] ?>').style.display='flex'" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:11px" title="Düzenle">✎</button>
        </div>
      <?php else: ?>
        <!-- Fatura no BOŞ -->
        <div style="display:flex;align-items:center;gap:6px">
          <span style="font-size:10px;color:var(--text-muted);font-style:italic">— Fatura yok —</span>
          <button type="button" onclick="document.getElementById('invForm-<?= (int)$o['id'] ?>').style.display='flex'" style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;cursor:pointer;color:#475569;font-size:10px;padding:2px 6px" title="Manuel ekle">+ Ekle</button>
        </div>
      <?php endif; ?>
      <!-- Inline edit form -->
      <form method="post" id="invForm-<?= (int)$o['id'] ?>" style="display:none;margin-top:6px;gap:4px;align-items:center" onsubmit="return true">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="set_invoice_no">
        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
        <input type="text" name="invoice_no" value="<?= h($invNo) ?>" placeholder="örn: SLS2026000123"
               style="font-family:monospace;font-size:11px;padding:3px 6px;border:1px solid #cbd5e1;border-radius:4px;width:130px">
        <button type="submit" style="background:#16a34a;color:#fff;border:none;border-radius:4px;font-size:10px;padding:3px 8px;cursor:pointer">✓</button>
        <button type="button" onclick="this.form.style.display='none'" style="background:#e5e7eb;color:#475569;border:none;border-radius:4px;font-size:10px;padding:3px 6px;cursor:pointer">✕</button>
      </form>
      <?php if (!empty($o['invoice_no_updated_at'])): ?>
      <div style="font-size:9px;color:var(--text-muted);margin-top:2px">
        <?= date('d.m.Y H:i', strtotime($o['invoice_no_updated_at'])) ?>
        <?= $invSrc==='parasut'?'· Paraşüt':'· Manuel' ?>
      </div>
      <?php endif; ?>
    </td>
    <td>
      <?php $ps = $o['payment_status'] ?? 'odenmedi';
      $pstyle = match($ps) { 'odendi'=>'success', 'kismi_odeme'=>'warning', default=>'neutral' };
      $plabel = match($ps) { 'odendi'=>'Ödendi', 'kismi_odeme'=>'Kısmen', default=>'Bekliyor' };
      ?>
      <span class="badge badge-<?= $pstyle ?>"><?= $plabel ?></span>
      <div style="font-size:10px;color:var(--text-muted);margin-top:2px"><?= h(paymentMethodLabel($o['payment_method'] ?? '')) ?></div>
    </td>
    <td style="white-space:nowrap">
      <a href="?page=orders&action=detail&id=<?= $o['id'] ?>" class="btn btn-ghost btn-sm">Detay →</a>
      <?php if (in_array($o['status'], ['teslim_edildi','iptal','iade'])): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Bu siparişi arşive kaldırmak istediğinize emin misiniz?');">
          <?= csrfField() ?>
          <input type="hidden" name="form_action" value="archive">
          <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
          <button type="submit" class="btn btn-sm" style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;font-size:11px;padding:4px 8px" title="Arşivle">📦</button>
        </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (empty($orders)): ?><tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-muted)">Sipariş bulunamadı.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
</div>
<?php if (!empty($pager)): ?><div style="margin-top:16px"><?= $pager ?></div><?php endif; ?>

<?php elseif ($action === 'archive_list'): ?>
<div class="page-header">
  <div>
    <h1 class="page-title">Arşiv</h1>
    <div style="font-size:12px;color:var(--text-muted);margin-top:4px">
      Toplam <strong><?= (int)($total ?? 0) ?></strong> arşivli sipariş
      <?php if ((int)($total ?? 0) > 0): ?>
       · Sayfa <strong><?= (int)$page ?>/<?= (int)ceil(($total ?? 0)/$perPage) ?></strong>
      <?php endif; ?>
    </div>
  </div>
  <a href="?page=orders" class="btn btn-ghost">← Aktif Siparişler</a>
</div>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>

<!-- Arama kutusu -->
<form method="get" style="margin-bottom:12px">
  <input type="hidden" name="page" value="orders">
  <input type="hidden" name="action" value="archive_list">
  <div style="display:flex;gap:8px;align-items:center">
    <input type="search" name="q" value="<?= h($search ?? '') ?>" placeholder="🔎 Sipariş no veya bayi adı ile ara..." class="form-control" style="flex:1;font-size:13px">
    <button type="submit" class="btn btn-primary btn-sm" style="height:38px;padding:0 18px">Ara</button>
    <?php if (!empty($search)): ?>
      <a href="?page=orders&action=archive_list" class="btn btn-ghost btn-sm" style="height:38px;padding:0 14px">✕ Temizle</a>
    <?php endif; ?>
  </div>
</form>

<div class="card">
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Sipariş No</th><th>Bayi</th><th>Tarih</th><th style="text-align:right">Tutar</th><th>Durum</th><th>Fatura No</th><th>Arşivlenme</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($archivedOrders ?? [] as $o): ?>
  <tr>
    <td class="fw-600"><a href="?page=orders&action=detail&id=<?= $o['id'] ?>"><?= h($o['order_no']) ?></a></td>
    <td><?= h($o['company_name']) ?></td>
    <td style="font-size:12px;color:var(--text-muted)"><?= fmtDate($o['created_at']) ?></td>
    <td style="text-align:right;font-weight:600"><?= money($o['grand_total']) ?></td>
    <td><?= orderStatusLabel($o['status']) ?></td>
    <td>
      <?php $invNo = trim($o['invoice_no'] ?? ''); ?>
      <?php if ($invNo !== ''): ?>
        <span style="font-family:monospace;font-size:11px;background:#f0fdf4;color:#15803d;padding:2px 6px;border-radius:4px;font-weight:600"><?= h($invNo) ?></span>
      <?php else: ?>
        <span style="font-size:10px;color:var(--text-muted);font-style:italic">—</span>
      <?php endif; ?>
    </td>
    <td style="font-size:11px;color:var(--text-muted)">
      <?= $o['archived_at'] ? date('d.m.Y', strtotime($o['archived_at'])) : '—' ?>
    </td>
    <td style="white-space:nowrap">
      <a href="?page=orders&action=detail&id=<?= $o['id'] ?>" class="btn btn-ghost btn-sm">Detay →</a>
      <form method="post" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="unarchive">
        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
        <button type="submit" class="btn btn-sm" style="background:#fff;color:#0e7490;border:1px solid #67e8f9;font-size:11px;padding:4px 8px" title="Arşivden Çıkar">📤</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (empty($archivedOrders)): ?><tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-muted)">Arşiv boş.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<!-- Pagination -->
<?php if (!empty($pager) && ($total ?? 0) > $perPage): ?>
<div style="padding:12px 16px;border-top:1px solid var(--border);background:#f8fafc">
  <?= $pager ?>
</div>
<?php endif; ?>
</div>

<?php elseif ($action === 'detail' && $order): ?>

<?php if (!empty($success)): ?>
<?php $isApprove = str_contains($success, 'onaylandı'); ?>
<div style="background:<?= $isApprove ? 'linear-gradient(135deg,#f0fdf4,#dcfce7)' : 'linear-gradient(135deg,#eff6ff,#dbeafe)' ?>;border:1px solid <?= $isApprove ? '#86efac' : '#93c5fd' ?>;border-radius:10px;padding:18px 24px;margin-bottom:20px;display:flex;align-items:center;gap:16px">
  <span style="font-size:28px"><?= $isApprove ? '✅' : 'ℹ️' ?></span>
  <div style="flex:1;font-size:14px;font-weight:600;color:<?= $isApprove ? '#15803d' : '#1d4ed8' ?>"><?= h($success) ?></div>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger" style="margin-bottom:16px"><?= h($error) ?></div><?php endif; ?>

<!-- Sipariş Detayı Başlık -->
<div class="page-header">
  <div>
    <h1 class="page-title"><?= h($order['order_no']) ?></h1>
    <p class="page-sub"><?= h($order['company_name']) ?> — <?= fmtDate($order['created_at']) ?></p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="?page=orders" class="btn btn-ghost">← Geri</a>
    <a href="?page=orders&action=detail&id=<?= (int)$order['id'] ?>&print=irsaliye" target="_blank" class="btn btn-secondary" style="background:#1f2937;border-color:#1f2937;color:#fff">🖨 İrsaliye Yazdır</a>
    <?php if ($order['status'] === 'bekliyor'): ?>
    <button class="btn btn-success" onclick="openModal('modal-approve')">✓ Onayla</button>
    <button class="btn btn-danger" onclick="openModal('modal-cancel')">✕ İptal</button>
    <?php elseif (in_array($order['status'], ['onaylandi','hazirlaniyor'])): ?>
    <button class="btn btn-secondary" onclick="openModal('modal-status')">Durumu Güncelle</button>
    <button class="btn btn-danger" onclick="openModal('modal-cancel')">✕ İptal</button>
    <?php elseif ($order['status'] === 'iptal'): ?>
    <button class="btn btn-warning" onclick="openModal('modal-reactivate')" style="background:#f59e0b;border-color:#f59e0b;color:#fff">🔄 Yeniden İşleme Al</button>
    <?php endif; ?>

    <?php
    // Arşivle / Arşivden Çıkar — sadece tamamlanmış (teslim/iptal/iade) siparişlerde göster
    $isCompleted = in_array($order['status'], ['teslim_edildi','iptal','iade']);
    $isArchived  = !empty($order['is_archived']);
    $payStatus   = $order['payment_status'] ?? 'odenmedi';
    ?>

    <?php // Ödeme hızlı işaretleme — henüz ödenmediyse ?>
    <?php if (!in_array($payStatus, ['odendi']) && $order['status'] !== 'iptal'): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Bu siparişi ÖDENDİ olarak işaretlemek istediğinize emin misiniz?\n\nDikkat: Bu sadece sipariş durumunu işaretler. Cari hesaba alacak kaydı OLUŞTURULMAZ. Cari hareketi için Tahsilat sayfasından ödeme kaydı eklemelisiniz.');">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="mark_paid">
        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
        <button type="submit" class="btn" style="background:#dcfce7;color:#15803d;border:1px solid #86efac;font-weight:600">💰 Ödendi İşaretle</button>
      </form>
    <?php endif; ?>

    <?php if ($isCompleted && !$isArchived): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Bu siparişi arşive kaldırmak istediğinize emin misiniz?\n\nAna listeden gizlenecek ama silinmeyecek. İstediğin zaman Arşiv menüsünden geri çıkarabilirsiniz.');">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="archive">
        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
        <button type="submit" class="btn" style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;font-weight:600">📦 Arşivle</button>
      </form>
    <?php elseif ($isArchived): ?>
      <form method="post" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="unarchive">
        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
        <button type="submit" class="btn" style="background:#fff;color:#0e7490;border:1px solid #67e8f9;font-weight:600">📤 Arşivden Çıkar</button>
      </form>
    <?php endif; ?>

    <button class="btn btn-danger" onclick="openModal('modal-delete-order')">🗑 Sil</button>
  </div>
</div>

<?php if (!empty($success)): ?><div class="alert alert-success" style="margin-bottom:16px"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-danger"  style="margin-bottom:16px"><?= h($error) ?></div><?php endif; ?>

<!-- İptal Talebi Paneli -->
<?php if (!empty($order['cancel_requested']) && $order['status'] !== 'iptal'): ?>
<div style="background:#fffbeb;border:2px solid #f59e0b;border-radius:10px;padding:16px 20px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
  <div>
    <div style="font-weight:700;font-size:15px;color:#92400e;margin-bottom:6px">⚠️ Bayi Sipariş İptali Talep Etti</div>
    <div style="font-size:13px;color:#78350f"><strong>Sebep:</strong> <?= h($order['cancel_reason'] ?? '—') ?></div>
    <?php if ($order['cancel_requested_at'] ?? ''): ?>
    <div style="font-size:12px;color:#a16207;margin-top:4px">Talep tarihi: <?= date('d.m.Y H:i', strtotime($order['cancel_requested_at'])) ?></div>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:8px;flex-shrink:0">
    <form method="post"><<?= csrfField() ?><input type="hidden" name="form_action" value="approve_cancel"><input type="hidden" name="order_id" value="<?= $order['id'] ?>">
      <button class="btn btn-danger" onclick="return confirm('İptal edilecek, stoklar geri yüklenecek. Onaylıyor musunuz?')">✓ İptali Onayla</button>
    </form>
    <form method="post"><?= csrfField() ?><input type="hidden" name="form_action" value="reject_cancel"><input type="hidden" name="order_id" value="<?= $order['id'] ?>">
      <button class="btn btn-secondary">✗ Reddet</button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Özet -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px">
  <div class="stat-card"><div class="stat-label">Durum</div><div class="stat-value" style="font-size:16px"><?= orderStatusLabel($order['status']) ?></div></div>
  <div class="stat-card"><div class="stat-label">Toplam Tutar</div><div class="stat-value"><?= money($order['grand_total']) ?></div></div>
  <div class="stat-card"><div class="stat-label">Vade Tarihi</div><div class="stat-value" style="font-size:16px"><?= $order['due_date'] ? fmtDate($order['due_date']) : '—' ?></div></div>
</div>

<!-- 2 kolon -->
<div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start">

<!-- Sol -->
<div>
  <!-- Bayi + Sipariş Bilgisi -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Bayi Bilgisi</h3></div>
      <div class="card-body" style="font-size:13px">
        <?php $d = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$order['dealer_id']]); ?>
        <div class="fw-600"><?= h($d['company_name'] ?? '') ?></div>
        <div style="color:var(--text-muted);margin-top:4px"><?= h($d['email'] ?? '') ?></div>
        <div><?= h($d['phone'] ?? '') ?></div>
        <div style="margin-top:6px;font-size:12px;color:var(--text-muted)"><?= h(($d['address']??'').', '.($d['city']??'')) ?></div>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3 class="card-title">Sipariş Bilgisi</h3></div>
      <div class="card-body" style="font-size:13px">
        <div style="margin-bottom:6px"><span style="color:var(--text-muted)">No:</span> <strong><?= h($order['order_no']) ?></strong></div>
        <div style="margin-bottom:6px"><span style="color:var(--text-muted)">Ödeme:</span> <?= h(paymentMethodLabel($order['payment_method'] ?? '')) ?></div>
        <?php if ($order['notes'] ?? ''): ?><div style="font-size:12px;color:var(--text-muted);margin-top:6px"><?= nl2br(h($order['notes'])) ?></div><?php endif; ?>
        <?php if ($order['cancel_reason'] ?? ''): ?><div style="margin-top:6px;color:var(--danger);font-size:12px">İptal: <?= h($order['cancel_reason']) ?></div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Sipariş Kalemleri -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <h3 class="card-title" style="margin:0">Sipariş Kalemleri</h3>
      <span style="font-size:11px;color:var(--text-muted)">Teslim miktarlarını güncelleyebilirsiniz</span>
    </div>
    <form method="post" id="delivered-qty-form">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="update_delivered_qty">
      <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
    <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Ürün</th>
          <th style="text-align:center;width:80px">Sipariş<br><span style="font-weight:400;font-size:10px;color:var(--text-muted)">Miktarı</span></th>
          <th style="text-align:center;width:120px">Teslim<br><span style="font-weight:400;font-size:10px;color:var(--text-muted)">Miktarı</span></th>
          <th style="text-align:right">Birim<br><span style="font-weight:400;font-size:10px;color:var(--text-muted)">(KDV Dahil)</span></th>
          <th style="text-align:right">KDV%</th>
          <th style="text-align:right">Toplam<br><span style="font-weight:400;font-size:10px;color:var(--text-muted)">(KDV Dahil)</span></th>
        </tr>
      </thead>
      <tbody>
      <?php $sub=0; $vat=0; $hasShortDelivery=false; foreach ($orderItems as $it):
        $qty    = (int)($it['qty'] ?? $it['quantity'] ?? 0);
        $unit   = (float)($it['unit_price'] ?? 0);
        $vatr   = (float)($it['vat_rate'] ?? $it['tax_rate'] ?? 0);
        $unitGross = $unit * (1 + $vatr/100);
        $lineNet= $unit * $qty;
        $lineTax= $lineNet * ($vatr/100);
        $sub += $lineNet; $vat += $lineTax;
        $deliveredQty = $it['delivered_qty'] ?? null;
        $isShort = $deliveredQty !== null && (float)$deliveredQty < $qty;
        if ($isShort) $hasShortDelivery = true;
      ?>
      <tr<?= $isShort ? ' style="background:rgba(245,158,11,.05)"' : '' ?>>
        <td><div class="fw-600" style="font-size:13px"><?= h($it['product_name']) ?></div><?php if ($it['product_sku']??''): ?><div style="font-size:11px;color:var(--text-muted)"><?= h($it['product_sku']) ?></div><?php endif; ?></td>
        <td style="text-align:center;font-weight:600;font-size:14px"><?= $qty ?></td>
        <td style="text-align:center">
          <input type="number" name="delivered_qty[<?= (int)$it['id'] ?>]"
                 value="<?= $deliveredQty !== null ? h($deliveredQty) : '' ?>"
                 step="0.01" min="0" max="<?= $qty ?>"
                 placeholder="<?= $qty ?>"
                 style="width:80px;padding:5px 8px;border:1px solid <?= $isShort ? '#f59e0b' : 'var(--border-2)' ?>;border-radius:6px;text-align:center;font-size:13px;font-weight:600;<?= $isShort ? 'background:#fffbeb;color:#b45309' : '' ?>">
          <?php if ($isShort): ?>
          <div style="font-size:9px;color:#d97706;font-weight:700;margin-top:2px">EKSİK (-<?= ($qty - (float)$deliveredQty) ?>)</div>
          <?php endif; ?>
        </td>
        <td style="text-align:right;font-size:13px"><?= money($unitGross) ?></td>
        <td style="text-align:right;font-size:12px;color:var(--text-muted)">%<?= (int)$vatr ?></td>
        <td style="text-align:right;font-weight:700"><?= money($lineNet+$lineTax) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:var(--bg)"><td colspan="5" style="text-align:right;padding:8px 16px;font-size:13px;color:var(--text-2)">Ara Toplam</td><td style="text-align:right;padding:8px 16px"><?= money($sub) ?></td></tr>
        <tr style="background:var(--bg)"><td colspan="5" style="text-align:right;padding:6px 16px;font-size:13px;color:var(--text-2)">KDV</td><td style="text-align:right;padding:6px 16px;font-size:13px"><?= money($vat) ?></td></tr>
        <tr style="background:var(--bg);border-top:2px solid var(--border)"><td colspan="5" style="text-align:right;font-weight:700;font-size:15px;padding:12px 16px">Genel Toplam</td><td style="text-align:right;font-weight:800;font-size:16px;color:var(--red);padding:12px 16px"><?= money((float)$order['grand_total']) ?></td></tr>
      </tfoot>
    </table>
    </div>
    <div style="padding:10px 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between;border-top:1px solid var(--border)">
      <div style="font-size:11px;color:var(--text-muted)">
        💡 Teslim miktarı boş bırakılırsa "henüz teslim edilmedi" sayılır. Sipariş miktarına eşit ise tam teslim, küçükse eksik teslim.
      </div>
      <button type="submit" class="btn btn-primary btn-sm">💾 Teslim Miktarlarını Kaydet</button>
    </div>
    </form>
  </div>

  <!-- Admin Notu -->
  <div class="card">
    <div class="card-header"><h3 class="card-title">Admin Notu</h3></div>
    <div class="card-body">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="add_note">
        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
        <textarea name="admin_note" class="form-control" rows="3" placeholder="Not ekle..."><?= h($order['admin_note'] ?? '') ?></textarea>
        <div style="margin-top:8px"><button class="btn btn-secondary btn-sm">Notu Kaydet</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Sağ: Kargo + Durum -->
<div>
  <?php if (!empty($order['cargo_company'])): ?>
  <div class="card" style="margin-bottom:12px">
    <div class="card-header"><h3 class="card-title">🚚 Kargo</h3></div>
    <div class="card-body" style="font-size:13px">
      <div class="fw-600"><?= h($order['cargo_company']) ?></div>
      <?php if ($order['tracking_number']??''): ?><div style="margin-top:4px;color:var(--text-muted)">Takip: <strong style="color:var(--text)"><?= h($order['tracking_number']) ?></strong></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Ödeme Geçmişi -->
  <?php $pmts = dbRows("SELECT * FROM b2b_payments WHERE order_id=? ORDER BY created_at DESC", [$order['id']]); ?>
  <?php if (!empty($pmts)): ?>
  <div class="card" style="margin-bottom:12px">
    <div class="card-header"><h3 class="card-title">Ödemeler</h3></div>
    <div class="card-body" style="padding:0">
      <?php foreach ($pmts as $pay): $ps=$pay['status']??'bekliyor'; ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid var(--border)">
        <div><div style="font-size:13px;font-weight:600"><?= money((float)$pay['amount']) ?></div><div style="font-size:11px;color:var(--text-muted)"><?= fmtDate($pay['created_at']) ?></div></div>
        <span class="badge badge-<?= $ps==='onaylandi'?'success':($ps==='reddedildi'?'danger':'warning') ?>"><?= ['onaylandi'=>'Onaylandı','reddedildi'=>'Reddedildi','bekliyor'=>'Bekliyor'][$ps]??$ps ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ─── PARAŞÜT FATURA KARTI ─── -->
  <?php if (parasut()->isEnabled()): ?>
  <?php
    // Paraşüt durumu
    $pInvoiceId    = $order['parasut_invoice_id']      ?? null;
    $pEinvoiceId   = $order['parasut_einvoice_id']     ?? null;
    $pEinvoiceType = $order['parasut_einvoice_type']   ?? null;
    $pPdfUrl       = $order['parasut_invoice_pdf_url'] ?? null;
    $pStatus       = $order['parasut_invoice_status']  ?? null;
    $pSyncedAt     = $order['parasut_synced_at']       ?? null;

    $statusLabels = [
      'paid'           => ['Ödenmiş', '#16a34a', '#dcfce7'],
      'unpaid'         => ['Ödenmemiş', '#dc2626', '#fee2e2'],
      'partially_paid' => ['Kısmi Ödendi', '#ea580c', '#ffedd5'],
      'overdue'        => ['Vadesi Geçmiş', '#dc2626', '#fee2e2'],
      'cancelled'      => ['İptal', '#6b7280', '#f3f4f6'],
      'draft'          => ['Taslak', '#6b7280', '#f3f4f6'],
    ];
    $stat = $statusLabels[$pStatus] ?? null;
  ?>
  <!-- ─── Görünür Fatura Numarası Kartı ─── -->
  <?php
    $invNo = trim($order['invoice_no'] ?? '');
    $invSrc = $order['invoice_no_source'] ?? '';
    $invUpdAt = $order['invoice_no_updated_at'] ?? null;
  ?>
  <div class="card" style="margin-bottom:12px">
    <div class="card-header" style="background:linear-gradient(135deg,<?= $invNo!=='' ? '#f0fdf4,#dcfce7' : '#fef3c7,#fde68a' ?>)">
      <h3 class="card-title" style="display:flex;align-items:center;gap:8px;color:<?= $invNo!=='' ? '#15803d' : '#92400e' ?>">
        <span><?= $invNo!=='' ? '✓' : '📝' ?></span> Fatura Numarası
      </h3>
    </div>
    <div class="card-body">
      <?php if ($invNo !== ''): ?>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px">
          <div style="font-family:monospace;font-size:18px;font-weight:700;color:#15803d;background:#f0fdf4;padding:8px 14px;border:1px solid #86efac;border-radius:8px;letter-spacing:.5px">
            <?= h($invNo) ?>
          </div>
          <span class="badge" style="background:<?= $invSrc==='parasut'?'#ddd6fe':'#dbeafe' ?>;color:<?= $invSrc==='parasut'?'#5b21b6':'#1e40af' ?>;font-size:10px;font-weight:700;padding:4px 8px">
            <?= $invSrc==='parasut' ? '📄 Paraşüt' : '✏️ Manuel' ?>
          </span>
          <?php if ($invUpdAt): ?>
          <span style="font-size:11px;color:var(--text-muted)">
            <?= date('d.m.Y H:i', strtotime($invUpdAt)) ?>
          </span>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div style="font-size:12px;color:#92400e;margin-bottom:12px;background:#fffbeb;padding:8px 12px;border-radius:6px">
          Henüz fatura numarası girilmedi. Aşağıdan manuel girebilir veya Paraşüt'ten otomatik fatura kestirebilirsiniz.
        </div>
      <?php endif; ?>

      <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="set_invoice_no">
        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
        <input type="hidden" name="return_detail" value="1">
        <input type="text" name="invoice_no" value="<?= h($invNo) ?>" placeholder="örn: SLS2026000123"
               style="font-family:monospace;font-size:13px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;flex:1;min-width:200px;font-weight:600">
        <button type="submit" class="btn btn-sm btn-primary" style="height:36px;padding:0 16px">
          💾 <?= $invNo!==''?'Güncelle':'Kaydet' ?>
        </button>
        <?php if ($invNo !== ''): ?>
        <button type="submit" name="invoice_no" value="" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;height:36px;padding:0 12px"
                onclick="return confirm('Fatura numarası silinsin mi?')">
          🗑 Sil
        </button>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <div class="card" style="margin-bottom:12px">
    <div class="card-header" style="background:linear-gradient(135deg,#f0fdf4,#dcfce7)">
      <h3 class="card-title" style="display:flex;align-items:center;gap:8px;color:#15803d">
        <span>📑</span> Paraşüt Faturası
      </h3>
    </div>
    <div class="card-body">
      <?php if ($pInvoiceId): ?>
        <!-- Fatura var -->
        <div style="display:grid;gap:8px;font-size:12px;margin-bottom:12px">
          <div style="display:flex;justify-content:space-between;border-bottom:1px solid #eee;padding-bottom:6px">
            <span style="color:var(--text-muted)">Sales Invoice ID</span>
            <span style="font-family:monospace;font-weight:600"><?= h($pInvoiceId) ?></span>
          </div>
          <?php if ($pEinvoiceId): ?>
          <div style="display:flex;justify-content:space-between;border-bottom:1px solid #eee;padding-bottom:6px">
            <span style="color:var(--text-muted)">Resmi Belge</span>
            <span>
              <span class="badge" style="background:<?= $pEinvoiceType==='e_invoice'?'#ddd6fe':'#fef3c7' ?>;color:<?= $pEinvoiceType==='e_invoice'?'#5b21b6':'#92400e' ?>;font-size:10px;font-weight:700">
                <?= $pEinvoiceType==='e_invoice' ? 'E-FATURA' : 'E-ARŞİV' ?>
              </span>
              <span style="font-family:monospace;font-size:11px;margin-left:6px"><?= h($pEinvoiceId) ?></span>
            </span>
          </div>
          <?php else: ?>
          <div style="display:flex;justify-content:space-between;border-bottom:1px solid #eee;padding-bottom:6px">
            <span style="color:var(--text-muted)">Resmi Belge</span>
            <span style="color:#dc2626;font-weight:600">Henüz Yok</span>
          </div>
          <?php endif; ?>
          <?php if ($stat): ?>
          <div style="display:flex;justify-content:space-between;border-bottom:1px solid #eee;padding-bottom:6px">
            <span style="color:var(--text-muted)">Durum</span>
            <span class="badge" style="background:<?= $stat[2] ?>;color:<?= $stat[1] ?>;font-size:10px;font-weight:700"><?= $stat[0] ?></span>
          </div>
          <?php endif; ?>
          <?php if ($pSyncedAt): ?>
          <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-muted)">
            <span>Son senkron</span>
            <span><?= date('d.m.Y H:i', strtotime($pSyncedAt)) ?></span>
          </div>
          <?php endif; ?>
        </div>

        <!-- Aksiyon butonları -->
        <div style="display:grid;gap:6px">
          <?php if ($pPdfUrl): ?>
          <a href="<?= h($pPdfUrl) ?>" target="_blank" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;width:100%">
            📄 Faturayı PDF İndir
          </a>
          <?php endif; ?>

          <?php if (empty($order['is_archived'])): ?>
          <form method="post" onsubmit="return confirm('Bu sipariş arşive kaldırılacak. Ana listeden gizlenir, Arşiv menüsünden geri çıkarılabilir.');">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="archive">
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <button type="submit" class="btn btn-sm" style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;width:100%">
              📦 Arşiv'e Kaldır
            </button>
          </form>
          <?php endif; ?>

          <?php if (!$pEinvoiceId): ?>
          <form method="post" onsubmit="return confirm('Bu siparişin faturası e-arşiv veya e-fatura olarak Paraşüt''te resmileştirilecek.\n\nDevam edilsin mi?');">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="parasut_full_flow">
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <button type="submit" class="btn btn-sm" style="background:#7c3aed;color:#fff;border:none;width:100%">
              ⚡ Resmileştir (E-Arşiv/E-Fatura)
            </button>
          </form>
          <?php endif; ?>

          <div style="display:flex;gap:6px">
            <form method="post" style="flex:1">
              <?= csrfField() ?>
              <input type="hidden" name="form_action" value="parasut_sync_status">
              <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
              <button type="submit" class="btn btn-sm btn-secondary" style="width:100%;font-size:11px">🔄 Durumu Çek</button>
            </form>
            <?php if ($pInvoiceId): ?>
            <form method="post" style="flex:1">
              <?= csrfField() ?>
              <input type="hidden" name="form_action" value="parasut_refresh_pdf">
              <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
              <button type="submit" class="btn btn-sm btn-secondary" style="width:100%;font-size:11px">🔄 PDF Yenile</button>
            </form>
            <?php endif; ?>
          </div>

          <?php if ($pStatus !== 'cancelled' && $pInvoiceId): ?>
          <form method="post" onsubmit="return confirm('⚠️ Paraşüt''teki fatura İPTAL edilecek. Bu işlem muhasebede iz bırakır.\n\nDevam edilsin mi?');" style="margin-top:6px">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="parasut_cancel_invoice">
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <button type="submit" class="btn btn-sm" style="background:transparent;color:#dc2626;border:1px solid #fecaca;width:100%;font-size:11px">
              🗑 Paraşüt'te İptal Et
            </button>
          </form>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <!-- Fatura yok -->
        <div style="text-align:center;padding:14px 0">
          <div style="font-size:13px;color:var(--text-muted);margin-bottom:10px">
            Bu sipariş için Paraşüt'te henüz fatura kesilmedi.
          </div>
          <?php if ($invNo !== ''): ?>
          <div style="font-size:12px;color:#166534;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:8px 10px;margin-bottom:10px;text-align:left">
            Manuel fatura numarası var: <strong><?= h($invNo) ?></strong>. Paraşüt'te bu numarayla kesilmiş eski faturayı bulup PDF'i bağlayabilirsiniz.
          </div>
          <form method="post" style="margin-bottom:8px">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="parasut_link_invoice_no">
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <button type="submit" class="btn" style="width:100%;background:#16a34a;color:#fff;border:none;font-weight:700">
              🔎 Manuel No ile Paraşüt'te Bul + PDF Bağla
            </button>
          </form>
          <?php endif; ?>
          <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="parasut_full_flow">
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <button type="submit" class="btn btn-primary" style="width:100%">
              ⚡ Paraşüt'te Fatura Oluştur + Resmileştir
            </button>
          </form>
          <form method="post" style="margin-top:8px" onsubmit="return confirm('Fatura kesilecek ve işlem başarılı olursa sipariş arşive kaldırılacak. Devam edilsin mi?');">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="parasut_full_flow_archive">
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <button type="submit" class="btn" style="width:100%;background:#fef3c7;color:#92400e;border:1px solid #fcd34d;font-weight:700">
              📦 Faturayı Kes ve Arşiv'e Kaldır
            </button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

</div><!-- /grid -->

<!-- Modaller -->
<div id="modal-approve" class="modal-overlay">
<div class="modal">
  <div class="modal-header">✓ Siparişi Onayla</div>
  <div class="modal-body"><p><strong><?= h($order['order_no']) ?></strong> onaylanacak ve stok düşülecek.</p></div>
  <div class="modal-footer">
    <button class="btn btn-ghost" onclick="closeModal('modal-approve')">Vazgeç</button>
    <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="form_action" value="approve"><input type="hidden" name="order_id" value="<?= $order['id'] ?>">
      <button class="btn btn-success">✓ Onayla</button>
    </form>
  </div>
</div>
</div>

<div id="modal-cancel" class="modal-overlay">
<div class="modal">
  <div class="modal-header">✕ Siparişi İptal Et</div>
  <div class="modal-body">
    <form method="post" id="form-cancel"><?= csrfField() ?><input type="hidden" name="form_action" value="cancel"><input type="hidden" name="order_id" value="<?= $order['id'] ?>">
      <div class="form-group"><label class="form-label">İptal Sebebi</label><textarea name="cancel_reason" class="form-control" rows="3"></textarea></div>
    </form>
  </div>
  <div class="modal-footer">
    <button class="btn btn-ghost" onclick="closeModal('modal-cancel')">Vazgeç</button>
    <button class="btn btn-danger" form="form-cancel" type="submit">İptal Et</button>
  </div>
</div>
</div>

<div id="modal-status" class="modal-overlay">
<div class="modal">
  <div class="modal-header">Durumu Güncelle</div>
  <div class="modal-body">
    <form method="post" id="form-status"><?= csrfField() ?><input type="hidden" name="form_action" value="update_status"><input type="hidden" name="order_id" value="<?= $order['id'] ?>">
      <div class="form-group"><label class="form-label">Yeni Durum</label>
        <select name="new_status" class="form-control">
          <?php foreach (['bekliyor'=>'Sipariş Alındı','onaylandi'=>'Onaylandı','hazirlaniyor'=>'Hazırlanıyor','kargoda'=>'Teslimata Çıktı','teslim_edildi'=>'Teslim Edildi'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= ($order['status']??'')===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Kargo Firması</label><input type="text" name="cargo_company" class="form-control" value="<?= h($order['cargo_company']??'') ?>"></div>
      <div class="form-group"><label class="form-label">Takip No</label><input type="text" name="tracking_number" class="form-control" value="<?= h($order['tracking_number']??'') ?>"></div>
    </form>
  </div>
  <div class="modal-footer">
    <button class="btn btn-ghost" onclick="closeModal('modal-status')">Vazgeç</button>
    <button class="btn btn-primary" form="form-status" type="submit">Güncelle</button>
  </div>
</div>
</div>

<div id="modal-reactivate" class="modal-overlay">
<div class="modal">
  <div class="modal-header">🔄 Yeniden İşleme Al</div>
  <div class="modal-body"><p><?= h($order['order_no']) ?> "Bekliyor" durumuna alınacak.</p></div>
  <div class="modal-footer">
    <button class="btn btn-ghost" onclick="closeModal('modal-reactivate')">Vazgeç</button>
    <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="form_action" value="reactivate"><input type="hidden" name="order_id" value="<?= $order['id'] ?>">
      <button class="btn btn-primary" style="background:#f59e0b;border-color:#f59e0b">🔄 Yeniden İşleme Al</button>
    </form>
  </div>
</div>
</div>

<div id="modal-delete-order" class="modal-overlay">
<div class="modal">
  <div class="modal-header">🗑 Siparişi Sil</div>
  <div class="modal-body"><p><strong><?= h($order['order_no']) ?></strong> kalıcı olarak silinecek. Bu işlem geri alınamaz.</p></div>
  <div class="modal-footer">
    <button class="btn btn-ghost" onclick="closeModal('modal-delete-order')">Vazgeç</button>
    <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="form_action" value="delete_order"><input type="hidden" name="order_id" value="<?= $order['id'] ?>">
      <button class="btn btn-danger">Evet, Sil</button>
    </form>
  </div>
</div>
</div>

<?php endif; ?>

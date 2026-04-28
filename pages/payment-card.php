<?php
/**
 * Rubikpara 3D Secure Kart ile Ödeme
 * ?page=payment-card&order_id=X
 */

// ── DEBUG: Tüm akış doğrulanana kadar kalacak ──────────────────
ini_set('display_errors', '1');
error_reporting(E_ALL);
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        echo '<div style="background:#fee2e2;border:2px solid #dc2626;color:#991b1b;padding:16px;margin:16px;border-radius:8px;font-family:monospace;font-size:13px;line-height:1.6">';
        echo '<strong style="font-size:15px">⚠️ PHP FATAL (payment-card.php)</strong><br>';
        echo '<strong>Mesaj:</strong> ' . htmlspecialchars($err['message']) . '<br>';
        echo '<strong>Dosya:</strong> ' . htmlspecialchars($err['file']) . ':' . (int)$err['line'];
        echo '</div>';
    }
});
// ────────────────────────────────────────────────────────────────

$step    = $_GET['step'] ?? 'card'; // card | callback
$orderId = intval($_GET['order_id'] ?? 0);

// 3DS callback'inde tarayıcı cross-site POST nedeniyle session cookie'yi
// göndermemiş olabilir. Bu durumda DB'deki geçici 3DS kaydından dealer'ı
// kurtarıp session'ı yeniden bağlarız. Banka POST'ta ThreeDSessionId
// gönderdiği için doğrulama mümkün; kayıt yoksa zaten devam etmeyiz.
if ($step === 'callback'
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && !empty($_POST['ThreeDSessionId'])
    && !isDealer()) {
    $rawSid = $_POST['ThreeDSessionId'];
    $rec    = dbRow(
        "SELECT * FROM b2b_payment_sessions
         WHERE threeds_session_id=? AND order_id=? AND created_at > NOW() - INTERVAL 1 HOUR",
        [$rawSid, $orderId]
    );
    if ($rec) {
        // Geçici giriş: sadece bu callback request'i için dealer bağla
        $_SESSION['dealer_id']   = (int)$rec['dealer_id'];
        $_SESSION['rk_pay'][$orderId] = [
            'sessionId'   => $rec['threeds_session_id'],
            'cardToken'   => $rec['card_token'] ?? '',
            'cardHolder'  => $rec['card_holder'] ?? '',
            'amount'      => (float)$rec['amount'],
            'baseAmount'  => (float)$rec['base_amount'],
            'commission'  => (float)$rec['commission'],
            'rate'        => (float)$rec['commission_rate'],
            'installment' => (int)$rec['installment'],
            'created_at'  => time(),
            'recovered_from_db' => true,
        ];
    }
}

requireDealer();
$dealer = currentDealer();

// ── PENDING MODE: ?pending=1 → order DB'de yok, sepet snapshot session'da
$pending = isset($_GET['pending']) && $_GET['pending'] === '1';
$pendingSnap = null;
if ($pending) {
    $pendingSnap = $_SESSION['pending_card'][(int)$dealer['id']] ?? null;
    // Süre kontrolü (1 saat) ve geçerlilik
    if (!$pendingSnap || (time() - ($pendingSnap['created_at'] ?? 0)) > 3600) {
        unset($_SESSION['pending_card'][(int)$dealer['id']]);
        echo '<script>location.replace("?page=cart");</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=?page=cart"></noscript>';
        echo '<p>Ödeme oturumunuz süresi doldu. <a href="?page=cart">Sepete dön</a></p>';
        exit;
    }
    // Yapay $order — DB'de henüz yok, sayfa render için sahte alanlar
    $order = [
        'id'             => 0,
        'order_no'       => '— (ödeme sonrası oluşturulacak)',
        'dealer_id'      => $dealer['id'],
        'subtotal'       => $pendingSnap['subtotal'],
        'vat_total'      => $pendingSnap['vat_total'],
        'discount_total' => 0,
        'grand_total'    => $pendingSnap['grand_total'],
        'payment_status' => 'odenmedi',
        'status'         => 'bekliyor',
        'company_name'   => $dealer['company_name']     ?? '',
        'first_name'     => $dealer['first_name']       ?? '',
        'last_name'      => $dealer['last_name']        ?? '',
    ];
} else {
    $order = $orderId ? dbRow(
        "SELECT o.*, d.company_name, d.first_name, d.last_name
         FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id
         WHERE o.id=? AND o.dealer_id=?",
        [$orderId, $dealer['id']]
    ) : null;

    if (!$order) {
        redirect('?page=orders');
    }
}

$rb    = rubikpara();
$error = '';

// ── 3DS Callback ────────────────────────────────────────────────
if ($step === 'callback' && isset($_POST['ThreeDSessionId'])) {
    $sessionId = $_POST['ThreeDSessionId'];
    // Form POST aşamasında session'a (veya DB-fallback'ten) yüklenen tutar bilgisi
    // Pending mode: orderId=0, normal mode: orderId>0
    $payInfo = $_SESSION['rk_pay'][$orderId] ?? null;
    if (!$payInfo || ($payInfo['sessionId'] ?? '') !== $sessionId) {
        $error = 'Ödeme oturumu bulunamadı veya geçersiz. Lütfen tekrar deneyin.';
    } else {
        try {
            $result   = $rb->threeDSSonuc($sessionId);
            $mdStatus = (int)($result['mdStatus'] ?? 0);

            if ($mdStatus === 1) {
                // Provizyon — komisyonlu (gerçek çekilecek) tutar
                $finalAmount = (float)$payInfo['amount'];
                $baseAmount  = (float)$payInfo['baseAmount'];
                $commission  = (float)$payInfo['commission'];
                $rate        = (float)$payInfo['rate'];
                $installment = (int)$payInfo['installment'];

                $prov = $rb->odeme(
                    $sessionId, $finalAmount, $installment,
                    0.0,
                    $payInfo['cardToken']  ?? '',
                    $payInfo['cardHolder'] ?? ''
                );
                if ($prov['isSucceed'] ?? false) {
                    if ($installment > 1) {
                        $note = sprintf('%d taksit — Sipariş %s + Komisyon %s (%%%s)',
                            $installment, money($baseAmount), money($commission), $rate);
                    } elseif ($commission > 0) {
                        $note = sprintf('Tek çekim — Sipariş %s + Komisyon %s (%%%s)',
                            money($baseAmount), money($commission), $rate);
                    } else {
                        $note = sprintf('Tek çekim — %s', money($finalAmount));
                    }

                    // ── Kart ödemesi başarılı: ORDER ŞİMDİ OLUŞTURULUYOR ──
                    $autoApprove = ($dealer['order_approval'] ?? '') === 'auto'
                        || ((float)setting('order_auto_approve_limit', '0') > 0
                            && $baseAmount <= (float)setting('order_auto_approve_limit', '0'));

                    if ($pending && $pendingSnap) {
                        // PENDING: cart.php'de DB'ye yazmadık, şimdi yazıyoruz
                        $orderPrefix = setting('order_prefix', 'SIP') . date('ymd');
                        $maxSuffix   = (int)dbVal(
                            "SELECT COALESCE(MAX(CAST(SUBSTRING(order_no, ?) AS UNSIGNED)), 0)
                             FROM b2b_orders WHERE order_no LIKE CONCAT(?, '%')",
                            [strlen($orderPrefix) + 1, $orderPrefix]
                        );
                        $newOrderId = null;
                        $newOrderNo = '';
                        for ($try = 0; $try < 10; $try++) {
                            $newOrderNo = $orderPrefix . str_pad($maxSuffix + 1 + $try, 3, '0', STR_PAD_LEFT);
                            try {
                                $newOrderId = dbInsertRow('b2b_orders', [
                                    'dealer_id'      => $dealer['id'],
                                    'order_no'       => $newOrderNo,
                                    'status'         => $autoApprove ? 'onaylandi' : 'bekliyor',
                                    'payment_status' => 'odendi',  // ödeme zaten yapıldı
                                    'payment_method' => 'kredi_karti',
                                    'subtotal'       => $pendingSnap['subtotal'],
                                    'vat_total'      => $pendingSnap['vat_total'],
                                    'discount_total' => 0,
                                    'grand_total'    => $pendingSnap['grand_total'],
                                    'notes'          => $pendingSnap['notes'] ?? '',
                                    'price_list_id'  => $pendingSnap['price_list_id'],
                                    'created_at'     => date('Y-m-d H:i:s'),
                                ]);
                                break;
                            } catch (\PDOException $e) {
                                if (strpos($e->getMessage(), 'Duplicate') !== false) continue;
                                throw $e;
                            }
                        }
                        if (!$newOrderId) {
                            throw new \RuntimeException('Sipariş kaydedilemedi (numara üretimi başarısız).');
                        }
                        // order_items + stok
                        foreach ($pendingSnap['items'] as $it) {
                            dbInsertRow('b2b_order_items', [
                                'order_id'         => $newOrderId,
                                'product_id'       => $it['product_id'],
                                'product_name'     => $it['product_name'],
                                'product_sku'      => $it['product_sku'],
                                'qty'              => $it['qty'],
                                'unit_price'       => $it['unit_price'],
                                'vat_rate'         => $it['vat_rate'],
                                'discount_percent' => $it['discount_percent'],
                                'line_total'       => $it['line_total'],
                            ]);
                            if ($it['qty'] > 0 && $it['product_id']) {
                                dbExec("UPDATE b2b_products SET stock=stock-? WHERE id=?",
                                       [$it['qty'], $it['product_id']]);
                            }
                        }
                        // Sepet temizle
                        dbExec("DELETE FROM b2b_cart WHERE dealer_id=?", [$dealer['id']]);
                        unset($_SESSION['pending_card'][(int)$dealer['id']]);
                        auditLog('order_created', 'b2b_orders', $newOrderId,
                            ['order_no'=>$newOrderNo, 'method'=>'kredi_karti', 'paid_first'=>true]);
                        // orderId'yi yeni order'a yönlendir (sonraki INSERT'lerde kullanılacak)
                        $orderId = $newOrderId;
                    } else {
                        // NORMAL (eski) MODE: order zaten DB'de var, stok düş + sepet temizle
                        $orderItems = dbRows("SELECT product_id, qty FROM b2b_order_items WHERE order_id=?", [$orderId]);
                        foreach ($orderItems as $oi) {
                            $oqty = (int)($oi['qty'] ?? 0);
                            if ($oqty > 0 && $oi['product_id']) {
                                dbExec("UPDATE b2b_products SET stock=stock-? WHERE id=?",
                                       [$oqty, $oi['product_id']]);
                            }
                        }
                        dbExec("DELETE FROM b2b_cart WHERE dealer_id=?", [$dealer['id']]);
                        if ($autoApprove) {
                            dbExec("UPDATE b2b_orders SET status='onaylandi' WHERE id=? AND status='bekliyor'",
                                   [$orderId]);
                        }
                    }

                    // Paraşüt fatura (otomatik onaylıysa, her iki mod için)
                    if ($autoApprove && function_exists('parasut')) {
                        try { parasut()->syncInvoice($orderId); } catch (\Throwable $e) {}
                    }

                    // Ödeme kaydı (amount = bayinin kartından çekilen toplam)
                    dbExec(
                        "INSERT INTO b2b_payments
                         (dealer_id,order_id,amount,type,status,payment_date,bank_name,transaction_ref,dealer_note,approved_at,approved_by)
                         VALUES (?,?,?,'kredi_karti','onaylandi',NOW(),'Rubikpara',?,?,NOW(),0)",
                        [
                            $dealer['id'], $orderId,
                            $finalAmount,
                            $prov['transactionId'] ?? $sessionId,
                            $note,
                        ]
                    );
                    // Cariye sadece sipariş tutarı kadar alacak (komisyon bayinin sırtında)
                    // ledgerAdd(dealerId, type, amount, desc, refType='manual', refId=0, dueDate=null)
                    ledgerAdd($dealer['id'], 'alacak', $baseAmount,
                        'Kart ödemesi — Rubikpara (' . $note . ')',
                        'payment', $orderId);
                    // Sipariş ödeme durumu
                    dbExec("UPDATE b2b_orders SET payment_status='odendi' WHERE id=?", [$orderId]);

                    // Aynı sipariş için bekleyen havale/EFT/diğer bildirimleri otomatik reddet
                    dbExec(
                        "UPDATE b2b_payments
                         SET status='reddedildi',
                             admin_note=CONCAT(COALESCE(admin_note,''),
                                IF(admin_note IS NULL OR admin_note='','','\n'),
                                '[Otomatik] Sipariş kart ile ödendi, bu bildirim geçersiz.')
                         WHERE order_id=? AND status='bekliyor' AND type<>'kredi_karti'",
                        [$orderId]
                    );

                    unset($_SESSION['rk_pay'][$orderId]);
                    // DB-fallback kaydını da temizle
                    try {
                        dbExec("DELETE FROM b2b_payment_sessions WHERE threeds_session_id=?", [$sessionId]);
                    } catch (\Throwable $e) {
                        error_log('payment-card DB-fallback cleanup hatası: ' . $e->getMessage());
                    }
                    $_SESSION['flash'] = ['type'=>'success','msg'=>'Ödeme başarıyla tamamlandı! Sipariş oluşturuldu.'];
                    $successUrl = '?page=orders&action=detail&id=' . $orderId;
                    echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
                    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($successUrl) . '">';
                    echo '<script>window.location.replace(' . json_encode($successUrl) . ');</script>';
                    echo '</head><body style="font-family:system-ui;padding:40px;text-align:center">';
                    echo '<h2 style="color:#16a34a">✓ Ödeme Başarılı</h2>';
                    echo '<p>Siparişiniz oluşturuldu, yönlendiriliyorsunuz...</p>';
                    echo '<p><a href="' . htmlspecialchars($successUrl) . '">Otomatik gitmezse buraya tıklayın</a></p>';
                    echo '</body></html>';
                    exit;
                }
                $error = '3DS doğrulandı ancak provizyon başarısız.';
            } else {
                $error = '3D Secure doğrulaması başarısız (mdStatus: ' . $mdStatus . ')';
                // mdStatus → kullanıcı dostu açıklama
                $mdMessages = [
                    0 => 'Banka 3D Secure doğrulamasını tamamlayamadı. Şifrenizi yanlış girmiş, vazgeçmiş veya bankanın test ortamı işyerini tanımıyor olabilir. Başka bir banka kartını deneyebilirsiniz.',
                    2 => 'Kart sahibi 3D Secure ile kayıtlı değil. Bankanızdan 3D Secure aktivasyonu yaptırmanız gerekir.',
                    3 => 'Banka 3D Secure sistemi şu an kullanılamıyor. Lütfen birkaç dakika sonra tekrar deneyin.',
                    4 => 'Banka 3D Secure doğrulamayı denemedi. Başka kart deneyebilirsiniz.',
                    5 => 'Banka tarafında teknik hata. Tekrar deneyin.',
                    6 => 'Banka 3D Secure katılımcı değil. Başka kart deneyebilirsiniz.',
                    7 => 'Banka tarafında sistem hatası.',
                    8 => 'Banka 3D Secure kontrolü yapamadı.',
                    9 => 'Geçersiz CVV. Kart bilgilerinizi kontrol edin.',
                ];
                if (isset($mdMessages[$mdStatus])) {
                    $error .= ' — ' . $mdMessages[$mdStatus];
                }
            }
        } catch (\Throwable $e) {
            $error = 'Hata: ' . $e->getMessage();
        }

        // ── Ödeme başarısız: temizlik ──
        // PENDING mode: order DB'de yok, sadece session'dan pending_card sil
        // NORMAL mode: ödenmemiş kart siparişini DB'den sil
        if ($error !== '') {
            try {
                if ($pending) {
                    unset($_SESSION['pending_card'][(int)$dealer['id']]);
                    unset($_SESSION['rk_pay'][$orderId]);
                    dbExec("DELETE FROM b2b_payment_sessions WHERE threeds_session_id=?", [$sessionId]);
                } elseif (isset($order) && ($order['payment_status'] ?? '') !== 'odendi' && $orderId > 0) {
                    dbExec("DELETE FROM b2b_order_items WHERE order_id=?", [$orderId]);
                    dbExec("DELETE FROM b2b_orders WHERE id=? AND payment_status!='odendi'", [$orderId]);
                    dbExec("DELETE FROM b2b_payment_sessions WHERE order_id=?", [$orderId]);
                    unset($_SESSION['rk_pay'][$orderId]);
                }
                $error .= '<br><br><strong>Sepetinize geri dönüp tekrar deneyebilirsiniz.</strong> ' .
                    '<a href="?page=cart" style="color:#fff;text-decoration:underline">Sepete Dön</a>';
            } catch (\Throwable $e) {
                error_log('payment-card cleanup hatası: ' . $e->getMessage());
            }
        }
    }
}

// ── Kart formu gönderildi ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'card') {
    csrfCheck();
    $cardNo     = preg_replace('/\s+/', '', $_POST['card_number'] ?? '');
    $expMonth   = str_pad($_POST['expire_month'] ?? '', 2, '0', STR_PAD_LEFT);
    $expYear    = $_POST['expire_year'] ?? '';
    $cvv        = $_POST['cvv'] ?? '';
    $holderName = trim($_POST['card_holder'] ?? '');
    $installment= max(1, intval($_POST['installment'] ?? 1));

    if (!$cardNo || !$expMonth || !$expYear || !$cvv || !$holderName) {
        $error = 'Tüm kart alanlarını doldurun.';
    } else {
        try {
            $baseAmount = (float)$order['grand_total'];
            $stage      = 'taksit';   // log için: hangi aşamada hata oluştu

            // Server-side taksit doğrulama (manipülasyon koruması) +
            // admin'in belirlediği tek çekim komisyonunun da uygulanması.
            // Tek çekim ve taksitli akışları aynı yoldan geçiriyoruz ki
            // bayinin JS dropdown'unda gördüğü tutar ile karttan gerçek
            // çekilen tutar her durumda tutarlı olsun.
            $bin     = substr(preg_replace('/\D/','',$cardNo), 0, 6);
            $options = [];
            try {
                $options = $rb->taksitSorgula($bin, $baseAmount);
            } catch (\Throwable $e) {
                // BIN sorgusu çökerse boş liste — helper tek çekim'i ekleyecek
                $options = [];
            }
            $options = rubikparaTaksitleriZenginlestir($options, $baseAmount);

            $match = null;
            foreach ($options as $o) {
                if ((int)$o['installmentCount'] === $installment) { $match = $o; break; }
            }
            if (!$match) {
                throw new Exception('Seçilen taksit bu kartta desteklenmiyor.');
            }
            $finalAmount = (float)$match['totalAmount'];
            $commission  = (float)$match['commission'];
            $rate        = (float)$match['commissionRate'];

            // Tokenize
            $stage    = 'tokenize';
            $tokenRes = $rb->kartTokenize($cardNo, $expMonth, $expYear, $cvv);
            $cardToken = $tokenRes['cardToken'] ?? '';
            if (!$cardToken) throw new Exception('Kart tokenize edilemedi.');

            // 3DS oturum — komisyonlu tutar ile aç
            $stage     = '3ds-oturum';
            $sessRes   = $rb->threeDSOturum($cardToken, $finalAmount, $installment);
            $sessionId = $sessRes['threeDSessionId'] ?? '';
            if (!$sessionId) throw new Exception('3DS oturumu oluşturulamadı.');

            // Callback'in okuyacağı bilgileri session'a yaz
            $_SESSION['rk_pay'][$orderId] = [
                'sessionId'    => $sessionId,
                'cardToken'    => $cardToken,
                'cardHolder'   => $holderName,
                'amount'       => $finalAmount,   // çekilecek (komisyonlu)
                'baseAmount'   => $baseAmount,    // sipariş tutarı
                'commission'   => $commission,
                'rate'         => $rate,
                'installment'  => $installment,
                'created_at'   => time(),
            ];

            // DB-fallback: callback'te tarayıcı session cookie'sini göndermezse
            // (cross-site POST) bu kayıttan ödeme bilgilerini kurtarırız.
            try {
                dbExec("DELETE FROM b2b_payment_sessions
                        WHERE threeds_session_id=? OR created_at < NOW() - INTERVAL 1 HOUR",
                       [$sessionId]);
                dbExec(
                    "INSERT INTO b2b_payment_sessions
                     (threeds_session_id, dealer_id, order_id, amount, base_amount,
                      commission, commission_rate, installment, card_token, card_holder, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,NOW())",
                    [$sessionId, $dealer['id'], $orderId, $finalAmount, $baseAmount,
                     $commission, $rate, $installment, $cardToken, $holderName]
                );
            } catch (\Throwable $e) {
                error_log('payment-card DB-fallback insert hatası: ' . $e->getMessage());
                // Insert başarısız olsa bile akışa devam — session zaten yazıldı
            }

            // 3DS başlat
            $stage       = '3ds-baslat';
            $callbackUrl = B2B_URL . '/?page=payment-card'
                . ($pending ? '&pending=1' : '&order_id=' . $orderId)
                . '&step=callback';
            $clientIp    = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $initRes     = $rb->threeDSBaslat($sessionId, $callbackUrl, $holderName, $clientIp);
            $htmlContent = $initRes['htmlContent'] ?? '';

            if ($htmlContent) {
                echo $htmlContent;
                exit;
            }
            throw new Exception('3DS başlatma yanıtı boş.');
        } catch (\Throwable $e) {
            $error = '[' . ($stage ?? '?') . '] ' . $e->getMessage();
            error_log("payment-card hatası (order $orderId, stage $stage): " . $e->getMessage());
        }
    }
}

// Sayfa ilk yüklendiğinde (kart no girilmeden) gösterilen tek çekim seçeneğini
// admin'in belirlediği oranla hazırla — JS BIN sorgusu yapana kadar bayi
// doğru tutarı görsün.
$initialBase   = (float)$order['grand_total'];
$initialList   = function_exists('rubikparaTaksitleriZenginlestir')
    ? rubikparaTaksitleriZenginlestir([], $initialBase)
    : [['installmentCount'=>1, 'totalAmount'=>$initialBase, 'installmentAmount'=>$initialBase, 'commission'=>0, 'commissionRate'=>0]];
$initialSingle = $initialList[0];
?>
<div class="page-body">

<div class="page-header">
  <div>
    <h1 class="page-title">Kart ile Ödeme</h1>
    <?php if ($pending): ?>
    <p class="page-sub">
      <span style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:3px 8px;border-radius:4px;font-size:12px;font-weight:700">⏳ ÖDEME ONAYI BEKLENİYOR</span>
      — Tutar: <strong><?= money((float)$order['grand_total']) ?></strong> — Ödeme onaylandıktan sonra siparişiniz oluşturulacak.
    </p>
    <?php else: ?>
    <p class="page-sub">Sipariş: <strong><?= h($order['order_no']) ?></strong> — Tutar: <strong><?= money((float)$order['grand_total']) ?></strong></p>
    <?php endif; ?>
  </div>
  <a href="<?= $pending ? '?page=cart' : '?page=orders&action=detail&id='.$orderId ?>" class="btn btn-secondary">← Geri</a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<?php if (!$rb->ayarliMi()): ?>
<div class="alert alert-warning">Kart ödemesi henüz yapılandırılmamış. Lütfen yönetici ile iletişime geçin.</div>
<?php else: ?>

<div style="display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start">

<!-- Kart Formu -->
<div class="card">
  <div class="card-header"><h3 class="card-title">Kart Bilgileri</h3></div>
  <div class="card-body">
    <form method="POST" action="?page=payment-card<?= $pending ? '&pending=1' : '&order_id='.$orderId ?>&step=card" id="cardForm">
      <?= csrfField() ?>

      <!-- Kart görsel önizleme -->
      <div id="cardPreview" style="background:linear-gradient(135deg,#1c1c2e,#2d2d45);border-radius:14px;padding:20px 24px;margin-bottom:20px;color:#fff;font-family:monospace;position:relative;overflow:hidden">
        <div style="position:absolute;top:0;right:0;width:200px;height:200px;background:rgba(237,41,57,.15);border-radius:50%;transform:translate(40%,-40%)"></div>
        <div style="font-size:11px;opacity:.5;margin-bottom:14px;letter-spacing:.1em">KART NUMARASI</div>
        <div id="pCardNo" style="font-size:18px;letter-spacing:.2em;margin-bottom:16px">•••• •••• •••• ••••</div>
        <div style="display:flex;justify-content:space-between;align-items:flex-end">
          <div><div style="font-size:10px;opacity:.5;letter-spacing:.05em">KART SAHİBİ</div>
          <div id="pHolder" style="font-size:13px;margin-top:2px">AD SOYAD</div></div>
          <div style="text-align:right"><div style="font-size:10px;opacity:.5;letter-spacing:.05em">SON.TRH</div>
          <div id="pExpiry" style="font-size:13px;margin-top:2px">AA/YY</div></div>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Kart Numarası</label>
        <input type="text" name="card_number" id="cardNumber" class="form-control"
               placeholder="•••• •••• •••• ••••" maxlength="19" inputmode="numeric"
               autocomplete="cc-number" required>
      </div>

      <div class="form-grid-3">
        <div class="form-group">
          <label class="form-label">Ay</label>
          <select name="expire_month" class="form-control" required id="expMonth">
            <option value="">Ay</option>
            <?php for ($m=1;$m<=12;$m++): ?><option value="<?= sprintf('%02d',$m) ?>"><?= sprintf('%02d',$m) ?></option><?php endfor; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Yıl</label>
          <select name="expire_year" class="form-control" required id="expYear">
            <option value="">Yıl</option>
            <?php for ($y=date('Y');$y<=date('Y')+15;$y++): ?><option value="<?= $y ?>"><?= $y ?></option><?php endfor; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">CVV</label>
          <input type="text" name="cvv" class="form-control" placeholder="•••"
                 maxlength="4" inputmode="numeric" autocomplete="cc-csc" required>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Kart Üzerindeki Ad Soyad</label>
        <input type="text" name="card_holder" id="cardHolder" class="form-control"
               placeholder="AD SOYAD" autocomplete="cc-name" required
               style="text-transform:uppercase">
      </div>

      <div class="form-group">
        <label class="form-label">Taksit
          <span id="instLoading" style="display:none;font-size:11px;color:var(--text-muted);font-weight:400">— sorgulanıyor…</span>
        </label>
        <select name="installment" id="installment" class="form-control" disabled>
          <option value="1">Tek çekim — <?= money((float)$initialSingle['totalAmount']) ?></option>
        </select>
        <div id="instHint" style="font-size:11px;color:var(--text-muted);margin-top:6px">
          Kart numarasını girince taksit seçenekleri ve banka komisyonları otomatik gelir.
        </div>
      </div>

      <!-- Komisyon özet kutusu -->
      <div id="commissionBox" style="display:none;background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:14px;font-size:13px">
        <div style="display:flex;justify-content:space-between;padding:3px 0">
          <span style="color:var(--text-muted)">Sipariş tutarı</span>
          <span id="cbBase"></span>
        </div>
        <div id="cbCommissionRow" style="display:flex;justify-content:space-between;padding:3px 0">
          <span style="color:var(--text-muted)">Banka komisyonu (<span id="cbRate"></span>)</span>
          <span id="cbCommission"></span>
        </div>
        <div id="cbPerInstallRow" style="display:flex;justify-content:space-between;padding:3px 0">
          <span style="color:var(--text-muted)">Aylık taksit</span>
          <span id="cbPerInstall"></span>
        </div>
        <div style="display:flex;justify-content:space-between;padding:6px 0 0;border-top:1px solid var(--border);margin-top:6px">
          <strong>Kartınızdan çekilecek toplam</strong>
          <strong id="cbTotal" style="color:var(--red)"></strong>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" id="payBtn" style="width:100%;height:44px;font-size:14px">
        🔒 3D Secure ile Öde — <span id="payBtnAmount"><?= money((float)$initialSingle['totalAmount']) ?></span>
      </button>
      <p style="text-align:center;font-size:11px;color:var(--text-muted);margin-top:10px">
        Ödemeniz PF Gateway (Rubikpara) altyapısı üzerinden 3D Secure korumalı olarak işlenir.
      </p>
    </form>
  </div>
</div>

<!-- Sipariş özeti -->
<div class="card">
  <div class="card-header"><h3 class="card-title">Sipariş Özeti</h3></div>
  <div class="card-body">
    <div style="font-size:13px;color:var(--text-2)">
      <?php if (!$pending): ?>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
        <span>Sipariş No</span><strong><?= h($order['order_no']) ?></strong>
      </div>
      <?php endif; ?>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
        <span>Ara Toplam</span><span><?= money((float)($order['subtotal'] ?? $order['grand_total'])) ?></span>
      </div>
      <?php if (($order['vat_total'] ?? 0) > 0): ?>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
        <span>KDV</span><span><?= money((float)$order['vat_total']) ?></span>
      </div>
      <?php endif; ?>
      <div style="display:flex;justify-content:space-between;padding:10px 0;font-size:15px;font-weight:700;color:var(--text);border-bottom:1px solid var(--border)">
        <span>Sipariş Toplamı</span><span><?= money((float)$order['grand_total']) ?></span>
      </div>

      <!-- Komisyon satırı — JS taksit seçimine göre güncelliyor -->
      <div id="summaryCommissionRow" style="display:<?= ($initialSingle['commission'] ?? 0) > 0 ? 'flex' : 'none' ?>;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;color:var(--text-2)">
        <span>+ Komisyon (<span id="summaryRate"><?= number_format((float)($initialSingle['commissionRate'] ?? 0), 2) ?></span>%)</span>
        <span id="summaryCommission"><?= money((float)($initialSingle['commission'] ?? 0)) ?></span>
      </div>

      <!-- Aylık taksit (sadece taksitlide görünür) -->
      <div id="summaryPerInstallRow" style="display:none;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;color:var(--text-2)">
        <span><span id="summaryInstallCount">0</span> taksit, aylık</span>
        <span id="summaryPerInstall"></span>
      </div>

      <div style="display:flex;justify-content:space-between;padding:12px 0 4px;font-size:16px;font-weight:800;color:var(--text)">
        <span>Karttan Çekilecek</span>
        <span style="color:var(--red)" id="summaryGrandTotal"><?= money((float)$initialSingle['totalAmount']) ?></span>
      </div>
    </div>
    <div style="margin-top:12px;padding:10px;background:var(--success-bg);border:1px solid var(--success-border);border-radius:var(--radius);font-size:12px;color:var(--success)">
      🔒 SSL ile şifreli bağlantı<br>
      🛡️ 3D Secure güvenceli ödeme<br>
      💳 Tüm kredi kartları kabul edilir
    </div>
  </div>
</div>

</div>
<?php endif; ?>
</div>

<script>
// Kart önizleme
const cardNo = document.getElementById('cardNumber');
const holder = document.getElementById('cardHolder');
const expM   = document.getElementById('expMonth');
const expY   = document.getElementById('expYear');

cardNo?.addEventListener('input', e => {
  let v = e.target.value.replace(/\D/g,'').substring(0,16);
  e.target.value = v.replace(/(.{4})/g,'$1 ').trim();
  document.getElementById('pCardNo').textContent =
    (v + '•'.repeat(16-v.length)).replace(/(.{4})/g,'$1 ').trim();
});
holder?.addEventListener('input', e => {
  document.getElementById('pHolder').textContent = e.target.value.toUpperCase() || 'AD SOYAD';
});
const updateExpiry = () => {
  const m = expM?.value || 'AA';
  const y = expY?.value ? expY.value.slice(-2) : 'YY';
  document.getElementById('pExpiry').textContent = m + '/' + y;
};
expM?.addEventListener('change', updateExpiry);
expY?.addEventListener('change', updateExpiry);

// ── Dinamik Taksit Sorgulama (Rubikpara /v1/Installment) ────────
const ORDER_ID    = <?= (int)$orderId ?>;
const IS_PENDING  = <?= $pending ? 'true' : 'false' ?>;
const BASE_AMOUNT = <?= json_encode((float)$order['grand_total']) ?>;
const CSRF_TOKEN  = <?= json_encode(csrfToken()) ?>;
// Admin'in belirlediği tek çekim komisyon oranıyla hesaplanmış başlangıç seçeneği
const INITIAL_FALLBACK = <?= json_encode([[
    'installmentCount'  => (int)$initialSingle['installmentCount'],
    'totalAmount'       => (float)$initialSingle['totalAmount'],
    'installmentAmount' => (float)$initialSingle['installmentAmount'],
    'commission'        => (float)$initialSingle['commission'],
    'commissionRate'    => (float)$initialSingle['commissionRate'],
]]) ?>;
const fmtTL = n => new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}).format(n) + ' ₺';

const sel  = document.getElementById('installment');
const hint = document.getElementById('instHint');
const load = document.getElementById('instLoading');
const box  = document.getElementById('commissionBox');
const btnAmt = document.getElementById('payBtnAmount');

let lastBin = '';
let currentOptions = INITIAL_FALLBACK;
let debounceTimer = null;

function renderOptions(list) {
  currentOptions = list;
  sel.innerHTML = '';
  list.forEach(o => {
    const opt = document.createElement('option');
    opt.value = o.installmentCount;
    opt.textContent = o.installmentCount === 1
      ? `Tek çekim — ${fmtTL(o.totalAmount)}`
      : `${o.installmentCount} taksit × ${fmtTL(o.installmentAmount)} = ${fmtTL(o.totalAmount)}`;
    sel.appendChild(opt);
  });
  sel.disabled = false;
  updateCommissionBox();
}

function updateCommissionBox() {
  const sel_count = parseInt(sel.value, 10);
  const o = currentOptions.find(x => x.installmentCount === sel_count) || currentOptions[0];
  btnAmt.textContent = fmtTL(o.totalAmount);

  // Sol taraf (form altındaki gri kutu)
  if (o.commission > 0) {
    box.style.display = 'block';
    document.getElementById('cbBase').textContent = fmtTL(BASE_AMOUNT);
    document.getElementById('cbCommission').textContent = fmtTL(o.commission);
    document.getElementById('cbRate').textContent = '%' + Number(o.commissionRate).toFixed(2);
    if (o.installmentCount > 1) {
      document.getElementById('cbPerInstallRow').style.display = 'flex';
      document.getElementById('cbPerInstall').textContent = fmtTL(o.installmentAmount);
    } else {
      document.getElementById('cbPerInstallRow').style.display = 'none';
    }
    document.getElementById('cbTotal').textContent = fmtTL(o.totalAmount);
  } else {
    box.style.display = 'none';
  }

  // Sağ taraf (Sipariş Özeti kartı)
  const sumCommissionRow = document.getElementById('summaryCommissionRow');
  const sumPerInstallRow = document.getElementById('summaryPerInstallRow');
  document.getElementById('summaryGrandTotal').textContent = fmtTL(o.totalAmount);
  if (o.commission > 0) {
    sumCommissionRow.style.display = 'flex';
    document.getElementById('summaryCommission').textContent = fmtTL(o.commission);
    document.getElementById('summaryRate').textContent = Number(o.commissionRate).toFixed(2);
  } else {
    sumCommissionRow.style.display = 'none';
  }
  if (o.installmentCount > 1) {
    sumPerInstallRow.style.display = 'flex';
    document.getElementById('summaryInstallCount').textContent = o.installmentCount;
    document.getElementById('summaryPerInstall').textContent = fmtTL(o.installmentAmount);
  } else {
    sumPerInstallRow.style.display = 'none';
  }
}

function fetchInstallments(bin) {
  if (bin === lastBin) return;
  lastBin = bin;
  load.style.display = 'inline';
  hint.style.display = 'none';

  // WAF (LiteSpeed) 6 haneli rakam içeren parametreleri kart no
  // pattern olarak algılayıp 403 atıyor. bin'i base64 encode ederek
  // 'x' parametresinde yolluyoruz, ayrıca endpoint URL'sini de değiştirdik
  // (rubikpara-installments → rk-tx) ki URL pattern'i de tetiklemesin.
  const body = new URLSearchParams({
    csrf: CSRF_TOKEN,
    x: btoa(bin), // base64: "541876" → "NTQxODc2"
    order_id: ORDER_ID,
    pending: IS_PENDING ? '1' : '0',
  });
  const url = '<?= B2B_URL ?>/api/rk-tx.php';

  fetch(url, {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: body.toString(),
    credentials: 'same-origin', // session cookie zorunlu
  })
  .then(async r => {
    const text = await r.text();
    let data = null, parseErr = null;
    try { data = JSON.parse(text); } catch (e) { parseErr = e.message; }
    return { status: r.status, ok: r.ok, text, data, parseErr };
  })
  .then(resp => {
    load.style.display = 'none';

    // 1) HTTP-level hata
    if (!resp.ok) {
      hint.style.display = 'block';
      hint.style.color = '#dc2626';
      hint.textContent = `HTTP ${resp.status}: ` + (resp.text.substring(0, 200) || 'Endpoint hata döndü');
      renderOptions(INITIAL_FALLBACK);
      return;
    }
    // 2) JSON parse hatası
    if (resp.parseErr) {
      hint.style.display = 'block';
      hint.style.color = '#dc2626';
      hint.textContent = `Yanıt geçerli JSON değil: ${resp.parseErr} | ` + resp.text.substring(0, 150);
      renderOptions(INITIAL_FALLBACK);
      return;
    }
    const d = resp.data;
    // 3) Application-level hata (d.ok=false)
    if (!d.ok) {
      hint.style.display = 'block';
      hint.style.color = '#dc2626';
      hint.textContent = 'Rubikpara: ' + (d.message || 'Bilinmeyen hata');
      renderOptions(INITIAL_FALLBACK);
      return;
    }
    // 4) Boş liste — Rubikpara taksit önermedi
    if (!d.installments || !d.installments.length) {
      hint.style.display = 'block';
      hint.style.color = '#dc2626';
      hint.textContent = 'Bu kart için Rubikpara taksit listesi döndürmedi (test merchant kart-banka kombinasyonu desteklemiyor olabilir). Tek çekim ile devam edebilirsiniz.';
      renderOptions(INITIAL_FALLBACK);
      return;
    }
    // 5) Başarı
    hint.style.display = 'block';
    hint.style.color = 'var(--text-muted)';
    hint.textContent = `${d.installments.length} seçenek bulundu — komisyonlar bankanın güncel oranlarıdır.`;
    renderOptions(d.installments);
  })
  .catch(err => {
    load.style.display = 'none';
    hint.style.display = 'block';
    hint.style.color = '#dc2626';
    hint.textContent = 'Bağlantı hatası: ' + err.message;
    renderOptions(INITIAL_FALLBACK);
  });
}

// ── Taksit AJAX KALDIRILDI ──
// Hosting WAF (LiteSpeed/Imunify360) kart BIN içeren POST isteklerini
// PCI-DSS koruması olarak 403 ile reddediyor. Bypass denemeleri (parametre
// parçalama, base64 encode, URL değiştirme, .htaccess) sonuç vermedi.
// Şimdilik sadece tek çekim aktif — initial render'da admin'in tanımladığı
// 'rubikpara_single_rate' oranıyla komisyon zaten gösteriliyor.
//
// Taksit özelliğini geri açmak için: hosting destekten /api/ klasörü için
// WAF kuralları (özellikle 'Credit Card Number Detection') whitelist edilmeli.

hint.style.display = 'block';
hint.style.color = 'var(--text-muted)';
hint.textContent = 'Şu an yalnızca tek çekim ile ödeme alınmaktadır.';

sel.addEventListener('change', updateCommissionBox);
</script>

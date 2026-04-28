<?php
/**
 * Rubikpara 3D Secure Kart ile Ödeme
 * ?page=payment-card&order_id=X
 */
requireDealer();

$orderId = intval($_GET['order_id'] ?? 0);
$order   = $orderId ? dbRow(
    "SELECT o.*, d.company_name, d.first_name, d.last_name
     FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id
     WHERE o.id=? AND o.dealer_id=?",
    [$orderId, $dealer['id']]
) : null;

if (!$order) {
    header('Location: ?page=orders');
    exit;
}

$rb     = rubikpara();
$error  = '';
$step   = $_GET['step'] ?? 'card'; // card | threeds | result

// ── 3DS Callback ────────────────────────────────────────────────
if ($step === 'callback' && isset($_POST['ThreeDSessionId'])) {
    $sessionId = $_POST['ThreeDSessionId'];
    try {
        $result  = $rb->threeDSSonuc($sessionId);
        $mdStatus = (int)($result['mdStatus'] ?? 0);

        if ($mdStatus === 1) {
            // Provizyon
            $prov = $rb->odeme($sessionId, (float)$order['grand_total']);
            if ($prov['isSucceed'] ?? false) {
                // Ödeme kaydı oluştur
                dbExec(
                    "INSERT INTO b2b_payments
                     (dealer_id,order_id,amount,type,status,payment_date,bank_name,transaction_ref,approved_at,approved_by)
                     VALUES (?,?,?,'kredi_karti','onaylandi',NOW(),'Rubikpara',?,NOW(),0)",
                    [
                        $dealer['id'], $orderId,
                        $order['grand_total'],
                        $prov['transactionId'] ?? $sessionId,
                    ]
                );
                // Cari alacak
                ledgerAdd($dealer['id'], 'alacak', (float)$order['grand_total'],
                    'Kart ödemesi — Rubikpara', null, null, 'payment');
                // Sipariş güncelle
                dbExec("UPDATE b2b_orders SET payment_status='odendi' WHERE id=?", [$orderId]);

                // Aynı sipariş için bekleyen havale/EFT/diğer bildirimleri otomatik reddet
                // (kart ile ödendiği için admin tarafında çift kayıt karışıklığı olmasın)
                dbExec(
                    "UPDATE b2b_payments
                     SET status='reddedildi',
                         admin_note=CONCAT(COALESCE(admin_note,''),
                            IF(admin_note IS NULL OR admin_note='','','\n'),
                            '[Otomatik] Sipariş kart ile ödendi, bu bildirim geçersiz.')
                     WHERE order_id=? AND status='bekliyor' AND type<>'kredi_karti'",
                    [$orderId]
                );

                $_SESSION['flash'] = ['type'=>'success','msg'=>'Ödeme başarıyla tamamlandı!'];
                header('Location: ?page=orders&action=detail&id=' . $orderId);
                exit;
            }
            $error = '3DS doğrulandı ancak provizyon başarısız.';
        } else {
            $error = '3D Secure doğrulaması başarısız (mdStatus: ' . $mdStatus . ')';
        }
    } catch (Exception $e) {
        $error = 'Hata: ' . $e->getMessage();
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
    $installment= intval($_POST['installment'] ?? 1);

    if (!$cardNo || !$expMonth || !$expYear || !$cvv || !$holderName) {
        $error = 'Tüm kart alanlarını doldurun.';
    } else {
        try {
            // Tokenize
            $tokenRes = $rb->kartTokenize($cardNo, $expMonth, $expYear, $cvv);
            $cardToken = $tokenRes['cardToken'] ?? '';
            if (!$cardToken) throw new Exception('Kart tokenize edilemedi.');

            // 3DS oturum
            $sessRes   = $rb->threeDSOturum($cardToken, (float)$order['grand_total'], $installment);
            $sessionId = $sessRes['threeDSessionId'] ?? '';
            if (!$sessionId) throw new Exception('3DS oturumu oluşturulamadı.');

            // 3DS başlat
            $callbackUrl = B2B_URL . '/?page=payment-card&order_id=' . $orderId . '&step=callback';
            $clientIp    = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $initRes     = $rb->threeDSBaslat($sessionId, $callbackUrl, $holderName, $clientIp);
            $htmlContent = $initRes['htmlContent'] ?? '';

            if ($htmlContent) {
                // Banka doğrulama sayfasını göster
                echo $htmlContent;
                exit;
            }
            throw new Exception('3DS başlatma yanıtı boş.');
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<div class="page-body">
<div class="page-header">
  <div>
    <h1 class="page-title">Kart ile Ödeme</h1>
    <p class="page-sub">Sipariş: <strong><?= h($order['order_no']) ?></strong> — Tutar: <strong><?= money((float)$order['grand_total']) ?></strong></p>
  </div>
  <a href="?page=orders&action=detail&id=<?= $orderId ?>" class="btn btn-secondary">← Geri</a>
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
    <form method="POST" action="?page=payment-card&order_id=<?= $orderId ?>&step=card" id="cardForm">
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
        <label class="form-label">Taksit</label>
        <select name="installment" class="form-control">
          <option value="1">Tek çekim</option>
          <option value="2">2 taksit</option>
          <option value="3">3 taksit</option>
          <option value="6">6 taksit</option>
          <option value="9">9 taksit</option>
          <option value="12">12 taksit</option>
        </select>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;height:44px;font-size:14px">
        🔒 3D Secure ile Öde — <?= money((float)$order['grand_total']) ?>
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
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
        <span>Sipariş No</span><strong><?= h($order['order_no']) ?></strong>
      </div>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
        <span>Ara Toplam</span><span><?= money((float)($order['subtotal'] ?? $order['grand_total'])) ?></span>
      </div>
      <?php if (($order['vat_total'] ?? 0) > 0): ?>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
        <span>KDV</span><span><?= money((float)$order['vat_total']) ?></span>
      </div>
      <?php endif; ?>
      <div style="display:flex;justify-content:space-between;padding:10px 0;font-size:16px;font-weight:800;color:var(--text)">
        <span>Toplam</span><span style="color:var(--red)"><?= money((float)$order['grand_total']) ?></span>
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
</script>

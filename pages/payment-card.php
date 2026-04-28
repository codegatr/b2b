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
    // Form POST aşamasında session'a yazılmış olan komisyonlu tutar / taksit bilgisi
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

                $prov = $rb->odeme($sessionId, $finalAmount, $installment);
                if ($prov['isSucceed'] ?? false) {
                    $note = $installment > 1
                        ? sprintf('%d taksit — Sipariş %s + Komisyon %s (%%%s)',
                            $installment, money($baseAmount), money($commission), $rate)
                        : sprintf('Tek çekim — %s', money($finalAmount));

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
                    ledgerAdd($dealer['id'], 'alacak', $baseAmount,
                        'Kart ödemesi — Rubikpara (' . $note . ')', null, null, 'payment');
                    // Sipariş güncelle
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

            // Server-side taksit doğrulama (manipülasyon koruması)
            // — Kullanıcı 12 taksit seçti diye 100₺ çekemesin, gerçek tutarı API söyler.
            $finalAmount = $baseAmount;
            $commission  = 0.0;
            $rate        = 0.0;
            if ($installment > 1) {
                $bin     = substr(preg_replace('/\D/','',$cardNo), 0, 6);
                $options = $rb->taksitSorgula($bin, $baseAmount);
                $match   = null;
                foreach ($options as $o) {
                    if ((int)$o['installmentCount'] === $installment) { $match = $o; break; }
                }
                if (!$match) {
                    throw new Exception('Seçilen taksit bu kartta desteklenmiyor.');
                }
                $finalAmount = (float)$match['totalAmount'];
                $commission  = (float)$match['commission'];
                $rate        = (float)$match['commissionRate'];
            }

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
                'sessionId'   => $sessionId,
                'amount'      => $finalAmount,   // çekilecek (komisyonlu)
                'baseAmount'  => $baseAmount,    // sipariş tutarı
                'commission'  => $commission,
                'rate'        => $rate,
                'installment' => $installment,
                'created_at'  => time(),
            ];

            // 3DS başlat
            $stage       = '3ds-baslat';
            $callbackUrl = B2B_URL . '/?page=payment-card&order_id=' . $orderId . '&step=callback';
            $clientIp    = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $initRes     = $rb->threeDSBaslat($sessionId, $callbackUrl, $holderName, $clientIp);
            $htmlContent = $initRes['htmlContent'] ?? '';

            if ($htmlContent) {
                echo $htmlContent;
                exit;
            }
            throw new Exception('3DS başlatma yanıtı boş.');
        } catch (Exception $e) {
            $error = '[' . ($stage ?? '?') . '] ' . $e->getMessage();
            error_log("payment-card hatası (order $orderId, stage $stage): " . $e->getMessage());
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
        <label class="form-label">Taksit
          <span id="instLoading" style="display:none;font-size:11px;color:var(--text-muted);font-weight:400">— sorgulanıyor…</span>
        </label>
        <select name="installment" id="installment" class="form-control" disabled>
          <option value="1">Tek çekim — <?= money((float)$order['grand_total']) ?></option>
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
        🔒 3D Secure ile Öde — <span id="payBtnAmount"><?= money((float)$order['grand_total']) ?></span>
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

// ── Dinamik Taksit Sorgulama (Rubikpara /v1/Installment) ────────
const ORDER_ID    = <?= (int)$orderId ?>;
const BASE_AMOUNT = <?= json_encode((float)$order['grand_total']) ?>;
const CSRF_TOKEN  = <?= json_encode(csrfToken()) ?>;
const fmtTL = n => new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}).format(n) + ' ₺';

const sel  = document.getElementById('installment');
const hint = document.getElementById('instHint');
const load = document.getElementById('instLoading');
const box  = document.getElementById('commissionBox');
const btnAmt = document.getElementById('payBtnAmount');

let lastBin = '';
let currentOptions = [{ installmentCount:1, totalAmount:BASE_AMOUNT, installmentAmount:BASE_AMOUNT, commission:0, commissionRate:0 }];
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
  if (o.installmentCount > 1 && o.commission > 0) {
    box.style.display = 'block';
    document.getElementById('cbBase').textContent = fmtTL(BASE_AMOUNT);
    document.getElementById('cbCommission').textContent = fmtTL(o.commission);
    document.getElementById('cbRate').textContent = '%' + o.commissionRate.toFixed(2);
    document.getElementById('cbPerInstall').textContent = fmtTL(o.installmentAmount);
    document.getElementById('cbTotal').textContent = fmtTL(o.totalAmount);
  } else {
    box.style.display = 'none';
  }
}

function fetchInstallments(bin) {
  if (bin === lastBin) return;
  lastBin = bin;
  load.style.display = 'inline';
  hint.style.display = 'none';

  const body = new URLSearchParams({ csrf: CSRF_TOKEN, bin: bin, order_id: ORDER_ID });
  fetch('<?= B2B_URL ?>/api/rubikpara-installments.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: body.toString(),
  })
  .then(r => r.json())
  .then(d => {
    load.style.display = 'none';
    if (!d.ok || !d.installments || !d.installments.length) {
      hint.style.display = 'block';
      hint.textContent = d.message || 'Taksit bilgisi alınamadı, sadece tek çekim kullanılabilir.';
      hint.style.color = '#dc2626';
      renderOptions([{ installmentCount:1, totalAmount:BASE_AMOUNT, installmentAmount:BASE_AMOUNT, commission:0, commissionRate:0 }]);
      return;
    }
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
    renderOptions([{ installmentCount:1, totalAmount:BASE_AMOUNT, installmentAmount:BASE_AMOUNT, commission:0, commissionRate:0 }]);
  });
}

cardNo?.addEventListener('input', e => {
  const cleaned = e.target.value.replace(/\D/g,'');
  clearTimeout(debounceTimer);
  if (cleaned.length >= 6) {
    debounceTimer = setTimeout(() => fetchInstallments(cleaned.substring(0,6)), 350);
  }
});

sel.addEventListener('change', updateCommissionBox);
</script>

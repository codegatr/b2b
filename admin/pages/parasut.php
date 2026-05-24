<?php
requireAdmin();

$msg = '';
$debugResult = null;

// ── Bağlantı testi
if (isset($_GET['test'])) {
    try {
        $result = parasut()->testConnection();
        // /v4/me cevabı: { data: { id, type:'users', attributes:{ name, email, ... } } }
        $user = $result['data'] ?? null;
        if (is_array($user) && !empty($user)) {
            // İki olası yapı: tek object veya array of objects
            $isList = isset($user[0]) && is_array($user[0]);
            if ($isList) {
                // Liste — eski yapıyı destekle
                $names = [];
                foreach ($user as $c) {
                    $name = $c['attributes']['name'] ?? '?';
                    $cid  = $c['id'] ?? '?';
                    $names[] = "{$name} (ID: {$cid})";
                }
                $msg = 'success:Bağlantı başarılı. Erişilebilir firmalar: ' . implode(', ', $names);
            } else {
                // Tek kullanıcı
                $userName  = h($user['attributes']['name']  ?? '?');
                $userEmail = h($user['attributes']['email'] ?? '?');
                $userId    = h((string)($user['id'] ?? '?'));
                $companyId = h(setting('parasut_company_id', ''));
                $msg = "success:✓ Bağlantı başarılı. Kullanıcı: <strong>{$userName}</strong> ({$userEmail}, ID: {$userId}). Kayıtlı Firma ID: <strong>{$companyId}</strong>";
            }
        } else {
            $msg = 'success:Bağlantı başarılı.';
        }
    } catch (Exception $e) {
        $msg = 'error:' . $e->getMessage();
    }
}

// ── DEBUG: Token endpoint'ine elden istek at (kullanıcı farklı parametreler deneyebilir)
if (isPost() && ($_POST['action'] ?? '') === 'debug_token') {
    csrfCheck();
    $email    = trim($_POST['debug_email'] ?? '');
    $password = $_POST['debug_password'] ?? '';
    $redirect = trim($_POST['debug_redirect'] ?? 'urn:ietf:wg:oauth:2.0:oob');

    $clientId     = setting('parasut_client_id');
    $clientSecret = setting('parasut_client_secret');

    if (empty($email) || empty($password)) {
        $msg = 'error:E-posta ve Şifre zorunlu.';
    } else {
        $ch = curl_init();
        $postData = http_build_query([
            'grant_type'    => 'password',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'username'      => $email,
            'password'      => $password,
            'redirect_uri'  => $redirect,
        ]);
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://api.parasut.com/oauth/token',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $debugResult = [
            'http_code'    => $code,
            'curl_error'   => $err,
            'raw_response' => $raw,
            'sent_params'  => [
                'client_id'     => substr($clientId, 0, 25) . '...',
                'client_secret' => substr($clientSecret, 0, 15) . '...',
                'username'      => $email,
                'password'      => '(' . strlen($password) . ' karakter)',
                'redirect_uri'  => $redirect,
                'grant_type'    => 'password',
            ],
        ];

        // Token başarılı olduysa kullanıcının kayıtlı şifresini de güncelle (kolaylık)
        $parsed = json_decode($raw, true);
        if (!empty($parsed['access_token'])) {
            settingSave('parasut_email', $email);
            settingSave('parasut_password', $password);
            settingSave('parasut_token_cache', $parsed['access_token'] . '|' . (time() + ($parsed['expires_in'] ?? 7200) - 60));
            $msg = 'success:✓ Token alındı! E-posta ve şifre kaydedildi. Artık entegrasyon kullanıma hazır.';
        } else {
            $msg = 'error:Token alınamadı — aşağıdaki detaya bakın.';
        }
    }
}

// ── Token cache temizle (yeniden token al)
if (isset($_GET['clear_token'])) {
    settingSave('parasut_token_cache', '');
    $msg = 'success:Token cache temizlendi. Bir sonraki istekte yeni token alınacak.';
}

// ── DETAYLI ENDPOINT TANI (her endpoint'i ayrı ayrı test et)
$diagResult = null;
if (isset($_GET['diag'])) {
    $p = parasut();
    $companyId = setting('parasut_company_id', '');

    // Her endpoint için manuel http çağrısı yap, raw response ile dön
    $tests = [];

    // Test 1: /me (kim olduğunu söyle)
    $tests['1_me'] = [
        'label' => 'Kimlik Doğrulama (/v4/me)',
        'desc'  => 'Token geçerli mi? Hangi kullanıcı olarak bağlandık?',
    ];

    // Test 2: /contacts (cari listesi)
    $tests['2_contacts'] = [
        'label' => 'Cariler (/v4/{cid}/contacts)',
        'desc'  => 'Müşteri ve tedarikçi listesi okuma izni var mı?',
    ];

    // Test 3: /products (ürün listesi)
    $tests['3_products'] = [
        'label' => 'Ürünler (/v4/{cid}/products)',
        'desc'  => 'Stok kayıtları okuma izni var mı?',
    ];

    // Test 4: /accounts (banka/kasa)
    $tests['4_accounts'] = [
        'label' => 'Hesaplar (/v4/{cid}/accounts)',
        'desc'  => 'Banka ve kasa hesapları okuma izni var mı?',
    ];

    $diagResult = [];

    try {
        // Test 1: /me
        $meRaw = $p->testConnection();
        $diagResult['1_me'] = [
            'label'    => $tests['1_me']['label'],
            'desc'     => $tests['1_me']['desc'],
            'http'     => $meRaw['__meta']['http_code'] ?? 0,
            'ok'       => !empty($meRaw['data']),
            'snippet'  => $meRaw['__meta']['raw_snippet'] ?? '',
            'count'    => 1,
        ];
    } catch (\Throwable $e) {
        $diagResult['1_me'] = ['label'=>$tests['1_me']['label'], 'ok'=>false, 'error'=>$e->getMessage()];
    }

    // Test 2: /contacts (sayfa 1, 25 kayıt)
    try {
        $contactRes = $p->listContacts(1, 25);
        $diagResult['2_contacts'] = [
            'label'   => $tests['2_contacts']['label'],
            'desc'    => $tests['2_contacts']['desc'],
            'http'    => $contactRes['meta']['__meta']['http_code'] ?? '?',
            'ok'      => !empty($contactRes['data']),
            'count'   => count($contactRes['data']),
            'err'     => $contactRes['err'] ?? null,
            'total_meta' => $contactRes['meta'] ?? [],
        ];
    } catch (\Throwable $e) {
        $diagResult['2_contacts'] = ['label'=>$tests['2_contacts']['label'], 'ok'=>false, 'error'=>$e->getMessage()];
    }

    // Test 3: /products
    try {
        $prodRes = $p->listProducts(1, 25);
        $diagResult['3_products'] = [
            'label' => $tests['3_products']['label'],
            'desc'  => $tests['3_products']['desc'],
            'ok'    => !empty($prodRes['data']),
            'count' => count($prodRes['data']),
            'err'   => $prodRes['err'] ?? null,
        ];
    } catch (\Throwable $e) {
        $diagResult['3_products'] = ['label'=>$tests['3_products']['label'], 'ok'=>false, 'error'=>$e->getMessage()];
    }

    // Test 4: /accounts
    try {
        $accs = $p->listAccounts();
        $diagResult['4_accounts'] = [
            'label' => $tests['4_accounts']['label'],
            'desc'  => $tests['4_accounts']['desc'],
            'ok'    => !empty($accs),
            'count' => count($accs),
        ];
    } catch (\Throwable $e) {
        $diagResult['4_accounts'] = ['label'=>$tests['4_accounts']['label'], 'ok'=>false, 'error'=>$e->getMessage()];
    }
}

// ── Tüm ürünleri toplu senkronize et
if (isPost() && ($_POST['action'] ?? '') === 'bulk_sync_products') {
    csrfCheck();
    try {
        $result = parasut()->bulkSyncProducts();
        if ($result['total'] === 0) {
            $msg = 'error:Senkronize edilecek aktif ürün bulunamadı.';
        } else {
            $msg = "success:Toplam {$result['total']} ürün işlendi — Başarılı: {$result['success']}, Hatalı: {$result['fail']}";
        }
    } catch (\Throwable $e) {
        $msg = 'error:Toplu senkron hatası: ' . $e->getMessage();
    }
}

// ── Tüm bayileri toplu senkronize et
if (isPost() && ($_POST['action'] ?? '') === 'bulk_sync_dealers') {
    csrfCheck();
    $dealers = dbRows("SELECT * FROM b2b_dealers WHERE is_active=1");
    $success = 0; $fail = 0;
    foreach ($dealers as $d) {
        try {
            $cid = parasut()->syncDealer($d);
            if ($cid) {
                dbExec("UPDATE b2b_dealers SET parasut_contact_id=? WHERE id=?", [$cid, $d['id']]);
                $success++;
            } else { $fail++; }
        } catch (\Throwable $e) { $fail++; }
    }
    $msg = "success:Toplam " . count($dealers) . " bayi işlendi — Başarılı: $success, Hatalı: $fail";
}

// Paraşüt log
$logs = dbRows("SELECT * FROM b2b_parasut_log ORDER BY created_at DESC LIMIT 30");

// İstatistik
$totalDealers   = (int)dbVal("SELECT COUNT(*) FROM b2b_dealers WHERE is_active=1");
$syncedDealers  = (int)dbVal("SELECT COUNT(*) FROM b2b_dealers WHERE parasut_contact_id IS NOT NULL AND parasut_contact_id != ''");
$syncedInvoices = (int)dbVal("SELECT COUNT(*) FROM b2b_orders WHERE parasut_invoice_id IS NOT NULL AND parasut_invoice_id != ''");
$syncedPayments = (int)dbVal("SELECT COUNT(*) FROM b2b_payments WHERE parasut_payment_id IS NOT NULL AND parasut_payment_id != ''");
$totalProducts  = (int)dbVal("SELECT COUNT(*) FROM b2b_products WHERE is_active=1");
$syncedProducts = (int)dbVal("SELECT COUNT(*) FROM b2b_products WHERE parasut_product_id IS NOT NULL AND parasut_product_id != ''");

$hasToken    = !empty(setting('parasut_email')) && !empty(setting('parasut_company_id')) && !empty(setting('parasut_client_id'));
$tokenCache  = setting('parasut_token_cache', '');
$tokenExpiry = null;
if ($tokenCache && strpos($tokenCache, '|') !== false) {
    $parts = explode('|', $tokenCache);
    if (count($parts) === 2) $tokenExpiry = (int)$parts[1];
}
$tokenValid = $tokenExpiry && $tokenExpiry > time();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Paraşüt Entegrasyonu</h1>
    <p class="page-sub">Muhasebe ve fatura senkronizasyonu</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="?page=parasut&test=1" class="btn btn-secondary">🔌 Bağlantıyı Test Et</a>
    <a href="?page=parasut&diag=1" class="btn btn-secondary" style="background:#7c3aed;color:#fff;border-color:#7c3aed">🩺 Endpoint Tanı</a>
    <?php if ($tokenCache): ?>
    <a href="?page=parasut&clear_token=1" class="btn btn-secondary" onclick="return confirm('Token cache temizlensin mi? Bir sonraki istekte yeniden alınır.');">🔄 Token Yenile</a>
    <?php endif; ?>
    <a href="?page=parasut-mapping" class="btn btn-primary" style="background:#7c3aed;border-color:#7c3aed">🔗 Eşleme Sayfası</a>
    <a href="?page=settings&tab=parasut" class="btn btn-primary">⚙ Ayarları Düzenle</a>
  </div>
</div>

<?php if ($msg): [$t,$m] = explode(':', $msg, 2); ?>
<div class="alert alert-<?= $t==='error'?'danger':'success' ?>">
  <?php if ($t === 'success'): ?>
    <?= $m // success mesajında sadece <strong> ve text var, güvenli ?>
  <?php else: ?>
    <?= h($m) ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($diagResult): ?>
<!-- ─── Endpoint Tanı Sonuçları ─── -->
<div class="card" style="margin-bottom:20px;border:2px solid #7c3aed">
  <div class="card-header" style="background:linear-gradient(135deg,#ede9fe,#ddd6fe);color:#5b21b6">
    <h3 class="card-title" style="display:flex;align-items:center;gap:8px;color:#5b21b6">
      🩺 Endpoint Tanı Sonuçları
    </h3>
  </div>
  <div class="card-body" style="padding:16px">
    <p style="margin:0 0 14px;font-size:12px;color:var(--text-muted)">
      Aşağıdaki test her Paraşüt API endpoint'ini ayrı ayrı çağırır. Sonuçlar sorunun yerini tespit etmenize yardımcı olur.
    </p>
    <?php foreach ($diagResult as $key => $r):
        $ok = !empty($r['ok']);
        $bg = $ok ? '#f0fdf4' : '#fef2f2';
        $border = $ok ? '#86efac' : '#fca5a5';
        $iconBg = $ok ? '#16a34a' : '#dc2626';
        $icon = $ok ? '✓' : '✗';
    ?>
    <div style="background:<?= $bg ?>;border:1px solid <?= $border ?>;border-radius:8px;padding:12px 14px;margin-bottom:10px">
      <div style="display:flex;align-items:flex-start;gap:12px">
        <div style="background:<?= $iconBg ?>;color:#fff;width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0">
          <?= $icon ?>
        </div>
        <div style="flex:1">
          <div style="font-weight:700;font-size:13px;color:#111"><?= h($r['label']) ?></div>
          <?php if (!empty($r['desc'])): ?>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= h($r['desc']) ?></div>
          <?php endif; ?>

          <?php if ($ok): ?>
            <div style="margin-top:6px;font-size:12px;color:#166534">
              <strong><?= (int)($r['count'] ?? 0) ?></strong> kayıt çekildi
              <?php if (isset($r['http'])): ?>
              · HTTP <strong><?= h($r['http']) ?></strong>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div style="margin-top:6px;font-size:12px;color:#b91c1c">
              <?php if (!empty($r['error'])): ?>
              <strong>Exception:</strong> <?= h($r['error']) ?>
              <?php endif; ?>
              <?php if (!empty($r['err'])): ?>
              <strong>API Hatası:</strong> <?= h($r['err']) ?>
              <?php endif; ?>
              <?php if (!empty($r['http']) && empty($r['error'])): ?>
              · HTTP <strong><?= h($r['http']) ?></strong>
              <?php endif; ?>
            </div>
            <?php if (!empty($r['snippet'])): ?>
            <details style="margin-top:8px">
              <summary style="cursor:pointer;font-size:11px;color:#7f1d1d;font-weight:600">📄 Ham yanıt göster</summary>
              <pre style="background:#fff;border:1px solid #fca5a5;padding:8px;border-radius:4px;margin-top:6px;font-size:10px;overflow:auto;max-height:200px"><?= h($r['snippet']) ?></pre>
            </details>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Yorumlama Kılavuzu -->
    <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:10px 12px;margin-top:14px;font-size:12px;color:#78350f">
      <strong>💡 Sonuç yorumu:</strong>
      <ul style="margin:6px 0 0 18px;padding:0">
        <li><strong>/me başarılı + diğerleri başarısız</strong> → Token doğru ama <code>parasut_company_id</code> yanlış veya kullanıcının o firma'ya erişimi yok</li>
        <li><strong>/me başarısız</strong> → Token sorunu, "Token Yenile" butonuna basın</li>
        <li><strong>/me + /contacts başarılı + 0 kayıt</strong> → Paraşüt hesabınız gerçekten boş (kayıt yok)</li>
        <li><strong>HTTP 422</strong> → Parametre formatı yanlış (örn page[size] > 25)</li>
        <li><strong>HTTP 401</strong> → Token süresi dolmuş veya geçersiz</li>
        <li><strong>HTTP 403</strong> → Yetki yok (API kullanıcısı bu kaynağa erişemiyor)</li>
        <li><strong>HTTP 404</strong> → Yanlış company_id veya endpoint bulunamadı</li>
      </ul>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Durum Kartları -->
<div class="stats-grid" style="margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-icon <?= $hasToken?'green':'red' ?>">
      <?php if ($hasToken): ?>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      <?php else: ?>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      <?php endif; ?>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $hasToken ? 'Aktif' : 'Eksik' ?></div>
      <div class="stat-label">Paraşüt Hesabı</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $syncedDealers ?> / <?= $totalDealers ?></div>
      <div class="stat-label">Senkronize Bayi</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $syncedProducts ?> / <?= $totalProducts ?></div>
      <div class="stat-label">Senkronize Ürün</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $syncedInvoices ?></div>
      <div class="stat-label">Oluşturulan Fatura</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $syncedPayments ?></div>
      <div class="stat-label">Senkronize Ödeme</div>
    </div>
  </div>
</div>

<!-- Ayar Özeti / Bağlantı Yok Uyarısı -->
<?php if (!$hasToken): ?>
<div class="alert alert-warning">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  Paraşüt entegrasyonu henüz yapılandırılmamış.
  <a href="?page=settings&tab=parasut">Ayarları tamamlayın →</a>
</div>
<?php else: ?>
<!-- ═══════════════════════ DEBUG / TANI PANELİ ═══════════════════════ -->
<details<?= $debugResult || ($msg && str_starts_with($msg, 'error')) ? ' open' : '' ?> style="background:#fff;border:1px solid var(--border);border-radius:12px;margin-bottom:20px;overflow:hidden">
  <summary style="padding:14px 18px;background:#fafafa;border-bottom:1px solid var(--border);cursor:pointer;font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px;list-style:none">
    🔍 Token Tanı Aracı
    <span style="font-size:11px;color:var(--text-muted);font-weight:400;margin-left:auto">Bağlantı hatası alıyorsan tıkla</span>
  </summary>
  <div style="padding:18px">
    <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px;line-height:1.6">
      Token alma hatası alıyorsan, e-posta ve şifreni <strong>elden tekrar girip test edebilirsin</strong>.
      Bu form kayıtlı şifreyi DEĞİL, buraya yazdığını kullanır.
      Başarılı olursa otomatik kaydeder.
    </p>

    <form method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="debug_token">
      <div>
        <label style="font-size:12px;font-weight:600;color:var(--text);margin-bottom:4px;display:block">E-posta</label>
        <input type="email" name="debug_email" class="form-control" required
               value="<?= h(setting('parasut_email')) ?>" placeholder="info@firma.com">
      </div>
      <div>
        <label style="font-size:12px;font-weight:600;color:var(--text);margin-bottom:4px;display:block">Şifre <span style="color:var(--text-muted);font-weight:400">(yeniden gir)</span></label>
        <input type="password" name="debug_password" class="form-control" required
               placeholder="Paraşüt şifresi" autocomplete="off">
      </div>
      <div style="grid-column:span 2">
        <label style="font-size:12px;font-weight:600;color:var(--text);margin-bottom:4px;display:block">
          Redirect URI <span style="color:var(--text-muted);font-weight:400">(Paraşüt destek tarafından verilen — varsayılan değer doğru)</span>
        </label>
        <input type="text" name="debug_redirect" class="form-control"
               value="urn:ietf:wg:oauth:2.0:oob"
               style="font-family:monospace;font-size:12px">
      </div>
      <div style="grid-column:span 2;display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding-top:4px">
        <button type="submit" class="btn btn-primary" style="background:#0ea5e9;border:none">🔌 Token Almayı Dene</button>
        <span style="font-size:11px;color:var(--text-muted)">Başarılı olursa otomatik kaydeder ve token cache'ler</span>
      </div>
    </form>

    <?php if ($debugResult): ?>
    <div style="background:#1e293b;color:#e2e8f0;padding:16px 18px;border-radius:8px;font-family:monospace;font-size:12px;line-height:1.6;overflow-x:auto;margin-top:12px">
      <div style="margin-bottom:10px">
        <strong style="color:#fbbf24">HTTP Kodu:</strong>
        <span style="color:<?= $debugResult['http_code'] < 400 ? '#22c55e' : '#ef4444' ?>;font-weight:700"><?= $debugResult['http_code'] ?: 'N/A' ?></span>
      </div>
      <?php if ($debugResult['curl_error']): ?>
      <div style="margin-bottom:10px;color:#ef4444">
        <strong>cURL Hatası:</strong> <?= h($debugResult['curl_error']) ?>
      </div>
      <?php endif; ?>
      <div style="margin-bottom:10px">
        <strong style="color:#fbbf24">Gönderilen parametreler:</strong>
        <pre style="margin:6px 0 0;color:#94a3b8;white-space:pre-wrap"><?= h(json_encode($debugResult['sent_params'], JSON_PRETTY_PRINT)) ?></pre>
      </div>
      <div>
        <strong style="color:#fbbf24">Paraşüt Yanıtı:</strong>
        <pre style="margin:6px 0 0;color:#94a3b8;white-space:pre-wrap;word-break:break-all"><?= h($debugResult['raw_response'] ?: '(boş)') ?></pre>
      </div>
    </div>
    <?php endif; ?>

    <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:12px 14px;margin-top:14px;font-size:12px;line-height:1.7">
      <strong style="color:#92400e">💡 invalid_grant hatası ne demek?</strong><br>
      <strong>Client ID ve Secret doğru</strong> (yoksa <code>invalid_client</code> dönerdi). Sorun <strong>kullanıcı doğrulamasında</strong>.<br>
      Olası nedenler:
      <ul style="margin:6px 0 0 18px;padding:0">
        <li><strong>2FA (iki aşamalı doğrulama) açık</strong> → Paraşüt → Güvenlik'ten kapat</li>
        <li><strong>E-posta veya şifre yanlış</strong> → uygulama.parasut.com'a manuel giriş yap, kontrol et</li>
        <li><strong>Şifrede özel karakter var</strong> (örn <code>%</code>, <code>&</code>) → harf+rakam olan yeni şifre belirle</li>
        <li><strong>Hesap askıda</strong> veya şifre süresi dolmuş → Paraşüt'ten kontrol et</li>
      </ul>
    </div>

    <!-- Destek başvuru şablonu -->
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 16px;margin-top:12px;font-size:12px;line-height:1.6">
      <div style="font-weight:700;color:#1e40af;font-size:13px;margin-bottom:8px">
        📧 Yukarıdakileri denedin ve hâlâ olmuyorsa — Paraşüt destek ekibine yaz
      </div>
      <div style="margin-bottom:8px;color:#1e3a8a">
        <strong>Adres:</strong>
        <a href="mailto:destek@parasut.com" style="color:#1d4ed8">destek@parasut.com</a>
      </div>
      <details style="margin-top:8px">
        <summary style="cursor:pointer;font-weight:600;color:#1e40af;font-size:12px;padding:4px 0">📋 Hazır e-posta şablonu (tıkla)</summary>
        <pre id="support-template" style="margin-top:8px;background:#fff;border:1px solid #c7d2fe;border-radius:6px;padding:12px 14px;font-family:monospace;font-size:11px;color:#1e293b;white-space:pre-wrap;line-height:1.6">Merhaba,

OAuth2 password grant ile API token alma denemelerimizde sürekli
"invalid_grant" hatası alıyoruz. HTTP 400 dönüyor.

Hesap bilgilerimiz:
- E-posta: <?= h(setting('parasut_email') ?: 'info@lemondedutacos.com') ?>

- Client ID: <?= h(setting('parasut_client_id')) ?>


- Firma ID: <?= h(setting('parasut_company_id')) ?>


Lütfen kontrol eder misiniz:
1. Yukarıdaki Client ID gerçekten bizim hesabımıza mı bağlı?
2. Hesabımızda 2FA (iki aşamalı doğrulama) aktif mi? Aktifse,
   password grant kullanamadığımız için authorization_code grant'a
   geçiş yapmamız mı gerekiyor?
3. API erişimi hesabımızda aktif mi?
4. Eğer authorization_code akışına geçmemiz gerekiyorsa, callback
   URL'imizi hangi adres olarak ayarlamamız gerekir?

Teşekkür ederim,
<?= h(setting('site_name', 'Le Monde Du Tacos B2B')) ?></pre>
        <button type="button" class="btn btn-sm" style="background:#1d4ed8;color:#fff;border:none;margin-top:8px" onclick="
          const el = document.getElementById('support-template');
          navigator.clipboard.writeText(el.textContent).then(() => {
            this.textContent = '✓ Kopyalandı';
            setTimeout(() => this.textContent = '📋 Şablonu Kopyala', 2000);
          });
        ">📋 Şablonu Kopyala</button>
      </details>
    </div>
  </div>
</details>

<div class="card" style="margin-bottom:20px">
  <div class="card-header"><h3 class="card-title">Mevcut Ayarlar</h3></div>
  <div class="card-body">
    <div class="form-grid-2">
      <div>
        <div class="stat-label">E-posta</div>
        <div style="font-weight:600;margin-top:2px"><?= h(setting('parasut_email')) ?></div>
      </div>
      <div>
        <div class="stat-label">Firma ID</div>
        <div style="font-weight:600;margin-top:2px"><?= h(setting('parasut_company_id')) ?></div>
      </div>
      <div>
        <div class="stat-label">Client ID</div>
        <div style="font-weight:600;margin-top:2px;font-family:monospace;font-size:11px;word-break:break-all"><?= h(substr(setting('parasut_client_id'), 0, 20) . '…') ?></div>
      </div>
      <div>
        <div class="stat-label">Token Durumu</div>
        <div style="margin-top:2px">
          <?php if ($tokenValid): ?>
          <span class="badge badge-success">Geçerli — <?= date('d.m.Y H:i', $tokenExpiry) ?> kadar</span>
          <?php elseif ($tokenCache): ?>
          <span class="badge badge-danger">Süresi Dolmuş</span>
          <?php else: ?>
          <span class="badge badge-neutral">Henüz alınmadı</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Toplu Senkronizasyon — ARTIK ESLEME SAYFASINDAN -->
<div class="card" style="margin-bottom:20px;border:2px solid #fcd34d;background:#fffbeb">
  <div class="card-header" style="background:transparent;border-bottom:1px solid #fde68a">
    <h3 class="card-title" style="color:#92400e">⚠️ Toplu Senkronizasyon — Eşleme Üzerinden Yapın</h3>
  </div>
  <div class="card-body">
    <p style="font-size:13px;color:#78350f;margin:0 0 14px;line-height:1.6">
      <strong>Doğrudan toplu senkron <u>çift kayıt</u> riski taşır.</strong> Paraşüt'te zaten bir cari/ürün varsa
      yenisi oluşur. Önce <strong>Eşleme</strong> sayfasında Paraşüt'tekilerle bayilerinizi/ürünlerinizi
      eşleştirin (otomatik veya manuel), sonra eşleşmemiş olanlar için "Paraşüt'te Oluştur" butonu kullanın.
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <a href="?page=parasut-mapping&tab=dealers" class="btn btn-primary" style="background:#d97706;border-color:#d97706">
        👥 Bayi Eşleme Sayfası
      </a>
      <a href="?page=parasut-mapping&tab=products" class="btn btn-primary" style="background:#d97706;border-color:#d97706">
        📦 Ürün Eşleme Sayfası
      </a>
      <details style="margin-left:auto;font-size:11px">
        <summary style="cursor:pointer;color:#92400e">Yine de körlemesine senkron istiyorum…</summary>
        <div style="margin-top:10px;display:flex;gap:8px">
          <form method="post" onsubmit="return confirm('⚠️ ÇİFT KAYIT RİSKİ\n\n<?= $totalDealers - $syncedDealers ?> bayi Paraşüt\'e körlemesine gönderilecek. Paraşüt\'te aynı isim/vergi no\'lu cari varsa YENİSİ oluşur.\n\nGerçekten devam edilsin mi? (önerilmez)');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="bulk_sync_dealers">
            <button type="submit" class="btn btn-sm btn-secondary"<?= ($totalDealers - $syncedDealers) <= 0 ? ' disabled' : '' ?>>
              Bayileri zorla gönder (<?= $totalDealers - $syncedDealers ?>)
            </button>
          </form>
          <form method="post" onsubmit="return confirm('⚠️ ÇİFT KAYIT RİSKİ\n\n<?= $totalProducts ?> ürün Paraşüt\'e körlemesine gönderilecek.\n\nGerçekten devam edilsin mi?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="bulk_sync_products">
            <button type="submit" class="btn btn-sm btn-secondary"<?= $totalProducts <= 0 ? ' disabled' : '' ?>>
              Ürünleri zorla gönder (<?= $totalProducts ?>)
            </button>
          </form>
        </div>
      </details>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- İşlem Logu -->
<div class="card">
  <div class="card-header"><h3 class="card-title">İşlem Geçmişi (Son 30)</h3></div>
  <?php if (empty($logs)): ?>
  <div class="card-body" style="text-align:center;color:var(--text-muted);padding:32px">
    Henüz işlem kaydı yok.
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>Tarih</th><th>İşlem</th><th>Durum</th><th>Detay (Response)</th></tr>
      </thead>
      <tbody>
      <?php foreach ($logs as $l): ?>
      <tr>
        <td style="font-size:12px;color:var(--text-muted);white-space:nowrap"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></td>
        <td><?= h($l['action']) ?></td>
        <td><span class="badge badge-<?= $l['status']==='success'?'success':'danger' ?>"><?= h($l['status']) ?></span></td>
        <td style="font-size:11px;color:var(--text-muted);max-width:500px;font-family:monospace">
          <?= h(mb_substr($l['response'] ?? '', 0, 200)) ?><?= mb_strlen($l['response'] ?? '') > 200 ? '…' : '' ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ─── Paraşüt API V4 Kapsam ─── -->
<details style="margin-top:20px;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden">
  <summary style="cursor:pointer;padding:14px 18px;background:#f8fafc;font-weight:700;font-size:14px;display:flex;align-items:center;gap:10px">
    <span style="font-size:18px">🔌</span>
    Paraşüt API V4 Kapsam — Entegrasyon Durumu
    <span style="margin-left:auto;font-size:12px;color:var(--text-muted);font-weight:400">Hangi endpoint'ler aktif</span>
  </summary>
  <div style="padding:16px 18px">
    <p style="font-size:12px;color:var(--text-muted);margin:0 0 14px">
      Aşağıda Paraşüt API V4'ün tüm temel endpoint'leri ve B2B sistemindeki entegrasyon durumu listelenmiştir.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px">
      <?php
      $apiCoverage = [
          ['name'=>'Cariler (contacts)',          'method'=>'listAllContacts',           'desc'=>'Müşteri/tedarikçi listesi',      'status'=>'active'],
          ['name'=>'Ürünler (products)',           'method'=>'listAllProducts',           'desc'=>'Stok kayıtları',                  'status'=>'active'],
          ['name'=>'Satış Faturaları (sales_invoices)','method'=>'syncInvoice',           'desc'=>'B2B sipariş → fatura',            'status'=>'active'],
          ['name'=>'E-Arşiv (e_archives)',         'method'=>'createEArchive',            'desc'=>'B2C tüketici faturası',          'status'=>'active'],
          ['name'=>'E-Fatura (e_invoices)',        'method'=>'createEInvoice',            'desc'=>'B2B kurumsal fatura',            'status'=>'active'],
          ['name'=>'E-Fatura Kayıtlı Kontrol',     'method'=>'isEInvoiceUser',            'desc'=>'VKN e-fatura mükellefi mi?',      'status'=>'active'],
          ['name'=>'Ödemeler (payments)',          'method'=>'createPayment',             'desc'=>'Banka tahsilatı kaydı',          'status'=>'active'],
          ['name'=>'Cari Bakiye',                  'method'=>'getContactBalance',         'desc'=>'Bayi açık bakiye sorgu',         'status'=>'active'],
          ['name'=>'Banka/Kasa Hesapları',         'method'=>'listAccounts',              'desc'=>'Ödeme yapılacak hesap seçimi',  'status'=>'active'],
          ['name'=>'Manuel Borç/Alacak',           'method'=>'debit/creditContact',       'desc'=>'Cari hareket girişi',            'status'=>'active'],
          ['name'=>'Kategoriler (item_categories)','method'=>'listCategories',            'desc'=>'Ürün/cari kategorileri',         'status'=>'new'],
          ['name'=>'Etiketler (tags)',             'method'=>'listTags',                  'desc'=>'Renk/etiket yönetimi',           'status'=>'new'],
          ['name'=>'Satış Teklifleri (sales_offers)','method'=>'listSalesOffers',         'desc'=>'Teklif → siparişe dönüşüm',     'status'=>'new'],
          ['name'=>'İrsaliyeler (shipment_documents)','method'=>'create/listShipmentDocuments','desc'=>'Sipariş sevkiyat irsaliyesi','status'=>'new'],
          ['name'=>'Stok Seviyesi (inventory)',    'method'=>'getProductInventory',       'desc'=>'Paraşüt → B2B stok senkron',     'status'=>'new'],
          ['name'=>'Async İş Takibi (trackable_jobs)','method'=>'getTrackableJob, waitForJob','desc'=>'E-belge oluşturma durum',     'status'=>'new'],
          ['name'=>'Webhook (real-time)',          'method'=>'list/create/deleteWebhook', 'desc'=>'Paraşüt → B2B canlı event',      'status'=>'new'],
          ['name'=>'Cari Arşivleme',               'method'=>'archive/unarchiveContact',  'desc'=>'Silmek yerine pasif yap',        'status'=>'new'],
      ];
      foreach ($apiCoverage as $a):
          $statusInfo = match($a['status']) {
              'active' => ['bg'=>'#f0fdf4','border'=>'#86efac','color'=>'#15803d','icon'=>'✓','label'=>'AKTİF'],
              'new'    => ['bg'=>'#eff6ff','border'=>'#93c5fd','color'=>'#1d4ed8','icon'=>'✨','label'=>'YENİ'],
              default  => ['bg'=>'#fef3c7','border'=>'#fcd34d','color'=>'#92400e','icon'=>'?','label'=>'PASİF'],
          };
      ?>
      <div style="background:<?= $statusInfo['bg'] ?>;border:1px solid <?= $statusInfo['border'] ?>;border-radius:8px;padding:10px 12px">
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">
          <span style="background:<?= $statusInfo['color'] ?>;color:#fff;font-size:9px;font-weight:700;padding:2px 6px;border-radius:3px"><?= $statusInfo['icon'] ?> <?= $statusInfo['label'] ?></span>
        </div>
        <div style="font-weight:700;font-size:12px;color:#111"><?= h($a['name']) ?></div>
        <div style="font-size:10px;color:var(--text-muted);margin-top:2px"><?= h($a['desc']) ?></div>
        <code style="font-size:10px;color:<?= $statusInfo['color'] ?>;display:block;margin-top:4px;background:#fff;padding:2px 6px;border-radius:3px"><?= h($a['method']) ?></code>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;font-size:12px;color:#075985">
      <strong>📚 Doküman:</strong> <a href="https://apidocs.parasut.com/" target="_blank" style="color:#075985;text-decoration:underline">apidocs.parasut.com</a> — Paraşüt API V4 resmi dokümantasyonu
    </div>
  </div>
</details>

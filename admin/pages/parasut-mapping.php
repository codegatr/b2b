<?php
/**
 * Admin — Paraşüt Eşleme (Mapping)
 *
 * Amaç: Çift kayıt önleme. Bayi ve ürünleri Paraşüt'tekilerle
 * önceden eşleştir (otomatik veya manuel), sonra eksik olanları
 * Paraşüt'te yeni oluştur.
 *
 * URL: ?page=parasut-mapping&tab=dealers|products
 */
requireAdmin();

if (!parasut()->isEnabled()) {
    echo '<div class="alert alert-warning" style="margin:20px">Paraşüt entegrasyonu yapılandırılmamış. Önce <a href="?page=settings&tab=parasut">Ayarlar</a> sayfasından credentials gir.</div>';
    return;
}

$tab = $_GET['tab'] ?? 'dealers';
if (!in_array($tab, ['dealers', 'products'], true)) $tab = 'dealers';
$msg = '';

// ──────────────────────────────────────────────────────────────
// POST HANDLERS
// ──────────────────────────────────────────────────────────────

// Bayi → Paraşüt cari eşleme (manuel)
if (isPost() && ($_POST['action'] ?? '') === 'map-dealer') {
    csrfCheck();
    $dealerId   = (int)($_POST['dealer_id'] ?? 0);
    $parasutId  = trim($_POST['parasut_id'] ?? '');
    if ($dealerId) {
        if ($parasutId === '' || $parasutId === '-') {
            dbExec("UPDATE b2b_dealers SET parasut_contact_id=NULL WHERE id=?", [$dealerId]);
            $msg = 'success:Eşleme kaldırıldı.';
        } else {
            dbExec("UPDATE b2b_dealers SET parasut_contact_id=? WHERE id=?", [$parasutId, $dealerId]);
            $msg = 'success:Eşleme kaydedildi.';
        }
    }
}

// Ürün → Paraşüt ürün eşleme (manuel)
if (isPost() && ($_POST['action'] ?? '') === 'map-product') {
    csrfCheck();
    $productId = (int)($_POST['product_id'] ?? 0);
    $parasutId = trim($_POST['parasut_id'] ?? '');
    if ($productId) {
        if ($parasutId === '' || $parasutId === '-') {
            dbExec("UPDATE b2b_products SET parasut_product_id=NULL WHERE id=?", [$productId]);
            $msg = 'success:Eşleme kaldırıldı.';
        } else {
            dbExec("UPDATE b2b_products SET parasut_product_id=? WHERE id=?", [$parasutId, $productId]);
            $msg = 'success:Eşleme kaydedildi.';
        }
    }
}

// Akıllı otomatik eşleştirme — bayiler
if (isPost() && ($_POST['action'] ?? '') === 'auto-match-dealers') {
    csrfCheck();
    $contacts = parasut()->listAllContacts();
    $dealers  = dbRows("SELECT * FROM b2b_dealers WHERE is_active=1 AND (parasut_contact_id IS NULL OR parasut_contact_id='')");

    $matched = 0;
    foreach ($dealers as $d) {
        $candidate = null;

        // Önce vergi no eşleşmesi (en güvenilir)
        $taxNo = trim($d['tax_number'] ?? '');
        if ($taxNo !== '') {
            foreach ($contacts as $c) {
                $cTax = trim($c['attributes']['tax_number'] ?? '');
                if ($cTax !== '' && $cTax === $taxNo) {
                    $candidate = $c;
                    break;
                }
            }
        }

        // Sonra e-mail eşleşmesi
        if (!$candidate && !empty($d['email'])) {
            $email = strtolower(trim($d['email']));
            foreach ($contacts as $c) {
                $cEmail = strtolower(trim($c['attributes']['email'] ?? ''));
                if ($cEmail !== '' && $cEmail === $email) {
                    $candidate = $c;
                    break;
                }
            }
        }

        // En son isim eşleşmesi (case-insensitive, tam)
        if (!$candidate && !empty($d['firm_name'])) {
            $name = mb_strtolower(trim($d['firm_name']));
            foreach ($contacts as $c) {
                $cName = mb_strtolower(trim($c['attributes']['name'] ?? ''));
                if ($cName !== '' && $cName === $name) {
                    $candidate = $c;
                    break;
                }
            }
        }

        if ($candidate) {
            dbExec("UPDATE b2b_dealers SET parasut_contact_id=? WHERE id=?", [$candidate['id'], $d['id']]);
            $matched++;
        }
    }
    $msg = "success:Otomatik eşleştirme tamamlandı. {$matched} bayi Paraşüt cari kayıtlarıyla eşleştirildi.";
}

// Akıllı otomatik eşleştirme — ürünler
if (isPost() && ($_POST['action'] ?? '') === 'auto-match-products') {
    csrfCheck();
    $parasutProducts = parasut()->listAllProducts();
    $products = dbRows("SELECT * FROM b2b_products WHERE is_active=1 AND (parasut_product_id IS NULL OR parasut_product_id='')");

    $matched = 0;
    foreach ($products as $p) {
        $candidate = null;

        // Önce SKU (kod) eşleşmesi
        $sku = trim($p['sku'] ?? '');
        if ($sku !== '') {
            foreach ($parasutProducts as $pp) {
                $ppCode = trim($pp['attributes']['code'] ?? '');
                if ($ppCode !== '' && $ppCode === $sku) {
                    $candidate = $pp;
                    break;
                }
            }
        }

        // Sonra isim eşleşmesi (tam, case-insensitive)
        if (!$candidate && !empty($p['name'])) {
            $name = mb_strtolower(trim($p['name']));
            foreach ($parasutProducts as $pp) {
                $ppName = mb_strtolower(trim($pp['attributes']['name'] ?? ''));
                if ($ppName !== '' && $ppName === $name) {
                    $candidate = $pp;
                    break;
                }
            }
        }

        if ($candidate) {
            dbExec("UPDATE b2b_products SET parasut_product_id=? WHERE id=?", [$candidate['id'], $p['id']]);
            $matched++;
        }
    }
    $msg = "success:Otomatik eşleştirme tamamlandı. {$matched} ürün Paraşüt stok kayıtlarıyla eşleştirildi.";
}

// Eşleşmemiş tek bayiyi Paraşüt'te oluştur
if (isPost() && ($_POST['action'] ?? '') === 'create-dealer') {
    csrfCheck();
    $dealerId = (int)($_POST['dealer_id'] ?? 0);
    if ($dealerId) {
        try {
            $newId = parasut()->syncDealer($dealerId);
            if ($newId) {
                dbExec("UPDATE b2b_dealers SET parasut_contact_id=? WHERE id=?", [$newId, $dealerId]);
                $msg = "success:Paraşüt'te yeni cari oluşturuldu (ID: {$newId}).";
            } else {
                $msg = 'error:Cari oluşturulamadı. İşlem geçmişinde detayı görün.';
            }
        } catch (\Throwable $e) {
            $msg = 'error:Hata: ' . $e->getMessage();
        }
    }
}

// Eşleşmemiş tek ürünü Paraşüt'te oluştur
if (isPost() && ($_POST['action'] ?? '') === 'create-product') {
    csrfCheck();
    $productId = (int)($_POST['product_id'] ?? 0);
    if ($productId) {
        try {
            $newId = parasut()->syncProduct($productId);
            if ($newId) {
                $msg = "success:Paraşüt'te yeni ürün oluşturuldu (ID: {$newId}).";
            } else {
                $msg = 'error:Ürün oluşturulamadı. İşlem geçmişinde detayı görün.';
            }
        } catch (\Throwable $e) {
            $msg = 'error:Hata: ' . $e->getMessage();
        }
    }
}

// ──────────────────────────────────────────────────────────────
// DATA HAZIRLA (her render'da)
// ──────────────────────────────────────────────────────────────

if ($tab === 'dealers') {
    $b2bDealers = dbRows("SELECT id, firm_name, email, phone, tax_number, parasut_contact_id FROM b2b_dealers WHERE is_active=1 ORDER BY firm_name");

    // Paraşüt'ten cari listesi
    $parasutContacts = parasut()->listAllContacts();

    // Hızlı lookup için ID→object map
    $parasutContactsById = [];
    foreach ($parasutContacts as $c) {
        $parasutContactsById[$c['id']] = $c;
    }

    // Eşleşmiş bayilerin Paraşüt ID'lerini topla → sadece Paraşüt'te olanları bul
    $usedContactIds = array_filter(array_column($b2bDealers, 'parasut_contact_id'));
    $orphanContacts = []; // Paraşüt'te olup B2B'de eşleşmemiş cariler
    foreach ($parasutContacts as $c) {
        if (!in_array($c['id'], $usedContactIds, true)) $orphanContacts[] = $c;
    }

    $stats = [
        'total_b2b'       => count($b2bDealers),
        'matched'         => count(array_filter($b2bDealers, fn($d) => !empty($d['parasut_contact_id']))),
        'unmatched_b2b'   => count(array_filter($b2bDealers, fn($d) => empty($d['parasut_contact_id']))),
        'total_parasut'   => count($parasutContacts),
        'orphan_parasut'  => count($orphanContacts),
    ];
} else {
    // products tab
    $b2bProducts = dbRows("SELECT id, name, sku, base_price, vat_rate, parasut_product_id FROM b2b_products WHERE is_active=1 ORDER BY name");

    $parasutProducts = parasut()->listAllProducts();

    $parasutProductsById = [];
    foreach ($parasutProducts as $p) {
        $parasutProductsById[$p['id']] = $p;
    }

    $usedProductIds = array_filter(array_column($b2bProducts, 'parasut_product_id'));
    $orphanProducts = [];
    foreach ($parasutProducts as $p) {
        if (!in_array($p['id'], $usedProductIds, true)) $orphanProducts[] = $p;
    }

    $stats = [
        'total_b2b'       => count($b2bProducts),
        'matched'         => count(array_filter($b2bProducts, fn($p) => !empty($p['parasut_product_id']))),
        'unmatched_b2b'   => count(array_filter($b2bProducts, fn($p) => empty($p['parasut_product_id']))),
        'total_parasut'   => count($parasutProducts),
        'orphan_parasut'  => count($orphanProducts),
    ];
}
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Paraşüt — Eşleme & Önizleme</h1>
    <p class="page-sub">Bayileri ve ürünleri Paraşüt kayıtlarıyla eşleştirin · Çift kayıt önleyin</p>
  </div>
  <div class="btn-group">
    <a href="?page=parasut" class="btn btn-secondary btn-sm">← Paraşüt Anasayfa</a>
  </div>
</div>

<?php if ($msg): [$t,$m] = explode(':', $msg, 2); ?>
<div class="alert alert-<?= $t==='error'?'danger':'success' ?>"><?= h($m) ?></div>
<?php endif; ?>

<!-- Sekme Navigasyonu -->
<div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--border)">
  <a href="?page=parasut-mapping&tab=dealers" style="padding:10px 18px;border-bottom:2px solid <?= $tab==='dealers'?'var(--accent)':'transparent' ?>;color:<?= $tab==='dealers'?'var(--text)':'var(--text-muted)' ?>;font-weight:<?= $tab==='dealers'?'700':'500' ?>;margin-bottom:-2px;text-decoration:none">
    👥 Bayiler (Cariler)
  </a>
  <a href="?page=parasut-mapping&tab=products" style="padding:10px 18px;border-bottom:2px solid <?= $tab==='products'?'var(--accent)':'transparent' ?>;color:<?= $tab==='products'?'var(--text)':'var(--text-muted)' ?>;font-weight:<?= $tab==='products'?'700':'500' ?>;margin-bottom:-2px;text-decoration:none">
    📦 Ürünler (Stoklar)
  </a>
</div>

<!-- Stat Kartları -->
<div class="stats-grid" style="margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-icon blue">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <?= $tab==='dealers' ? '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>' : '<path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>' ?>
      </svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['total_b2b'] ?></div>
      <div class="stat-label">B2B Toplam</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['matched'] ?></div>
      <div class="stat-label">Eşleşmiş</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['unmatched_b2b'] ?></div>
      <div class="stat-label">B2B'de Bağlanmamış</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['total_parasut'] ?></div>
      <div class="stat-label">Paraşüt'te Toplam</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon red">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['orphan_parasut'] ?></div>
      <div class="stat-label">Sadece Paraşüt'te</div>
    </div>
  </div>
</div>

<!-- Aksiyon kutusu -->
<div class="card" style="margin-bottom:20px">
  <div class="card-body" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
    <div style="flex:1;min-width:240px">
      <div style="font-weight:700;font-size:14px;margin-bottom:4px">🔄 Akıllı Otomatik Eşleştir</div>
      <div style="font-size:12px;color:var(--text-muted);line-height:1.5">
        <?php if ($tab === 'dealers'): ?>
        Vergi numarası, e-mail veya isim eşleşmesine göre B2B bayileri Paraşüt cari kayıtlarıyla otomatik bağlar.
        Sadece <strong>henüz eşleşmemiş</strong> bayileri etkiler.
        <?php else: ?>
        SKU (stok kodu) veya isim eşleşmesine göre B2B ürünleri Paraşüt stok kayıtlarıyla otomatik bağlar.
        Sadece <strong>henüz eşleşmemiş</strong> ürünleri etkiler.
        <?php endif; ?>
      </div>
    </div>
    <form method="post" onsubmit="return confirm('Akıllı eşleştirme çalışacak. Devam edilsin mi?');">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="auto-match-<?= $tab ?>">
      <button type="submit" class="btn btn-primary">🤖 Otomatik Eşleştir</button>
    </form>
  </div>
</div>

<?php if ($tab === 'dealers'): ?>
<!-- BAYİLER TAB -->
<div class="card">
  <div class="card-header"><h3 class="card-title">B2B Bayiler ↔ Paraşüt Cariler</h3></div>
  <div class="table-wrap">
    <table class="table" style="font-size:13px">
      <thead>
        <tr>
          <th>B2B Bayi</th>
          <th>Vergi No</th>
          <th>E-posta</th>
          <th>Paraşüt Cari Eşleşmesi</th>
          <th style="width:130px">Aksiyon</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($b2bDealers as $d):
            $linked = null;
            if (!empty($d['parasut_contact_id']) && isset($parasutContactsById[$d['parasut_contact_id']])) {
                $linked = $parasutContactsById[$d['parasut_contact_id']];
            }
        ?>
        <tr style="<?= $linked ? '' : 'background:#fffbeb' ?>">
          <td>
            <div style="font-weight:600"><?= h($d['firm_name']) ?></div>
            <?php if (!empty($d['phone'])): ?>
            <div style="font-size:11px;color:var(--text-muted)"><?= h($d['phone']) ?></div>
            <?php endif; ?>
          </td>
          <td style="font-family:monospace;font-size:12px"><?= h($d['tax_number'] ?: '—') ?></td>
          <td style="font-size:12px"><?= h($d['email'] ?: '—') ?></td>
          <td>
            <form method="post" style="display:flex;gap:6px;align-items:center" class="map-form">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="map-dealer">
              <input type="hidden" name="dealer_id" value="<?= (int)$d['id'] ?>">
              <select name="parasut_id" class="form-control" style="font-size:12px;padding:4px 8px;height:30px;min-height:30px;flex:1">
                <option value="-">— Bağlı değil —</option>
                <?php foreach ($parasutContacts as $c):
                    $cName = $c['attributes']['name'] ?? '?';
                    $cTax  = $c['attributes']['tax_number'] ?? '';
                    $isSel = ($d['parasut_contact_id'] ?? '') === $c['id'];
                ?>
                <option value="<?= h($c['id']) ?>"<?= $isSel ? ' selected' : '' ?>>
                  <?= h($cName) ?><?= $cTax ? ' [VKN: ' . h($cTax) . ']' : '' ?> (ID: <?= h($c['id']) ?>)
                </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-sm" style="padding:4px 10px;font-size:11px;background:#16a34a;color:#fff;border:none">💾</button>
            </form>
            <?php if ($linked): ?>
            <div style="margin-top:4px;font-size:10px;color:#15803d;font-weight:600">✓ Eşleşmiş</div>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!$linked): ?>
            <form method="post" onsubmit="return confirm('Bu bayi Paraşüt\'te yeni cari olarak oluşturulacak. Önce eşleşme aramayı düşündünüz mü?');">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="create-dealer">
              <input type="hidden" name="dealer_id" value="<?= (int)$d['id'] ?>">
              <button type="submit" class="btn btn-sm" style="background:#0ea5e9;color:#fff;border:none;font-size:11px;padding:4px 10px">+ Paraşüt'te Oluştur</button>
            </form>
            <?php else: ?>
            <span style="font-size:11px;color:#15803d">Senkron hazır</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (!empty($orphanContacts)): ?>
<details style="margin-top:20px">
  <summary style="cursor:pointer;padding:12px 16px;background:#f3f4f6;border-radius:8px;font-weight:600">
    📋 Sadece Paraşüt'te Var (B2B'de yok) — <?= count($orphanContacts) ?> kayıt
  </summary>
  <div style="padding:12px 4px;font-size:12px;color:var(--text-muted);margin-bottom:8px">
    Aşağıdaki Paraşüt cari kayıtlarının B2B sistemde karşılığı yok. Eğer bunlardan birini bir B2B bayisiyle eşleştirmek istiyorsanız, yukarıdaki tabloda ilgili bayinin dropdown'undan seçin.
  </div>
  <div class="table-wrap">
    <table class="table" style="font-size:12px">
      <thead>
        <tr><th>Paraşüt Adı</th><th>Vergi No</th><th>E-posta</th><th>Paraşüt ID</th></tr>
      </thead>
      <tbody>
        <?php foreach ($orphanContacts as $c): ?>
        <tr>
          <td><?= h($c['attributes']['name'] ?? '') ?></td>
          <td style="font-family:monospace"><?= h($c['attributes']['tax_number'] ?? '—') ?></td>
          <td><?= h($c['attributes']['email'] ?? '—') ?></td>
          <td style="font-family:monospace"><?= h($c['id']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>
<?php endif; ?>

<?php else: ?>
<!-- ÜRÜNLER TAB -->
<div class="card">
  <div class="card-header"><h3 class="card-title">B2B Ürünler ↔ Paraşüt Stoklar</h3></div>
  <div class="table-wrap">
    <table class="table" style="font-size:13px">
      <thead>
        <tr>
          <th>B2B Ürün</th>
          <th>SKU</th>
          <th>Baz Fiyat</th>
          <th>KDV</th>
          <th>Paraşüt Stok Eşleşmesi</th>
          <th style="width:130px">Aksiyon</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($b2bProducts as $p):
            $linked = null;
            if (!empty($p['parasut_product_id']) && isset($parasutProductsById[$p['parasut_product_id']])) {
                $linked = $parasutProductsById[$p['parasut_product_id']];
            }
        ?>
        <tr style="<?= $linked ? '' : 'background:#fffbeb' ?>">
          <td style="font-weight:600"><?= h($p['name']) ?></td>
          <td style="font-family:monospace;font-size:12px"><?= h($p['sku'] ?: '—') ?></td>
          <td style="font-size:12px"><?= money((float)$p['base_price']) ?></td>
          <td style="font-size:12px">%<?= (int)($p['vat_rate'] ?? 20) ?></td>
          <td>
            <form method="post" style="display:flex;gap:6px;align-items:center">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="map-product">
              <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <select name="parasut_id" class="form-control" style="font-size:12px;padding:4px 8px;height:30px;min-height:30px;flex:1">
                <option value="-">— Bağlı değil —</option>
                <?php foreach ($parasutProducts as $pp):
                    $ppName = $pp['attributes']['name'] ?? '?';
                    $ppCode = $pp['attributes']['code'] ?? '';
                    $isSel = ($p['parasut_product_id'] ?? '') === $pp['id'];
                ?>
                <option value="<?= h($pp['id']) ?>"<?= $isSel ? ' selected' : '' ?>>
                  <?= h($ppName) ?><?= $ppCode ? ' [Kod: ' . h($ppCode) . ']' : '' ?> (ID: <?= h($pp['id']) ?>)
                </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-sm" style="padding:4px 10px;font-size:11px;background:#16a34a;color:#fff;border:none">💾</button>
            </form>
            <?php if ($linked): ?>
            <div style="margin-top:4px;font-size:10px;color:#15803d;font-weight:600">✓ Eşleşmiş</div>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!$linked): ?>
            <form method="post" onsubmit="return confirm('Bu ürün Paraşüt\'te yeni stok olarak oluşturulacak. Önce eşleşme aramayı düşündünüz mü?');">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="create-product">
              <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <button type="submit" class="btn btn-sm" style="background:#0ea5e9;color:#fff;border:none;font-size:11px;padding:4px 10px">+ Paraşüt'te Oluştur</button>
            </form>
            <?php else: ?>
            <span style="font-size:11px;color:#15803d">Senkron hazır</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (!empty($orphanProducts)): ?>
<details style="margin-top:20px">
  <summary style="cursor:pointer;padding:12px 16px;background:#f3f4f6;border-radius:8px;font-weight:600">
    📋 Sadece Paraşüt'te Var (B2B'de yok) — <?= count($orphanProducts) ?> kayıt
  </summary>
  <div style="padding:12px 4px;font-size:12px;color:var(--text-muted);margin-bottom:8px">
    Aşağıdaki Paraşüt stok kayıtlarının B2B sistemde karşılığı yok. Bu ürünler Paraşüt'te ek olarak var (eski faturalar, B2B dışı satış, vs).
  </div>
  <div class="table-wrap">
    <table class="table" style="font-size:12px">
      <thead>
        <tr><th>Paraşüt Adı</th><th>Stok Kodu</th><th>KDV</th><th>Paraşüt ID</th></tr>
      </thead>
      <tbody>
        <?php foreach ($orphanProducts as $pp): ?>
        <tr>
          <td><?= h($pp['attributes']['name'] ?? '') ?></td>
          <td style="font-family:monospace"><?= h($pp['attributes']['code'] ?? '—') ?></td>
          <td>%<?= (int)($pp['attributes']['vat_rate'] ?? 0) ?></td>
          <td style="font-family:monospace"><?= h($pp['id']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>
<?php endif; ?>

<?php endif; ?>

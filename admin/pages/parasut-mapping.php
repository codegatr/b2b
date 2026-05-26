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

        // En son isim eşleşmesi (case-insensitive, tam) — company_name veya display_name
        $matchName = !empty($d['company_name']) ? $d['company_name'] : (trim(($d['first_name']??'').' '.($d['last_name']??'')) ?: '');
        if (!$candidate && $matchName !== '') {
            $name = mb_strtolower(trim($matchName));
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

// ─── Paraşüt'ten seçilen carileri B2B'ye aktar ──
if (isPost() && ($_POST['action'] ?? '') === 'import-contacts') {
    csrfCheck();
    $ids = $_POST['parasut_ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        $msg = 'error:Lütfen aktarılacak cari(leri) seçin.';
    } else {
        // Paraşüt cari listesini bir kere çek, lookup yap
        $allContacts = parasut()->listAllContacts();
        $byId = [];
        foreach ($allContacts as $c) $byId[(string)$c['id']] = $c;

        $created = 0; $skipped = 0; $errors = [];
        foreach ($ids as $pid) {
            $pid = (string)$pid;
            if (!isset($byId[$pid])) { $skipped++; continue; }

            $c = $byId[$pid];
            $a = $c['attributes'] ?? [];

            // Aynı parasut_contact_id zaten varsa atla
            $exists = dbVal("SELECT COUNT(*) FROM b2b_dealers WHERE parasut_contact_id=?", [$pid]);
            if ($exists > 0) { $skipped++; continue; }

            $name    = trim($a['name'] ?? '');
            $taxNo   = trim($a['tax_number'] ?? '');
            $taxOff  = trim($a['tax_office'] ?? '');
            $email   = trim($a['email'] ?? '');
            $phone   = trim($a['phone'] ?? '');
            $address = trim(($a['address'] ?? '') ?: ($a['address1'] ?? ''));
            $city    = trim($a['city'] ?? '');

            if ($name === '') { $skipped++; continue; }

            // Bayi tipi — VKN 10 hane ise kurumsal, 11 ise bireysel
            $type = (strlen($taxNo) === 11) ? 'bireysel' : 'kurumsal';

            try {
                $code = 'PAR' . substr(uniqid(), -6);
                dbExec(
                    "INSERT INTO b2b_dealers
                     (dealer_code, type, company_name, first_name, last_name, email, phone, address, city,
                      tax_number, tax_office, parasut_contact_id, is_active, created_at)
                     VALUES (?, ?, ?, '', '', ?, ?, ?, ?, ?, ?, ?, 1, NOW())",
                    [$code, $type, $name, $email, $phone, $address, $city, $taxNo, $taxOff, $pid]
                );
                $created++;
            } catch (\Throwable $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }

        auditLog('parasut_contacts_imported', 'b2b_dealers', 0, ['created'=>$created, 'skipped'=>$skipped]);
        $parts = [];
        if ($created > 0) $parts[] = "{$created} bayi oluşturuldu";
        if ($skipped > 0) $parts[] = "{$skipped} kayıt atlandı (zaten var veya geçersiz)";
        if (!empty($errors)) $parts[] = 'hatalar: ' . implode(' | ', array_slice($errors, 0, 3));
        $msg = 'success:✓ Aktarım tamamlandı — ' . implode(' · ', $parts);
    }
}

// ─── Paraşüt'ten seçilen ürünleri B2B'ye aktar ──
if (isPost() && ($_POST['action'] ?? '') === 'import-products') {
    csrfCheck();
    $ids = $_POST['parasut_ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        $msg = 'error:Lütfen aktarılacak ürün(leri) seçin.';
    } else {
        $allProds = parasut()->listAllProducts();
        $byId = [];
        foreach ($allProds as $p) $byId[(string)$p['id']] = $p;

        $created = 0; $skipped = 0; $errors = [];
        foreach ($ids as $pid) {
            $pid = (string)$pid;
            if (!isset($byId[$pid])) { $skipped++; continue; }

            $p = $byId[$pid];
            $a = $p['attributes'] ?? [];

            $exists = dbVal("SELECT COUNT(*) FROM b2b_products WHERE parasut_product_id=?", [$pid]);
            if ($exists > 0) { $skipped++; continue; }

            $name = trim($a['name'] ?? '');
            $code = trim($a['code'] ?? '');
            if ($name === '') { $skipped++; continue; }

            $vatRate = (float)($a['vat_rate'] ?? 20);
            $price   = (float)($a['list_price'] ?? 0);
            // Paraşüt list_price KDV Dahil — net'e çevir
            if ($vatRate > 0 && $price > 0) {
                $price = round($price / (1 + $vatRate / 100), 4);
            }

            try {
                dbExec(
                    "INSERT INTO b2b_products
                     (name, sku, base_price, vat_rate, stock, stock_critical, is_active,
                      parasut_product_id, created_at)
                     VALUES (?, ?, ?, ?, 0, 5, 1, ?, NOW())",
                    [$name, $code, $price, $vatRate, $pid]
                );
                $created++;
            } catch (\Throwable $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }

        auditLog('parasut_products_imported', 'b2b_products', 0, ['created'=>$created, 'skipped'=>$skipped]);
        $parts = [];
        if ($created > 0) $parts[] = "{$created} ürün oluşturuldu";
        if ($skipped > 0) $parts[] = "{$skipped} kayıt atlandı (zaten var veya geçersiz)";
        if (!empty($errors)) $parts[] = 'hatalar: ' . implode(' | ', array_slice($errors, 0, 3));
        $msg = 'success:✓ Aktarım tamamlandı — ' . implode(' · ', $parts);
    }
}

// ─── TOPLU KAYIP EŞLEŞMELERİ SIFIRLA ──
// Paraşüt'te bulunamayan ID'lere sahip tüm B2B kayıtlarının
// parasut_*_id alanlarını NULL yapar. Sonra yeniden eşleme yapılabilir.
if (isPost() && ($_POST['action'] ?? '') === 'reset-lost-matches') {
    csrfCheck();
    $type = $_POST['type'] ?? '';

    if ($type === 'dealers') {
        // Tüm B2B bayilerin parasut_contact_id'sini al, Paraşüt listesinde olmayanları sıfırla
        $allContacts = parasut()->listAllContacts();
        $existingIds = array_map(fn($c) => (string)$c['id'], $allContacts);

        $allDealers = dbRows("SELECT id, parasut_contact_id FROM b2b_dealers
                              WHERE is_active=1 AND parasut_contact_id IS NOT NULL AND parasut_contact_id != ''");
        $resetIds = [];
        foreach ($allDealers as $d) {
            if (!in_array((string)$d['parasut_contact_id'], $existingIds, true)) {
                $resetIds[] = (int)$d['id'];
            }
        }
        if (!empty($resetIds)) {
            $placeholders = implode(',', array_fill(0, count($resetIds), '?'));
            dbExec("UPDATE b2b_dealers SET parasut_contact_id=NULL WHERE id IN ({$placeholders})", $resetIds);
            auditLog('parasut_lost_dealer_matches_reset', 'b2b_dealers', 0, ['count'=>count($resetIds)]);
            $msg = 'success:✓ ' . count($resetIds) . ' kayıp bayi eşleşmesi sıfırlandı. Şimdi "🤖 Otomatik Eşleştir" ile yeniden bağlayın.';
        } else {
            $msg = 'success:Kayıp eşleşme bulunamadı, hepsi geçerli.';
        }
    } elseif ($type === 'products') {
        $allProds = parasut()->listAllProducts();
        $existingIds = array_map(fn($p) => (string)$p['id'], $allProds);

        $allP = dbRows("SELECT id, parasut_product_id FROM b2b_products
                        WHERE is_active=1 AND parasut_product_id IS NOT NULL AND parasut_product_id != ''");
        $resetIds = [];
        foreach ($allP as $p) {
            if (!in_array((string)$p['parasut_product_id'], $existingIds, true)) {
                $resetIds[] = (int)$p['id'];
            }
        }
        if (!empty($resetIds)) {
            $placeholders = implode(',', array_fill(0, count($resetIds), '?'));
            dbExec("UPDATE b2b_products SET parasut_product_id=NULL WHERE id IN ({$placeholders})", $resetIds);
            auditLog('parasut_lost_product_matches_reset', 'b2b_products', 0, ['count'=>count($resetIds)]);
            $msg = 'success:✓ ' . count($resetIds) . ' kayıp ürün eşleşmesi sıfırlandı. Şimdi "🤖 Otomatik Eşleştir" ile yeniden bağlayın.';
        } else {
            $msg = 'success:Kayıp eşleşme bulunamadı, hepsi geçerli.';
        }
    }
}

// ──────────────────────────────────────────────────────────────
// DATA HAZIRLA (her render'da)
// ──────────────────────────────────────────────────────────────

if ($tab === 'dealers') {
    $b2bDealers = dbRows("SELECT id, company_name, first_name, last_name, email, phone, tax_number, parasut_contact_id,
                                  COALESCE(NULLIF(company_name,''), CONCAT(TRIM(first_name),' ',TRIM(last_name))) AS display_name
                          FROM b2b_dealers WHERE is_active=1
                          ORDER BY (parasut_contact_id IS NOT NULL AND parasut_contact_id != '') DESC, display_name");

    // Paraşüt'ten cari listesi — aktif + arşivlenmiş ayrı ayrı
    $activeRes = parasut()->listAllContactsWithMeta(40);
    $archivedRes = parasut()->listAllContactsWithMeta(40, ['archived' => 'true']);
    $parasutContacts = array_merge($activeRes['data'], $archivedRes['data']);

    $parasutMeta = [
        'active_total'   => $activeRes['total_count'],
        'active_fetched' => $activeRes['fetched'],
        'archived_total' => $archivedRes['total_count'],
        'archived_fetched' => $archivedRes['fetched'],
    ];

    // Hızlı lookup için ID→object map (string-key)
    $parasutContactsById = [];
    foreach ($parasutContacts as $c) {
        $parasutContactsById[(string)$c['id']] = $c;
    }

    // Eşleşmiş bayilerin Paraşüt ID'lerini topla
    $usedContactIds = array_map('strval', array_filter(array_column($b2bDealers, 'parasut_contact_id')));

    // Paraşüt'te olup B2B'de eşleşmemiş cariler (yetimler)
    $orphanContacts = [];
    foreach ($parasutContacts as $c) {
        if (!in_array((string)$c['id'], $usedContactIds, true)) $orphanContacts[] = $c;
    }

    // B2B'de eşleşmiş AMA Paraşüt'te bulunamayan ID'ler (silinmiş/yanlış)
    $missingMatches = 0;
    foreach ($b2bDealers as $d) {
        $pid = (string)($d['parasut_contact_id'] ?? '');
        if ($pid !== '' && !isset($parasutContactsById[$pid])) {
            $missingMatches++;
        }
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

    // ?q=XXX — server-side arama (kullanıcı G-14 gibi spesifik ürün ararken)
    $searchQuery = trim($_GET['q'] ?? '');

    // Default güvenli initializer (exception olsa bile UI doğru çalışır)
    $parasutProducts = [];
    $rawProducts = [];
    $activeRes = ['data'=>[], 'total_count'=>0, 'fetched'=>0];
    $archivedRes = ['data'=>[], 'total_count'=>0, 'fetched'=>0];
    $parasutError = null;

    try {
        if ($searchQuery !== '') {
            // PHP-side fuzzy contains arama (Paraşüt'ün filter[name] EXACT yapıyor)
            $searchResults = parasut()->searchProducts($searchQuery, 100);
            $rawProducts = $searchResults;
            $activeRes = ['total_count' => 0, 'fetched' => count($searchResults)];
        } else {
            // Normal akış — tüm ürünleri çek (aktif + arşivli)
            $activeRes = parasut()->listAllProductsWithMeta(200);
            $archivedRes = parasut()->listAllProductsWithMeta(200, ['archived' => 'true']);
            $rawProducts = array_merge($activeRes['data'], $archivedRes['data']);
        }
    } catch (\Throwable $e) {
        $parasutError = $e->getMessage();
        error_log('parasut-mapping products fetch hata: ' . $e->getMessage());
    }

    // ?show_all=1 — muhasebe hesap kalemleri dahil
    $showAll = isset($_GET['show_all']) && $_GET['show_all'] === '1';

    $isAccountingItem = function($attrs) {
        $name = trim($attrs['name'] ?? '');
        $code = trim($attrs['code'] ?? '');
        $namePattern = $name !== '' && preg_match('/^[\d.]+$/', $name);
        $codePattern = $code !== '' && preg_match('/^[\d.]+$/', $code);
        if ($code === '') return $namePattern;
        return $namePattern || $codePattern;
    };

    $filteredCount = 0;
    foreach ($rawProducts as $p) {
        if (!$showAll && $isAccountingItem($p['attributes'] ?? [])) {
            $filteredCount++;
            continue;
        }
        $parasutProducts[] = $p;
    }

    // ─── PHP-side alfabetik sıralama (Türkçe karakter destekli) ───
    // Paraşüt'ün sort=name Türkçe karakterlerde sorun çıkarıyor, PHP-side garanti.
    usort($parasutProducts, function($a, $b) {
        $nameA = mb_strtolower(trim($a['attributes']['name'] ?? ''), 'UTF-8');
        $nameB = mb_strtolower(trim($b['attributes']['name'] ?? ''), 'UTF-8');
        // Boş isimleri en alta at
        if ($nameA === '' && $nameB !== '') return 1;
        if ($nameA !== '' && $nameB === '') return -1;
        return strnatcasecmp($nameA, $nameB);
    });

    $parasutMeta = [
        'active_total'    => $activeRes['total_count'] ?? 0,
        'active_fetched'  => $activeRes['fetched'] ?? 0,
        'archived_total'  => $archivedRes['total_count'] ?? 0,
        'archived_fetched'=> $archivedRes['fetched'] ?? 0,
        'show_all'        => $showAll,
        'filtered_count'  => $filteredCount,
        'search_query'    => $searchQuery,
        'error'           => $parasutError,
    ];

    $parasutProductsById = [];
    foreach ($parasutProducts as $p) {
        $parasutProductsById[(string)$p['id']] = $p;
    }

    $usedProductIds = array_map('strval', array_filter(array_column($b2bProducts, 'parasut_product_id')));
    $orphanProducts = [];
    foreach ($parasutProducts as $p) {
        if (!in_array((string)$p['id'], $usedProductIds, true)) $orphanProducts[] = $p;
    }

    // B2B'de eşleşmiş AMA Paraşüt'te bulunamayan ID'ler
    $missingMatches = 0;
    foreach ($b2bProducts as $p) {
        $pid = (string)($p['parasut_product_id'] ?? '');
        if ($pid !== '' && !isset($parasutProductsById[$pid])) {
            $missingMatches++;
        }
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

<!-- ─── Paraşüt API DURUMU ─── -->
<?php
$apiCount = ($tab === 'dealers') ? count($parasutContacts) : count($parasutProducts);
$apiKind  = ($tab === 'dealers') ? 'cari' : 'ürün';
$activeSearchQuery = ($tab === 'products') ? trim($parasutMeta['search_query'] ?? '') : '';
$isSearching = $activeSearchQuery !== '';
$apiError = ($tab === 'products') ? ($parasutMeta['error'] ?? null) : null;
?>
<?php if ($apiError): ?>
<!-- EXCEPTION yakalandı — Paraşüt'e bağlantı/yetki sorunu var -->
<div class="alert" style="background:#fef2f2;border:2px solid #dc2626;color:#7f1d1d;padding:14px 16px;margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;gap:12px">
    <div style="font-size:24px">⛔</div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:14px;color:#b91c1c;margin-bottom:6px">
        Paraşüt API Hatası
      </div>
      <div style="font-size:12px;line-height:1.6;background:#fff;padding:8px 10px;border-radius:6px;font-family:monospace;color:#7f1d1d;border:1px solid #fca5a5;margin-bottom:8px">
        <?= h($apiError) ?>
      </div>
      <div style="font-size:11px;color:#7f1d1d">
        Bu hatayla karşılaştığınızda eşleme yapılamaz. Lütfen tanı araçlarını kullanın:
      </div>
      <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
        <a href="?page=parasut&diag=1" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none">🩺 Endpoint Tanı</a>
        <a href="?page=parasut&clear_token=1" class="btn btn-sm" style="background:#fff;color:#dc2626;border:1px solid #dc2626" onclick="return confirm('Token cache temizlensin mi?');">🔄 Token Yenile</a>
        <a href="?page=parasut-mapping&tab=products&diag_products=1" class="btn btn-sm" style="background:#fff;color:#dc2626;border:1px solid #dc2626">🩺 Ürün Tanı</a>
      </div>
    </div>
  </div>
</div>
<?php elseif ($apiCount === 0 && !$isSearching): ?>
<!-- Paraşüt'ten HİÇ veri gelmedi (arama YOKKEN) - SERT UYARI -->
<div class="alert" style="background:#fef2f2;border:2px solid #dc2626;color:#7f1d1d;padding:14px 16px;margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;gap:12px">
    <div style="font-size:24px">🚨</div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:14px;color:#b91c1c;margin-bottom:4px">
        Paraşüt'ten <?= $apiKind ?> listesi BOŞ döndü
      </div>
      <div style="font-size:12px;line-height:1.6">
        Bu durumda eşleştirme yapılamaz. Olası sebepler:
        <ul style="margin:6px 0 0 18px;padding:0">
          <li><strong>Token sorunu:</strong> Erişim tokeni geçersiz veya süresi dolmuş</li>
          <li><strong>Yanlış Company ID:</strong> Settings'teki <code>parasut_company_id</code> başka bir hesaba ait</li>
          <li><strong>Yetki eksikliği:</strong> Bu API kullanıcısının <?= $apiKind ?>leri okuma izni yok</li>
          <li><strong>Hesabınız boş:</strong> Paraşüt hesabınızda gerçekten kayıt yok</li>
        </ul>
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
          <a href="?page=parasut&diag=1" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none">🩺 Endpoint Tanı</a>
          <a href="?page=parasut&test=1" class="btn btn-sm" style="background:#fff;color:#dc2626;border:1px solid #dc2626">🔌 Bağlantı Test Et</a>
          <a href="?page=parasut&clear_token=1" class="btn btn-sm" style="background:#fff;color:#dc2626;border:1px solid #dc2626" onclick="return confirm('Token cache temizlensin mi?');">🔄 Token Yenile</a>
          <a href="?page=settings&tab=parasut" class="btn btn-sm" style="background:#fff;color:#dc2626;border:1px solid #dc2626">⚙ Credentials Kontrol</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php elseif ($apiCount === 0 && $isSearching): ?>
<!-- Arama yapıldı, ürün bulunamadı (Paraşüt veritabanı boş DEĞİL!) -->
<div class="alert" style="background:#fefce8;border:2px solid #fde68a;color:#713f12;padding:14px 16px;margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;gap:12px">
    <div style="font-size:24px">🔍</div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:14px;color:#854d0e;margin-bottom:4px">
        "<?= h($activeSearchQuery) ?>" araması Paraşüt'te eşleşme bulamadı
      </div>
      <div style="font-size:12px;line-height:1.6">
        Paraşüt'te bu ada/koda sahip <strong>aktif veya arşivli ürün yok</strong>. Şunları deneyin:
        <ul style="margin:6px 0 0 18px;padding:0">
          <li>Aramayı temizleyip <strong>tüm ürünleri görün</strong></li>
          <li>Daha kısa anahtar kelime deneyin (örn "tavuk" → "tav")</li>
          <li>Paraşüt'te ürünün gerçek adını kontrol edin</li>
        </ul>
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
          <a href="?page=parasut-mapping&tab=products" class="btn btn-sm" style="background:#854d0e;color:#fff;border:none">✕ Aramayı Temizle</a>
          <a href="?page=parasut-mapping&tab=products&diag_products=1" class="btn btn-sm" style="background:#fff;color:#854d0e;border:1px solid #854d0e">🩺 Tanı Aç</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php elseif (!empty($missingMatches)): ?>
<!-- Eşleşmiş ama Paraşüt'te bulunamayan kayıtlar var -->
<div class="alert" style="background:#fef3c7;border:1px solid #fcd34d;color:#78350f;padding:12px 14px;margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;gap:10px">
    <div style="font-size:20px">⚠️</div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:13px;color:#92400e">
        <?= $missingMatches ?> kayıp eşleşme tespit edildi
      </div>
      <div style="font-size:12px;line-height:1.5;margin-top:4px">
        <?= $missingMatches ?> <?= $apiKind ?> için kayıtlı Paraşüt ID'si var, ancak Paraşüt'ten gelen listede bulunamadı.
        Bu kayıtlar Paraşüt'te silinmiş veya başka bir hesapta olabilir.
      </div>
      <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <form method="post" onsubmit="return confirm('⚠️ TOPLU SIFIRLAMA\n\n<?= $missingMatches ?> kayıp <?= $apiKind ?> eşleşmesinin parasut_<?= $tab === 'dealers' ? 'contact' : 'product' ?>_id alanları NULL yapılacak.\n\nBu işlem GERİ ALINAMAZ. Sonra \"Otomatik Eşleştir\" ile yeniden bağlayabilirsiniz.\n\nDevam edilsin mi?');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reset-lost-matches">
          <input type="hidden" name="type" value="<?= $tab === 'dealers' ? 'dealers' : 'products' ?>">
          <button type="submit" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none">
            🧹 Hepsini Sıfırla (<?= $missingMatches ?>)
          </button>
        </form>
        <span style="font-size:11px;color:#78350f">Sonra ⬇ aşağıdan 🤖 Otomatik Eşleştir tıkla</span>
      </div>
    </div>
  </div>
</div>
<?php else: ?>
<!-- Sağlıklı durum - kısa bilgi + meta detayı -->
<div style="background:#f0fdf4;border:1px solid #86efac;color:#166534;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:12px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div>
      ✓ Paraşüt'ten <strong><?= $apiCount ?></strong> <?= $apiKind ?> çekildi
      <?php if (isset($parasutMeta)): ?>
        <span style="color:#166534;margin-left:8px">
          (<?= (int)$parasutMeta['active_fetched'] ?>/<?= (int)$parasutMeta['active_total'] ?> aktif
          + <?= (int)$parasutMeta['archived_fetched'] ?>/<?= (int)$parasutMeta['archived_total'] ?> arşivli)
        </span>
      <?php endif; ?>
    </div>
    <?php
    // Çekilen vs toplam fark var mı? (eksik kayıt tespiti)
    if (isset($parasutMeta)) {
        $expected = $parasutMeta['active_total'] + $parasutMeta['archived_total'];
        $missing  = $expected - $apiCount;
        if ($missing > 0):
    ?>
    <div style="background:#fef3c7;color:#92400e;padding:4px 10px;border-radius:6px;font-weight:600;font-size:11px">
      ⚠️ <?= $missing ?> kayıt çekilemedi (sayfa limiti?)
    </div>
    <?php endif; } ?>
  </div>
</div>
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
            $linkedId = (string)($d['parasut_contact_id'] ?? '');
            $linked   = null;
            $isLost   = false;  // Eşleşme var ama Paraşüt'te bulunamayan ID

            if ($linkedId !== '') {
                if (isset($parasutContactsById[$linkedId])) {
                    $linked = $parasutContactsById[$linkedId];
                } else {
                    $isLost = true;
                }
            }

            // Satır arka plan rengi:
            // - Eşleşmiş ve sağlam: hafif yeşil
            // - Eşleşmiş ama kayıp: kırmızı (uyarı)
            // - Bağlanmamış: sarımsı
            if ($linked)      $rowBg = '#f0fdf4'; // green-50
            elseif ($isLost)  $rowBg = '#fef2f2'; // red-50
            else              $rowBg = '#fffbeb'; // yellow-50

            $rowBorder = $linked ? '4px solid #16a34a' : ($isLost ? '4px solid #dc2626' : '4px solid transparent');
        ?>
        <tr style="background:<?= $rowBg ?>;border-left:<?= $rowBorder ?>">
          <td>
            <div style="font-weight:600"><?= h($d["display_name"] ?: ($d["company_name"] ?: ($d["first_name"]." ".$d["last_name"]))) ?></div>
            <?php if (!empty($d['phone'])): ?>
            <div style="font-size:11px;color:var(--text-muted)"><?= h($d['phone']) ?></div>
            <?php endif; ?>
            <?php if ($linked): ?>
            <div style="margin-top:4px;font-size:10px;color:#15803d;font-weight:700">✓ <?= h($linked['attributes']['name'] ?? 'Eşleşti') ?> (ID: <?= h($linkedId) ?>)</div>
            <?php elseif ($isLost): ?>
            <div style="margin-top:4px;font-size:10px;color:#b91c1c;font-weight:700">⚠️ Paraşüt'te bu ID bulunamadı: <?= h($linkedId) ?></div>
            <div style="font-size:10px;color:#7f1d1d">Bu kayıt Paraşüt'te silinmiş olabilir. Aşağıdan "— Bağlı değil —" seç + 💾 ile eşlemeyi sıfırla.</div>
            <?php endif; ?>
          </td>
          <td style="font-family:monospace;font-size:12px"><?= h($d['tax_number'] ?: '—') ?></td>
          <td style="font-size:12px"><?= h($d['email'] ?: '—') ?></td>
          <td>
            <form method="post" style="display:flex;gap:6px;align-items:center" class="map-form">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="map-dealer">
              <input type="hidden" name="dealer_id" value="<?= (int)$d['id'] ?>">
              <select name="parasut_id" class="form-control" style="font-size:12px;padding:4px 8px;height:30px;min-height:30px;flex:1<?= $linked ? ';border:2px solid #16a34a' : ($isLost ? ';border:2px solid #dc2626' : '') ?>">
                <option value="-">— Bağlı değil —</option>
                <?php if ($isLost): ?>
                <option value="<?= h($linkedId) ?>" selected style="color:#dc2626">⚠️ KAYIP ID: <?= h($linkedId) ?> (Paraşüt'te yok)</option>
                <?php endif; ?>
                <?php foreach ($parasutContacts as $c):
                    $cName = $c['attributes']['name'] ?? '?';
                    $cTax  = $c['attributes']['tax_number'] ?? '';
                    $isSel = (string)($d['parasut_contact_id'] ?? '') === (string)$c['id'];
                ?>
                <option value="<?= h($c['id']) ?>"<?= $isSel ? ' selected' : '' ?>>
                  <?= h($cName) ?><?= $cTax ? ' [VKN: ' . h($cTax) . ']' : '' ?> (ID: <?= h($c['id']) ?>)
                </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-sm" style="padding:4px 10px;font-size:11px;background:#16a34a;color:#fff;border:none">💾</button>
            </form>
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
<div class="card" style="margin-top:20px;border:2px solid #0ea5e9">
  <div class="card-header" style="background:linear-gradient(135deg,#e0f2fe,#bae6fd);color:#075985;display:flex;justify-content:space-between;align-items:center">
    <h3 class="card-title" style="color:#075985">📥 Paraşüt'ten B2B'ye Aktarılabilir Cariler — <?= count($orphanContacts) ?> kayıt</h3>
    <div style="font-size:11px;color:#0c4a6e">Bu kayıtlar Paraşüt'te var, B2B'de yok</div>
  </div>
  <div class="card-body" style="padding:0">
    <div style="background:#fffbeb;border-bottom:1px solid #fde68a;padding:10px 16px;font-size:12px;color:#78350f">
      ℹ️ Seçilen carileri B2B'ye <strong>yeni bayi</strong> olarak aktarın (parasut_contact_id otomatik bağlanır, çift kayıt olmaz). Karmaşa olmasın diye <strong>tek tek veya küçük gruplar</strong> halinde seçin.
    </div>
    <!-- Arama kutusu -->
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);background:#fafafa">
      <input type="search" id="orphanContactSearch" placeholder="🔍 İsim, VKN, e-mail veya ID ile ara…"
             class="form-control" style="font-size:13px;padding:8px 12px">
    </div>
    <form method="post" id="importContactsForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="import-contacts">
      <div class="table-wrap">
        <table class="table" style="font-size:12px;margin:0">
          <thead>
            <tr style="background:#f9fafb">
              <th style="width:40px;text-align:center">
                <input type="checkbox" id="orphanSelectAll" title="Tümünü seç/kaldır">
              </th>
              <th>Paraşüt Adı</th>
              <th>Vergi No</th>
              <th>Vergi Dairesi</th>
              <th>E-posta</th>
              <th>Telefon</th>
              <th>Paraşüt ID</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orphanContacts as $c):
              $a = $c['attributes'] ?? [];
              $isArch = !empty($a['archived']);
              $searchText = mb_strtolower(
                  ($a['name'] ?? '') . ' ' .
                  ($a['tax_number'] ?? '') . ' ' .
                  ($a['email'] ?? '') . ' ' .
                  ($a['phone'] ?? '') . ' ' .
                  $c['id']
              );
            ?>
            <tr class="orphan-contact-row" data-search="<?= h($searchText) ?>" style="<?= $isArch ? 'background:#fafafa;color:#94a3b8' : '' ?>">
              <td style="text-align:center">
                <input type="checkbox" name="parasut_ids[]" value="<?= h($c['id']) ?>" class="orphan-check">
              </td>
              <td style="font-weight:600">
                <?= h($a['name'] ?? '—') ?>
                <?php if ($isArch): ?>
                  <span class="badge" style="background:#e5e7eb;color:#6b7280;font-size:9px;font-weight:600;margin-left:6px">ARŞİVLİ</span>
                <?php endif; ?>
              </td>
              <td style="font-family:monospace"><?= h($a['tax_number'] ?? '—') ?></td>
              <td><?= h($a['tax_office'] ?? '—') ?></td>
              <td><?= h($a['email'] ?? '—') ?></td>
              <td><?= h($a['phone'] ?? '—') ?></td>
              <td style="font-family:monospace;color:var(--text-muted)"><?= h($c['id']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="padding:14px 16px;background:#f9fafb;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <div style="font-size:12px;color:var(--text-muted)">
          <span id="orphanCount">0</span> kayıt seçildi
        </div>
        <button type="submit" class="btn btn-primary" id="importContactsBtn" disabled style="background:#0ea5e9;border-color:#0ea5e9" onclick="return confirm('Seçilen ' + document.querySelectorAll('.orphan-check:checked').length + ' cariyi B2B\'ye yeni bayi olarak aktarmak istediğinize emin misiniz?\n\nHer biri için yeni bir b2b_dealers kaydı oluşturulur, Paraşüt ID otomatik bağlanır.');">
          📥 Seçilenleri B2B'ye Aktar
        </button>
      </div>
    </form>
  </div>
</div>
<script>
(function(){
  const cb = document.querySelectorAll('.orphan-check');
  const all = document.getElementById('orphanSelectAll');
  const cnt = document.getElementById('orphanCount');
  const btn = document.getElementById('importContactsBtn');
  const search = document.getElementById('orphanContactSearch');

  function refresh() {
    const c = document.querySelectorAll('.orphan-check:checked').length;
    cnt.textContent = c;
    btn.disabled = c === 0;
  }
  cb.forEach(b => b.addEventListener('change', refresh));
  all.addEventListener('change', () => {
    // Sadece görünür satırları seç (filtreden geçmişleri)
    document.querySelectorAll('.orphan-contact-row').forEach(row => {
      if (row.style.display !== 'none') {
        const c = row.querySelector('.orphan-check');
        if (c) c.checked = all.checked;
      }
    });
    refresh();
  });

  // Arama
  if (search) {
    search.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      document.querySelectorAll('.orphan-contact-row').forEach(row => {
        const txt = row.dataset.search || '';
        row.style.display = (q === '' || txt.includes(q)) ? '' : 'none';
      });
    });
  }
})();
</script>
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
            $linkedId = (string)($p['parasut_product_id'] ?? '');
            $linked   = null;
            $isLost   = false;

            if ($linkedId !== '') {
                if (isset($parasutProductsById[$linkedId])) {
                    $linked = $parasutProductsById[$linkedId];
                } else {
                    $isLost = true;
                }
            }

            if ($linked)      $rowBg = '#f0fdf4';
            elseif ($isLost)  $rowBg = '#fef2f2';
            else              $rowBg = '#fffbeb';

            $rowBorder = $linked ? '4px solid #16a34a' : ($isLost ? '4px solid #dc2626' : '4px solid transparent');
        ?>
        <tr style="background:<?= $rowBg ?>;border-left:<?= $rowBorder ?>">
          <td>
            <div style="font-weight:600"><?= h($p['name']) ?></div>
            <?php if ($linked): ?>
            <div style="margin-top:4px;font-size:10px;color:#15803d;font-weight:700">✓ <?= h($linked['attributes']['name'] ?? 'Eşleşti') ?> (ID: <?= h($linkedId) ?>)</div>
            <?php elseif ($isLost): ?>
            <div style="margin-top:4px;font-size:10px;color:#b91c1c;font-weight:700">⚠️ Paraşüt'te bu ID bulunamadı: <?= h($linkedId) ?></div>
            <div style="font-size:10px;color:#7f1d1d">Aşağıdan "— Bağlı değil —" seç + 💾 ile eşlemeyi sıfırla.</div>
            <?php endif; ?>
          </td>
          <td style="font-family:monospace;font-size:12px"><?= h($p['sku'] ?: '—') ?></td>
          <td style="font-size:12px"><?= moneyInc((float)$p['base_price'], $p['vat_rate'] ?? 20) ?></td>
          <td style="font-size:12px">%<?= (int)($p['vat_rate'] ?? 20) ?></td>
          <td>
            <form method="post" style="display:flex;gap:6px;align-items:center">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="map-product">
              <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <select name="parasut_id" class="form-control" style="font-size:12px;padding:4px 8px;height:30px;min-height:30px;flex:1<?= $linked ? ';border:2px solid #16a34a' : ($isLost ? ';border:2px solid #dc2626' : '') ?>">
                <option value="-">— Bağlı değil —</option>
                <?php if ($isLost): ?>
                <option value="<?= h($linkedId) ?>" selected style="color:#dc2626">⚠️ KAYIP ID: <?= h($linkedId) ?> (Paraşüt'te yok)</option>
                <?php endif; ?>
                <?php foreach ($parasutProducts as $pp):
                    $ppAttr  = $pp['attributes'] ?? [];
                    $ppName  = trim($ppAttr['name'] ?? '');
                    $ppCode  = trim($ppAttr['code'] ?? '');
                    $ppCat   = trim($ppAttr['_category_name'] ?? '');
                    // Fallback chain — ürün adı boşsa kod, o da yoksa ID
                    $display = $ppName !== '' ? $ppName
                             : ($ppCode !== '' ? '[Adsız - ' . $ppCode . ']'
                             : '[Adsız - ID: ' . $pp['id'] . ']');
                    $isSel = (string)($p['parasut_product_id'] ?? '') === (string)$pp['id'];
                ?>
                <option value="<?= h($pp['id']) ?>"<?= $isSel ? ' selected' : '' ?>>
                  <?= h($display) ?><?php
                    if ($ppName !== '' && $ppCode !== '') echo ' [' . h($ppCode) . ']';
                    if ($ppCat !== '') echo ' — ' . h($ppCat);
                    echo ' (ID: ' . h($pp['id']) . ')';
                  ?>
                </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-sm" style="padding:4px 10px;font-size:11px;background:#16a34a;color:#fff;border:none">💾</button>
            </form>
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
<div class="card" style="margin-top:20px;border:2px solid #0ea5e9">
  <div class="card-header" style="background:linear-gradient(135deg,#e0f2fe,#bae6fd);color:#075985;display:flex;justify-content:space-between;align-items:center">
    <h3 class="card-title" style="color:#075985">📥 Paraşüt'ten B2B'ye Aktarılabilir Ürünler — <?= count($orphanProducts) ?> kayıt</h3>
    <div style="font-size:11px;color:#0c4a6e">Bu ürünler Paraşüt'te var, B2B'de yok</div>
  </div>
  <div class="card-body" style="padding:0">
    <div style="background:#fffbeb;border-bottom:1px solid #fde68a;padding:10px 16px;font-size:12px;color:#78350f">
      ℹ️ Seçilen ürünleri B2B'ye <strong>yeni ürün</strong> olarak aktarın (parasut_product_id otomatik bağlanır). Stok 0 ve aktif olarak eklenir, sonra admin → Ürünler'den düzenleyin.
    </div>

    <!-- Filtre durumu -->
    <?php if (isset($parasutMeta) && $tab === 'products'): ?>

    <!-- Server-side arama formu - Paraşüt'te direkt sorgu -->
    <div style="padding:14px 16px;border-bottom:1px solid var(--border);background:#f8fafc">
      <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="page" value="parasut-mapping">
        <input type="hidden" name="tab" value="products">
        <?php if ($parasutMeta['show_all']): ?><input type="hidden" name="show_all" value="1"><?php endif; ?>
        <div style="flex:1;min-width:240px">
          <input type="search" name="q" value="<?= h($parasutMeta['search_query']) ?>"
                 placeholder="🔎 Paraşüt'te direkt ara (örn: G-14, churros, tavuk)..."
                 class="form-control" style="font-size:13px;padding:8px 12px">
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="height:36px;padding:0 16px">
          🔎 Paraşüt'te Ara
        </button>
        <?php if ($parasutMeta['search_query'] !== ''): ?>
        <a href="?page=parasut-mapping&tab=products<?= $parasutMeta['show_all'] ? '&show_all=1' : '' ?>"
           class="btn btn-secondary btn-sm" style="height:36px">✕ Aramayı Temizle</a>
        <?php endif; ?>
        <a href="?page=parasut-mapping&tab=products&diag_products=1" class="btn btn-sm" style="background:#7c3aed;color:#fff;border:none;height:36px;padding:0 16px;font-size:12px" title="Sayfa sayfa çekim detayını gör">
          🩺 Tanı
        </a>
      </form>
      <?php if ($parasutMeta['search_query'] !== ''): ?>
      <div style="margin-top:8px;font-size:11px;color:#1e40af;background:#eff6ff;border-left:3px solid #1e40af;padding:6px 10px;border-radius:4px">
        🔎 <strong>"<?= h($parasutMeta['search_query']) ?>"</strong> için Paraşüt'te <strong><?= count($parasutProducts) ?></strong> ürün bulundu
        (toplam <?= $parasutMeta['active_fetched'] ?> kayıt çekildi).
      </div>
      <?php endif; ?>
    </div>

    <?php
    // ─── DETAYLI TANI PANELİ ───
    if (isset($_GET['diag_products'])) {
        $diagActive   = parasut()->listAllProductsWithMeta(200);
        $diagArchived = parasut()->listAllProductsWithMeta(200, ['archived' => 'true']);
    ?>
    <div style="background:#faf5ff;border-top:3px solid #7c3aed;border-bottom:3px solid #7c3aed;padding:18px 20px;font-size:12px">
      <h3 style="margin:0 0 12px;font-size:14px;color:#5b21b6">🩺 Paraşüt Ürün Çekimi — Detaylı Tanı</h3>

      <!-- AKTİF ÇEKİM -->
      <div style="background:#fff;border-radius:8px;padding:12px;margin-bottom:10px">
        <div style="font-weight:700;color:#15803d;margin-bottom:8px">✅ Aktif Ürünler</div>
        <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:11px;margin-bottom:8px">
          <div><strong>Paraşüt diyor:</strong> <?= (int)$diagActive['total_count'] ?> aktif ürün var</div>
          <div><strong>Biz çektik:</strong> <?= (int)$diagActive['fetched'] ?> ürün</div>
          <div><strong>Toplam sayfa:</strong> <?= (int)$diagActive['total_pages'] ?></div>
          <?php
          $fark = (int)$diagActive['total_count'] - (int)$diagActive['fetched'];
          ?>
          <?php if ($fark > 0): ?>
          <div style="color:#dc2626;font-weight:700">⚠️ <?= $fark ?> ürün eksik!</div>
          <?php else: ?>
          <div style="color:#15803d;font-weight:700">✓ Tam çekildi</div>
          <?php endif; ?>
        </div>
        <details>
          <summary style="cursor:pointer;font-size:11px;color:#6b21a8">Sayfa sayfa detay göster</summary>
          <table style="width:100%;margin-top:8px;font-size:10px;font-family:monospace">
            <thead><tr style="background:#f3f4f6"><th style="text-align:left;padding:4px">Sayfa</th><th style="text-align:left;padding:4px">Gelen kayıt</th><th style="text-align:left;padding:4px">HTTP</th><th style="text-align:left;padding:4px">Deneme</th><th style="text-align:left;padding:4px">Hata</th></tr></thead>
            <tbody>
              <?php foreach (($diagActive['page_log'] ?? []) as $pl): ?>
              <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:4px">Sayfa #<?= $pl['page'] ?></td>
                <td style="padding:4px"><strong><?= $pl['count'] ?></strong> kayıt</td>
                <td style="padding:4px"><?= h((string)($pl['http'] ?? '—')) ?></td>
                <td style="padding:4px"><?= (int)($pl['attempts'] ?? 1) ?>x</td>
                <td style="padding:4px;color:#dc2626"><?= $pl['err'] ? h($pl['err']) : '—' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </details>
      </div>

      <!-- ARŞİVLİ ÇEKİM -->
      <div style="background:#fff;border-radius:8px;padding:12px;margin-bottom:10px">
        <div style="font-weight:700;color:#6b7280;margin-bottom:8px">📁 Arşivli Ürünler</div>
        <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:11px;margin-bottom:8px">
          <div><strong>Paraşüt diyor:</strong> <?= (int)$diagArchived['total_count'] ?> arşivli ürün var</div>
          <div><strong>Biz çektik:</strong> <?= (int)$diagArchived['fetched'] ?> ürün</div>
          <?php
          $fark2 = (int)$diagArchived['total_count'] - (int)$diagArchived['fetched'];
          ?>
          <?php if ($fark2 > 0): ?>
          <div style="color:#dc2626;font-weight:700">⚠️ <?= $fark2 ?> ürün eksik!</div>
          <?php else: ?>
          <div style="color:#15803d;font-weight:700">✓ Tam çekildi</div>
          <?php endif; ?>
        </div>
        <details>
          <summary style="cursor:pointer;font-size:11px;color:#6b21a8">Sayfa sayfa detay göster</summary>
          <table style="width:100%;margin-top:8px;font-size:10px;font-family:monospace">
            <thead><tr style="background:#f3f4f6"><th style="text-align:left;padding:4px">Sayfa</th><th style="text-align:left;padding:4px">Gelen kayıt</th><th style="text-align:left;padding:4px">HTTP</th><th style="text-align:left;padding:4px">Deneme</th><th style="text-align:left;padding:4px">Hata</th></tr></thead>
            <tbody>
              <?php foreach (($diagArchived['page_log'] ?? []) as $pl): ?>
              <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:4px">Sayfa #<?= $pl['page'] ?></td>
                <td style="padding:4px"><strong><?= $pl['count'] ?></strong> kayıt</td>
                <td style="padding:4px"><?= h((string)($pl['http'] ?? '—')) ?></td>
                <td style="padding:4px"><?= (int)($pl['attempts'] ?? 1) ?>x</td>
                <td style="padding:4px;color:#dc2626"><?= $pl['err'] ? h($pl['err']) : '—' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </details>
      </div>

      <!-- ÖZET -->
      <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:12px;font-size:11px;color:#78350f">
        <strong>💡 Yorum:</strong>
        <?php
        $totalApi = (int)$diagActive['total_count'] + (int)$diagArchived['total_count'];
        $totalUs  = (int)$diagActive['fetched'] + (int)$diagArchived['fetched'];
        ?>
        <ul style="margin:6px 0 0 18px;padding:0">
          <li>Paraşüt'ün söylediği <strong>TOPLAM</strong>: <?= $totalApi ?> ürün</li>
          <li>Biz çekebildik: <strong><?= $totalUs ?></strong> ürün</li>
          <?php if ($totalApi > $totalUs): ?>
          <li style="color:#b91c1c"><strong>⚠️ <?= ($totalApi - $totalUs) ?> ürün eksik kalıyor!</strong>
              Olası sebepler: pagination kesilmesi, draft/silinmiş ürünler, custom statü</li>
          <?php else: ?>
          <li style="color:#15803d"><strong>✓ Tüm ürünler çekildi.</strong>
              Eğer hâlâ aradığın bir ürünü göremiyorsan, yukarıdan "Paraşüt'te direkt ara" kutusunu kullan.</li>
          <?php endif; ?>
        </ul>
      </div>

      <div style="margin-top:10px">
        <a href="?page=parasut-mapping&tab=products" class="btn btn-sm btn-secondary" style="font-size:11px">← Tanı'yı Kapat</a>
      </div>
    </div>
    <?php } ?>

    <div style="background:#eff6ff;border-bottom:1px solid #bfdbfe;padding:10px 16px;font-size:12px;color:#1e40af;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div>
        <?php if (!empty($parasutMeta['show_all'])): ?>
        ⚠️ <strong>TÜM kayıtlar gösteriliyor</strong> — muhasebe kalemleri (TDHP) dahil
        <?php else: ?>
        📦 <strong>Gerçek ürünler</strong> gösteriliyor
        <?php if ($parasutMeta['filtered_count'] > 0): ?>
        · <strong><?= (int)$parasutMeta['filtered_count'] ?></strong> muhasebe kalemi gizlendi
        <?php endif; ?>
        <?php endif; ?>
        ·
        <strong>Paraşüt toplam:</strong>
        <?= (int)($parasutMeta['active_total'] ?: $parasutMeta['active_fetched']) ?> aktif +
        <?= (int)($parasutMeta['archived_total'] ?: $parasutMeta['archived_fetched']) ?> arşivli
      </div>
      <?php if (!empty($parasutMeta['show_all'])): ?>
      <a href="?page=parasut-mapping&tab=products<?= $parasutMeta['search_query'] !== '' ? '&q=' . urlencode($parasutMeta['search_query']) : '' ?>" class="btn btn-sm" style="background:#1e40af;color:#fff;border:none;font-size:11px">📦 Sadece Gerçek Ürünler</a>
      <?php else: ?>
      <a href="?page=parasut-mapping&tab=products&show_all=1<?= $parasutMeta['search_query'] !== '' ? '&q=' . urlencode($parasutMeta['search_query']) : '' ?>" class="btn btn-sm" style="background:#fff;color:#1e40af;border:1px solid #1e40af;font-size:11px">📋 Tüm Kayıtlar</a>
      <?php endif; ?>
    </div>

    <?php
    // ─── Eksik ürün otomatik uyarı bandı ───
    $activeMissing   = max(0, (int)$parasutMeta['active_total'] - (int)$parasutMeta['active_fetched']);
    $archivedMissing = max(0, (int)$parasutMeta['archived_total'] - (int)$parasutMeta['archived_fetched']);
    $totalMissing    = $activeMissing + $archivedMissing;
    if ($totalMissing > 0 && $parasutMeta['search_query'] === ''):
    ?>
    <div style="background:#fef2f2;border-bottom:2px solid #fca5a5;padding:12px 16px;font-size:12px;color:#7f1d1d;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div>
        ⚠️ <strong style="color:#b91c1c">DİKKAT:</strong>
        Paraşüt'te <strong><?= $activeMissing + $archivedMissing ?></strong> ürün daha var ama çekemedik!
        <span style="font-size:11px;color:#7f1d1d">
          (<?= $activeMissing ?> aktif, <?= $archivedMissing ?> arşivli eksik)
        </span>
      </div>
      <a href="?page=parasut-mapping&tab=products&diag_products=1" class="btn btn-sm" style="background:#b91c1c;color:#fff;border:none;font-size:11px">🩺 Detaylı Tanı</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Arama kutusu -->
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);background:#fafafa">
      <input type="search" id="orphanProdSearch" placeholder="🔍 İsim, SKU/kod veya ID ile ara… (örn: G-01, tavuk, KNORR)"
             class="form-control" style="font-size:13px;padding:8px 12px">
    </div>
    <form method="post" id="importProductsForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="import-products">
      <div class="table-wrap">
        <table class="table" style="font-size:12px;margin:0">
          <thead>
            <tr style="background:#f9fafb">
              <th style="width:40px;text-align:center">
                <input type="checkbox" id="orphanProdSelectAll" title="Tümünü seç/kaldır">
              </th>
              <th>Paraşüt Adı</th>
              <th>Stok Kodu</th>
              <th>KDV</th>
              <th>Birim Fiyat</th>
              <th>Paraşüt ID</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orphanProducts as $pp):
              $a = $pp['attributes'] ?? [];
              $isArch = !empty($a['archived']);
              // İsim fallback chain
              $rawName = trim($a['name'] ?? '');
              $code    = trim($a['code'] ?? '');
              $catName = trim($a['_category_name'] ?? '');
              $display = $rawName !== '' ? $rawName
                       : ($code !== '' ? '[Adsız - Kod: ' . $code . ']'
                       : '[Adsız - ID: ' . $pp['id'] . ']');
              $searchText = mb_strtolower(
                  $rawName . ' ' . $code . ' ' . $catName . ' ' . $pp['id']
              );
            ?>
            <tr class="orphan-prod-row" data-search="<?= h($searchText) ?>" style="<?= $isArch ? 'background:#fafafa;color:#94a3b8' : '' ?>">
              <td style="text-align:center">
                <input type="checkbox" name="parasut_ids[]" value="<?= h($pp['id']) ?>" class="orphan-prod-check">
              </td>
              <td>
                <div style="font-weight:600;color:<?= $rawName === '' ? '#dc2626' : 'inherit' ?>">
                  <?= h($display) ?>
                  <?php if ($isArch): ?>
                    <span class="badge" style="background:#e5e7eb;color:#6b7280;font-size:9px;font-weight:600;margin-left:6px">ARŞİVLİ</span>
                  <?php endif; ?>
                </div>
                <?php if ($catName !== ''): ?>
                <div style="margin-top:3px">
                  <span class="badge" style="background:#fef3c7;color:#92400e;font-size:9px;font-weight:600;padding:2px 6px;border-radius:3px;text-transform:uppercase;letter-spacing:.3px">📁 <?= h($catName) ?></span>
                </div>
                <?php endif; ?>
              </td>
              <td style="font-family:monospace"><?= h($code ?: '—') ?></td>
              <td>%<?= (int)($a['vat_rate'] ?? 0) ?></td>
              <td><?= isset($a['list_price']) ? money((float)$a['list_price']) : '—' ?></td>
              <td style="font-family:monospace;color:var(--text-muted);font-size:11px"><?= h($pp['id']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="padding:14px 16px;background:#f9fafb;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <div style="font-size:12px;color:var(--text-muted)">
          <span id="orphanProdCount">0</span> ürün seçildi
        </div>
        <button type="submit" class="btn btn-primary" id="importProductsBtn" disabled style="background:#0ea5e9;border-color:#0ea5e9" onclick="return confirm('Seçilen ' + document.querySelectorAll('.orphan-prod-check:checked').length + ' ürünü B2B\'ye yeni ürün olarak aktarmak istediğinize emin misiniz?\n\nHer biri için yeni b2b_products kaydı oluşturulur, Paraşüt ID otomatik bağlanır.');">
          📥 Seçilenleri B2B'ye Aktar
        </button>
      </div>
    </form>
  </div>
</div>
<script>
(function(){
  const cb = document.querySelectorAll('.orphan-prod-check');
  const all = document.getElementById('orphanProdSelectAll');
  const cnt = document.getElementById('orphanProdCount');
  const btn = document.getElementById('importProductsBtn');
  const search = document.getElementById('orphanProdSearch');

  function refresh() {
    const c = document.querySelectorAll('.orphan-prod-check:checked').length;
    cnt.textContent = c;
    btn.disabled = c === 0;
  }
  cb.forEach(b => b.addEventListener('change', refresh));
  all.addEventListener('change', () => {
    document.querySelectorAll('.orphan-prod-row').forEach(row => {
      if (row.style.display !== 'none') {
        const c = row.querySelector('.orphan-prod-check');
        if (c) c.checked = all.checked;
      }
    });
    refresh();
  });

  if (search) {
    search.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      document.querySelectorAll('.orphan-prod-row').forEach(row => {
        const txt = row.dataset.search || '';
        row.style.display = (q === '' || txt.includes(q)) ? '' : 'none';
      });
    });
  }
})();
</script>
<?php endif; ?>

<?php endif; ?>

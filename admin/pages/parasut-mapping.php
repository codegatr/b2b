<?php
/**
 * Admin â€” ParaÅŸÃ¼t EÅŸleme (Mapping)
 *
 * AmaÃ§: Ã‡ift kayÄ±t Ã¶nleme. Bayi ve Ã¼rÃ¼nleri ParaÅŸÃ¼t'tekilerle
 * Ã¶nceden eÅŸleÅŸtir (otomatik veya manuel), sonra eksik olanlarÄ±
 * ParaÅŸÃ¼t'te yeni oluÅŸtur.
 *
 * URL: ?page=parasut-mapping&tab=dealers|products
 */
requireAdmin();

if (!parasut()->isEnabled()) {
    echo '<div class="alert alert-warning" style="margin:20px">ParaÅŸÃ¼t entegrasyonu yapÄ±landÄ±rÄ±lmamÄ±ÅŸ. Ã–nce <a href="?page=settings&tab=parasut">Ayarlar</a> sayfasÄ±ndan credentials gir.</div>';
    return;
}

// â”€â”€â”€ AJAX SEARCH ENDPOINT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// JS'den fetch ile Ã§aÄŸrÄ±lÄ±r: ?page=parasut-mapping&ajax=search&kind=products&q=cheddar
if (($_GET['ajax'] ?? '') === 'search') {
    // TÃ¼m output buffer'Ä± temizle (header conflict Ã¶nleme)
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $kind = $_GET['kind'] ?? 'products';
    $q    = trim($_GET['q'] ?? '');
    $limit = min(50, max(5, (int)($_GET['limit'] ?? 30)));

    try {
        if ($kind === 'contacts') {
            $items = parasut_cache_get_contacts($q);
        } else {
            $items = parasut_cache_get_products($q, true);
        }

        // Ä°lk N tane (sayfa hÄ±zÄ± iÃ§in sÄ±nÄ±rla)
        $items = array_slice($items, 0, $limit);

        // SadeleÅŸtir
        $out = [];
        foreach ($items as $it) {
            $attrs = $it['attributes'] ?? [];
            $name  = trim($attrs['name'] ?? '');
            $code  = trim($attrs['code'] ?? '');
            $cat   = trim($attrs['_category_name'] ?? '');
            $vat   = (float)($attrs['vat_rate'] ?? 0);
            $price = isset($attrs['list_price']) ? (float)$attrs['list_price'] : null;
            $arch  = !empty($attrs['archived']);

            // GÃ¶rÃ¼nÃ¼r label
            $label = $name !== '' ? $name : ($code !== '' ? '[AdsÄ±z - ' . $code . ']' : '[AdsÄ±z - ID: ' . $it['id'] . ']');

            $out[] = [
                'id'       => $it['id'],
                'name'     => $name,
                'code'     => $code,
                'category' => $cat,
                'vat_rate' => $vat,
                'price'    => $price,
                'archived' => $arch,
                'label'    => $label,
            ];
        }
        echo json_encode(['success' => true, 'items' => $out, 'count' => count($out)], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$tab = $_GET['tab'] ?? 'dealers';
if (!in_array($tab, ['dealers', 'products'], true)) $tab = 'dealers';
$msg = '';

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// POST HANDLERS
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

// ğŸ”„ ParaÅŸÃ¼t Cache Senkronizasyonu (manuel buton)
if (isPost() && ($_POST['action'] ?? '') === 'sync-parasut-cache') {
    csrfCheck();
    // Uzun sÃ¼rebilir (1144 Ã¼rÃ¼n Ã— ~150ms = ~3 dakika)
    @set_time_limit(600);
    @ini_set('max_execution_time', 600);
    @ignore_user_abort(true);

    $r = parasut_cache_sync_products();
    if ($r['success']) {
        $msg = 'success:âœ“ Senkronizasyon tamamlandÄ±! ' . $r['total'] . ' Ã¼rÃ¼n cache\'de (' . $r['active'] . ' aktif + ' . $r['archived'] . ' arÅŸivli). SÃ¼re: ' . $r['duration'] . ' sn.';
    } else {
        $msg = 'danger:âœ— Senkronizasyon baÅŸarÄ±sÄ±z: ' . ($r['error'] ?? 'Bilinmeyen hata');
    }
    // POST sonrasÄ± redirect (PRG pattern) â€” sayfa yenilensin
    $_SESSION['flash'] = ['type' => $r['success'] ? 'success' : 'danger', 'msg' => substr($msg, strpos($msg, ':') + 1)];
    redirect('?page=parasut-mapping&tab=products');
}

// Bayi â†’ ParaÅŸÃ¼t cari eÅŸleme (manuel)
if (isPost() && ($_POST['action'] ?? '') === 'map-dealer') {
    csrfCheck();
    $dealerId   = (int)($_POST['dealer_id'] ?? 0);
    $parasutId  = trim($_POST['parasut_id'] ?? '');
    if ($dealerId) {
        if ($parasutId === '' || $parasutId === '-') {
            dbExec("UPDATE b2b_dealers SET parasut_contact_id=NULL WHERE id=?", [$dealerId]);
            $msg = 'success:EÅŸleme kaldÄ±rÄ±ldÄ±.';
        } else {
            dbExec("UPDATE b2b_dealers SET parasut_contact_id=? WHERE id=?", [$parasutId, $dealerId]);
            $msg = 'success:EÅŸleme kaydedildi.';
        }
    }
}

// ÃœrÃ¼n â†’ ParaÅŸÃ¼t Ã¼rÃ¼n eÅŸleme (manuel)
if (isPost() && ($_POST['action'] ?? '') === 'map-product') {
    csrfCheck();
    $productId = (int)($_POST['product_id'] ?? 0);
    $parasutId = trim($_POST['parasut_id'] ?? '');
    if ($productId) {
        if ($parasutId === '' || $parasutId === '-') {
            dbExec("UPDATE b2b_products SET parasut_product_id=NULL WHERE id=?", [$productId]);
            $msg = 'success:EÅŸleme kaldÄ±rÄ±ldÄ±.';
        } else {
            dbExec("UPDATE b2b_products SET parasut_product_id=? WHERE id=?", [$parasutId, $productId]);
            $msg = 'success:EÅŸleme kaydedildi.';
        }
    }
}

// AkÄ±llÄ± otomatik eÅŸleÅŸtirme â€” bayiler
if (isPost() && ($_POST['action'] ?? '') === 'auto-match-dealers') {
    csrfCheck();
    $contacts = parasut()->listAllContacts();
    $dealers  = dbRows("SELECT * FROM b2b_dealers WHERE is_active=1 AND (parasut_contact_id IS NULL OR parasut_contact_id='')");

    $matched = 0;
    foreach ($dealers as $d) {
        $candidate = null;

        // Ã–nce vergi no eÅŸleÅŸmesi (en gÃ¼venilir)
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

        // Sonra e-mail eÅŸleÅŸmesi
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

        // En son isim eÅŸleÅŸmesi (case-insensitive, tam) â€” company_name veya display_name
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
    $msg = "success:Otomatik eÅŸleÅŸtirme tamamlandÄ±. {$matched} bayi ParaÅŸÃ¼t cari kayÄ±tlarÄ±yla eÅŸleÅŸtirildi.";
}

// AkÄ±llÄ± otomatik eÅŸleÅŸtirme â€” Ã¼rÃ¼nler
if (isPost() && ($_POST['action'] ?? '') === 'auto-match-products') {
    csrfCheck();
    $parasutProducts = function_exists('parasut_cache_get_products') ? parasut_cache_get_products('', false) : parasut()->listAllProducts();
    $products = dbRows("SELECT * FROM b2b_products WHERE is_active=1 AND (parasut_product_id IS NULL OR parasut_product_id='')");

    $matched = 0;
    foreach ($products as $p) {
        $candidate = null;

        // Ã–nce SKU (kod) eÅŸleÅŸmesi
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

        // Sonra isim eÅŸleÅŸmesi (tam, case-insensitive)
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
    $msg = "success:Otomatik eÅŸleÅŸtirme tamamlandÄ±. {$matched} Ã¼rÃ¼n ParaÅŸÃ¼t stok kayÄ±tlarÄ±yla eÅŸleÅŸtirildi.";
}

// EÅŸleÅŸmemiÅŸ tek bayiyi ParaÅŸÃ¼t'te oluÅŸtur
if (isPost() && ($_POST['action'] ?? '') === 'create-dealer') {
    csrfCheck();
    $dealerId = (int)($_POST['dealer_id'] ?? 0);
    if ($dealerId) {
        try {
            $newId = parasut()->syncDealer($dealerId);
            if ($newId) {
                dbExec("UPDATE b2b_dealers SET parasut_contact_id=? WHERE id=?", [$newId, $dealerId]);
                $msg = "success:ParaÅŸÃ¼t'te yeni cari oluÅŸturuldu (ID: {$newId}).";
            } else {
                $msg = 'error:Cari oluÅŸturulamadÄ±. Ä°ÅŸlem geÃ§miÅŸinde detayÄ± gÃ¶rÃ¼n.';
            }
        } catch (\Throwable $e) {
            $msg = 'error:Hata: ' . $e->getMessage();
        }
    }
}

// EÅŸleÅŸmemiÅŸ tek Ã¼rÃ¼nÃ¼ ParaÅŸÃ¼t'te oluÅŸtur
if (isPost() && ($_POST['action'] ?? '') === 'create-product') {
    csrfCheck();
    $productId = (int)($_POST['product_id'] ?? 0);
    if ($productId) {
        try {
            $newId = parasut()->syncProduct($productId);
            if ($newId) {
                $msg = "success:ParaÅŸÃ¼t'te yeni Ã¼rÃ¼n oluÅŸturuldu (ID: {$newId}).";
            } else {
                $msg = 'error:ÃœrÃ¼n oluÅŸturulamadÄ±. Ä°ÅŸlem geÃ§miÅŸinde detayÄ± gÃ¶rÃ¼n.';
            }
        } catch (\Throwable $e) {
            $msg = 'error:Hata: ' . $e->getMessage();
        }
    }
}

// â”€â”€â”€ ParaÅŸÃ¼t'ten seÃ§ilen carileri B2B'ye aktar â”€â”€
if (isPost() && ($_POST['action'] ?? '') === 'import-contacts') {
    csrfCheck();
    $ids = $_POST['parasut_ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        $msg = 'error:LÃ¼tfen aktarÄ±lacak cari(leri) seÃ§in.';
    } else {
        // ParaÅŸÃ¼t cari listesini bir kere Ã§ek, lookup yap
        $allContacts = parasut()->listAllContacts();
        $byId = [];
        foreach ($allContacts as $c) $byId[(string)$c['id']] = $c;

        $created = 0; $skipped = 0; $errors = [];
        foreach ($ids as $pid) {
            $pid = (string)$pid;
            if (!isset($byId[$pid])) { $skipped++; continue; }

            $c = $byId[$pid];
            $a = $c['attributes'] ?? [];

            // AynÄ± parasut_contact_id zaten varsa atla
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

            // Bayi tipi â€” VKN 10 hane ise kurumsal, 11 ise bireysel
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
        if ($created > 0) $parts[] = "{$created} bayi oluÅŸturuldu";
        if ($skipped > 0) $parts[] = "{$skipped} kayÄ±t atlandÄ± (zaten var veya geÃ§ersiz)";
        if (!empty($errors)) $parts[] = 'hatalar: ' . implode(' | ', array_slice($errors, 0, 3));
        $msg = 'success:âœ“ AktarÄ±m tamamlandÄ± â€” ' . implode(' Â· ', $parts);
    }
}

// â”€â”€â”€ ParaÅŸÃ¼t'ten seÃ§ilen Ã¼rÃ¼nleri B2B'ye aktar â”€â”€
if (isPost() && ($_POST['action'] ?? '') === 'import-products') {
    csrfCheck();
    $ids = $_POST['parasut_ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        $msg = 'error:LÃ¼tfen aktarÄ±lacak Ã¼rÃ¼n(leri) seÃ§in.';
    } else {
        $allProds = function_exists('parasut_cache_get_products') ? parasut_cache_get_products('', true) : parasut()->listAllProducts();
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
            // ParaÅŸÃ¼t list_price KDV Dahil â€” net'e Ã§evir
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
        if ($created > 0) $parts[] = "{$created} Ã¼rÃ¼n oluÅŸturuldu";
        if ($skipped > 0) $parts[] = "{$skipped} kayÄ±t atlandÄ± (zaten var veya geÃ§ersiz)";
        if (!empty($errors)) $parts[] = 'hatalar: ' . implode(' | ', array_slice($errors, 0, 3));
        $msg = 'success:âœ“ AktarÄ±m tamamlandÄ± â€” ' . implode(' Â· ', $parts);
    }
}

// â”€â”€â”€ TOPLU KAYIP EÅLEÅMELERÄ° SIFIRLA â”€â”€
// ParaÅŸÃ¼t'te bulunamayan ID'lere sahip tÃ¼m B2B kayÄ±tlarÄ±nÄ±n
// parasut_*_id alanlarÄ±nÄ± NULL yapar. Sonra yeniden eÅŸleme yapÄ±labilir.
if (isPost() && ($_POST['action'] ?? '') === 'reset-lost-matches') {
    csrfCheck();
    $type = $_POST['type'] ?? '';

    if ($type === 'dealers') {
        // TÃ¼m B2B bayilerin parasut_contact_id'sini al, ParaÅŸÃ¼t listesinde olmayanlarÄ± sÄ±fÄ±rla
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
            $msg = 'success:âœ“ ' . count($resetIds) . ' kayÄ±p bayi eÅŸleÅŸmesi sÄ±fÄ±rlandÄ±. Åimdi "ğŸ¤– Otomatik EÅŸleÅŸtir" ile yeniden baÄŸlayÄ±n.';
        } else {
            $msg = 'success:KayÄ±p eÅŸleÅŸme bulunamadÄ±, hepsi geÃ§erli.';
        }
    } elseif ($type === 'products') {
        $allProds = function_exists('parasut_cache_get_products') ? parasut_cache_get_products('', true) : parasut()->listAllProducts();
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
            $msg = 'success:âœ“ ' . count($resetIds) . ' kayÄ±p Ã¼rÃ¼n eÅŸleÅŸmesi sÄ±fÄ±rlandÄ±. Åimdi "ğŸ¤– Otomatik EÅŸleÅŸtir" ile yeniden baÄŸlayÄ±n.';
        } else {
            $msg = 'success:KayÄ±p eÅŸleÅŸme bulunamadÄ±, hepsi geÃ§erli.';
        }
    }
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// DATA HAZIRLA (her render'da)
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

if ($tab === 'dealers') {
    $b2bDealers = dbRows("SELECT id, company_name, first_name, last_name, email, phone, tax_number, parasut_contact_id,
                                  COALESCE(NULLIF(company_name,''), CONCAT(TRIM(first_name),' ',TRIM(last_name))) AS display_name
                          FROM b2b_dealers WHERE is_active=1
                          ORDER BY (parasut_contact_id IS NOT NULL AND parasut_contact_id != '') DESC, display_name");

    // ParaÅŸÃ¼t'ten cari listesi â€” aktif + arÅŸivlenmiÅŸ ayrÄ± ayrÄ±
    $activeRes = parasut()->listAllContactsWithMeta(40);
    $archivedRes = parasut()->listAllContactsWithMeta(40, ['archived' => 'true']);
    $parasutContacts = array_merge($activeRes['data'], $archivedRes['data']);

    $parasutMeta = [
        'active_total'   => $activeRes['total_count'],
        'active_fetched' => $activeRes['fetched'],
        'archived_total' => $archivedRes['total_count'],
        'archived_fetched' => $archivedRes['fetched'],
    ];

    // HÄ±zlÄ± lookup iÃ§in IDâ†’object map (string-key)
    $parasutContactsById = [];
    foreach ($parasutContacts as $c) {
        $parasutContactsById[(string)$c['id']] = $c;
    }

    // EÅŸleÅŸmiÅŸ bayilerin ParaÅŸÃ¼t ID'lerini topla
    $usedContactIds = array_map('strval', array_filter(array_column($b2bDealers, 'parasut_contact_id')));

    // ParaÅŸÃ¼t'te olup B2B'de eÅŸleÅŸmemiÅŸ cariler (yetimler)
    $orphanContacts = [];
    foreach ($parasutContacts as $c) {
        if (!in_array((string)$c['id'], $usedContactIds, true)) $orphanContacts[] = $c;
    }

    // B2B'de eÅŸleÅŸmiÅŸ AMA ParaÅŸÃ¼t'te bulunamayan ID'ler (silinmiÅŸ/yanlÄ±ÅŸ)
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

    // ?q=XXX â€” cache iÃ§inde anlÄ±k arama (ParaÅŸÃ¼t'e gitmez)
    $searchQuery = trim($_GET['q'] ?? '');

    // ?show_all=1 â€” muhasebe hesap kalemleri dahil
    $showAll = isset($_GET['show_all']) && $_GET['show_all'] === '1';

    // CACHE FIRST: arka planda DB'de hazÄ±r Ã¼rÃ¼nler
    $cacheStats = parasut_cache_stats();

    // YENI MANTIK (v1.1.81+): Sayfa aÃ§Ä±ldÄ±ÄŸÄ±nda otomatik 1144 Ã¼rÃ¼n gÃ¶sterme.
    // Sadece kullanÄ±cÄ± arama yaparsa cache'den Ã§ek (LIKE prefix optimized).
    // Bu sayfa hÄ±zlÄ± yÃ¼klenir, scroll uzun olmaz.
    if ($searchQuery !== '') {
        $rawProducts = parasut_cache_get_products($searchQuery, true);
    } else {
        $rawProducts = []; // BoÅŸ arama â†’ Ã¼rÃ¼n listesi gÃ¶sterme
    }

    // KRITIK (v1.1.83): Mevcut eÅŸleÅŸtirilmiÅŸ Ã¼rÃ¼nleri HER ZAMAN dropdown'a dahil et
    // Aksi takdirde arama yokken kullanÄ±cÄ± eÅŸleÅŸmeleri "kayÄ±p" gÃ¶rÃ¼r
    $linkedIds = array_filter(array_column($b2bProducts, 'parasut_product_id'));
    if (!empty($linkedIds)) {
        $placeholders = implode(',', array_fill(0, count($linkedIds), '?'));
        $linkedRows = dbRows(
            "SELECT * FROM b2b_parasut_cache WHERE kind='products' AND parasut_id IN ($placeholders)",
            array_values($linkedIds)
        );
        $existingIds = array_column($rawProducts, 'id');
        foreach ($linkedRows as $row) {
            // EÄŸer bu ID zaten rawProducts'ta yoksa ekle
            if (!in_array($row['parasut_id'], $existingIds, true)) {
                $attrs = !empty($row['raw_data']) ? (json_decode($row['raw_data'], true) ?: []) : [];
                if (empty($attrs['name']))         $attrs['name']     = $row['name'];
                if (empty($attrs['code']))         $attrs['code']     = $row['code'];
                if (!isset($attrs['vat_rate']))    $attrs['vat_rate'] = (float)$row['vat_rate'];
                if (!isset($attrs['archived']))    $attrs['archived'] = (bool)$row['archived'];
                if (empty($attrs['_category_name']) && !empty($row['category_name'])) {
                    $attrs['_category_name'] = $row['category_name'];
                }
                $rawProducts[] = [
                    'id'         => $row['parasut_id'],
                    'type'       => 'products',
                    'attributes' => $attrs,
                ];
            }
        }
    }

    $parasutError = null;
    $parasutProducts = [];

    /**
     * Muhasebe kalemi tespit fonksiyonu (regex: SADECE rakam+nokta)
     */
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

    // PHP-side alfabetik sÄ±ralama (TÃ¼rkÃ§e karakter destekli)
    usort($parasutProducts, function($a, $b) {
        $nameA = mb_strtolower(trim($a['attributes']['name'] ?? ''), 'UTF-8');
        $nameB = mb_strtolower(trim($b['attributes']['name'] ?? ''), 'UTF-8');
        if ($nameA === '' && $nameB !== '') return 1;
        if ($nameA !== '' && $nameB === '') return -1;
        return strnatcasecmp($nameA, $nameB);
    });

    $parasutMeta = [
        'cache_total'    => $cacheStats['total'],
        'cache_active'   => $cacheStats['active'],
        'cache_archived' => $cacheStats['archived'],
        'last_synced'    => $cacheStats['last_synced'],
        'active_total'   => $cacheStats['active'],
        'active_fetched' => $cacheStats['active'],
        'archived_total' => $cacheStats['archived'],
        'archived_fetched'=> $cacheStats['archived'],
        'show_all'       => $showAll,
        'filtered_count' => $filteredCount,
        'search_query'   => $searchQuery,
        'error'          => $parasutError,
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

    // B2B'de eÅŸleÅŸmiÅŸ AMA ParaÅŸÃ¼t'te bulunamayan ID'ler
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
        'total_parasut'   => (int)$cacheStats['total'], // Cache'deki TOPLAM Ã¼rÃ¼n sayÄ±sÄ± (sayfada arama yoksa bile)
        'orphan_parasut'  => count($orphanProducts),
    ];
}
?>

<div class="page-header">
  <div>
    <h1 class="page-title">ParaÅŸÃ¼t â€” EÅŸleme & Ã–nizleme</h1>
    <p class="page-sub">Bayileri ve Ã¼rÃ¼nleri ParaÅŸÃ¼t kayÄ±tlarÄ±yla eÅŸleÅŸtirin Â· Ã‡ift kayÄ±t Ã¶nleyin</p>
  </div>
  <div class="btn-group">
    <a href="?page=parasut" class="btn btn-secondary btn-sm">â† ParaÅŸÃ¼t Anasayfa</a>
  </div>
</div>

<?php if ($msg): [$t,$m] = explode(':', $msg, 2); ?>
<div class="alert alert-<?= $t==='error'?'danger':'success' ?>"><?= h($m) ?></div>
<?php endif; ?>

<!-- â”€â”€â”€ ParaÅŸÃ¼t API DURUMU â”€â”€â”€ -->
<?php
$apiCount = ($tab === 'dealers') ? count($parasutContacts) : count($parasutProducts);
$apiKind  = ($tab === 'dealers') ? 'cari' : 'Ã¼rÃ¼n';
$activeSearchQuery = ($tab === 'products') ? trim($parasutMeta['search_query'] ?? '') : '';
$isSearching = $activeSearchQuery !== '';
$apiError = ($tab === 'products') ? ($parasutMeta['error'] ?? null) : null;
?>
<?php if ($apiError): ?>
<!-- EXCEPTION yakalandÄ± â€” ParaÅŸÃ¼t'e baÄŸlantÄ±/yetki sorunu var -->
<div class="alert" style="background:#fef2f2;border:2px solid #dc2626;color:#7f1d1d;padding:14px 16px;margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;gap:12px">
    <div style="font-size:24px">â›”</div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:14px;color:#b91c1c;margin-bottom:6px">
        ParaÅŸÃ¼t API HatasÄ±
      </div>
      <div style="font-size:12px;line-height:1.6;background:#fff;padding:8px 10px;border-radius:6px;font-family:monospace;color:#7f1d1d;border:1px solid #fca5a5;margin-bottom:8px">
        <?= h($apiError) ?>
      </div>
      <div style="font-size:11px;color:#7f1d1d">
        Bu hatayla karÅŸÄ±laÅŸtÄ±ÄŸÄ±nÄ±zda eÅŸleme yapÄ±lamaz. LÃ¼tfen tanÄ± araÃ§larÄ±nÄ± kullanÄ±n:
      </div>
      <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
        <a href="?page=parasut&diag=1" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none">ğŸ©º Endpoint TanÄ±</a>
        <a href="?page=parasut&clear_token=1" class="btn btn-sm" style="background:#fff;color:#dc2626;border:1px solid #dc2626" onclick="return confirm('Token cache temizlensin mi?');">ğŸ”„ Token Yenile</a>
        <a href="?page=parasut-mapping&tab=products&diag_products=1" class="btn btn-sm" style="background:#fff;color:#dc2626;border:1px solid #dc2626">ğŸ©º ÃœrÃ¼n TanÄ±</a>
      </div>
    </div>
  </div>
</div>
<?php elseif ($apiCount === 0 && !$isSearching && $tab === 'products'): ?>
<?php /* Products tab'ta cache boÅŸ veya arama sonucu yok durumu iÃ§in ayrÄ±ca uyarÄ± gÃ¶stermiyoruz â€”
         yukarÄ±daki cache durum kartÄ± ve "Ä°pucu" rehberi yeterli. Buradan herhangi bir uyarÄ±
         Ã§Ä±karmÄ±yoruz, sayfa temiz gÃ¶rÃ¼nÃ¼r. */ ?>
<?php elseif ($apiCount === 0 && !$isSearching && $tab === 'dealers'): ?>
<!-- DEALERS TAB: ParaÅŸÃ¼t'ten direkt Ã§ekiyoruz, BOÅ ciddi sorun -->
<div class="alert" style="background:#fef2f2;border:2px solid #dc2626;color:#7f1d1d;padding:14px 16px;margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;gap:12px">
    <div style="font-size:24px">ğŸš¨</div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:14px;color:#b91c1c;margin-bottom:4px">
        ParaÅŸÃ¼t'ten <?= $apiKind ?> listesi BOÅ dÃ¶ndÃ¼
      </div>
      <div style="font-size:12px;line-height:1.6">
        Bu durumda eÅŸleÅŸtirme yapÄ±lamaz. OlasÄ± sebepler:
        <ul style="margin:6px 0 0 18px;padding:0">
          <li><strong>Token sorunu:</strong> EriÅŸim tokeni geÃ§ersiz veya sÃ¼resi dolmuÅŸ</li>
          <li><strong>YanlÄ±ÅŸ Company ID:</strong> Settings'teki <code>parasut_company_id</code> baÅŸka bir hesaba ait</li>
          <li><strong>Yetki eksikliÄŸi:</strong> Bu API kullanÄ±cÄ±sÄ±nÄ±n <?= $apiKind ?>leri okuma izni yok</li>
          <li><strong>HesabÄ±nÄ±z boÅŸ:</strong> ParaÅŸÃ¼t hesabÄ±nÄ±zda gerÃ§ekten kayÄ±t yok</li>
        </ul>
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
          <a href="?page=parasut&diag=1" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none">ğŸ©º Endpoint TanÄ±</a>
          <a href="?page=parasut&test=1" class="btn btn-sm" style="background:#fff;color:#dc2626;border:1px solid #dc2626">ğŸ”Œ BaÄŸlantÄ± Test Et</a>
          <a href="?page=parasut&clear_token=1" class="btn btn-sm" style="background:#fff;color:#dc2626;border:1px solid #dc2626" onclick="return confirm('Token cache temizlensin mi?');">ğŸ”„ Token Yenile</a>
          <a href="?page=settings&tab=parasut" class="btn btn-sm" style="background:#fff;color:#dc2626;border:1px solid #dc2626">âš™ Credentials Kontrol</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php elseif ($apiCount === 0 && $isSearching): ?>
<!-- Arama yapÄ±ldÄ±, Ã¼rÃ¼n bulunamadÄ± (ParaÅŸÃ¼t veritabanÄ± boÅŸ DEÄÄ°L!) -->
<div class="alert" style="background:#fefce8;border:2px solid #fde68a;color:#713f12;padding:14px 16px;margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;gap:12px">
    <div style="font-size:24px">ğŸ”</div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:14px;color:#854d0e;margin-bottom:4px">
        "<?= h($activeSearchQuery) ?>" aramasÄ± ParaÅŸÃ¼t'te eÅŸleÅŸme bulamadÄ±
      </div>
      <div style="font-size:12px;line-height:1.6">
        ParaÅŸÃ¼t'te bu ada/koda sahip <strong>aktif veya arÅŸivli Ã¼rÃ¼n yok</strong>. ÅunlarÄ± deneyin:
        <ul style="margin:6px 0 0 18px;padding:0">
          <li>AramayÄ± temizleyip <strong>tÃ¼m Ã¼rÃ¼nleri gÃ¶rÃ¼n</strong></li>
          <li>Daha kÄ±sa anahtar kelime deneyin (Ã¶rn "tavuk" â†’ "tav")</li>
          <li>ParaÅŸÃ¼t'te Ã¼rÃ¼nÃ¼n gerÃ§ek adÄ±nÄ± kontrol edin</li>
        </ul>
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
          <a href="?page=parasut-mapping&tab=products" class="btn btn-sm" style="background:#854d0e;color:#fff;border:none">âœ• AramayÄ± Temizle</a>
          <a href="?page=parasut-mapping&tab=products&diag_products=1" class="btn btn-sm" style="background:#fff;color:#854d0e;border:1px solid #854d0e">ğŸ©º TanÄ± AÃ§</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php elseif (!empty($missingMatches)): ?>
<!-- EÅŸleÅŸmiÅŸ ama ParaÅŸÃ¼t'te bulunamayan kayÄ±tlar var -->
<div class="alert" style="background:#fef3c7;border:1px solid #fcd34d;color:#78350f;padding:12px 14px;margin-bottom:16px">
  <div style="display:flex;align-items:flex-start;gap:10px">
    <div style="font-size:20px">âš ï¸</div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:13px;color:#92400e">
        <?= $missingMatches ?> kayÄ±p eÅŸleÅŸme tespit edildi
      </div>
      <div style="font-size:12px;line-height:1.5;margin-top:4px">
        <?= $missingMatches ?> <?= $apiKind ?> iÃ§in kayÄ±tlÄ± ParaÅŸÃ¼t ID'si var, ancak ParaÅŸÃ¼t'ten gelen listede bulunamadÄ±.
        Bu kayÄ±tlar ParaÅŸÃ¼t'te silinmiÅŸ veya baÅŸka bir hesapta olabilir.
      </div>
      <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <form method="post" onsubmit="return confirm('âš ï¸ TOPLU SIFIRLAMA\n\n<?= $missingMatches ?> kayÄ±p <?= $apiKind ?> eÅŸleÅŸmesinin parasut_<?= $tab === 'dealers' ? 'contact' : 'product' ?>_id alanlarÄ± NULL yapÄ±lacak.\n\nBu iÅŸlem GERÄ° ALINAMAZ. Sonra \"Otomatik EÅŸleÅŸtir\" ile yeniden baÄŸlayabilirsiniz.\n\nDevam edilsin mi?');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reset-lost-matches">
          <input type="hidden" name="type" value="<?= $tab === 'dealers' ? 'dealers' : 'products' ?>">
          <button type="submit" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none">
            ğŸ§¹ Hepsini SÄ±fÄ±rla (<?= $missingMatches ?>)
          </button>
        </form>
        <span style="font-size:11px;color:#78350f">Sonra â¬‡ aÅŸaÄŸÄ±dan ğŸ¤– Otomatik EÅŸleÅŸtir tÄ±kla</span>
      </div>
    </div>
  </div>
</div>
<?php else: ?>
<!-- SaÄŸlÄ±klÄ± durum - kÄ±sa bilgi + meta detayÄ± -->
<div style="background:#f0fdf4;border:1px solid #86efac;color:#166534;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:12px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div>
      âœ“ ParaÅŸÃ¼t'ten <strong><?= $apiCount ?></strong> <?= $apiKind ?> Ã§ekildi
      <?php if (isset($parasutMeta)): ?>
        <span style="color:#166534;margin-left:8px">
          (<?= (int)$parasutMeta['active_fetched'] ?>/<?= (int)$parasutMeta['active_total'] ?> aktif
          + <?= (int)$parasutMeta['archived_fetched'] ?>/<?= (int)$parasutMeta['archived_total'] ?> arÅŸivli)
        </span>
      <?php endif; ?>
    </div>
    <?php
    // Ã‡ekilen vs toplam fark var mÄ±? (eksik kayÄ±t tespiti)
    if (isset($parasutMeta)) {
        $expected = $parasutMeta['active_total'] + $parasutMeta['archived_total'];
        $missing  = $expected - $apiCount;
        if ($missing > 0):
    ?>
    <div style="background:#fef3c7;color:#92400e;padding:4px 10px;border-radius:6px;font-weight:600;font-size:11px">
      âš ï¸ <?= $missing ?> kayÄ±t Ã§ekilemedi (sayfa limiti?)
    </div>
    <?php endif; } ?>
  </div>
</div>
<?php endif; ?>

<!-- Sekme Navigasyonu -->
<div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--border)">
  <a href="?page=parasut-mapping&tab=dealers" style="padding:10px 18px;border-bottom:2px solid <?= $tab==='dealers'?'var(--accent)':'transparent' ?>;color:<?= $tab==='dealers'?'var(--text)':'var(--text-muted)' ?>;font-weight:<?= $tab==='dealers'?'700':'500' ?>;margin-bottom:-2px;text-decoration:none">
    ğŸ‘¥ Bayiler (Cariler)
  </a>
  <a href="?page=parasut-mapping&tab=products" style="padding:10px 18px;border-bottom:2px solid <?= $tab==='products'?'var(--accent)':'transparent' ?>;color:<?= $tab==='products'?'var(--text)':'var(--text-muted)' ?>;font-weight:<?= $tab==='products'?'700':'500' ?>;margin-bottom:-2px;text-decoration:none">
    ğŸ“¦ ÃœrÃ¼nler (Stoklar)
  </a>
</div>

<!-- Stat KartlarÄ± -->
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
      <div class="stat-label">EÅŸleÅŸmiÅŸ</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['unmatched_b2b'] ?></div>
      <div class="stat-label">B2B'de BaÄŸlanmamÄ±ÅŸ</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['total_parasut'] ?></div>
      <div class="stat-label">ParaÅŸÃ¼t'te Toplam</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon red">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['orphan_parasut'] ?></div>
      <div class="stat-label">Sadece ParaÅŸÃ¼t'te</div>
    </div>
  </div>
</div>

<!-- Aksiyon kutusu -->
<div class="card" style="margin-bottom:20px">
  <div class="card-body" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
    <div style="flex:1;min-width:240px">
      <div style="font-weight:700;font-size:14px;margin-bottom:4px">ğŸ”„ AkÄ±llÄ± Otomatik EÅŸleÅŸtir</div>
      <div style="font-size:12px;color:var(--text-muted);line-height:1.5">
        <?php if ($tab === 'dealers'): ?>
        Vergi numarasÄ±, e-mail veya isim eÅŸleÅŸmesine gÃ¶re B2B bayileri ParaÅŸÃ¼t cari kayÄ±tlarÄ±yla otomatik baÄŸlar.
        Sadece <strong>henÃ¼z eÅŸleÅŸmemiÅŸ</strong> bayileri etkiler.
        <?php else: ?>
        SKU (stok kodu) veya isim eÅŸleÅŸmesine gÃ¶re B2B Ã¼rÃ¼nleri ParaÅŸÃ¼t stok kayÄ±tlarÄ±yla otomatik baÄŸlar.
        Sadece <strong>henÃ¼z eÅŸleÅŸmemiÅŸ</strong> Ã¼rÃ¼nleri etkiler.
        <?php endif; ?>
      </div>
    </div>
    <form method="post" onsubmit="return confirm('AkÄ±llÄ± eÅŸleÅŸtirme Ã§alÄ±ÅŸacak. Devam edilsin mi?');">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="auto-match-<?= $tab ?>">
      <button type="submit" class="btn btn-primary">ğŸ¤– Otomatik EÅŸleÅŸtir</button>
    </form>
  </div>
</div>

<?php if ($tab === 'dealers'): ?>
<!-- BAYÄ°LER TAB -->
<div class="card">
  <div class="card-header"><h3 class="card-title">B2B Bayiler â†” ParaÅŸÃ¼t Cariler</h3></div>
  <div class="table-wrap">
    <table class="table" style="font-size:13px">
      <thead>
        <tr>
          <th>B2B Bayi</th>
          <th>Vergi No</th>
          <th>E-posta</th>
          <th>ParaÅŸÃ¼t Cari EÅŸleÅŸmesi</th>
          <th style="width:130px">Aksiyon</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($b2bDealers as $d):
            $linkedId = (string)($d['parasut_contact_id'] ?? '');
            $linked   = null;
            $isLost   = false;  // EÅŸleÅŸme var ama ParaÅŸÃ¼t'te bulunamayan ID

            if ($linkedId !== '') {
                if (isset($parasutContactsById[$linkedId])) {
                    $linked = $parasutContactsById[$linkedId];
                } else {
                    $isLost = true;
                }
            }

            // SatÄ±r arka plan rengi:
            // - EÅŸleÅŸmiÅŸ ve saÄŸlam: hafif yeÅŸil
            // - EÅŸleÅŸmiÅŸ ama kayÄ±p: kÄ±rmÄ±zÄ± (uyarÄ±)
            // - BaÄŸlanmamÄ±ÅŸ: sarÄ±msÄ±
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
            <div style="margin-top:4px;font-size:10px;color:#15803d;font-weight:700">âœ“ <?= h($linked['attributes']['name'] ?? 'EÅŸleÅŸti') ?> (ID: <?= h($linkedId) ?>)</div>
            <?php elseif ($isLost): ?>
            <div style="margin-top:4px;font-size:10px;color:#b91c1c;font-weight:700">âš ï¸ ParaÅŸÃ¼t'te bu ID bulunamadÄ±: <?= h($linkedId) ?></div>
            <div style="font-size:10px;color:#7f1d1d">Bu kayÄ±t ParaÅŸÃ¼t'te silinmiÅŸ olabilir. AÅŸaÄŸÄ±dan "â€” BaÄŸlÄ± deÄŸil â€”" seÃ§ + ğŸ’¾ ile eÅŸlemeyi sÄ±fÄ±rla.</div>
            <?php endif; ?>
          </td>
          <td style="font-family:monospace;font-size:12px"><?= h($d['tax_number'] ?: 'â€”') ?></td>
          <td style="font-size:12px"><?= h($d['email'] ?: 'â€”') ?></td>
          <td>
            <form method="post" class="parasut-search-form map-form" data-kind="contacts" style="display:flex;gap:6px;align-items:center;position:relative">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="map-dealer">
              <input type="hidden" name="dealer_id" value="<?= (int)$d['id'] ?>">
              <input type="hidden" name="parasut_id" class="ps-parasut-id" value="<?= h($linkedId !== '' ? $linkedId : '-') ?>">
              <div style="position:relative;flex:1">
                <input type="text" class="form-control ps-search-input"
                       placeholder="ğŸ” ParaÅŸÃ¼t cari ara (en az 2 karakter)..."
                       autocomplete="off"
                       value="<?php
                         if ($linked) {
                           $ln = trim($linked['attributes']['name'] ?? '');
                           $vt = trim($linked['attributes']['tax_number'] ?? '');
                           echo h(($ln !== '' ? $ln : 'AdsÄ±z') . ($vt ? ' [VKN: ' . $vt . ']' : '') . ' (ID: ' . $linkedId . ')');
                         } elseif ($isLost) {
                           echo h('âš ï¸ KAYIP ID: ' . $linkedId);
                         }
                       ?>"
                       style="font-size:12px;padding:6px 30px 6px 10px;height:32px;<?= $linked ? 'border:2px solid #16a34a;background:#f0fdf4' : ($isLost ? 'border:2px solid #dc2626;background:#fef2f2' : '') ?>">
                <?php if ($linked || $isLost): ?>
                <button type="button" class="ps-clear-btn" title="EÅŸlemeyi temizle"
                        style="position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;color:#64748b;cursor:pointer;font-size:14px;padding:2px 6px">âœ•</button>
                <?php endif; ?>
                <div class="ps-suggestions" style="display:none;position:absolute;top:calc(100% + 2px);left:0;right:0;background:#fff;border:1px solid #cbd5e1;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.15);max-height:280px;overflow-y:auto;z-index:50"></div>
              </div>
              <button type="submit" class="btn btn-sm" style="padding:4px 10px;font-size:11px;background:#16a34a;color:#fff;border:none;height:32px">ğŸ’¾</button>
            </form>
          </td>
          <td>
            <?php if (!$linked): ?>
            <form method="post" onsubmit="return confirm('Bu bayi ParaÅŸÃ¼t\'te yeni cari olarak oluÅŸturulacak. Ã–nce eÅŸleÅŸme aramayÄ± dÃ¼ÅŸÃ¼ndÃ¼nÃ¼z mÃ¼?');">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="create-dealer">
              <input type="hidden" name="dealer_id" value="<?= (int)$d['id'] ?>">
              <button type="submit" class="btn btn-sm" style="background:#0ea5e9;color:#fff;border:none;font-size:11px;padding:4px 10px">+ ParaÅŸÃ¼t'te OluÅŸtur</button>
            </form>
            <?php else: ?>
            <span style="font-size:11px;color:#15803d">Senkron hazÄ±r</span>
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
    <h3 class="card-title" style="color:#075985">ğŸ“¥ ParaÅŸÃ¼t'ten B2B'ye AktarÄ±labilir Cariler â€” <?= count($orphanContacts) ?> kayÄ±t</h3>
    <div style="font-size:11px;color:#0c4a6e">Bu kayÄ±tlar ParaÅŸÃ¼t'te var, B2B'de yok</div>
  </div>
  <div class="card-body" style="padding:0">
    <div style="background:#fffbeb;border-bottom:1px solid #fde68a;padding:10px 16px;font-size:12px;color:#78350f">
      â„¹ï¸ SeÃ§ilen carileri B2B'ye <strong>yeni bayi</strong> olarak aktarÄ±n (parasut_contact_id otomatik baÄŸlanÄ±r, Ã§ift kayÄ±t olmaz). KarmaÅŸa olmasÄ±n diye <strong>tek tek veya kÃ¼Ã§Ã¼k gruplar</strong> halinde seÃ§in.
    </div>
    <!-- Arama kutusu -->
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);background:#fafafa">
      <input type="search" id="orphanContactSearch" placeholder="ğŸ” Ä°sim, VKN, e-mail veya ID ile araâ€¦"
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
                <input type="checkbox" id="orphanSelectAll" title="TÃ¼mÃ¼nÃ¼ seÃ§/kaldÄ±r">
              </th>
              <th>ParaÅŸÃ¼t AdÄ±</th>
              <th>Vergi No</th>
              <th>Vergi Dairesi</th>
              <th>E-posta</th>
              <th>Telefon</th>
              <th>ParaÅŸÃ¼t ID</th>
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
                <?= h($a['name'] ?? 'â€”') ?>
                <?php if ($isArch): ?>
                  <span class="badge" style="background:#e5e7eb;color:#6b7280;font-size:9px;font-weight:600;margin-left:6px">ARÅÄ°VLÄ°</span>
                <?php endif; ?>
              </td>
              <td style="font-family:monospace"><?= h($a['tax_number'] ?? 'â€”') ?></td>
              <td><?= h($a['tax_office'] ?? 'â€”') ?></td>
              <td><?= h($a['email'] ?? 'â€”') ?></td>
              <td><?= h($a['phone'] ?? 'â€”') ?></td>
              <td style="font-family:monospace;color:var(--text-muted)"><?= h($c['id']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="padding:14px 16px;background:#f9fafb;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <div style="font-size:12px;color:var(--text-muted)">
          <span id="orphanCount">0</span> kayÄ±t seÃ§ildi
        </div>
        <button type="submit" class="btn btn-primary" id="importContactsBtn" disabled style="background:#0ea5e9;border-color:#0ea5e9" onclick="return confirm('SeÃ§ilen ' + document.querySelectorAll('.orphan-check:checked').length + ' cariyi B2B\'ye yeni bayi olarak aktarmak istediÄŸinize emin misiniz?\n\nHer biri iÃ§in yeni bir b2b_dealers kaydÄ± oluÅŸturulur, ParaÅŸÃ¼t ID otomatik baÄŸlanÄ±r.');">
          ğŸ“¥ SeÃ§ilenleri B2B'ye Aktar
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
  if (all) all.addEventListener('change', () => {
    // Sadece gÃ¶rÃ¼nÃ¼r satÄ±rlarÄ± seÃ§ (filtreden geÃ§miÅŸleri)
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
<!-- ÃœRÃœNLER TAB -->
<div class="card">
  <div class="card-header"><h3 class="card-title">B2B ÃœrÃ¼nler â†” ParaÅŸÃ¼t Stoklar</h3></div>
  <div class="table-wrap">
    <table class="table" style="font-size:13px">
      <thead>
        <tr>
          <th>B2B ÃœrÃ¼n</th>
          <th>SKU</th>
          <th>Baz Fiyat</th>
          <th>KDV</th>
          <th>ParaÅŸÃ¼t Stok EÅŸleÅŸmesi</th>
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
            <div style="margin-top:4px;font-size:10px;color:#15803d;font-weight:700">âœ“ <?= h($linked['attributes']['name'] ?? 'EÅŸleÅŸti') ?> (ID: <?= h($linkedId) ?>)</div>
            <?php elseif ($isLost): ?>
            <div style="margin-top:4px;font-size:10px;color:#b91c1c;font-weight:700">âš ï¸ ParaÅŸÃ¼t'te bu ID bulunamadÄ±: <?= h($linkedId) ?></div>
            <div style="font-size:10px;color:#7f1d1d">AÅŸaÄŸÄ±dan "â€” BaÄŸlÄ± deÄŸil â€”" seÃ§ + ğŸ’¾ ile eÅŸlemeyi sÄ±fÄ±rla.</div>
            <?php endif; ?>
          </td>
          <td style="font-family:monospace;font-size:12px"><?= h($p['sku'] ?: 'â€”') ?></td>
          <td style="font-size:12px"><?= moneyInc((float)$p['base_price'], $p['vat_rate'] ?? 20) ?></td>
          <td style="font-size:12px">%<?= (int)($p['vat_rate'] ?? 20) ?></td>
          <td>
            <form method="post" class="parasut-search-form" data-kind="products" style="display:flex;gap:6px;align-items:center;position:relative">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="map-product">
              <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="parasut_id" class="ps-parasut-id" value="<?= h($linkedId !== '' ? $linkedId : '-') ?>">
              <div style="position:relative;flex:1">
                <input type="text" class="form-control ps-search-input"
                       placeholder="ğŸ” ParaÅŸÃ¼t Ã¼rÃ¼n ara (en az 2 karakter)..."
                       autocomplete="off"
                       value="<?php
                         if ($linked) {
                           $ln = trim($linked['attributes']['name'] ?? '');
                           echo h(($ln !== '' ? $ln : 'AdsÄ±z') . ' (ID: ' . $linkedId . ')');
                         } elseif ($isLost) {
                           echo h('âš ï¸ KAYIP ID: ' . $linkedId);
                         }
                       ?>"
                       style="font-size:12px;padding:6px 30px 6px 10px;height:32px;<?= $linked ? 'border:2px solid #16a34a;background:#f0fdf4' : ($isLost ? 'border:2px solid #dc2626;background:#fef2f2' : '') ?>">
                <?php if ($linked || $isLost): ?>
                <button type="button" class="ps-clear-btn" title="EÅŸlemeyi temizle"
                        style="position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;color:#64748b;cursor:pointer;font-size:14px;padding:2px 6px">âœ•</button>
                <?php endif; ?>
                <!-- Suggestion dropdown (JS doldurur) -->
                <div class="ps-suggestions" style="display:none;position:absolute;top:calc(100% + 2px);left:0;right:0;background:#fff;border:1px solid #cbd5e1;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.15);max-height:280px;overflow-y:auto;z-index:50"></div>
              </div>
              <button type="submit" class="btn btn-sm" style="padding:4px 10px;font-size:11px;background:#16a34a;color:#fff;border:none;height:32px">ğŸ’¾</button>
            </form>
          </td>
          <td>
            <?php if (!$linked): ?>
            <form method="post" onsubmit="return confirm('Bu Ã¼rÃ¼n ParaÅŸÃ¼t\'te yeni stok olarak oluÅŸturulacak. Ã–nce eÅŸleÅŸme aramayÄ± dÃ¼ÅŸÃ¼ndÃ¼nÃ¼z mÃ¼?');">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="create-product">
              <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <button type="submit" class="btn btn-sm" style="background:#0ea5e9;color:#fff;border:none;font-size:11px;padding:4px 10px">+ ParaÅŸÃ¼t'te OluÅŸtur</button>
            </form>
            <?php else: ?>
            <span style="font-size:11px;color:#15803d">Senkron hazÄ±r</span>
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
    <h3 class="card-title" style="color:#075985">ğŸ“¥ ParaÅŸÃ¼t'ten B2B'ye AktarÄ±labilir ÃœrÃ¼nler â€” <?= count($orphanProducts) ?> kayÄ±t</h3>
    <div style="font-size:11px;color:#0c4a6e">Bu Ã¼rÃ¼nler ParaÅŸÃ¼t'te var, B2B'de yok</div>
  </div>
  <div class="card-body" style="padding:0">
    <div style="background:#fffbeb;border-bottom:1px solid #fde68a;padding:10px 16px;font-size:12px;color:#78350f">
      â„¹ï¸ SeÃ§ilen Ã¼rÃ¼nleri B2B'ye <strong>yeni Ã¼rÃ¼n</strong> olarak aktarÄ±n (parasut_product_id otomatik baÄŸlanÄ±r). Stok 0 ve aktif olarak eklenir, sonra admin â†’ ÃœrÃ¼nler'den dÃ¼zenleyin.
    </div>

    <!-- Filtre durumu -->
    <?php if (isset($parasutMeta) && $tab === 'products'): ?>

    <!-- â”€â”€â”€ PARAÅÃœT CACHE DURUMU + SYNC BUTONU â”€â”€â”€ -->
    <?php
      $lastSync = $parasutMeta['last_synced'] ?? null;
      $isCacheEmpty = ((int)$parasutMeta['cache_total']) === 0;
      $cacheAgeMinutes = $lastSync ? floor((time() - strtotime($lastSync)) / 60) : null;
      $isCacheStale = $cacheAgeMinutes !== null && $cacheAgeMinutes > 360; // 6 saat+
    ?>
    <div style="background:linear-gradient(135deg,<?= $isCacheEmpty ? '#fef2f2,#fee2e2' : ($isCacheStale ? '#fffbeb,#fef3c7' : '#f0fdf4,#dcfce7') ?>);border-bottom:1px solid <?= $isCacheEmpty ? '#fca5a5' : ($isCacheStale ? '#fcd34d' : '#86efac') ?>;padding:14px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
      <div>
        <?php if ($isCacheEmpty): ?>
          <div style="font-weight:700;color:#b91c1c;font-size:13px;margin-bottom:3px">ğŸ”´ Cache BoÅŸ â€” Senkronizasyon Gerekli</div>
          <div style="font-size:11px;color:#7f1d1d">ParaÅŸÃ¼t Ã¼rÃ¼nleri henÃ¼z Ã§ekilmedi. "Åimdi Senkronize Et" butonuna basÄ±n.</div>
        <?php else: ?>
          <div style="font-weight:700;color:<?= $isCacheStale ? '#92400e' : '#15803d' ?>;font-size:13px;margin-bottom:3px">
            <?= $isCacheStale ? 'ğŸŸ¡' : 'âœ…' ?>
            Cache'de <strong><?= (int)$parasutMeta['cache_total'] ?></strong> Ã¼rÃ¼n hazÄ±r
            (<?= (int)$parasutMeta['cache_active'] ?> aktif + <?= (int)$parasutMeta['cache_archived'] ?> arÅŸivli)
          </div>
          <div style="font-size:11px;color:<?= $isCacheStale ? '#78350f' : '#166534' ?>">
            Son senkron: <strong><?= h(date('d.m.Y H:i', strtotime($lastSync))) ?></strong>
            <?php if ($cacheAgeMinutes !== null): ?>
              Â· <?= $cacheAgeMinutes < 60 ? ($cacheAgeMinutes . ' dakika Ã¶nce') : (floor($cacheAgeMinutes/60) . ' saat Ã¶nce') ?>
              <?php if ($isCacheStale): ?> Â· <strong>Yenilenmesi Ã¶neriliyor</strong><?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <form method="post" onsubmit="return confirm('ParaÅŸÃ¼t\'ten tÃ¼m Ã¼rÃ¼nler tekrar Ã§ekilecek. Bu iÅŸlem 1-3 dakika sÃ¼rebilir. Devam edilsin mi?');" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="sync-parasut-cache">
        <button type="submit" class="btn" style="background:<?= $isCacheEmpty ? '#dc2626' : ($isCacheStale ? '#d97706' : '#16a34a') ?>;color:#fff;border:none;font-weight:700;padding:10px 18px;font-size:13px">
          ğŸ”„ <?= $isCacheEmpty ? 'Åimdi Senkronize Et' : 'Yeniden Senkronize Et' ?>
        </button>
      </form>
    </div>

    <!-- Server-side arama formu - cache iÃ§inde anlÄ±k -->
    <div style="padding:14px 16px;border-bottom:1px solid var(--border);background:#f8fafc">
      <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="page" value="parasut-mapping">
        <input type="hidden" name="tab" value="products">
        <?php if ($parasutMeta['show_all']): ?><input type="hidden" name="show_all" value="1"><?php endif; ?>
        <div style="flex:1;min-width:240px">
          <input type="search" name="q" value="<?= h($parasutMeta['search_query']) ?>"
                 placeholder="ğŸ” Cache'de ara (Ã¶rn: G-14, churros, tavuk)..."
                 class="form-control" style="font-size:13px;padding:8px 12px"
                 autofocus>
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="height:36px;padding:0 16px">
          ğŸ” Ara
        </button>
        <?php if ($parasutMeta['search_query'] !== ''): ?>
        <a href="?page=parasut-mapping&tab=products<?= $parasutMeta['show_all'] ? '&show_all=1' : '' ?>"
           class="btn btn-secondary btn-sm" style="height:36px">âœ• AramayÄ± Temizle</a>
        <?php endif; ?>
        <a href="?page=parasut-mapping&tab=products&diag_products=1" class="btn btn-sm" style="background:#7c3aed;color:#fff;border:none;height:36px;padding:0 16px;font-size:12px" title="Sayfa sayfa Ã§ekim detayÄ±nÄ± gÃ¶r">
          ğŸ©º TanÄ±
        </a>
      </form>
      <?php if ($parasutMeta['search_query'] !== ''): ?>
      <div style="margin-top:8px;font-size:11px;color:#1e40af;background:#eff6ff;border-left:3px solid #1e40af;padding:6px 10px;border-radius:4px">
        ğŸ” <strong>"<?= h($parasutMeta['search_query']) ?>"</strong> iÃ§in cache'de <strong><?= count($parasutProducts) ?></strong> Ã¼rÃ¼n bulundu
      </div>
      <?php elseif (!$isCacheEmpty): ?>
      <!-- BoÅŸ arama, cache hazÄ±r: kullanÄ±cÄ±yÄ± yÃ¶nlendir -->
      <div style="margin-top:8px;font-size:12px;color:#475569;background:#f1f5f9;border-left:3px solid #64748b;padding:8px 12px;border-radius:4px;line-height:1.6">
        ğŸ’¡ <strong>Ä°pucu:</strong> YukarÄ±daki arama kutusuna yazmaya baÅŸlayÄ±n.
        ÃœrÃ¼n adÄ±, SKU veya ParaÅŸÃ¼t ID ile cache'de anlÄ±k arama yapabilirsiniz.
        BoÅŸ listeleme yapÄ±lmaz â€” sayfayÄ± hÄ±zlÄ± tutmak iÃ§in sadece <strong>arama sonuÃ§larÄ±</strong> gÃ¶sterilir.
        <br>
        <span style="font-size:11px;color:#64748b">
          Cache'de <strong><?= (int)$parasutMeta['cache_total'] ?> Ã¼rÃ¼n</strong> hazÄ±r (<?= (int)$parasutMeta['cache_active'] ?> aktif).
          Ã–rnek aramalar: <code style="background:#fff;padding:2px 5px;border-radius:3px">G-14</code>,
          <code style="background:#fff;padding:2px 5px;border-radius:3px">churros</code>,
          <code style="background:#fff;padding:2px 5px;border-radius:3px">tavuk</code>
        </span>
      </div>
      <?php endif; ?>
    </div>

    <?php
    // â”€â”€â”€ DETAYLI TANI PANELÄ° â”€â”€â”€
    if (isset($_GET['diag_products'])) {
        $diagActive   = parasut()->listAllProductsWithMeta(200);
        $diagArchived = parasut()->listAllProductsWithMeta(200, ['archived' => 'true']);
    ?>
    <div style="background:#faf5ff;border-top:3px solid #7c3aed;border-bottom:3px solid #7c3aed;padding:18px 20px;font-size:12px">
      <h3 style="margin:0 0 12px;font-size:14px;color:#5b21b6">ğŸ©º ParaÅŸÃ¼t ÃœrÃ¼n Ã‡ekimi â€” DetaylÄ± TanÄ±</h3>

      <!-- AKTÄ°F Ã‡EKÄ°M -->
      <div style="background:#fff;border-radius:8px;padding:12px;margin-bottom:10px">
        <div style="font-weight:700;color:#15803d;margin-bottom:8px">âœ… Aktif ÃœrÃ¼nler</div>
        <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:11px;margin-bottom:8px">
          <div><strong>ParaÅŸÃ¼t diyor:</strong> <?= (int)$diagActive['total_count'] ?> aktif Ã¼rÃ¼n var</div>
          <div><strong>Biz Ã§ektik:</strong> <?= (int)$diagActive['fetched'] ?> Ã¼rÃ¼n</div>
          <div><strong>Toplam sayfa:</strong> <?= (int)$diagActive['total_pages'] ?></div>
          <?php
          $fark = (int)$diagActive['total_count'] - (int)$diagActive['fetched'];
          ?>
          <?php if ($fark > 0): ?>
          <div style="color:#dc2626;font-weight:700">âš ï¸ <?= $fark ?> Ã¼rÃ¼n eksik!</div>
          <?php else: ?>
          <div style="color:#15803d;font-weight:700">âœ“ Tam Ã§ekildi</div>
          <?php endif; ?>
        </div>
        <details>
          <summary style="cursor:pointer;font-size:11px;color:#6b21a8">Sayfa sayfa detay gÃ¶ster</summary>
          <table style="width:100%;margin-top:8px;font-size:10px;font-family:monospace">
            <thead><tr style="background:#f3f4f6"><th style="text-align:left;padding:4px">Sayfa</th><th style="text-align:left;padding:4px">Gelen kayÄ±t</th><th style="text-align:left;padding:4px">HTTP</th><th style="text-align:left;padding:4px">Deneme</th><th style="text-align:left;padding:4px">Hata</th></tr></thead>
            <tbody>
              <?php foreach (($diagActive['page_log'] ?? []) as $pl): ?>
              <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:4px">Sayfa #<?= $pl['page'] ?></td>
                <td style="padding:4px"><strong><?= $pl['count'] ?></strong> kayÄ±t</td>
                <td style="padding:4px"><?= h((string)($pl['http'] ?? 'â€”')) ?></td>
                <td style="padding:4px"><?= (int)($pl['attempts'] ?? 1) ?>x</td>
                <td style="padding:4px;color:#dc2626"><?= $pl['err'] ? h($pl['err']) : 'â€”' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </details>
      </div>

      <!-- ARÅÄ°VLÄ° Ã‡EKÄ°M -->
      <div style="background:#fff;border-radius:8px;padding:12px;margin-bottom:10px">
        <div style="font-weight:700;color:#6b7280;margin-bottom:8px">ğŸ“ ArÅŸivli ÃœrÃ¼nler</div>
        <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:11px;margin-bottom:8px">
          <div><strong>ParaÅŸÃ¼t diyor:</strong> <?= (int)$diagArchived['total_count'] ?> arÅŸivli Ã¼rÃ¼n var</div>
          <div><strong>Biz Ã§ektik:</strong> <?= (int)$diagArchived['fetched'] ?> Ã¼rÃ¼n</div>
          <?php
          $fark2 = (int)$diagArchived['total_count'] - (int)$diagArchived['fetched'];
          ?>
          <?php if ($fark2 > 0): ?>
          <div style="color:#dc2626;font-weight:700">âš ï¸ <?= $fark2 ?> Ã¼rÃ¼n eksik!</div>
          <?php else: ?>
          <div style="color:#15803d;font-weight:700">âœ“ Tam Ã§ekildi</div>
          <?php endif; ?>
        </div>
        <details>
          <summary style="cursor:pointer;font-size:11px;color:#6b21a8">Sayfa sayfa detay gÃ¶ster</summary>
          <table style="width:100%;margin-top:8px;font-size:10px;font-family:monospace">
            <thead><tr style="background:#f3f4f6"><th style="text-align:left;padding:4px">Sayfa</th><th style="text-align:left;padding:4px">Gelen kayÄ±t</th><th style="text-align:left;padding:4px">HTTP</th><th style="text-align:left;padding:4px">Deneme</th><th style="text-align:left;padding:4px">Hata</th></tr></thead>
            <tbody>
              <?php foreach (($diagArchived['page_log'] ?? []) as $pl): ?>
              <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:4px">Sayfa #<?= $pl['page'] ?></td>
                <td style="padding:4px"><strong><?= $pl['count'] ?></strong> kayÄ±t</td>
                <td style="padding:4px"><?= h((string)($pl['http'] ?? 'â€”')) ?></td>
                <td style="padding:4px"><?= (int)($pl['attempts'] ?? 1) ?>x</td>
                <td style="padding:4px;color:#dc2626"><?= $pl['err'] ? h($pl['err']) : 'â€”' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </details>
      </div>

      <!-- Ã–ZET -->
      <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:12px;font-size:11px;color:#78350f">
        <strong>ğŸ’¡ Yorum:</strong>
        <?php
        $totalApi = (int)$diagActive['total_count'] + (int)$diagArchived['total_count'];
        $totalUs  = (int)$diagActive['fetched'] + (int)$diagArchived['fetched'];
        ?>
        <ul style="margin:6px 0 0 18px;padding:0">
          <li>ParaÅŸÃ¼t'Ã¼n sÃ¶ylediÄŸi <strong>TOPLAM</strong>: <?= $totalApi ?> Ã¼rÃ¼n</li>
          <li>Biz Ã§ekebildik: <strong><?= $totalUs ?></strong> Ã¼rÃ¼n</li>
          <?php if ($totalApi > $totalUs): ?>
          <li style="color:#b91c1c"><strong>âš ï¸ <?= ($totalApi - $totalUs) ?> Ã¼rÃ¼n eksik kalÄ±yor!</strong>
              OlasÄ± sebepler: pagination kesilmesi, draft/silinmiÅŸ Ã¼rÃ¼nler, custom statÃ¼</li>
          <?php else: ?>
          <li style="color:#15803d"><strong>âœ“ TÃ¼m Ã¼rÃ¼nler Ã§ekildi.</strong>
              EÄŸer hÃ¢lÃ¢ aradÄ±ÄŸÄ±n bir Ã¼rÃ¼nÃ¼ gÃ¶remiyorsan, yukarÄ±dan "ParaÅŸÃ¼t'te direkt ara" kutusunu kullan.</li>
          <?php endif; ?>
        </ul>
      </div>

      <div style="margin-top:10px">
        <a href="?page=parasut-mapping&tab=products" class="btn btn-sm btn-secondary" style="font-size:11px">â† TanÄ±'yÄ± Kapat</a>
      </div>
    </div>
    <?php } ?>

    <div style="background:#eff6ff;border-bottom:1px solid #bfdbfe;padding:10px 16px;font-size:12px;color:#1e40af;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div>
        <?php if (!empty($parasutMeta['show_all'])): ?>
        âš ï¸ <strong>TÃœM kayÄ±tlar gÃ¶steriliyor</strong> â€” muhasebe kalemleri (TDHP) dahil
        <?php else: ?>
        ğŸ“¦ <strong>GerÃ§ek Ã¼rÃ¼nler</strong> gÃ¶steriliyor
        <?php if ($parasutMeta['filtered_count'] > 0): ?>
        Â· <strong><?= (int)$parasutMeta['filtered_count'] ?></strong> muhasebe kalemi gizlendi
        <?php endif; ?>
        <?php endif; ?>
        Â·
        <strong>ParaÅŸÃ¼t toplam:</strong>
        <?= (int)($parasutMeta['active_total'] ?: $parasutMeta['active_fetched']) ?> aktif +
        <?= (int)($parasutMeta['archived_total'] ?: $parasutMeta['archived_fetched']) ?> arÅŸivli
      </div>
      <?php if (!empty($parasutMeta['show_all'])): ?>
      <a href="?page=parasut-mapping&tab=products<?= $parasutMeta['search_query'] !== '' ? '&q=' . urlencode($parasutMeta['search_query']) : '' ?>" class="btn btn-sm" style="background:#1e40af;color:#fff;border:none;font-size:11px">ğŸ“¦ Sadece GerÃ§ek ÃœrÃ¼nler</a>
      <?php else: ?>
      <a href="?page=parasut-mapping&tab=products&show_all=1<?= $parasutMeta['search_query'] !== '' ? '&q=' . urlencode($parasutMeta['search_query']) : '' ?>" class="btn btn-sm" style="background:#fff;color:#1e40af;border:1px solid #1e40af;font-size:11px">ğŸ“‹ TÃ¼m KayÄ±tlar</a>
      <?php endif; ?>
    </div>

    <?php
    // â”€â”€â”€ Eksik Ã¼rÃ¼n otomatik uyarÄ± bandÄ± â”€â”€â”€
    $activeMissing   = max(0, (int)$parasutMeta['active_total'] - (int)$parasutMeta['active_fetched']);
    $archivedMissing = max(0, (int)$parasutMeta['archived_total'] - (int)$parasutMeta['archived_fetched']);
    $totalMissing    = $activeMissing + $archivedMissing;
    if ($totalMissing > 0 && $parasutMeta['search_query'] === ''):
    ?>
    <div style="background:#fef2f2;border-bottom:2px solid #fca5a5;padding:12px 16px;font-size:12px;color:#7f1d1d;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div>
        âš ï¸ <strong style="color:#b91c1c">DÄ°KKAT:</strong>
        ParaÅŸÃ¼t'te <strong><?= $activeMissing + $archivedMissing ?></strong> Ã¼rÃ¼n daha var ama Ã§ekemedik!
        <span style="font-size:11px;color:#7f1d1d">
          (<?= $activeMissing ?> aktif, <?= $archivedMissing ?> arÅŸivli eksik)
        </span>
      </div>
      <a href="?page=parasut-mapping&tab=products&diag_products=1" class="btn btn-sm" style="background:#b91c1c;color:#fff;border:none;font-size:11px">ğŸ©º DetaylÄ± TanÄ±</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Arama kutusu -->
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);background:#fafafa">
      <input type="search" id="orphanProdSearch" placeholder="ğŸ” Ä°sim, SKU/kod veya ID ile araâ€¦ (Ã¶rn: G-01, tavuk, KNORR)"
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
                <input type="checkbox" id="orphanProdSelectAll" title="TÃ¼mÃ¼nÃ¼ seÃ§/kaldÄ±r">
              </th>
              <th>ParaÅŸÃ¼t AdÄ±</th>
              <th>Stok Kodu</th>
              <th>KDV</th>
              <th>Birim Fiyat</th>
              <th>ParaÅŸÃ¼t ID</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orphanProducts as $pp):
              $a = $pp['attributes'] ?? [];
              $isArch = !empty($a['archived']);
              // Ä°sim fallback chain
              $rawName = trim($a['name'] ?? '');
              $code    = trim($a['code'] ?? '');
              $catName = trim($a['_category_name'] ?? '');
              $display = $rawName !== '' ? $rawName
                       : ($code !== '' ? '[AdsÄ±z - Kod: ' . $code . ']'
                       : '[AdsÄ±z - ID: ' . $pp['id'] . ']');
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
                    <span class="badge" style="background:#e5e7eb;color:#6b7280;font-size:9px;font-weight:600;margin-left:6px">ARÅÄ°VLÄ°</span>
                  <?php endif; ?>
                </div>
                <?php if ($catName !== ''): ?>
                <div style="margin-top:3px">
                  <span class="badge" style="background:#fef3c7;color:#92400e;font-size:9px;font-weight:600;padding:2px 6px;border-radius:3px;text-transform:uppercase;letter-spacing:.3px">ğŸ“ <?= h($catName) ?></span>
                </div>
                <?php endif; ?>
              </td>
              <td style="font-family:monospace"><?= h($code ?: 'â€”') ?></td>
              <td>%<?= (int)($a['vat_rate'] ?? 0) ?></td>
              <td><?= isset($a['list_price']) ? money((float)$a['list_price']) : 'â€”' ?></td>
              <td style="font-family:monospace;color:var(--text-muted);font-size:11px"><?= h($pp['id']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="padding:14px 16px;background:#f9fafb;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <div style="font-size:12px;color:var(--text-muted)">
          <span id="orphanProdCount">0</span> Ã¼rÃ¼n seÃ§ildi
        </div>
        <button type="submit" class="btn btn-primary" id="importProductsBtn" disabled style="background:#0ea5e9;border-color:#0ea5e9" onclick="return confirm('SeÃ§ilen ' + document.querySelectorAll('.orphan-prod-check:checked').length + ' Ã¼rÃ¼nÃ¼ B2B\'ye yeni Ã¼rÃ¼n olarak aktarmak istediÄŸinize emin misiniz?\n\nHer biri iÃ§in yeni b2b_products kaydÄ± oluÅŸturulur, ParaÅŸÃ¼t ID otomatik baÄŸlanÄ±r.');">
          ğŸ“¥ SeÃ§ilenleri B2B'ye Aktar
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
  if (all) all.addEventListener('change', () => {
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

<!-- â”€â”€â”€ INLINE PARAÅÃœT ARAMA COMPONENTÄ° â”€â”€â”€ -->
<script>
(function() {
  const SEARCH_URL = '?page=parasut-mapping&ajax=search';
  let openSuggestions = null; // ÅŸu an aÃ§Ä±k olan dropdown
  let debounceTimer = null;

  // Sayfa iÃ§indeki tÃ¼m arama formlarÄ±nÄ± yakala
  document.querySelectorAll('.parasut-search-form').forEach(form => {
    const input    = form.querySelector('.ps-search-input');
    const hiddenId = form.querySelector('.ps-parasut-id');
    const sugBox   = form.querySelector('.ps-suggestions');
    const clearBtn = form.querySelector('.ps-clear-btn');
    const kind     = form.dataset.kind || 'products';

    if (!input || !hiddenId || !sugBox) return;

    document.body.appendChild(sugBox);
    sugBox.style.position = 'fixed';
    sugBox.style.left = '0';
    sugBox.style.top = '0';
    sugBox.style.right = 'auto';
    sugBox.style.zIndex = '2000';

    // EÅŸleme zaten varsa input read-only baÅŸlasÄ±n
    let isLocked = hiddenId.value && hiddenId.value !== '-' && hiddenId.value !== '';

    if (isLocked) {
      input.readOnly = true;
      input.style.cursor = 'pointer';
    }

    // âœ• butonu - eÅŸlemeyi temizle (henÃ¼z kaydedilmedi)
    if (clearBtn) {
      clearBtn.addEventListener('click', e => {
        e.preventDefault();
        hiddenId.value = '-';
        input.value = '';
        input.readOnly = false;
        input.style.cursor = '';
        input.style.border = '';
        input.style.background = '';
        clearBtn.remove();
        isLocked = false;
        input.focus();
      });
    }

    // Input'a tÄ±klayÄ±nca (kilitliyse) â†’ kilidi aÃ§ + temizle
    input.addEventListener('focus', e => {
      if (isLocked) {
        if (!confirm('Mevcut eÅŸlemeyi deÄŸiÅŸtirmek istiyor musunuz?')) {
          input.blur();
          return;
        }
        hiddenId.value = '-';
        input.value = '';
        input.readOnly = false;
        input.style.cursor = '';
        input.style.border = '';
        input.style.background = '';
        if (clearBtn) clearBtn.remove();
        isLocked = false;
      }
    });

    // YazÄ±nca â†’ debounce + fetch
    input.addEventListener('input', () => {
      clearTimeout(debounceTimer);
      const q = input.value.trim();
      if (q.length < 2) {
        hideSuggestions(sugBox);
        return;
      }
      debounceTimer = setTimeout(() => doSearch(q, kind, sugBox, input, hiddenId), 200);
    });

    // Klavye desteÄŸi (yukarÄ±/aÅŸaÄŸÄ±/enter/escape)
    input.addEventListener('keydown', e => {
      if (sugBox.style.display === 'none') return;
      const items = sugBox.querySelectorAll('.ps-suggestion-item');
      const active = sugBox.querySelector('.ps-suggestion-active');
      let idx = active ? Array.from(items).indexOf(active) : -1;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        idx = Math.min(items.length - 1, idx + 1);
        if (active) active.classList.remove('ps-suggestion-active');
        if (items[idx]) {
          items[idx].classList.add('ps-suggestion-active');
          items[idx].scrollIntoView({block: 'nearest'});
        }
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        idx = Math.max(0, idx - 1);
        if (active) active.classList.remove('ps-suggestion-active');
        if (items[idx]) {
          items[idx].classList.add('ps-suggestion-active');
          items[idx].scrollIntoView({block: 'nearest'});
        }
      } else if (e.key === 'Enter') {
        if (active) {
          e.preventDefault();
          active.click();
        }
      } else if (e.key === 'Escape') {
        hideSuggestions(sugBox);
      }
    });

    // DÄ±ÅŸarÄ± tÄ±klayÄ±nca kapat
    document.addEventListener('click', e => {
      if (!form.contains(e.target)) hideSuggestions(sugBox);
    });
  });

  function hideSuggestions(box) {
    box.style.display = 'none';
    box.innerHTML = '';
    if (openSuggestions === box) openSuggestions = null;
  }

  function positionSuggestions(box, input) {
    const rect = input.getBoundingClientRect();
    const maxHeight = Math.max(180, Math.min(320, window.innerHeight - rect.bottom - 12));
    box.style.left = rect.left + 'px';
    box.style.top = (rect.bottom + 4) + 'px';
    box.style.width = rect.width + 'px';
    box.style.maxHeight = maxHeight + 'px';
  }

  function doSearch(q, kind, sugBox, input, hiddenId) {
    positionSuggestions(sugBox, input);
    sugBox.style.display = 'block';
    sugBox.innerHTML = '<div style="padding:10px;color:#64748b;font-size:12px;text-align:center">ğŸ” AranÄ±yor...</div>';
    openSuggestions = sugBox;

    fetch(SEARCH_URL + '&kind=' + encodeURIComponent(kind) + '&q=' + encodeURIComponent(q) + '&limit=30')
      .then(r => r.json())
      .then(data => {
        if (!data.success) {
          sugBox.innerHTML = '<div style="padding:10px;color:#dc2626;font-size:12px">Hata: ' + (data.error || 'Bilinmeyen') + '</div>';
          return;
        }
        if (!data.items || data.items.length === 0) {
          sugBox.innerHTML = '<div style="padding:10px;color:#64748b;font-size:12px;text-align:center">SonuÃ§ yok. Cache\'i senkronize ettiniz mi?</div>';
          return;
        }
        renderSuggestions(data.items, sugBox, input, hiddenId);
      })
      .catch(err => {
        sugBox.innerHTML = '<div style="padding:10px;color:#dc2626;font-size:12px">BaÄŸlantÄ± hatasÄ±: ' + err.message + '</div>';
      });
  }

  function renderSuggestions(items, sugBox, input, hiddenId) {
    sugBox.innerHTML = '';
    items.forEach((it, idx) => {
      const div = document.createElement('div');
      div.className = 'ps-suggestion-item';
      div.style.cssText = 'padding:8px 10px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:12px;line-height:1.4';
      div.dataset.id = it.id;

      // Ä°sim + kod + kategori + ID
      let html = '<div style="font-weight:600;color:#1e293b">' + escapeHtml(it.label || 'AdsÄ±z');
      if (it.code && it.code !== it.label) {
        html += ' <span style="font-family:monospace;font-size:11px;color:#64748b;background:#f1f5f9;padding:1px 5px;border-radius:3px">' + escapeHtml(it.code) + '</span>';
      }
      html += '</div>';
      html += '<div style="font-size:10px;color:#64748b;margin-top:2px">';
      if (it.category) html += 'ğŸ“ ' + escapeHtml(it.category) + ' Â· ';
      if (it.archived) html += '<span style="color:#dc2626;font-weight:600">ğŸ“¦ ARÅÄ°VLÄ°</span> Â· ';
      html += 'ID: ' + escapeHtml(it.id);
      if (it.price !== null && it.price !== undefined) {
        html += ' Â· ' + Number(it.price).toFixed(2) + ' â‚º';
      }
      html += '</div>';
      div.innerHTML = html;

      div.addEventListener('mouseenter', () => {
        sugBox.querySelectorAll('.ps-suggestion-item').forEach(el => el.classList.remove('ps-suggestion-active'));
        div.classList.add('ps-suggestion-active');
      });

      div.addEventListener('click', e => {
        e.preventDefault();
        hiddenId.value = it.id;
        const ln = it.name || 'AdsÄ±z';
        input.value = ln + (it.code ? ' [' + it.code + ']' : '') + ' (ID: ' + it.id + ')';
        input.style.border = '2px solid #16a34a';
        input.style.background = '#f0fdf4';
        hideSuggestions(sugBox);
      });

      sugBox.appendChild(div);
    });

    // Stil ekle (sadece bir kez)
    if (!document.getElementById('ps-suggestion-style')) {
      const style = document.createElement('style');
      style.id = 'ps-suggestion-style';
      style.textContent = '.ps-suggestion-active { background:#eff6ff !important; }';
      document.head.appendChild(style);
    }
  }

  function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  window.addEventListener('scroll', () => {
    if (openSuggestions) hideSuggestions(openSuggestions);
  }, true);
  window.addEventListener('resize', () => {
    if (openSuggestions) hideSuggestions(openSuggestions);
  });
})();
</script>
<?php endif; ?>

<?php endif; ?>


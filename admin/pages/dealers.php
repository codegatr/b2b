<?php
// admin/pages/dealers.php — Bayi Yönetimi
requireAdmin();

$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);

// ── Fiyat Listeleri (select için) ─────────────────────────────────────────
$priceLists = dbRows("SELECT id, name FROM b2b_price_lists WHERE is_active=1 ORDER BY name");

// ── İşlemler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save') {
        // DB kolonlarına birebir uyan veri dizisi (install.sql şeması)
        $contactRaw = trim($_POST['contact_name'] ?? '');
        $nameParts  = explode(' ', $contactRaw, 2);
        $data = [
            'type'               => $_POST['dealer_type'] ?? 'kurumsal',
            'company_name'       => trim($_POST['company_name']),
            'first_name'         => $nameParts[0] ?? '',
            'last_name'          => $nameParts[1] ?? '',
            'email'              => trim($_POST['email']),
            'phone'              => trim($_POST['phone']),
            'tax_number'         => trim($_POST['tax_number']),
            'tax_office'         => trim($_POST['tax_office']),
            'address'            => trim($_POST['address']),
            'city'               => trim($_POST['city']),
            'price_list_id'      => intval($_POST['price_list_id']) ?: null,
            'credit_limit'       => floatval($_POST['credit_limit'] ?? 0),
            'payment_term_days'  => intval($_POST['payment_term_days'] ?? 30),
            'order_approval'     => $_POST['order_approval'] ?? 'manual',
            'notes'              => trim($_POST['notes'] ?? ''),
            'is_active'          => isset($_POST['is_active']) ? 1 : 0,
        ];

        $label = $data['company_name'] ?: ($data['first_name'] . ' ' . $data['last_name']);

        if (empty($data['email'])) {
            $error = 'E-posta zorunludur.';
        } elseif (empty($label)) {
            $error = 'Firma adı veya ad soyad zorunludur.';
        } else {
            if ($id) {
                dbUpdateRow('b2b_dealers', $data, 'id', $id);
                auditLog('dealer_updated', 'b2b_dealers', $id, ['label' => $label]);
                $success = 'Bayi güncellendi.';
            } else {
                // Yeni bayi — şifre zorunlu
                $pass = trim($_POST['password'] ?? '');
                // E-posta benzersizlik kontrolü
                $exists = dbVal("SELECT COUNT(*) FROM b2b_dealers WHERE email=?", [$data['email']]);
                if ($exists) {
                    $error = 'Bu e-posta zaten kayıtlı.';
                } elseif (strlen($pass) < 6) {
                    $error = 'Şifre en az 6 karakter olmalıdır.';
                } else {
                    $data['password']   = password_hash($pass, PASSWORD_DEFAULT);
                    $data['created_at'] = date('Y-m-d H:i:s');
                    $newId = dbInsertRow('b2b_dealers', $data);
                    auditLog('dealer_created', 'b2b_dealers', $newId, ['label' => $label]);
                    try { parasut()->syncDealer($newId); } catch (Exception $e) {}
                    $success = 'Bayi eklendi.';
                    $id = 0;
                    $action = 'list';
                }
            }
        }
    }

    if ($act === 'toggle') {
        $dealer = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$_POST['dealer_id']]);
        if ($dealer) {
            $new = $dealer['is_active'] ? 0 : 1;
            dbExec("UPDATE b2b_dealers SET is_active=? WHERE id=?", [$new, $dealer['id']]);
            auditLog('dealer_toggle', 'b2b_dealers', $dealer['id'], ['is_active' => $new]);
        }
        header('Location: ?page=dealers'); exit;
    }

    if ($act === 'delete') {
        $did = intval($_POST['dealer_id']);
        $dealer = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$did]);
        if ($dealer) {
            dbExec("UPDATE b2b_dealers SET is_active=0 WHERE id=?", [$did]);
            auditLog('dealer_deleted', 'b2b_dealers', $did, []);
            $success = 'Bayi pasife alındı.';
        }
        $action = 'list';
    }

    if ($act === 'reset_password') {
        $did  = intval($_POST['dealer_id']);
        $pass = trim($_POST['new_password']);
        if (strlen($pass) < 6) { $error = 'Şifre en az 6 karakter.'; }
        else {
            dbExec("UPDATE b2b_dealers SET password=? WHERE id=?", [password_hash($pass, PASSWORD_DEFAULT), $did]);
            auditLog('dealer_password_reset', 'b2b_dealers', $did, []);
            $success = 'Şifre sıfırlandı.';
        }
        $action = 'detail';
        $id = intval($_POST['dealer_id']);
    }
}

// ── Form edit: bayi yükle ──────────────────────────────────────────────────
$dealer = null;
if (($action === 'edit' || $action === 'detail') && $id) {
    $dealer = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$id]);
    if (!$dealer) { $action = 'list'; $id = 0; }
}

// ── Liste ──────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $search   = trim($_GET['q'] ?? '');
    $status   = $_GET['status'] ?? '';
    $plId     = intval($_GET['pl'] ?? 0);
    $perPage  = 20;
    $page     = max(1, intval($_GET['p'] ?? 1));
    $offset   = ($page - 1) * $perPage;

    $where = ['1=1']; $params = [];
    if ($search) {
        $where[]  = '(d.company_name LIKE ? OR d.email LIKE ? OR d.first_name LIKE ? OR d.phone LIKE ?)';
        $s = "%$search%";
        array_push($params, $s, $s, $s, $s);
    }
    if ($status !== '') { $where[] = 'd.is_active=?'; $params[] = intval($status); }
    if ($plId) { $where[] = 'd.price_list_id=?'; $params[] = $plId; }

    $whereStr = implode(' AND ', $where);
    $total    = dbVal("SELECT COUNT(*) FROM b2b_dealers d WHERE $whereStr", $params);
    $dealers  = dbRows(
        "SELECT d.*, pl.name AS price_list_name,
            (SELECT COUNT(*) FROM b2b_orders o WHERE o.dealer_id=d.id) AS order_count,
            (SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger l WHERE l.dealer_id=d.id AND l.is_closed=0) AS open_balance
         FROM b2b_dealers d
         LEFT JOIN b2b_price_lists pl ON pl.id=d.price_list_id
         WHERE $whereStr ORDER BY d.company_name LIMIT $perPage OFFSET $offset",
        $params
    );
    $pager = pagination($total, $perPage, $page, '?page=dealers&q=' . urlencode($search) . "&status=$status&pl=$plId&p=");
}
?>

<?php if ($action === 'list'): ?>
<!-- ═══════════════════ LİSTE ═══════════════════ -->
<div class="page-header">
    <div>
        <h1 class="page-title">Bayi Yönetimi</h1>
        <p class="page-sub">Toplam <?= $total ?> bayi</p>
    </div>
    <a href="?page=dealers&action=add" class="btn btn-primary"><i class="icon">＋</i> Yeni Bayi</a>
</div>

<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<!-- Filtreler -->
<div class="filter-bar card mb-4">
    <form method="get" class="filter-form">
        <input type="hidden" name="page" value="dealers">
        <input type="text" name="q" value="<?= h($search) ?>" placeholder="Ara: firma, e-posta, telefon…" class="form-control" style="max-width:260px">
        <select name="status" class="form-control" style="max-width:140px">
            <option value="">Tüm Durumlar</option>
            <option value="1" <?= $status==='1'?'selected':'' ?>>Aktif</option>
            <option value="0" <?= $status==='0'?'selected':'' ?>>Pasif</option>
        </select>
        <select name="pl" class="form-control" style="max-width:200px">
            <option value="">Tüm Fiyat Listeleri</option>
            <?php foreach ($priceLists as $pl): ?>
            <option value="<?= $pl['id'] ?>" <?= $plId==$pl['id']?'selected':'' ?>><?= h($pl['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Filtrele</button>
        <a href="?page=dealers" class="btn btn-ghost">Temizle</a>
    </form>
</div>

<div class="card">
<table class="table">
    <thead>
        <tr>
            <th>Firma</th>
            <th>Tip</th>
            <th>İletişim</th>
            <th>Fiyat Listesi</th>
            <th>Açık Bakiye</th>
            <th>Limit</th>
            <th>Sipariş</th>
            <th>Durum</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($dealers as $d): ?>
    <tr>
        <td>
            <a href="?page=dealers&action=detail&id=<?= $d['id'] ?>" class="font-medium text-primary"><?= h($d['company_name']) ?></a>
            <br><small class="text-muted"><?= h($d['email']) ?></small>
        </td>
        <td><span class="badge badge-<?= ($d['type']??'kurumsal')==='kurumsal'?'blue':'purple' ?>"><?= h($d['type']??'kurumsal') ?></span></td>
        <td><?= h(trim(($d['first_name']??'').' '.($d['last_name']??''))) ?: '—' ?><br><small><?= h($d['phone']??'') ?></small></td>
        <td><?= $d['price_list_name'] ? h($d['price_list_name']) : '<span class="text-muted">—</span>' ?></td>
        <td class="<?= $d['open_balance']>0?'text-danger':($d['open_balance']<0?'text-success':'') ?> font-medium">
            <?= money($d['open_balance']) ?>
        </td>
        <td><?= money($d['credit_limit']) ?></td>
        <td><?= $d['order_count'] ?></td>
        <td>
            <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="toggle">
                <input type="hidden" name="dealer_id" value="<?= $d['id'] ?>">
                <button type="submit" class="badge badge-<?= $d['is_active']?'green':'gray' ?>" style="border:none;cursor:pointer">
                    <?= $d['is_active']?'Aktif':'Pasif' ?>
                </button>
            </form>
        </td>
        <td class="text-right">
            <a href="?page=dealers&action=detail&id=<?= $d['id'] ?>" class="btn btn-xs btn-ghost">Detay</a>
            <a href="?page=dealers&action=edit&id=<?= $d['id'] ?>"   class="btn btn-xs btn-secondary">Düzenle</a>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($dealers)): ?>
    <tr><td colspan="9" class="text-center text-muted py-8">Bayi bulunamadı.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?= $pager ?>

<?php elseif ($action === 'detail' && $dealer): ?>
<!-- ═══════════════════ DETAY ═══════════════════ -->
<?php
$orders  = dbRows("SELECT * FROM b2b_orders WHERE dealer_id=? ORDER BY created_at DESC LIMIT 10", [$id]);
$ledger  = dbRows("SELECT * FROM b2b_ledger WHERE dealer_id=? ORDER BY created_at DESC LIMIT 15", [$id]);
$balance = dbVal("SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0", [$id]);
$payments= dbRows("SELECT * FROM b2b_payments WHERE dealer_id=? ORDER BY created_at DESC LIMIT 5", [$id]);
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= h($dealer['company_name']) ?></h1>
        <p class="page-sub"><?= h($dealer['type']??'kurumsal') ?> — <?= h($dealer['city']??'') ?></p>
    </div>
    <div class="btn-group">
        <a href="?page=dealers" class="btn btn-ghost">← Geri</a>
        <a href="?page=dealers&action=edit&id=<?= $id ?>" class="btn btn-secondary">Düzenle</a>
    </div>
</div>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div class="grid grid-cols-4 gap-4 mb-6">
    <div class="stat-card"><div class="stat-label">Açık Bakiye</div><div class="stat-value <?= $balance>0?'text-danger':'' ?>"><?= money($balance) ?></div></div>
    <div class="stat-card"><div class="stat-label">Kredi Limiti</div><div class="stat-value"><?= money($dealer['credit_limit']) ?></div></div>
    <div class="stat-card"><div class="stat-label">Vade (gün)</div><div class="stat-value"><?= $dealer['payment_term_days'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Sipariş Onayı</div><div class="stat-value"><?= $dealer['order_approval'] === 'auto' ? 'Otomatik' : 'Manuel' ?></div></div>
</div>

<div class="grid grid-cols-2 gap-6">
    <!-- Bayi Bilgileri -->
    <div class="card">
        <div class="card-header"><h3>Bayi Bilgileri</h3></div>
        <div class="card-body">
            <dl class="info-list">
                <dt>E-posta</dt><dd><?= h($dealer['email']) ?></dd>
                <dt>Telefon</dt><dd><?= h($dealer['phone']) ?></dd>
                <dt>İletişim Kişisi</dt><dd><?= h(trim(($dealer['first_name']??'').' '.($dealer['last_name']??''))) ?></dd>
                <dt>Vergi No</dt><dd><?= h($dealer['tax_number']) ?> / <?= h($dealer['tax_office']) ?></dd>
                <dt>Adres</dt><dd><?= h($dealer['address']) ?>, <?= h($dealer['city']) ?></dd>
                <dt>Fiyat Listesi</dt><dd><?= dbVal("SELECT name FROM b2b_price_lists WHERE id=?",[$dealer['price_list_id']]) ?: '—' ?></dd>
                <dt>Kayıt Tarihi</dt><dd><?= fmtDate($dealer['created_at']) ?></dd>
            </dl>
        </div>
    </div>

    <!-- Şifre Sıfırla -->
    <div class="card">
        <div class="card-header"><h3>Şifre Sıfırla</h3></div>
        <div class="card-body">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="reset_password">
                <input type="hidden" name="dealer_id" value="<?= $id ?>">
                <div class="form-group">
                    <label>Yeni Şifre</label>
                    <input type="password" name="new_password" class="form-control" placeholder="En az 6 karakter">
                </div>
                <button type="submit" class="btn btn-secondary">Şifreyi Güncelle</button>
            </form>
        </div>
    </div>
</div>

<!-- Son Siparişler -->
<div class="card mt-6">
    <div class="card-header"><h3>Son Siparişler</h3><a href="?page=orders&dealer_id=<?= $id ?>" class="btn btn-xs btn-ghost">Tümü</a></div>
    <table class="table">
        <thead><tr><th>Sipariş No</th><th>Tarih</th><th>Tutar</th><th>Durum</th><th>Ödeme</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
        <tr>
            <td><a href="?page=orders&action=detail&id=<?= $o['id'] ?>"><?= h($o['order_number']) ?></a></td>
            <td><?= fmtDate($o['created_at']) ?></td>
            <td><?= money($o['total_amount']) ?></td>
            <td><?= orderStatusLabel($o['status']) ?></td>
            <td><span class="badge badge-<?= $o['payment_status']==='odendi'?'green':($o['payment_status']==='bekliyor'?'yellow':'blue') ?>"><?= h($o['payment_status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($orders)): ?><tr><td colspan="5" class="text-muted text-center">Sipariş yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Cari Hesap -->
<div class="card mt-6">
    <div class="card-header"><h3>Son Cari Hareketler</h3><a href="?page=ledger&dealer_id=<?= $id ?>" class="btn btn-xs btn-ghost">Tümü</a></div>
    <table class="table">
        <thead><tr><th>Tarih</th><th>Açıklama</th><th>Borç</th><th>Alacak</th><th>Vade</th></tr></thead>
        <tbody>
        <?php foreach ($ledger as $l): ?>
        <tr>
            <td><?= fmtDate($l['created_at']) ?></td>
            <td><?= h($l['description']) ?></td>
            <td><?= $l['type']==='borc' ? money($l['amount']) : '' ?></td>
            <td><?= $l['type']==='alacak' ? money($l['amount']) : '' ?></td>
            <td><?= $l['due_date'] ? fmtDate($l['due_date']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($ledger)): ?><tr><td colspan="5" class="text-muted text-center">Hareket yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<!-- ═══════════════════ FORM ═══════════════════ -->
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $action==='add'?'Yeni Bayi':'Bayi Düzenle' ?></h1>
        <?php if ($dealer): ?><p class="page-sub"><?= h($dealer['company_name']) ?></p><?php endif; ?>
    </div>
    <a href="?page=dealers<?= $dealer?"&action=detail&id=$id":'' ?>" class="btn btn-ghost">← Geri</a>
</div>
<?php if (!empty($error)): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div class="card">
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="form_action" value="save">

    <div class="form-section-title">Temel Bilgiler</div>
    <div class="form-grid-2">
        <div class="form-group">
            <label>Bayi Tipi *</label>
            <select name="dealer_type" class="form-control">
                <option value="kurumsal" <?= ($dealer['type']??'kurumsal')==='kurumsal'?'selected':'' ?>>Kurumsal</option>
                <option value="bireysel" <?= ($dealer['type']??'')==='bireysel'?'selected':'' ?>>Bireysel</option>
            </select>
        </div>
        <div class="form-group">
            <label>Firma / Ad Soyad *</label>
            <input type="text" name="company_name" value="<?= h($dealer['company_name']??'') ?>" class="form-control" required>
        </div>
        <div class="form-group">
            <label>İletişim Kişisi</label>
            <input type="text" name="contact_name" value="<?= h(trim(($dealer['first_name']??'').' '.($dealer['last_name']??''))) ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>E-posta *</label>
            <input type="email" name="email" value="<?= h($dealer['email']??'') ?>" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Telefon</label>
            <input type="text" name="phone" value="<?= h($dealer['phone']??'') ?>" class="form-control">
        </div>
        <?php if ($action === 'add'): ?>
        <div class="form-group">
            <label>Şifre *</label>
            <input type="password" name="password" class="form-control" placeholder="En az 6 karakter" required>
        </div>
        <?php endif; ?>
    </div>

    <div class="form-section-title">Vergi / Adres</div>
    <div class="form-grid-2">
        <div class="form-group">
            <label>Vergi No / TC No</label>
            <input type="text" name="tax_number" value="<?= h($dealer['tax_number']??'') ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Vergi Dairesi</label>
            <input type="text" name="tax_office" value="<?= h($dealer['tax_office']??'') ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Adres</label>
            <input type="text" name="address" value="<?= h($dealer['address']??'') ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Şehir</label>
            <input type="text" name="city" value="<?= h($dealer['city']??'') ?>" class="form-control">
        </div>
    </div>

    <div class="form-section-title">Ticari Ayarlar</div>
    <div class="form-grid-2">
        <div class="form-group">
            <label>Fiyat Listesi</label>
            <select name="price_list_id" class="form-control">
                <option value="">— Varsayılan —</option>
                <?php foreach ($priceLists as $pl): ?>
                <option value="<?= $pl['id'] ?>" <?= ($dealer['price_list_id']??'')==$pl['id']?'selected':'' ?>><?= h($pl['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Kredi Limiti (₺)</label>
            <input type="number" step="0.01" name="credit_limit" value="<?= $dealer['credit_limit']??0 ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Vade (gün)</label>
            <input type="number" name="payment_term_days" value="<?= $dealer['payment_term_days']??30 ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Sipariş Onayı</label>
            <select name="order_approval" class="form-control">
                <option value="manual" <?= ($dealer['order_approval']??'manual')==='manual'?'selected':'' ?>>Manuel Onay</option>
                <option value="auto"   <?= ($dealer['order_approval']??'')==='auto'?'selected':'' ?>>Otomatik Onay</option>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label class="checkbox-label">
            <input type="checkbox" name="is_active" value="1" <?= ($dealer['is_active']??1)?'checked':'' ?>>
            Bayi aktif
        </label>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">💾 Kaydet</button>
        <a href="?page=dealers<?= $id?"&action=detail&id=$id":'' ?>" class="btn btn-ghost">İptal</a>
    </div>
</form>
</div>
</div>
<?php endif; ?>

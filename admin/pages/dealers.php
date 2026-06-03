<?php
// admin/pages/dealers.php — Bayi Yönetimi
requireAdmin();

$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);

// Flash mesajı oku
$success = $_SESSION['flash_success'] ?? '';
$error   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

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
            'commission_rate'       => floatval($_POST['commission_rate'] ?? 0),
            'commission_min_amount' => floatval($_POST['commission_min_amount'] ?? 0),
            'commission_notes'      => trim($_POST['commission_notes'] ?? ''),
            'order_approval'     => $_POST['order_approval'] ?? 'manual',
            'payment_methods'    => implode(',', array_filter((array)($_POST['payment_methods'] ?? ['havale']))),
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
                $_SESSION['flash_success'] = 'Bayi güncellendi.';
                redirect('?page=dealers&action=detail&id=' . $id);
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
                    try {
                        $newDealer = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$newId]);
                        if ($newDealer) parasut()->syncDealer($newDealer);
                    } catch (\Throwable $e) {}
                    // PRG — yenileme sorununu önle, mesajı session ile taşı
                    $_SESSION['flash_success'] = 'Bayi başarıyla eklendi.';
                    redirect('?page=dealers');
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
        redirect('?page=dealers');
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
<?php if (!empty($error)):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

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
$ledger  = dbRows("SELECT * FROM b2b_ledger WHERE dealer_id=? AND is_closed=0 ORDER BY created_at DESC LIMIT 15", [$id]);
$balance = dbVal("SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0", [$id]);
$totalOrders = dbVal("SELECT COUNT(*) FROM b2b_orders WHERE dealer_id=?", [$id]);
$totalSpend  = dbVal("SELECT COALESCE(SUM(grand_total),0) FROM b2b_orders WHERE dealer_id=? AND status NOT IN ('iptal','iade')", [$id]);
$priceListName = dbVal("SELECT name FROM b2b_price_lists WHERE id=?", [$dealer['price_list_id']]) ?: '—';
$initials = strtoupper(mb_substr($dealer['company_name'], 0, 1));
$isActive = ($dealer['is_active'] ?? 1);
?>

<?php if (!empty($success)): ?><div class="alert alert-success" style="margin-bottom:16px"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-danger"  style="margin-bottom:16px"><?= h($error) ?></div><?php endif; ?>

<!-- ── HERO HEADER ── -->
<div style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:28px 32px;margin-bottom:20px;display:flex;align-items:center;gap:24px">
  <!-- Avatar -->
  <div style="width:72px;height:72px;border-radius:16px;background:linear-gradient(135deg,#1e3a5f,#2d5f9e);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#fff;flex-shrink:0;letter-spacing:-1px">
    <?= $initials ?>
  </div>
  <!-- İsim + meta -->
  <div style="flex:1;min-width:0">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <h1 style="font-size:22px;font-weight:700;color:var(--text);margin:0"><?= h($dealer['company_name']) ?></h1>
      <?php if ($isActive): ?>
        <span style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;border-radius:6px;font-size:11px;font-weight:700;padding:2px 10px">● Aktif</span>
      <?php else: ?>
        <span style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:6px;font-size:11px;font-weight:700;padding:2px 10px">○ Pasif</span>
      <?php endif; ?>
      <span style="background:var(--bg);color:var(--text-muted);border:1px solid var(--border);border-radius:6px;font-size:11px;padding:2px 10px"><?= h(ucfirst($dealer['type'] ?? 'kurumsal')) ?></span>
    </div>
    <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:16px">
      <?php if ($dealer['email']): ?><span style="font-size:13px;color:var(--text-muted)">✉ <?= h($dealer['email']) ?></span><?php endif; ?>
      <?php if ($dealer['phone']): ?><span style="font-size:13px;color:var(--text-muted)">📞 <?= h($dealer['phone']) ?></span><?php endif; ?>
      <?php if ($dealer['city']): ?><span style="font-size:13px;color:var(--text-muted)">📍 <?= h($dealer['city']) ?></span><?php endif; ?>
      <?php if ($dealer['dealer_code']): ?><span style="font-size:13px;color:var(--text-muted);font-family:monospace">Kod: <?= h($dealer['dealer_code']) ?></span><?php endif; ?>
    </div>
  </div>
  <!-- Butonlar -->
  <div style="display:flex;gap:8px;flex-shrink:0">
    <a href="?page=dealers" class="btn btn-ghost">← Geri</a>
    <a href="?page=dealers&action=edit&id=<?= $id ?>" class="btn btn-primary">✏ Düzenle</a>
  </div>
</div>

<!-- ── 4 STAT KART ── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px">
  <!-- Açık Bakiye -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 22px;border-left:4px solid <?= $balance > 0 ? '#dc2626' : '#16a34a' ?>">
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:8px">Açık Bakiye</div>
    <div style="font-size:22px;font-weight:700;color:<?= $balance > 0 ? '#dc2626' : '#16a34a' ?>"><?= money($balance) ?></div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:4px"><?= $balance > 0 ? 'Vadesi gelen borç' : 'Borç yok' ?></div>
  </div>
  <!-- Kredi Limiti -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 22px;border-left:4px solid #6366f1">
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:8px">Kredi Limiti</div>
    <div style="font-size:22px;font-weight:700;color:var(--text)"><?= money($dealer['credit_limit']) ?></div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Vade: <?= $dealer['payment_term_days'] ?> gün</div>
  </div>
  <!-- Toplam Sipariş -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 22px;border-left:4px solid #0ea5e9">
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:8px">Toplam Sipariş</div>
    <div style="font-size:22px;font-weight:700;color:var(--text)"><?= $totalOrders ?></div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:4px"><?= $dealer['order_approval'] === 'auto' ? '⚡ Otomatik onay' : '👁 Manuel onay' ?></div>
  </div>
  <!-- Toplam Ciro -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 22px;border-left:4px solid #f59e0b">
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:8px">Toplam Ciro</div>
    <div style="font-size:22px;font-weight:700;color:var(--text)"><?= money($totalSpend) ?></div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:4px">İptal hariç</div>
  </div>
</div>

<!-- ── 2 KOLON: BİLGİ + İŞLEMLER ── -->
<div style="display:grid;grid-template-columns:1fr 320px;gap:16px;margin-bottom:20px">

  <!-- Sol: Bayi Bilgileri -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden">
    <div style="padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <span style="font-size:13px;font-weight:700;color:var(--text)">Bayi Bilgileri</span>
      <a href="?page=ledger&dealer_id=<?= $id ?>" class="btn btn-ghost btn-sm">Cari Ekstreye Git →</a>
    </div>
    <div style="padding:4px 0">
      <?php
      $rows_info = [
        ['E-posta',        h($dealer['email'])],
        ['Telefon',        h($dealer['phone'])],
        ['İletişim Kişisi', h(trim(($dealer['first_name']??'').' '.($dealer['last_name']??'')))],
        ['Vergi No / Dairesi', h($dealer['tax_number']).' / '.h($dealer['tax_office'])],
        ['Adres',          h($dealer['address']).', '.h($dealer['city'])],
        ['Fiyat Listesi',  h($priceListName)],
        ['Kayıt Tarihi',   fmtDate($dealer['created_at'])],
      ];
      foreach ($rows_info as [$label, $val]): ?>
      <div style="display:flex;align-items:baseline;gap:12px;padding:11px 22px;border-bottom:1px solid var(--border)">
        <div style="width:140px;flex-shrink:0;font-size:12px;color:var(--text-muted);font-weight:500"><?= $label ?></div>
        <div style="font-size:13px;color:var(--text);font-weight:500"><?= $val ?: '—' ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Sağ: Hızlı İşlemler -->
  <div style="display:flex;flex-direction:column;gap:12px">

    <!-- Şifre Sıfırla -->
    <div style="background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden">
      <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
        <span style="font-size:13px;font-weight:700;color:var(--text)">🔐 Şifre Sıfırla</span>
      </div>
      <div style="padding:16px 18px">
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="form_action" value="reset_password">
          <input type="hidden" name="dealer_id" value="<?= $id ?>">
          <input type="password" name="new_password" class="form-control" placeholder="En az 6 karakter" style="margin-bottom:10px">
          <button type="submit" class="btn btn-secondary" style="width:100%">Şifreyi Güncelle</button>
        </form>
      </div>
    </div>

    <!-- Hızlı Linkler -->
    <div style="background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden">
      <div style="padding:14px 18px;border-bottom:1px solid var(--border)">
        <span style="font-size:13px;font-weight:700;color:var(--text)">🔗 Hızlı Erişim</span>
      </div>
      <div style="padding:8px 0">
        <a href="?page=orders&dealer_id=<?= $id ?>" style="display:flex;align-items:center;gap:10px;padding:10px 18px;font-size:13px;color:var(--text);text-decoration:none;transition:background .15s" onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background=''">
          <span style="width:28px;height:28px;background:#eff6ff;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:14px">📦</span>
          Tüm Siparişler
          <span style="margin-left:auto;font-size:11px;color:var(--text-muted)"><?= $totalOrders ?></span>
        </a>
        <a href="?page=ledger&dealer_id=<?= $id ?>" style="display:flex;align-items:center;gap:10px;padding:10px 18px;font-size:13px;color:var(--text);text-decoration:none;transition:background .15s" onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background=''">
          <span style="width:28px;height:28px;background:#fef9f0;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:14px">📊</span>
          Cari Hesap
          <?php if ($balance > 0): ?><span style="margin-left:auto;font-size:11px;background:#fef2f2;color:#dc2626;border-radius:4px;padding:1px 6px;font-weight:600"><?= money($balance) ?></span><?php endif; ?>
        </a>
        <a href="?page=payments&dealer_id=<?= $id ?>" style="display:flex;align-items:center;gap:10px;padding:10px 18px;font-size:13px;color:var(--text);text-decoration:none;transition:background .15s" onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background=''">
          <span style="width:28px;height:28px;background:#f0fdf4;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:14px">💳</span>
          Ödemeler
        </a>
      </div>
    </div>

  </div>
</div>

<!-- ── SON SİPARİŞLER ── -->
<div style="background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:20px">
  <div style="padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <span style="font-size:13px;font-weight:700;color:var(--text)">Son Siparişler</span>
    <a href="?page=orders&dealer_id=<?= $id ?>" class="btn btn-ghost btn-sm">Tümünü Gör →</a>
  </div>
  <table class="table" style="margin:0">
    <thead>
      <tr>
        <th>Sipariş No</th>
        <th>Tarih</th>
        <th style="text-align:right">Tutar</th>
        <th>Durum</th>
        <th>Ödeme</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
      <tr>
        <td><a href="?page=orders&action=detail&id=<?= $o['id'] ?>" style="font-weight:600;color:var(--primary);font-family:monospace"><?= h($o['order_no']) ?></a></td>
        <td style="color:var(--text-muted);font-size:12px"><?= fmtDate($o['created_at']) ?></td>
        <td style="text-align:right;font-weight:600"><?= money($o['grand_total']) ?></td>
        <td><?= orderStatusLabel($o['status']) ?></td>
        <td><?= paymentStatusLabel($o['payment_status'] ?? 'odenmedi') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($orders)): ?>
      <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted)">Henüz sipariş yok.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- ── SON CARİ HAREKETLER ── -->
<div style="background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden">
  <div style="padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <span style="font-size:13px;font-weight:700;color:var(--text)">Son Cari Hareketler</span>
    <a href="?page=ledger&dealer_id=<?= $id ?>" class="btn btn-ghost btn-sm">Tam Ekstre →</a>
  </div>
  <table class="table" style="margin:0">
    <thead>
      <tr>
        <th>Tarih</th>
        <th>Açıklama</th>
        <th style="text-align:right">Borç</th>
        <th style="text-align:right">Alacak</th>
        <th>Vade</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($ledger as $l): ?>
      <tr>
        <td style="color:var(--text-muted);font-size:12px"><?= fmtDate($l['created_at']) ?></td>
        <td style="font-size:13px"><?= h($l['description']) ?></td>
        <td style="text-align:right;font-weight:600;color:<?= $l['type']==='borc' ? '#dc2626' : 'transparent' ?>"><?= $l['type']==='borc' ? money($l['amount']) : '' ?></td>
        <td style="text-align:right;font-weight:600;color:<?= $l['type']==='alacak' ? '#16a34a' : 'transparent' ?>"><?= $l['type']==='alacak' ? money($l['amount']) : '' ?></td>
        <td style="font-size:12px;color:var(--text-muted)"><?= $l['due_date'] ? fmtDate($l['due_date']) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($ledger)): ?>
      <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted)">Cari hareket yok.</td></tr>
      <?php endif; ?>
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
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

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

    <!-- ─── Ciro Primi (Aylık Komisyon) ─── -->
    <div style="margin-top:18px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:14px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
        <h4 style="margin:0;color:#92400e;font-size:13px;font-weight:700">💰 Ciro Primi (Aylık Komisyon)</h4>
        <a href="?page=commissions&dealer_id=<?= (int)($dealer['id']??0) ?>" style="font-size:11px;color:#92400e;text-decoration:underline">Geçmiş primler →</a>
      </div>
      <div class="form-grid form-grid-2">
        <div class="form-group">
          <label style="font-size:12px">Prim Oranı (%)
            <span style="font-size:10px;color:var(--text-muted);font-weight:400">— örn: 2.50</span>
          </label>
          <input type="number" step="0.01" min="0" max="100" name="commission_rate"
                 value="<?= htmlspecialchars($dealer['commission_rate']??0) ?>"
                 placeholder="0.00"
                 class="form-control">
        </div>
        <div class="form-group">
          <label style="font-size:12px">Min. Alış Tutarı (₺)
            <span style="font-size:10px;color:var(--text-muted);font-weight:400">— bu tutar altında prim hesaplanmaz</span>
          </label>
          <input type="number" step="0.01" min="0" name="commission_min_amount"
                 value="<?= htmlspecialchars($dealer['commission_min_amount']??0) ?>"
                 placeholder="0.00"
                 class="form-control">
        </div>
      </div>
      <div class="form-group" style="margin-top:8px;margin-bottom:0">
        <label style="font-size:12px">Prim Notu (admin)</label>
        <input type="text" name="commission_notes"
               value="<?= htmlspecialchars($dealer['commission_notes']??'') ?>"
               placeholder="örn: 6 aylık özel anlaşma, Q1 sonu yeniden değerlendirilecek"
               class="form-control">
      </div>
    </div>

    <!-- Ödeme Yöntemleri -->
    <div class="form-group">
        <label class="form-label" style="margin-bottom:10px;display:block;font-weight:600">Ödeme Yöntemleri</label>
        <?php
        $allowedMethods = array_filter(explode(',', $dealer['payment_methods'] ?? 'havale,kredi_karti'));
        $allMethods = [
            'havale'      => ['label' => 'Havale / EFT',       'icon' => '🏦'],
            'kredi_karti' => ['label' => 'Kredi Kartı',        'icon' => '💳'],
            'nakit'       => ['label' => 'Nakit',              'icon' => '💵'],
            'cek'         => ['label' => 'Çek',                'icon' => '📄'],
            'cari'        => ['label' => 'Açık Hesap (Cari)',  'icon' => '📊'],
        ];
        ?>
        <div style="display:flex;flex-wrap:wrap;gap:10px">
        <?php foreach ($allMethods as $val => $m): ?>
        <label style="display:flex;align-items:center;gap:8px;padding:10px 16px;border:1px solid var(--border);border-radius:8px;cursor:pointer;background:<?= in_array($val,$allowedMethods)?'var(--primary-bg,#eff6ff)':'var(--surface)' ?>;font-size:13px;user-select:none" onclick="this.style.background=this.querySelector('input').checked?'var(--surface)':'var(--primary-bg,#eff6ff)'">
            <input type="checkbox" name="payment_methods[]" value="<?= $val ?>" <?= in_array($val, $allowedMethods) ? 'checked' : '' ?> style="accent-color:var(--primary)">
            <span><?= $m['icon'] ?> <?= $m['label'] ?></span>
        </label>
        <?php endforeach; ?>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:6px">Seçilmeyenler bayinin ödeme formunda görünmez.</div>
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

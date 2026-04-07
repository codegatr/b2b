<?php
// admin/pages/applications.php — Bayilik Başvuruları
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';
    $aid = intval($_POST['app_id'] ?? 0);

    if ($act === 'approve') {
        $app = dbRow("SELECT * FROM b2b_applications WHERE id=?", [$aid]);
        if ($app && $app['status'] === 'bekliyor') {
            // Bayi hesabı oluştur
            $pass = bin2hex(random_bytes(4)); // Geçici şifre
            $did  = dbInsertRow('b2b_dealers', [
                'company_name'      => $app['company_name'],
                'type'              => $app['type'],
                'first_name'      => $app['first_name'],
                'last_name'       => $app['last_name'] ?? '',
                'email'             => $app['email'],
                'phone'             => $app['phone'],
                'tax_number'        => $app['tax_number'],
                'tax_office'        => $app['tax_office'],
                'address'           => $app['address'],
                'city'              => $app['city'],
                'password'          => password_hash($pass, PASSWORD_DEFAULT),
                'order_approval'    => 'manual',
                'credit_limit'      => 0,
                'payment_term_days' => 30,
                'is_active'         => 1,
                'created_at'        => date('Y-m-d H:i:s'),
            ]);
            dbExec("UPDATE b2b_applications SET status='onaylandi', reviewed_by=?, reviewed_at=NOW(), dealer_id=? WHERE id=?",
                [adminId(), $did, $aid]);
            // Paraşüt sync
            try { parasut()->syncDealer($did); } catch (Exception $e) {}
            auditLog('application_approved', 'b2b_applications', $aid, ['dealer_id'=>$did]);
            $success = "Başvuru onaylandı. Bayi hesabı oluşturuldu. Geçici şifre: <strong>$pass</strong> (bayiye e-posta gönderin)";
        }
    }

    if ($act === 'reject') {
        $reason = trim($_POST['reject_reason'] ?? '');
        dbExec("UPDATE b2b_applications SET status='reddedildi', reject_reason=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?",
            [$reason, adminId(), $aid]);
        $success = 'Başvuru reddedildi.';
    }
}

$status  = $_GET['status'] ?? 'bekliyor';
$page    = max(1, intval($_GET['p'] ?? 1));
$perPage = 20;
$offset  = ($page-1)*$perPage;

$where  = $status ? 'WHERE status=?' : 'WHERE 1=1';
$params = $status ? [$status] : [];
$total  = dbVal("SELECT COUNT(*) FROM b2b_applications $where", $params);
$apps   = dbRows("SELECT * FROM b2b_applications $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset", $params);
$pager  = pagination($total, $perPage, $page, "?page=applications&status=$status&p=");

$pendingCount = dbVal("SELECT COUNT(*) FROM b2b_applications WHERE status='bekliyor'", []);
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Bayilik Başvuruları <?php if ($pendingCount): ?><span class="badge badge-yellow"><?= $pendingCount ?></span><?php endif; ?></h1>
    </div>
</div>

<?php if (!empty($success)): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

<?php
$_acounts = [
    'bekliyor'   => (int)dbVal("SELECT COUNT(*) FROM b2b_applications WHERE status='bekliyor'"),
    'onaylandi'  => (int)dbVal("SELECT COUNT(*) FROM b2b_applications WHERE status='onaylandi'"),
    'reddedildi' => (int)dbVal("SELECT COUNT(*) FROM b2b_applications WHERE status='reddedildi'"),
    ''           => (int)dbVal("SELECT COUNT(*) FROM b2b_applications"),
];
?>
<div class="tab-bar">
    <?php foreach (['bekliyor'=>'Bekleyen','onaylandi'=>'Onaylanan','reddedildi'=>'Reddedilen',''=>'Tümü'] as $v=>$l): ?>
    <a href="?page=applications&status=<?= $v ?>" class="tab-item <?= $status===$v?'active':'' ?>">
        <?= $l ?>
        <?php if ($_acounts[$v] > 0): ?>
        <span class="tab-count <?= $v==='bekliyor'?'warn':'' ?>"><?= $_acounts[$v] ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="grid gap-4">
<?php foreach ($apps as $a): ?>
<div class="card">
    <div class="card-body">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
            <div>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                    <span class="badge badge-<?= $a['type']==='kurumsal'?'blue':'purple' ?>"><?= h($a['type']) ?></span>
                    <span class="badge badge-<?= $a['status']==='bekliyor'?'yellow':($a['status']==='onaylandi'?'green':'red') ?>"><?= h($a['status']) ?></span>
                    <span class="text-muted text-sm"><?= fmtDate($a['created_at']) ?></span>
                </div>
                <h3 class="font-semibold mb-1"><?= h($a['company_name']) ?></h3>
                <div class="text-sm text-muted" style="display:grid;grid-template-columns:repeat(3,1fr);gap:4px 16px">
                    <span>👤 <?= h($a['first_name']) ?></span>
                    <span>📧 <?= h($a['email']) ?></span>
                    <span>📞 <?= h($a['phone']) ?></span>
                    <span>🏢 <?= h($a['tax_number']) ?> / <?= h($a['tax_office']) ?></span>
                    <span>📍 <?= h($a['address']) ?>, <?= h($a['city']) ?></span>
                </div>
                <?php if ($a['notes']): ?>
                <div class="mt-2 p-2 bg-muted rounded text-sm"><?= h($a['notes']) ?></div>
                <?php endif; ?>
                <?php if ($a['reject_reason']): ?>
                <div class="mt-2 text-sm text-danger">Red Nedeni: <?= h($a['reject_reason']) ?></div>
                <?php endif; ?>
            </div>
            <?php if ($a['status'] === 'bekliyor'): ?>
            <div style="display:flex;flex-direction:column;gap:8px;min-width:120px">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="form_action" value="approve">
                    <input type="hidden" name="app_id" value="<?= $a['id'] ?>">
                    <button class="btn btn-success w-full">✓ Onayla</button>
                </form>
                <button class="btn btn-danger w-full" onclick="rejectApp(<?= $a['id'] ?>)">✕ Reddet</button>
                <?php if ($a['dealer_id']): ?>
                <a href="?page=dealers&action=detail&id=<?= $a['dealer_id'] ?>" class="btn btn-ghost w-full text-sm">Bayi →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php if (empty($apps)): ?>
<div class="card"><div class="card-body text-center text-muted py-8">Başvuru bulunamadı.</div></div>
<?php endif; ?>
</div>
<?= $pager ?>

<!-- Modal: Reddet -->
<div id="modal-reject" class="modal-overlay" style="display:none">
<div class="modal">
    <div class="modal-header"><h3>Başvuruyu Reddet</h3></div>
    <div class="modal-body">
        <form method="post" id="form-reject">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="reject">
            <input type="hidden" name="app_id" id="reject-app-id" value="">
            <div class="form-group">
                <label>Red Nedeni</label>
                <textarea name="reject_reason" class="form-control" rows="3" placeholder="Başvuruya neden reddedildiğini açıklayın…"></textarea>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost" type="button" onclick="closeModal('modal-reject')">Vazgeç</button>
        <button class="btn btn-danger" type="submit" form="form-reject">Reddet</button>
    </div>
</div>
</div>
<script>
function rejectApp(id) {
    document.getElementById('reject-app-id').value = id;
    openModal('modal-reject');
}
</script>

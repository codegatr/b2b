<?php
// admin/pages/update.php — Güncelleme Merkezi
requireAdmin();

$updater = updater();
$currentVersion = defined('B2B_VERSION') ? B2B_VERSION : setting('app_version','1.0.0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';

    if ($act === 'update') {
        $version = trim($_POST['version'] ?? '');
        if ($version) {
            try {
                $result = $updater->update($version);
                if ($result['success']) {
                    $success = "Güncelleme tamamlandı! Sürüm: <strong>$version</strong> — {$result['files_updated']} dosya güncellendi.";
                } else {
                    $error = $result['message'] ?? 'Güncelleme başarısız.';
                }
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }

    if ($act === 'rollback') {
        $backup = trim($_POST['backup_file'] ?? '');
        if ($backup) {
            try {
                $result = $updater->rollback($backup);
                $success = $result['success'] ? 'Geri alma başarılı.' : ($result['message'] ?? 'Hata.');
                if (!$result['success']) $error = $result['message'];
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// GitHub bilgilerini çek
$releases = [];
$latestRelease = null;
$fetchError = null;
try {
    $releases      = $updater->getReleases(10);
    $latestRelease = $releases[0] ?? null;
} catch (Exception $e) {
    $fetchError = $e->getMessage();
}

$backups = $updater->getBackups();
$logs    = dbRows("SELECT * FROM b2b_update_log ORDER BY created_at DESC LIMIT 20");
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Güncelleme Merkezi</h1>
        <p class="page-sub">Mevcut Sürüm: <strong><?= h($currentVersion) ?></strong></p>
    </div>
</div>

<?php if (!empty($success)): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
<?php if ($fetchError):      ?><div class="alert alert-warning">GitHub bağlantısı kurulamadı: <?= h($fetchError) ?> — <a href="?page=settings&tab=github">Ayarlara Git</a></div><?php endif; ?>

<!-- Güncel Sürüm Banner -->
<?php if ($latestRelease): ?>
<?php $hasUpdate = version_compare($latestRelease['tag_name'], $currentVersion, '>'); ?>
<div class="card mb-6 <?= $hasUpdate?'card--highlight':'' ?>">
    <div class="card-body" style="display:flex;justify-content:space-between;align-items:center">
        <div>
            <div class="text-lg font-semibold">
                <?= $hasUpdate ? '🆕 Yeni Sürüm Mevcut!' : '✅ Sistem Güncel' ?>
            </div>
            <div class="text-muted mt-1">
                En son sürüm: <strong><?= h($latestRelease['tag_name']) ?></strong>
                — <?= fmtDate($latestRelease['published_at']) ?>
            </div>
            <?php if ($latestRelease['body']): ?>
            <div class="mt-2 text-sm" style="max-width:600px"><?= nl2br(h(substr($latestRelease['body'],0,300))) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($hasUpdate): ?>
        <form method="post" onsubmit="return confirm('Güncelleme yapılacak. Önce yedek alınacak. Devam?')">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="version" value="<?= h($latestRelease['tag_name']) ?>">
            <button type="submit" class="btn btn-success btn-lg">⬆ Güncelle <?= h($latestRelease['tag_name']) ?></button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-2 gap-6">
    <!-- Sürüm Geçmişi -->
    <div class="card">
        <div class="card-header"><h3>GitHub Sürümleri</h3></div>
        <div class="card-body" style="padding:0">
        <?php if ($releases): ?>
        <?php foreach ($releases as $r): ?>
        <?php $installed = version_compare($r['tag_name'], $currentVersion, '<='); ?>
        <div class="release-item">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <div>
                    <span class="font-medium"><?= h($r['tag_name']) ?></span>
                    <?php if ($r['tag_name'] === $currentVersion): ?>
                    <span class="badge badge-green ml-2">Mevcut</span>
                    <?php elseif (!$installed): ?>
                    <span class="badge badge-blue ml-2">Yeni</span>
                    <?php endif; ?>
                    <div class="text-muted text-sm mt-1"><?= fmtDate($r['published_at']) ?></div>
                </div>
                <?php if (!$installed && $r['tag_name'] !== $currentVersion): ?>
                <form method="post" onsubmit="return confirm('<?= h($r['tag_name']) ?> sürümüne geçilecek. Devam?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="form_action" value="update">
                    <input type="hidden" name="version" value="<?= h($r['tag_name']) ?>">
                    <button type="submit" class="btn btn-xs btn-secondary">Yükle</button>
                </form>
                <?php endif; ?>
            </div>
            <?php if ($r['body']): ?>
            <div class="text-sm text-muted mt-1" style="max-height:60px;overflow:hidden"><?= nl2br(h(substr($r['body'],0,200))) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="p-4 text-muted">Sürüm bilgisi alınamadı.</div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Backup & Rollback -->
    <div>
        <div class="card mb-4">
            <div class="card-header"><h3>Yedekler (Rollback)</h3></div>
            <div class="card-body" style="padding:0">
            <?php if ($backups): ?>
            <?php foreach ($backups as $b): ?>
            <div class="release-item">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <div class="font-medium text-sm"><?= h($b['name']) ?></div>
                        <div class="text-muted text-xs"><?= $b['size'] ?> — <?= fmtDate($b['date']) ?></div>
                    </div>
                    <form method="post" onsubmit="return confirm('Bu yedekten geri alınacak. Emin misiniz?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="form_action" value="rollback">
                        <input type="hidden" name="backup_file" value="<?= h($b['path']) ?>">
                        <button type="submit" class="btn btn-xs btn-danger">Geri Al</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="p-4 text-muted text-sm">Yedek bulunamadı. Güncelleme yapıldığında otomatik yedek alınır.</div>
            <?php endif; ?>
            </div>
        </div>

        <!-- Güncelleme Logu -->
        <div class="card">
            <div class="card-header"><h3>Güncelleme Geçmişi</h3></div>
            <table class="table">
                <thead><tr><th>Tarih</th><th>Sürüm</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $l): ?>
                <tr>
                    <td class="text-sm"><?= fmtDate($l['created_at']) ?></td>
                    <td class="mono text-sm"><?= h($l['version']) ?></td>
                    <td><span class="badge badge-<?= $l['status']==='success'?'green':'red' ?>"><?= h($l['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?><tr><td colspan="3" class="text-muted text-center">Kayıt yok.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.release-item { padding:12px 16px; border-bottom:1px solid var(--border); }
.release-item:last-child { border-bottom:none; }
</style>

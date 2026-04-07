<?php
// admin/pages/update.php
requireAdmin();

$updater = updater();
$currentVersion = $updater->getCurrentVersion();
$installedSha   = $updater->getInstalledSha();

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';

    // Branch'den guncelle
    if ($act === 'update_branch') {
        try {
            $result = $updater->updateFromBranch();
            if ($result['success']) {
                $fc = is_array($result['files'] ?? null) ? count($result['files']) : 0;
                $sha = $result['commit']['sha_short'] ?? '';
                $success = "Güncelleme tamamlandı! {$fc} dosya güncellendi. Commit: {$sha}";
            } else {
                $error = $result['message'] ?? 'Guncelleme basarisiz.';
            }
        } catch (Exception $e) { $error = $e->getMessage(); }
    }

    // Release'den guncelle
    if ($act === 'update_release') {
        $version = trim($_POST['version'] ?? '');
        if ($version) {
            try {
                $result = $updater->updateFromRelease($version);
                if ($result['success']) {
                    $fc = is_array($result['files'] ?? null) ? count($result['files']) : 0;
                    $success = "Release {$version} yuklendi! {$fc} dosya guncellendi.";
                } else {
                    $error = $result['message'] ?? 'Guncelleme basarisiz.';
                }
            } catch (Exception $e) { $error = $e->getMessage(); }
        }
    }

    // Rollback
    if ($act === 'rollback') {
        $backup = trim($_POST['backup_file'] ?? '');
        if ($backup) {
            try {
                $result = $updater->rollback($backup);
                $success = $result['success'] ? 'Geri alma basarili.' : ($result['message'] ?? 'Hata.');
                if (!$result['success']) $error = $result['message'];
            } catch (Exception $e) { $error = $e->getMessage(); }
        }
    }
}

// GitHub bilgilerini cek
$latestCommit  = null;
$releases      = [];
$fetchError    = '';
try {
    $latestCommit = $updater->getLatestCommit();
} catch (Exception $e) { $fetchError = $e->getMessage(); }

try {
    $releases = $updater->getReleases(5);
} catch (Exception $e) {}

$backups  = $updater->getBackups();
$logs     = dbRows("SELECT * FROM b2b_update_log ORDER BY created_at DESC LIMIT 20");
$hasBranchUpdate = $latestCommit && ($latestCommit['sha'] !== $installedSha);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Guncelleme Merkezi</h1>
    <p class="page-sub">Mevcut Surum: <strong><?= h($currentVersion) ?></strong>
    <?php if ($installedSha): ?> &nbsp;·&nbsp; Commit: <code style="font-size:.8em"><?= h(substr($installedSha,0,7)) ?></code><?php endif; ?>
    </p>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if ($fetchError): ?><div class="alert alert-warning">GitHub: <?= h($fetchError) ?> — <a href="?page=settings&tab=github">Token Ayarla</a></div><?php endif; ?>

<!-- ── BRANCH GUNCELLEME (ana mod) ── -->
<div class="card" style="margin-bottom:1.5rem;border-left:3px solid var(--accent)">
  <div class="card-header" style="display:flex;align-items:center;gap:12px">
    <h3 class="card-title" style="flex:1">&#128640; main Branch'den Guncelle</h3>
    <?php if ($hasBranchUpdate): ?>
    <span class="badge badge-danger">Guncelleme Mevcut</span>
    <?php elseif ($latestCommit): ?>
    <span class="badge badge-success">Guncel</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if ($latestCommit): ?>
    <div style="background:var(--bg-elevated);border-radius:8px;padding:14px 16px;margin-bottom:16px;font-size:.875rem">
      <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap">
        <div style="flex:1;min-width:200px">
          <div style="color:var(--text-muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">Son Commit</div>
          <div style="font-weight:600;margin-bottom:2px"><?= h(mb_substr($latestCommit['message'], 0, 80)) ?></div>
          <div style="color:var(--text-muted);font-size:.8rem"><?= h($latestCommit['author']) ?> — <?= date('d.m.Y H:i', strtotime($latestCommit['date'])) ?></div>
        </div>
        <div>
          <code style="background:var(--bg-base);padding:4px 8px;border-radius:5px;font-size:.8rem;color:var(--accent)"><?= h($latestCommit['sha_short']) ?></code>
        </div>
      </div>
    </div>
    <?php if ($hasBranchUpdate): ?>
    <div class="alert alert-warning" style="margin-bottom:12px">
      Yeni commit mevcut — (<code><?= h(substr($installedSha,0,7) ?: 'bilinmiyor') ?></code>) ile GitHub farklı. Guncelleme mevcut.
    </div>
    <?php else: ?>
    <div style="color:var(--text-muted);font-size:.875rem;margin-bottom:12px">
      Sistem güncel.
      <?php if ($installedSha): ?>
      <span style="color:var(--success);font-size:.8rem">
        ✓ Yüklü: <code style="font-size:11px"><?= h(substr($installedSha,0,7)) ?></code>
        = GitHub: <code style="font-size:11px"><?= h($latestCommit['sha_short'] ?? '?') ?></code>
      </span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="update_branch">
      <button type="submit" class="btn btn-primary"
        onclick="return confirm('Branch guncellemesi yapilacak. Backup otomatik alinir. Devam edilsin mi?')"
      >
        <?= $hasBranchUpdate ? '&#128640; Guncellemeyi Yukle' : '&#128260; Yeniden Yukle (zorla)' ?>
      </button>
    </form>
    <?php else: ?>
    <p style="color:var(--text-muted)">GitHub baglantisi kurulamadi. <a href="?page=settings&tab=github">Token ve repo ayarlarini kontrol edin.</a></p>
    <?php endif; ?>
  </div>
</div>

<!-- ── RELEASE GUNCELLEME (opsiyonel) ── -->
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-header"><h3 class="card-title">&#127381; GitHub Release'den Guncelle</h3></div>
  <div class="card-body">
    <?php if (empty($releases)): ?>
    <p style="color:var(--text-muted);font-size:.875rem">
      Henuz release yok. Branch guncelleme (yukaridaki) daha pratik —
      sadece <code>git push</code> yapmaniz yeterli.
    </p>
    <?php else: ?>
    <table class="table">
      <thead><tr><th>Surum</th><th>Tarih</th><th>Notlar</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($releases as $r): ?>
      <tr>
        <td><strong><?= h($r['tag_name']) ?></strong></td>
        <td style="color:var(--text-muted);font-size:.85rem"><?= date('d.m.Y', strtotime($r['published_at'])) ?></td>
        <td style="color:var(--text-muted);font-size:.85rem"><?= h(mb_substr($r['name'],0,50)) ?></td>
        <td>
          <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="update_release">
            <input type="hidden" name="version" value="<?= h(ltrim($r['tag_name'],'v')) ?>">
            <button type="submit" class="btn btn-sm btn-secondary"
              onclick="return confirm('<?= h($r['tag_name']) ?> yuklenecek. Devam edilsin mi?')">Yukle</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- ── YEDEKLER ── -->
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-header"><h3 class="card-title">&#128190; Yedekler (Rollback)</h3></div>
  <div class="card-body">
    <?php if (empty($backups)): ?>
    <p style="color:var(--text-muted);font-size:.875rem">Yedek bulunamadi. Guncelleme yapildiginda otomatik yedek alinir.</p>
    <?php else: ?>
    <?php foreach ($backups as $b): ?>
    <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
      <div style="flex:1;font-size:.875rem"><?= h(basename($b)) ?></div>
      <div style="color:var(--text-muted);font-size:.8rem"><?= round(filesize($b)/1024) ?> KB</div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="rollback">
        <input type="hidden" name="backup_file" value="<?= h(basename($b)) ?>">
        <button type="submit" class="btn btn-sm btn-danger"
          onclick="return confirm('Bu yedege donmek istediginize emin misiniz?')">Geri Don</button>
      </form>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ── GECMIS ── -->
<?php if ($logs): ?>
<div class="card">
  <div class="card-header"><h3 class="card-title">&#128203; Guncelleme Gecmisi</h3></div>
  <table class="table">
    <thead><tr><th>Tarih</th><th>Surum/Commit</th><th>Versiyon</th><th>Durum</th><th>Not</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
    <tr>
      <td style="font-size:.82rem;color:var(--text-muted)"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></td>
      <td><code style="font-size:.8rem"><?= h($l['to_version']) ?></code></td>
      <td><?= (int)('—' ?? 0) ?></td>
      <td><span class="badge badge-<?= $l['status']=='success'?'success':'danger' ?>"><?= h($l['status']) ?></span></td>
      <td style="font-size:.8rem;color:var(--text-muted)"><?= h(mb_substr($l['note']??''  ,0,60)) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

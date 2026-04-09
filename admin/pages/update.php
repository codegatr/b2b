<?php
// admin/pages/update.php
requireAdmin();

$updater = updater();
$currentVersion = $updater->getCurrentVersion();
$installedSha   = $updater->getInstalledSha();

$success = $error = '';
$migrationResults = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';

    // Migration çalıştır (tek veya hepsi)
    if ($act === 'run_migrations') {
        $migrationResults = migrationRunAll();
        $ok  = count(array_filter($migrationResults, fn($r) => $r['ok']));
        $fail = count($migrationResults) - $ok;
        if (empty($migrationResults)) {
            $success = 'Çalıştırılacak migration yok, sistem güncel.';
        } elseif ($fail === 0) {
            $success = "{$ok} migration başarıyla çalıştırıldı.";
        } else {
            $error = "{$ok} başarılı, {$fail} başarısız. Detaylar aşağıda.";
        }
    }

    if ($act === 'run_single_migration') {
        $file = B2B_ROOT . '/install/migrations/' . basename($_POST['migration_file'] ?? '');
        if (file_exists($file)) {
            $r = migrationRun($file);
            $migrationResults = [$r];
            if ($r['ok']) $success = $r['name'] . ' başarıyla çalıştırıldı.';
            else $error = $r['name'] . ' hatası: ' . $r['error'];
        }
    }

    // Branch'den guncelle
    if ($act === 'update_branch') {
        try {
            $result = $updater->updateFromBranch();
            if ($result['success']) {
                $fc   = is_array($result['files'] ?? null) ? count($result['files']) : 0;
                $sha  = $result['commit']['sha_short'] ?? '';
                $migr = $result['migrations'] ?? ['run'=>0,'errors'=>[]];
                $msg  = "✅ Güncelleme tamamlandı! {$fc} dosya güncellendi. Commit: {$sha}";
                if (($migr['run'] ?? 0) > 0) {
                    $msg .= " · {$migr['run']} migration çalıştırıldı.";
                }
                if (!empty($migr['errors'])) {
                    $msg .= " ⚠️ Migration hatası: " . implode(', ', $migr['errors']);
                }
                // PRG — güncellenen sayfa kendi kendini render etsin
                $_SESSION['flash_admin'] = ['type'=>'success','msg'=>$msg];
                header('Location: ?page=update&updated=1');
                exit;
                // Güncelleme sonrası bekleyen migration varsa otomatik çalıştır
                $pending = migrationGetPending();
                if ($pending) {
                    $migrationResults = migrationRunAll();
                    $ok = count(array_filter($migrationResults, fn($r) => $r['ok']));
                    $success .= " — {$ok} migration otomatik çalıştırıldı.";
                }
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
                    $pending = migrationGetPending();
                    if ($pending) {
                        $migrationResults = migrationRunAll();
                        $ok = count(array_filter($migrationResults, fn($r) => $r['ok']));
                        $success .= " — {$ok} migration otomatik çalıştırıldı.";
                    }
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

$backups         = $updater->getBackups();
$logs            = dbRows("SELECT * FROM b2b_update_log ORDER BY created_at DESC LIMIT 20");
$hasBranchUpdate = $latestCommit && ($latestCommit['sha'] !== $installedSha);
$migStatus       = migrationStatus();
$hasPending      = !empty($migStatus['pending']);
?>

<?php if ($justUpdated && !empty($_SESSION['flash_admin'])): ?>
<?php endif; ?>
<?php if ($justUpdated): ?>
<div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:2px solid #22c55e;border-radius:12px;padding:20px 24px;margin-bottom:24px;display:flex;align-items:center;gap:16px">
  <div style="font-size:32px">✅</div>
  <div>
    <div style="font-weight:700;font-size:16px;color:#15803d">Güncelleme Başarıyla Tamamlandı!</div>
    <div style="font-size:13px;color:#166534;margin-top:4px">
      Yüklü sürüm: <strong>v<?= h($currentVersion) ?></strong>
      <?php if ($installedSha): ?> · Commit: <code><?= h(substr($installedSha,0,7)) ?></code><?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Güncelleme Merkezi</h1>
    <p class="page-sub">Mevcut Sürüm: <strong><?= h($currentVersion) ?></strong>
    <?php if ($installedSha): ?> &nbsp;·&nbsp; Commit: <code style="font-size:.8em"><?= h(substr($installedSha,0,7)) ?></code><?php endif; ?>
    </p>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if ($fetchError): ?><div class="alert alert-warning">GitHub: <?= h($fetchError) ?> — <a href="?page=settings&tab=github">Token Ayarla</a></div><?php endif; ?>

<?php if (!empty($migrationResults)): ?>
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-header"><h3 class="card-title">Migration Sonuçları</h3></div>
  <div class="card-body" style="padding:12px 16px">
    <?php foreach ($migrationResults as $mr): ?>
    <div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid var(--border)">
      <span style="color:<?= $mr['ok']?'var(--success)':'var(--danger)' ?>"><?= $mr['ok']?'✓':'✗' ?></span>
      <code style="font-size:.8rem;flex:1"><?= h($mr['name']) ?></code>
      <?php if (!$mr['ok']): ?>
      <span style="color:var(--danger);font-size:.78rem"><?= h($mr['error']) ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── MİGRATIONLAR ── -->
<div class="card" style="margin-bottom:1.5rem;border-left:3px solid <?= $hasPending?'var(--warning)':'var(--success)' ?>">
  <div class="card-header">
    <h3 class="card-title" style="flex:1">🗄️ Veritabanı Migrationları</h3>
    <?php if ($hasPending): ?>
    <span class="badge badge-warning"><?= count($migStatus['pending']) ?> bekliyor</span>
    <?php else: ?>
    <span class="badge badge-success">Güncel</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if ($hasPending): ?>
    <div class="alert alert-warning" style="margin-bottom:12px">
      <strong><?= count($migStatus['pending']) ?> migration çalıştırılmayı bekliyor.</strong>
      Güncelleme sonrası veritabanı değişiklikleri bu migration'larla uygulanır.
    </div>
    <div style="margin-bottom:14px">
      <?php foreach ($migStatus['pending'] as $pName): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:7px 10px;background:var(--warning-bg);border:1px solid var(--warning-border);border-radius:6px;margin-bottom:6px">
        <span style="color:var(--warning)">⏳</span>
        <code style="font-size:.8rem;flex:1;color:var(--warning)"><?= h($pName) ?></code>
        <form method="POST" style="display:inline">
          <?= csrfField() ?>
          <input type="hidden" name="form_action" value="run_single_migration">
          <input type="hidden" name="migration_file" value="<?= h($pName) ?>">
          <button type="submit" class="btn btn-sm btn-secondary"
            onclick="return confirm('<?= h($pName) ?> çalıştırılsın mı?')">Çalıştır</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="run_migrations">
      <button type="submit" class="btn btn-primary"
        onclick="return confirm('Tüm bekleyen migrationlar çalıştırılacak. Önce yedek aldığınızdan emin olun. Devam?')">
        ⚡ Tümünü Çalıştır
      </button>
    </form>
    <?php else: ?>
    <p style="color:var(--text-muted);font-size:.875rem">
      Tüm migrationlar uygulanmış (<?= count($migStatus['done']) ?> toplam).
    </p>
    <?php endif; ?>

    <?php if (!empty($migStatus['done'])): ?>
    <details style="margin-top:12px">
      <summary style="font-size:.8rem;color:var(--text-muted);cursor:pointer">Çalıştırılmış (<?= count($migStatus['done']) ?>)</summary>
      <div style="margin-top:8px">
        <?php foreach ($migStatus['done'] as $dName): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid var(--border);font-size:.8rem">
          <span style="color:var(--success)">✓</span>
          <code><?= h($dName) ?></code>
        </div>
        <?php endforeach; ?>
      </div>
    </details>
    <?php endif; ?>
  </div>
</div>

<!-- ── BRANCH GUNCELLEME ── -->
<div class="card" style="margin-bottom:1.5rem;border-left:3px solid var(--info)">
  <div class="card-header" style="display:flex;align-items:center;gap:12px">
    <h3 class="card-title" style="flex:1">🚀 main Branch'den Güncelle</h3>
    <?php if ($hasBranchUpdate): ?>
    <span class="badge badge-danger">Güncelleme Mevcut</span>
    <?php elseif ($latestCommit): ?>
    <span class="badge badge-success">Güncel</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if ($latestCommit): ?>
    <div style="background:var(--bg);border-radius:8px;padding:14px 16px;margin-bottom:16px;font-size:.875rem;border:1px solid var(--border)">
      <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap">
        <div style="flex:1;min-width:200px">
          <div style="color:var(--text-muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">Son Commit</div>
          <div style="font-weight:600;margin-bottom:2px"><?= h(mb_substr($latestCommit['message'], 0, 80)) ?></div>
          <div style="color:var(--text-muted);font-size:.8rem"><?= h($latestCommit['author']) ?> — <?= date('d.m.Y H:i', strtotime($latestCommit['date'])) ?></div>
        </div>
        <code style="background:var(--bg);padding:4px 8px;border-radius:5px;font-size:.8rem;border:1px solid var(--border)"><?= h($latestCommit['sha_short']) ?></code>
      </div>
    </div>
    <?php if ($hasBranchUpdate): ?>
    <div class="alert alert-warning" style="margin-bottom:12px">
      Yeni commit mevcut — yüklü: <code><?= h(substr($installedSha,0,7) ?: 'bilinmiyor') ?></code>.
      <?php if ($hasPending): ?><br><strong>⚠️ Bekleyen migration var — güncelleme sonrası otomatik çalıştırılır.</strong><?php endif; ?>
    </div>
    <?php else: ?>
    <p style="color:var(--text-muted);font-size:.875rem;margin-bottom:12px">
      ✓ Sistem güncel — yüklü: <code><?= h(substr($installedSha,0,7)) ?></code>
    </p>
    <?php endif; ?>
    <form method="POST" id="form-branch-update">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="update_branch">
      <button type="submit" id="btn-branch-update" class="btn btn-primary" style="min-width:200px"
              onclick="return startUpdate(this)">
        <?= $hasBranchUpdate ? '⬆️ Güncellemeyi Yükle' : '🔄 Yeniden Yükle (zorla)' ?>
      </button>
      <div id="update-progress" style="display:none;margin-top:12px">
        <div style="height:4px;background:var(--border);border-radius:4px;overflow:hidden;width:100%;max-width:320px">
          <div id="update-bar" style="height:4px;width:0;background:linear-gradient(90deg,#ed2939,#f59e0b);border-radius:4px;transition:width 5s linear"></div>
        </div>
        <div id="update-msg" style="font-size:12px;color:var(--text-muted);margin-top:6px">Başlatılıyor...</div>
      </div>
    </form>
    <?php else: ?>
    <p style="color:var(--text-muted)">GitHub bağlantısı kurulamadı. <a href="?page=settings&tab=github">Token ve repo ayarlarını kontrol edin.</a></p>
    <?php endif; ?>
  </div>
</div>

<!-- ── RELEASE GUNCELLEME ── -->
<?php if (!empty($releases)): ?>
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-header"><h3 class="card-title">🏷️ GitHub Release'den Güncelle</h3></div>
  <div class="card-body">
    <table class="table">
      <thead><tr><th>Sürüm</th><th>Tarih</th><th>Not</th><th></th></tr></thead>
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
              onclick="return confirm('<?= h($r['tag_name']) ?> yüklenecek. Devam?')">Yükle</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── YEDEKLER ── -->
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-header"><h3 class="card-title">💾 Yedekler (Rollback)</h3></div>
  <div class="card-body">
    <?php if (empty($backups)): ?>
    <p style="color:var(--text-muted);font-size:.875rem">Yedek bulunamadı. Güncelleme yapıldığında otomatik yedek alınır.</p>
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
          onclick="return confirm('Bu yedeğe dönmek istediğinize emin misiniz?')">Geri Dön</button>
      </form>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ── GEÇMİŞ ── -->
<?php if ($logs): ?>
<div class="card">
  <div class="card-header"><h3 class="card-title">📋 Güncelleme Geçmişi</h3></div>
  <table class="table">
    <thead><tr><th>Tarih</th><th>Commit/Sürüm</th><th>Durum</th><th>Not</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
    <tr>
      <td style="font-size:.82rem;color:var(--text-muted)"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></td>
      <td><code style="font-size:.8rem"><?= h($l['to_version']) ?></code></td>
      <td><span class="badge badge-<?= $l['status']==='success'?'success':'danger' ?>"><?= h($l['status']) ?></span></td>
      <td style="font-size:.8rem;color:var(--text-muted)"><?= h(mb_substr($l['note']??'',0,60)) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

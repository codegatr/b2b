<?php
// admin/pages/update.php — Güncelleme Merkezi
requireAdmin();

$updater        = updater();
$currentVersion = $updater->getCurrentVersion();
$installedSha   = $updater->getInstalledSha();
$success = $error = '';
$migrationResults = [];

// ── POST Handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';

    if ($act === 'run_migrations') {
        $migrationResults = migrationRunAll();
        $ok   = count(array_filter($migrationResults, fn($r) => $r['ok']));
        $fail = count($migrationResults) - $ok;
        if (empty($migrationResults)) $success = 'Çalıştırılacak migration yok.';
        elseif (!$fail)              $success = "{$ok} migration başarıyla uygulandı.";
        else                          $error   = "{$ok} başarılı, {$fail} başarısız.";
    }

    if ($act === 'run_single') {
        $file = B2B_ROOT . '/migrations/' . basename($_POST['mfile'] ?? '');
        if (file_exists($file)) {
            $r = migrationRun($file);
            $migrationResults = [$r];
            if ($r['ok']) $success = $r['name'] . ' uygulandı.';
            else $error = $r['name'] . ' hatası: ' . $r['error'];
        }
    }

    if ($act === 'update_branch') {
        try {
            $result = $updater->updateFromBranch();
            if ($result['success']) {
                $fc   = is_array($result['files'] ?? null) ? count($result['files']) : 0;
                $sha  = $result['commit']['sha_short'] ?? '';
                $migr = $result['migrations'] ?? ['run'=>0,'errors'=>[]];
                $msg  = "✅ Güncelleme tamamlandı! {$fc} dosya. Commit: {$sha}";
                if ($migr['run'] > 0) $msg .= " · {$migr['run']} migration uygulandı.";
                if (!empty($migr['errors'])) $msg .= " ⚠️ " . implode(', ', $migr['errors']);
                $_SESSION['flash_admin'] = ['type'=>'success','msg'=>$msg];
                header('Location: ?page=update&done=1'); exit;
            } else { $error = $result['message'] ?? 'Güncelleme başarısız.'; }
        } catch (Exception $e) { $error = $e->getMessage(); }
    }

    if ($act === 'update_release') {
        $ver = trim($_POST['version'] ?? '');
        if ($ver) {
            try {
                $result = $updater->updateFromRelease($ver);
                if ($result['success']) {
                    $migrationResults = migrationRunAll();
                    $ok = count(array_filter($migrationResults, fn($r) => $r['ok']));
                    $success = "v{$ver} yüklendi! " . ($ok ? "{$ok} migration uygulandı." : '');
                } else { $error = $result['message'] ?? 'Başarısız.'; }
            } catch (Exception $e) { $error = $e->getMessage(); }
        }
    }

    if ($act === 'rollback') {
        $backup = trim($_POST['backup_file'] ?? '');
        if ($backup) {
            try {
                $result = $updater->rollback($backup);
                if ($result['success']) $success = 'Geri alma başarılı.';
                else $error = $result['message'] ?? 'Geri alma başarısız.';
            } catch (Exception $e) { $error = $e->getMessage(); }
        }
    }
}

// ── Veri ─────────────────────────────────────────────────────
$latestCommit  = null;
$releases      = [];
$fetchError    = '';
try { $latestCommit = $updater->getLatestCommit(); } catch (Exception $e) { $fetchError = $e->getMessage(); }
try { $releases = $updater->getReleases(5); } catch (Exception $e) {}

$backups         = $updater->getBackups();
$logs            = dbRows("SELECT * FROM b2b_update_log ORDER BY created_at DESC LIMIT 10");
$hasBranchUpdate = $latestCommit && ($latestCommit['sha'] !== $installedSha);
$migStatus       = migrationStatus();
$hasPending      = !empty($migStatus['pending']);
$justDone        = !empty($_GET['done']);
?>

<?php if ($justDone): ?>
<div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:2px solid #16a34a;border-radius:12px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;gap:16px">
  <div style="font-size:36px;line-height:1">✅</div>
  <div>
    <div style="font-weight:700;font-size:17px;color:#15803d;margin-bottom:4px">Güncelleme Başarıyla Tamamlandı!</div>
    <div style="font-size:13px;color:#166534">
      Sürüm: <strong>v<?= h($currentVersion) ?></strong>
      <?php if ($installedSha): ?> &nbsp;·&nbsp; Commit: <code style="background:rgba(0,0,0,.07);padding:2px 6px;border-radius:4px"><?= h(substr($installedSha,0,7)) ?></code><?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Başlık -->
<div class="page-header" style="margin-bottom:20px">
  <div>
    <h1 class="page-title">Güncelleme Merkezi</h1>
    <div style="display:flex;align-items:center;gap:10px;margin-top:4px;flex-wrap:wrap">
      <span style="font-size:13px;color:var(--text-muted)">Sürüm: <strong>v<?= h($currentVersion) ?></strong></span>
      <?php if ($installedSha): ?>
      <code style="font-size:12px;background:var(--bg);border:1px solid var(--border);padding:2px 8px;border-radius:4px"><?= h(substr($installedSha,0,7)) ?></code>
      <?php endif; ?>
      <?php if ($hasBranchUpdate): ?>
      <span style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:99px;font-size:11px;font-weight:700;padding:2px 10px;animation:pulse-red 2s infinite">↑ Güncelleme Var</span>
      <?php else: ?>
      <span style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;border-radius:99px;font-size:11px;font-weight:600;padding:2px 10px">✓ Sistem Güncel</span>
      <?php endif; ?>
      <?php if ($hasPending): ?>
      <span style="background:#fffbeb;color:#d97706;border:1px solid #fed7aa;border-radius:99px;font-size:11px;font-weight:700;padding:2px 10px">⚠ <?= count($migStatus['pending']) ?> migration bekliyor</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success" style="margin-bottom:16px"><?= h($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"  style="margin-bottom:16px"><?= h($error) ?></div><?php endif; ?>
<?php if ($fetchError): ?><div class="alert alert-warning" style="margin-bottom:16px">GitHub bağlantı hatası: <?= h($fetchError) ?> — <a href="?page=settings&tab=github">Ayarlar</a></div><?php endif; ?>

<!-- Migration sonuçları -->
<?php if (!empty($migrationResults)): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3 class="card-title">Migration Sonuçları</h3></div>
  <div class="card-body" style="padding:8px 16px">
    <?php foreach ($migrationResults as $mr): ?>
    <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
      <span style="font-size:16px"><?= $mr['ok']?'✅':'❌' ?></span>
      <code style="flex:1;font-size:12px;color:var(--text-2)"><?= h($mr['name']) ?></code>
      <?php if (!$mr['ok']): ?><span style="color:var(--danger);font-size:12px"><?= h($mr['error']) ?></span><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">
<div>

<!-- Branch Güncelleme -->
<div class="card" style="margin-bottom:16px;border-top:3px solid <?= $hasBranchUpdate?'#ed2939':'#16a34a' ?>">
  <div class="card-header">
    <h3 class="card-title">🚀 Branch Güncelleme <span style="font-size:11px;font-weight:400;color:var(--text-muted)">main</span></h3>
    <?php if ($hasBranchUpdate): ?>
    <span class="badge badge-danger">Yeni Commit</span>
    <?php elseif ($latestCommit): ?>
    <span class="badge badge-success">Güncel</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if ($latestCommit): ?>
    <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px 14px;margin-bottom:14px">
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px">SON COMMİT</div>
      <div style="font-weight:600;font-size:13px;margin-bottom:3px"><?= h(mb_substr($latestCommit['message'],0,75)) ?></div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px">
        <span style="font-size:12px;color:var(--text-muted)"><?= h($latestCommit['author']) ?> — <?= date('d.m.Y H:i', strtotime($latestCommit['date'])) ?></span>
        <code style="font-size:11px;background:var(--surface);border:1px solid var(--border);padding:2px 7px;border-radius:4px"><?= h($latestCommit['sha_short']) ?></code>
      </div>
    </div>
    <?php if ($hasBranchUpdate): ?>
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
      Yüklü: <code style="background:var(--bg);padding:1px 5px;border-radius:3px"><?= h(substr($installedSha,0,7)?:'—') ?></code>
      → GitHub: <code style="background:var(--bg);padding:1px 5px;border-radius:3px"><?= h($latestCommit['sha_short']) ?></code>
    </div>
    <?php endif; ?>
    <form method="POST" id="form-update">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="update_branch">
      <button type="submit" id="btn-update" class="btn btn-primary" style="width:100%;height:42px" onclick="return doUpdate(this)">
        <?= $hasBranchUpdate ? '⬆ Güncellemeyi Yükle' : '🔄 Yeniden Yükle' ?>
      </button>
    </form>
    <!-- Progress -->
    <div id="update-wrap" style="display:none;margin-top:12px">
      <div style="height:6px;background:var(--border);border-radius:6px;overflow:hidden">
        <div id="update-bar" style="height:6px;width:0;background:linear-gradient(90deg,#ed2939,#f59e0b);border-radius:6px;transition:width 6s cubic-bezier(.1,0,.1,1)"></div>
      </div>
      <div id="update-msg" style="font-size:12px;color:var(--text-muted);margin-top:8px;text-align:center">Hazırlanıyor...</div>
    </div>
    <?php else: ?>
    <p style="color:var(--text-muted);font-size:13px">GitHub bağlantısı kurulamadı. <a href="?page=settings&tab=github">Token Ayarla →</a></p>
    <?php endif; ?>
  </div>
</div>

<!-- Migration Durumu -->
<div class="card" style="border-top:3px solid <?= $hasPending?'#f59e0b':'#16a34a' ?>">
  <div class="card-header">
    <h3 class="card-title">🗄 Veritabanı Migrationları</h3>
    <span class="badge badge-<?= $hasPending?'warning':'success' ?>"><?= $hasPending?count($migStatus['pending']).' bekliyor':'Güncel' ?></span>
  </div>
  <div class="card-body">
    <?php if ($hasPending): ?>
    <div style="margin-bottom:12px">
      <?php foreach ($migStatus['pending'] as $pName): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:#fffbeb;border:1px solid #fed7aa;border-radius:6px;margin-bottom:6px">
        <span style="color:#f59e0b;font-size:14px">⏳</span>
        <code style="flex:1;font-size:12px;color:#92400e"><?= h($pName) ?></code>
        <form method="POST" style="display:inline">
          <?= csrfField() ?>
          <input type="hidden" name="form_action" value="run_single">
          <input type="hidden" name="mfile" value="<?= h($pName) ?>">
          <button class="btn btn-sm btn-secondary" onclick="return confirm('<?= h($pName) ?> çalıştırılsın mı?')">Uygula</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="run_migrations">
      <button class="btn btn-warning" style="width:100%" onclick="return confirm('Tüm bekleyen migrationlar uygulanacak. Devam?')">
        ⚡ Tümünü Uygula (<?= count($migStatus['pending']) ?>)
      </button>
    </form>
    <?php else: ?>
    <div style="text-align:center;padding:8px 0;color:var(--text-muted);font-size:13px">
      ✓ Tüm migrationlar uygulanmış
    </div>
    <?php endif; ?>
    <?php if (!empty($migStatus['done'])): ?>
    <details style="margin-top:12px">
      <summary style="font-size:12px;color:var(--text-muted);cursor:pointer;user-select:none">Uygulananlar (<?= count($migStatus['done']) ?>)</summary>
      <div style="margin-top:8px">
        <?php foreach ($migStatus['done'] as $d): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid var(--border)">
          <span style="color:#16a34a">✓</span>
          <code style="font-size:11px;color:var(--text-muted)"><?= h($d) ?></code>
        </div>
        <?php endforeach; ?>
      </div>
    </details>
    <?php endif; ?>
  </div>
</div>

</div><!-- /sol -->
<div>

<!-- Release -->
<?php if (!empty($releases)): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3 class="card-title">🏷 Release Sürümleri</h3></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Sürüm</th><th>Tarih</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($releases as $r): ?>
      <tr>
        <td class="fw-600"><?= h($r['tag_name']) ?></td>
        <td style="font-size:12px;color:var(--text-muted)"><?= date('d.m.Y', strtotime($r['published_at'])) ?></td>
        <td>
          <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="update_release">
            <input type="hidden" name="version" value="<?= h(ltrim($r['tag_name'],'v')) ?>">
            <button class="btn btn-ghost btn-sm" onclick="return confirm('<?= h($r['tag_name']) ?> yüklenecek. Devam?')">Yükle</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Yedekler -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3 class="card-title">💾 Yedekler</h3></div>
  <div class="card-body" style="padding:8px 0">
    <?php if (empty($backups)): ?>
    <p style="color:var(--text-muted);font-size:13px;padding:8px 16px">Yedek yok. Güncelleme yapıldığında otomatik alınır.</p>
    <?php else: ?>
    <?php foreach ($backups as $b): ?>
    <div style="display:flex;align-items:center;gap:8px;padding:8px 16px;border-bottom:1px solid var(--border)">
      <div style="flex:1;font-size:12px;color:var(--text-2)"><?= h(basename($b)) ?></div>
      <div style="font-size:11px;color:var(--text-muted)"><?= round(filesize($b)/1024) ?>KB</div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="rollback">
        <input type="hidden" name="backup_file" value="<?= h(basename($b)) ?>">
        <button class="btn btn-ghost btn-sm" style="color:var(--danger)" onclick="return confirm('Bu yedeğe dönülecek. Emin misiniz?')">Geri Dön</button>
      </form>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Log -->
<?php if ($logs): ?>
<div class="card">
  <div class="card-header"><h3 class="card-title">📋 Güncelleme Geçmişi</h3></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Tarih</th><th>Commit</th><th>Durum</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $l): ?>
      <tr>
        <td style="font-size:12px;color:var(--text-muted)"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></td>
        <td><code style="font-size:11px"><?= h(substr($l['to_version'],0,7)) ?></code>
          <div style="font-size:11px;color:var(--text-muted)"><?= h(mb_substr($l['note']??'',0,50)) ?></div></td>
        <td><span class="badge badge-<?= $l['status']==='success'?'success':'danger' ?>"><?= $l['status']==='success'?'OK':'Hata' ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

</div><!-- /sağ -->
</div><!-- /grid -->

<script>
function doUpdate(btn) {
    if (!confirm('Güncelleme yapılacak. Yedek otomatik alınır. Devam?')) return false;
    btn.disabled = true;
    document.getElementById('update-wrap').style.display = 'block';
    const bar = document.getElementById('update-bar');
    const msg = document.getElementById('update-msg');
    const steps = [
        [0, '🔗 GitHub\'a bağlanılıyor...'],
        [1000, '📦 Dosyalar indiriliyor...'],
        [2500, '📂 Dosyalar güncelleniyor...'],
        [4500, '🗄 Migration\'lar uygulanıyor...'],
        [5500, '✅ Tamamlanıyor...'],
    ];
    setTimeout(() => { bar.style.width = '100%'; }, 50);
    steps.forEach(([t, m]) => setTimeout(() => {
        msg.textContent = m;
        btn.textContent = m;
    }, t));
    return true;
}
</script>

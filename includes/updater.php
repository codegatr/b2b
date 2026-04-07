<?php
/**
 * CODEGA B2B — GitHub Releases Güncelleme Motoru
 * Repo: codegatr/b2b (admin panel üzerinden token girilir)
 */

class B2BUpdater {
    private string $repo;
    private string $token;
    private string $root;
    private string $currentVersion;

    public function __construct() {
        $this->root           = B2B_ROOT;
        $vfile = B2B_ROOT . '/version.txt';
        $this->currentVersion = file_exists($vfile) ? trim(file_get_contents($vfile)) : (defined('B2B_VERSION') ? B2B_VERSION : '1.0.0');
        $this->repo           = setting('github_repo', 'codegatr/b2b');
        $this->token          = setting('github_token', '');
    }

    /** GitHub API'den son release bilgisini getir */
    public function getLatestRelease(): ?array {
        $url = "https://api.github.com/repos/{$this->repo}/releases/latest";
        return $this->githubRequest($url);
    }

    /** Tüm release'leri getir */
    public function getReleases(int $limit = 10): array {
        $url  = "https://api.github.com/repos/{$this->repo}/releases?per_page={$limit}";
        $data = $this->githubRequest($url);
        // 404 → repo var ama release yok → boş dizi
        return is_array($data) ? $data : [];
    }

    /** Mevcut versiyonu getir */
    public function getCurrentVersion(): string {
        return $this->currentVersion;
    }

    /** Güncelleme var mı? */
    public function hasUpdate(): bool {
        $latest = $this->getLatestRelease();
        if (!$latest) return false;
        $latestTag = ltrim($latest['tag_name'] ?? '', 'v');
        return version_compare($latestTag, $this->currentVersion, '>');
    }

    /** Güncellemeyi indir ve uygula */
    public function update(string $targetVersion): array {
        // 1. Release bul
        $releases = $this->getReleases();
        $release  = null;
        foreach ($releases as $r) {
            if (ltrim($r['tag_name'], 'v') === ltrim($targetVersion, 'v')) {
                $release = $r;
                break;
            }
        }
        if (!$release) return ['ok'=>false, 'success'=>false, 'message'=>"$targetVersion versiyonu bulunamadı."];

        // 2. ZIP asset'ini bul
        $zipUrl = null;
        foreach ($release['assets'] ?? [] as $asset) {
            if (str_ends_with($asset['name'], '.zip')) {
                $zipUrl = $asset['browser_download_url'];
                break;
        }}
        if (!$zipUrl) return ['ok'=>false, 'success'=>false, 'message'=>'ZIP dosyası bulunamadı.'];

        // 3. ZIP indir
        $tmpZip = sys_get_temp_dir() . '/b2b_update_' . time() . '.zip';
        if (!$this->downloadFile($zipUrl, $tmpZip)) {
            return ['ok'=>false, 'success'=>false, 'message'=>'ZIP indirilemedi.'];
        }

        // 4. Backup al
        $backupPath = $this->backup();
        if (!$backupPath) return ['ok'=>false, 'success'=>false, 'message'=>'Backup alınamadı.'];

        // 5. manifest.json oku
        $manifest = $this->readManifestFromZip($tmpZip);

        // 6. Dosyaları güncelle
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            return ['ok'=>false, 'success'=>false, 'message'=>'ZIP açılamadı.'];
        }

        $updated = [];
        if ($manifest) {
            // Sadece manifest'teki dosyaları güncelle
            foreach ($manifest as $file) {
                $idx = $zip->locateName($file);
                if ($idx !== false) {
                    $destPath = $this->root . '/' . ltrim($file, '/');
                    @mkdir(dirname($destPath), 0755, true);
                    file_put_contents($destPath, $zip->getFromIndex($idx));
                    $updated[] = $file;
                }
            }
        } else {
            // manifest.json yoksa tüm ZIP'i çıkar (install/ hariç)
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_starts_with($name, 'install/')) continue;
                if (str_ends_with($name, '/')) {
                    @mkdir($this->root . '/' . $name, 0755, true);
                    continue;
                }
                $destPath = $this->root . '/' . $name;
                @mkdir(dirname($destPath), 0755, true);
                file_put_contents($destPath, $zip->getFromIndex($i));
                $updated[] = $name;
            }
        }
        $zip->close();
        @unlink($tmpZip);

        // 7. DB migration çalıştır (varsa)
        $this->runMigrations($targetVersion);

        // 8. config.php versiyon güncelle
        $cfgFile = $this->root . '/config.php';
        $cfgContent = file_get_contents($cfgFile);
        $cfgContent = preg_replace("/'version'\s*=>\s*'[^']+'/", "'version' => '$targetVersion'", $cfgContent);
        file_put_contents($cfgFile, $cfgContent);

        // 9. Log kaydet
        dbInsert(
            "INSERT INTO b2b_update_log (from_version,to_version,status,note,updated_by,created_at) VALUES (?,?,'success',?,?,NOW())",
            [$this->currentVersion, $targetVersion, count($updated).' dosya güncellendi', $_SESSION['admin_id'] ?? 0]
        );

        return ['ok'=>true, 'success'=>true, 'message'=>"$targetVersion sürümüne güncellendi.", 'files'=>$updated, 'backup'=>basename($backupPath)];
    }

    /** Rollback — backup'a dön */
    public function rollback(string $backupFile): array {
        $backupPath = $this->root . '/backups/' . basename($backupFile);
        if (!file_exists($backupPath)) return ['ok'=>false, 'success'=>false, 'message'=>'Backup dosyası bulunamadı.'];

        $zip = new ZipArchive();
        if ($zip->open($backupPath) !== true) return ['ok'=>false, 'success'=>false, 'message'=>'Backup açılamadı.'];

        $extract = sys_get_temp_dir() . '/b2b_rollback_' . time();
        $zip->extractTo($extract);
        $zip->close();

        // Dosyaları geri yükle
        $this->copyDirectory($extract, $this->root);
        $this->removeDir($extract);

        dbInsert("INSERT INTO b2b_update_log (to_version,status,note,updated_by,created_at) VALUES ('rollback','rolledback',?,?,NOW())",
            ["$backupFile'den geri alındı", $_SESSION['admin_id'] ?? 0]);

        return ['ok'=>true, 'success'=>true, 'message'=>'Rollback başarılı.'];
    }

    /** Backup al */
    private function backup(): string|false {
        $backupDir = $this->root . '/backups';
        @mkdir($backupDir, 0755, true);

        $backupFile = $backupDir . '/backup_' . $this->currentVersion . '_' . date('YmdHis') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($backupFile, ZipArchive::CREATE) !== true) return false;

        // Kritik dosyaları yedekle
        $include = ['admin', 'includes', 'api', 'assets/css', 'assets/js', 'index.php', 'config.php', 'version.txt'];
        foreach ($include as $item) {
            $full = $this->root . '/' . $item;
            if (is_dir($full)) {
                $this->addDirToZip($zip, $full, $item);
            } elseif (is_file($full)) {
                $zip->addFile($full, $item);
            }
        }
        $zip->close();

        // Eski backup'ları temizle (en fazla 5 tut)
        $backups = glob($backupDir . '/backup_*.zip');
        usort($backups, fn($a,$b) => filemtime($b) - filemtime($a));
        foreach (array_slice($backups, 5) as $old) @unlink($old);

        return $backupFile;
    }

    /** Mevcut backup listesi */
    public function getBackups(): array {
        $dir = $this->root . '/backups';
        $files = glob($dir . '/backup_*.zip') ?: [];
        usort($files, fn($a,$b) => filemtime($b) - filemtime($a));
        return array_map(fn($f) => [
            'name' => basename($f),
            'size' => round(filesize($f)/1024, 1) . ' KB',
            'date' => date('d.m.Y H:i', filemtime($f)),
        ], $files);
    }

    /** ZIP içindeki manifest.json oku */
    private function readManifestFromZip(string $zipPath): ?array {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) return null;
        $content = $zip->getFromName('manifest.json');
        $zip->close();
        if (!$content) return null;
        return json_decode($content, true)['files'] ?? null;
    }

    /** GitHub API isteği */
    private function githubRequest(string $url): mixed {
        $ch = curl_init($url);
        $headers = ['Accept: application/vnd.github.v3+json', 'User-Agent: CODEGA-B2B-Updater/1.0'];
        if ($this->token) $headers[] = 'Authorization: Bearer ' . $this->token;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $result = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($err) return null; // cURL hatası
        if ($code === 404) return []; // bulunamadı (release yok)
        if ($code !== 200) return null;
        $decoded = json_decode($result, true);
        return $decoded;
    }

    /** Dosya indir */
    private function downloadFile(string $url, string $dest): bool {
        $ch = curl_init($url);
        $fp = fopen($dest, 'wb');
        $headers = ['User-Agent: CODEGA-B2B-Updater/1.0'];
        if ($this->token) $headers[] = 'Authorization: Bearer ' . $this->token;
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 120,
        ]);
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        return $ok && $code === 200;
    }

    /** DB migration'ları çalıştır */
    private function runMigrations(string $version): void {
        $sqlFile = $this->root . '/install/migrations/' . $version . '.sql';
        if (!file_exists($sqlFile)) return;
        $sql = file_get_contents($sqlFile);
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
            try { db()->exec($stmt . ';'); } catch (Exception) {}
        }
    }

    private function addDirToZip(ZipArchive $zip, string $dir, string $prefix): void {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isDir()) continue;
            $relative = $prefix . '/' . substr($file->getRealPath(), strlen($dir)+1);
            $zip->addFile($file->getRealPath(), str_replace('\\','/',$relative));
        }
    }

    private function copyDirectory(string $src, string $dst): void {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel  = substr($file->getRealPath(), strlen($src)+1);
            $dest = $dst . '/' . $rel;
            if ($file->isDir()) { @mkdir($dest, 0755, true); }
            else { @mkdir(dirname($dest), 0755, true); copy($file->getRealPath(), $dest); }
        }
    }

    private function removeDir(string $dir): void {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath()); }
        rmdir($dir);
    }
}

function updater(): B2BUpdater {
    static $u = null;
    if ($u === null) $u = new B2BUpdater();
    return $u;
}

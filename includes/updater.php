<?php
/**
 * CODEGA B2B — Güncelleme Motoru
 * İki mod:
 *   1) Branch ZIP  — main branch den direkt cek (Release gerektirmez)
 *   2) Release ZIP — GitHub Releases dan cek (manuel release gerekir)
 */

class B2BUpdater {

    private string $repo;
    private string $token;
    private string $root;
    private string $branch;

    public function __construct() {
        $this->root   = B2B_ROOT;
        $this->repo   = setting('github_repo',   'codegatr/b2b');
        $this->token  = setting('github_token',  '');
        $this->branch = setting('github_branch', 'main');
    }

    // Versiyon
    public function getCurrentVersion(): string {
        $f = $this->root . '/version.txt';
        return file_exists($f) ? trim(file_get_contents($f)) : '1.0.0';
    }

    // Son commit bilgisi
    public function getLatestCommit(): ?array {
        $url  = "https://api.github.com/repos/{$this->repo}/commits/{$this->branch}";
        $data = $this->githubRequest($url);
        if (!is_array($data) || !isset($data['sha'])) return null;
        return [
            'sha'       => $data['sha'],
            'sha_short' => substr($data['sha'], 0, 7),
            'message'   => $data['commit']['message'] ?? '',
            'author'    => $data['commit']['author']['name'] ?? '',
            'date'      => $data['commit']['author']['date'] ?? '',
            'url'       => $data['html_url'] ?? '',
        ];
    }

    public function getInstalledSha(): string {
        $f = $this->root . '/commit.txt';
        return file_exists($f) ? trim(file_get_contents($f)) : '';
    }

    public function hasBranchUpdate(): bool {
        $latest = $this->getLatestCommit();
        if (!$latest) return false;
        return $this->getInstalledSha() !== $latest['sha'];
    }

    // Release bilgisi (opsiyonel)
    public function getLatestRelease(): ?array {
        $url  = "https://api.github.com/repos/{$this->repo}/releases/latest";
        $data = $this->githubRequest($url);
        return (is_array($data) && isset($data['tag_name'])) ? $data : null;
    }

    public function getReleases(int $limit = 10): array {
        $url  = "https://api.github.com/repos/{$this->repo}/releases?per_page={$limit}";
        $data = $this->githubRequest($url);
        return is_array($data) ? $data : [];
    }

    /**
     * Branch den guncelle (ana mod — Release gerektirmez)
     * main a push et, admin panelden "Guncelle" ye bas.
     */
    public function updateFromBranch(): array {
        $commit = $this->getLatestCommit();
        if (!$commit) {
            return ['ok'=>false,'success'=>false,'message'=>'GitHub baglantisi kurulamadi. Token ve repo adini kontrol edin.'];
        }

        // Branch ZIP URL: github.com/owner/repo/archive/refs/heads/main.zip
        $zipUrl = "https://github.com/{$this->repo}/archive/refs/heads/{$this->branch}.zip";
        $tmpZip = sys_get_temp_dir() . '/b2b_branch_' . time() . '.zip';

        if (!$this->downloadFile($zipUrl, $tmpZip)) {
            return ['ok'=>false,'success'=>false,'message'=>'ZIP indirilemedi. Repo public mi? Token dogru mu?'];
        }

        $backupPath = $this->backup();
        if (!$backupPath) {
            @unlink($tmpZip);
            return ['ok'=>false,'success'=>false,'message'=>'Backup alinamadi.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            return ['ok'=>false,'success'=>false,'message'=>'ZIP acilamadi.'];
        }

        // GitHub branch ZIP: "repo-branch/" prefix ile gelir (ornegin b2b-main/)
        $prefix = '';
        for ($i = 0; $i < min($zip->numFiles, 5); $i++) {
            $name = $zip->getNameIndex($i);
            if (str_ends_with($name, '/') && substr_count($name, '/') === 1) {
                $prefix = $name;
                break;
            }
        }

        // manifest.json var mi?
        $manifestJson = $zip->getFromName($prefix . 'manifest.json');
        $manifest     = $manifestJson ? json_decode($manifestJson, true) : null;
        $targetFiles  = $manifest['files'] ?? null;

        $skip    = ['config.php', '.git/', 'uploads/', 'commit.txt'];
        $updated = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $zipPath = $zip->getNameIndex($i);
            if ($prefix && !str_starts_with($zipPath, $prefix)) continue;
            $relPath = $prefix ? substr($zipPath, strlen($prefix)) : $zipPath;
            if (!$relPath || str_ends_with($relPath, '/')) continue;
            if ($this->shouldSkip($relPath, $skip)) continue;
            if ($targetFiles !== null && !in_array($relPath, $targetFiles)) continue;

            $destPath = $this->root . '/' . $relPath;
            $destDir  = dirname($destPath);
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $content = $zip->getFromIndex($i);
            if (file_put_contents($destPath, $content) !== false) {
                $updated[] = $relPath;
            }
        }
        $zip->close();
        @unlink($tmpZip);

        // commit.txt kaydet
        file_put_contents($this->root . '/commit.txt', $commit['sha']);

        // DB cache'i güncelle — badge anında kapansın
        settingSave('update_latest_sha',  $commit['sha']);
        settingSave('update_last_check',  (string)time());
        settingClearCache();

        // version.txt — manifest'ten
        if (!empty($manifest['version'])) {
            file_put_contents($this->root . '/version.txt', $manifest['version']);
        }

        dbExec(
            "INSERT INTO b2b_update_log (from_version, to_version, status, note, created_at) VALUES (?,?,?,?,NOW())",
            [
                $this->getInstalledSha() ? substr($this->getInstalledSha(),0,7) : 'unknown',
                $commit['sha_short'],
                'success',
                'Branch: ' . $this->branch . ' — ' . mb_substr($commit['message'], 0, 100),
            ]
        );

        return [
            'ok'      => true,
            'success' => true,
            'message' => 'Guncelleme tamamlandi. ' . count($updated) . ' dosya guncellendi.',
            'commit'  => $commit,
            'files'   => $updated,
            'backup'  => basename($backupPath),
        ];
    }

    // Release den guncelle (opsiyonel)
    public function updateFromRelease(string $targetVersion): array {
        $releases = $this->getReleases();
        $release  = null;
        foreach ($releases as $r) {
            if (ltrim($r['tag_name'], 'v') === ltrim($targetVersion, 'v')) {
                $release = $r; break;
            }
        }
        if (!$release) return ['ok'=>false,'success'=>false,'message'=>"$targetVersion bulunamadi."];

        $zipUrl = null;
        foreach ($release['assets'] ?? [] as $a) {
            if (str_ends_with($a['name'], '.zip')) { $zipUrl = $a['browser_download_url']; break; }
        }
        if (!$zipUrl) return ['ok'=>false,'success'=>false,'message'=>'ZIP asset bulunamadi.'];

        $tmpZip = sys_get_temp_dir() . '/b2b_release_' . time() . '.zip';
        if (!$this->downloadFile($zipUrl, $tmpZip)) {
            return ['ok'=>false,'success'=>false,'message'=>'ZIP indirilemedi.'];
        }
        $backupPath = $this->backup();
        if (!$backupPath) { @unlink($tmpZip); return ['ok'=>false,'success'=>false,'message'=>'Backup alinamadi.']; }

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) { @unlink($tmpZip); return ['ok'=>false,'success'=>false,'message'=>'ZIP acilamadi.']; }

        $manifest    = $this->readManifestFromZip($tmpZip);
        $targetFiles = $manifest['files'] ?? null;
        $skip        = ['config.php', '.git/', 'uploads/'];
        $updated     = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $relPath = $zip->getNameIndex($i);
            if (str_ends_with($relPath, '/')) continue;
            if ($this->shouldSkip($relPath, $skip)) continue;
            if ($targetFiles !== null && !in_array($relPath, $targetFiles)) continue;
            $destPath = $this->root . '/' . $relPath;
            if (!is_dir(dirname($destPath))) mkdir(dirname($destPath), 0755, true);
            if (file_put_contents($destPath, $zip->getFromIndex($i)) !== false) $updated[] = $relPath;
        }
        $zip->close();
        @unlink($tmpZip);

        file_put_contents($this->root . '/version.txt', ltrim($targetVersion, 'v'));
        dbExec("INSERT INTO b2b_update_log (from_version, to_version, status, note, created_at) VALUES (?,?,?,?,NOW())",
            [$this->getCurrentVersion(), $targetVersion, 'success', 'Release: ' . $targetVersion]);

        return ['ok'=>true,'success'=>true,'message'=>"$targetVersion yuklendi.",'files'=>$updated,'backup'=>basename($backupPath)];
    }

    /** Geri donusluluk: update.php update() cagirabilir */
    public function update(string $targetVersion): array {
        return $this->updateFromRelease($targetVersion);
    }

    // Rollback
    public function rollback(string $backupFile): array {
        $backupPath = $this->root . '/storage/backups/' . basename($backupFile);
        if (!file_exists($backupPath)) return ['ok'=>false,'success'=>false,'message'=>'Backup bulunamadi.'];
        $zip = new ZipArchive();
        if ($zip->open($backupPath) !== true) return ['ok'=>false,'success'=>false,'message'=>'Backup acilamadi.'];
        $skip = ['config.php', '.git/'];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $relPath = $zip->getNameIndex($i);
            if (str_ends_with($relPath, '/')) continue;
            if ($this->shouldSkip($relPath, $skip)) continue;
            $destPath = $this->root . '/' . $relPath;
            if (!is_dir(dirname($destPath))) mkdir(dirname($destPath), 0755, true);
            file_put_contents($destPath, $zip->getFromIndex($i));
        }
        $zip->close();
        return ['ok'=>true,'success'=>true,'message'=>'Rollback tamamlandi.'];
    }

    public function getBackups(): array {
        $dir = $this->root . '/storage/backups';
        if (!is_dir($dir)) return [];
        $files = glob($dir . '/backup_*.zip') ?: [];
        rsort($files);
        return array_slice($files, 0, 5);
    }

    // Yardimcilar
    private function shouldSkip(string $relPath, array $skip): bool {
        foreach ($skip as $s) { if (str_starts_with($relPath, $s)) return true; }
        return false;
    }

    private function backup(): string|false {
        $dir = $this->root . '/storage/backups';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $file = $dir . '/backup_' . date('Ymd_His') . '.zip';
        $zip  = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE) !== true) return false;
        $skip = ['.git/', 'storage/backups/', 'uploads/'];
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $f) {
            if ($f->isDir()) continue;
            $relPath = str_replace($this->root . '/', '', $f->getPathname());
            if ($this->shouldSkip($relPath, $skip)) continue;
            $zip->addFile($f->getPathname(), $relPath);
        }
        $zip->close();
        $all = glob($dir . '/backup_*.zip') ?: [];
        rsort($all);
        foreach (array_slice($all, 5) as $old) @unlink($old);
        return $file;
    }

    private function readManifestFromZip(string $zipPath): ?array {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) return null;
        $c = $zip->getFromName('manifest.json');
        $zip->close();
        return $c ? json_decode($c, true) : null;
    }

    private function githubRequest(string $url): mixed {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        $headers = ['Accept: application/vnd.github.v3+json', 'User-Agent: CODEGA-B2B/1.0'];
        if ($this->token) $headers[] = 'Authorization: Bearer ' . $this->token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $result = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($err || $code === 0) return null;
        if ($code === 404) return [];
        if ($code !== 200) return null;
        return json_decode($result, true);
    }

    private function downloadFile(string $url, string $dest): bool {
        if (!function_exists('curl_init')) return false;
        $fp = fopen($dest, 'wb');
        if (!$fp) return false;
        $ch = curl_init($url);
        $headers = ['User-Agent: CODEGA-B2B/1.0'];
        if ($this->token) $headers[] = 'Authorization: Bearer ' . $this->token;
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        return in_array($code, [200, 302]);
    }
}

function updater(): B2BUpdater {
    static $u = null;
    if ($u === null) $u = new B2BUpdater();
    return $u;
}

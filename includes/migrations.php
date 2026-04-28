<?php
/**
 * Migration Sistemi
 * install/migrations/*.sql dosyalarını takip eder ve otomatik çalıştırır.
 */

/**
 * Çalışmış migration adlarını döndür (b2b_settings'den)
 */
function migrationGetRan(): array {
    $val = setting('migrations_ran', '');
    return $val ? json_decode($val, true) : [];
}

/**
 * Tüm migration dosyalarını döndür (sıralı)
 */
function migrationGetAll(): array {
    $dir = B2B_ROOT . '/migrations';
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/*.sql');
    if (!$files) return [];
    sort($files);
    return $files;
}

/**
 * Çalışmamış (bekleyen) migrationları döndür
 */
function migrationGetPending(): array {
    $ran = migrationGetRan();
    $pending = [];
    foreach (migrationGetAll() as $file) {
        $name = basename($file);
        if (!in_array($name, $ran)) {
            $pending[] = $file;
        }
    }
    return $pending;
}

/**
 * Tek bir migration dosyasını çalıştır
 * @return array{ok: bool, name: string, error: string}
 */
function migrationRun(string $filePath): array {
    $name = basename($filePath);
    try {
        $sql = file_get_contents($filePath);
        if (!$sql) return ['ok'=>false,'name'=>$name,'error'=>'Dosya okunamadı'];

        // SQL'i satır satır işle, yorum satırlarını atla, statement'lara böl
        $lines = explode("\n", $sql);
        $clean = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '--')) continue;
            $clean[] = $line;
        }
        $statements = array_filter(
            array_map('trim', explode(';', implode(' ', $clean)))
        );

        foreach ($statements as $stmt) {
            if (empty($stmt)) continue;
            try {
                db()->exec($stmt);
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                // Idempotent hatalar (kolon zaten var, tablo zaten var, indeks zaten var) yutulur
                // — migration tekrar çalıştırılırsa bile patlamaz.
                $idempotent = (
                    str_contains($msg, 'Duplicate column') ||
                    str_contains($msg, 'already exists') ||
                    str_contains($msg, 'Duplicate key name') ||
                    str_contains($msg, 'check that column/key exists')
                );
                if (!$idempotent) {
                    throw $e;
                }
                // Idempotent hatayı log'a yaz, devam et
                error_log("Migration $name idempotent skip: $msg");
            }
        }

        // Çalışmış olarak işaretle
        $ran = migrationGetRan();
        $ran[] = $name;
        settingSave('migrations_ran', json_encode(array_values(array_unique($ran))));

        return ['ok'=>true,'name'=>$name,'error'=>''];
    } catch (\Throwable $e) {
        return ['ok'=>false,'name'=>$name,'error'=>$e->getMessage()];
    }
}

/**
 * Tüm bekleyen migrationları çalıştır
 * @return array[] Her migration için sonuç
 */
function migrationRunAll(): array {
    $results = [];
    foreach (migrationGetPending() as $file) {
        $results[] = migrationRun($file);
    }
    return $results;
}

/**
 * Migration özet bilgisi (update sayfası için)
 */
function migrationStatus(): array {
    $all     = migrationGetAll();
    $ran     = migrationGetRan();
    $pending = [];
    $done    = [];
    foreach ($all as $file) {
        $name = basename($file);
        if (in_array($name, $ran)) {
            $done[] = $name;
        } else {
            $pending[] = $name;
        }
    }
    return [
        'total'   => count($all),
        'done'    => $done,
        'pending' => $pending,
    ];
}

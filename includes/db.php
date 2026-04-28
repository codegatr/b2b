<?php
/**
 * CODEGA B2B — Veritabanı Bağlantısı
 * PDO singleton, prepared statement yardımcıları
 */
defined('B2B_ROOT') || define('B2B_ROOT', dirname(__DIR__));

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = require B2B_ROOT . '/config.php';
    $d = $cfg['db'];
    $dsn = "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}";
    try {
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
    } catch (PDOException $e) {
        if (defined('B2B_DEBUG') && B2B_DEBUG) {
            die('DB Bağlantı Hatası: ' . $e->getMessage());
        }
        die('Sistem geçici olarak kullanılamıyor. Lütfen daha sonra tekrar deneyin.');
    }
    return $pdo;
}

/** Tek satır getir */
function dbRow(string $sql, array $params = []): ?array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Tüm satırları getir */
function dbRows(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Tek değer getir */
function dbVal(string $sql, array $params = []): mixed {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    return $row ? $row[0] : null;
}

/** INSERT / UPDATE / DELETE çalıştır, etkilenen satır döndür */
function dbExec(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/** INSERT, son insert ID döndür */
function dbInsert(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int)db()->lastInsertId();
}

/** Tablo'ya dinamik INSERT */
function dbInsertRow(string $table, array $data): int {
    $cols = implode(',', array_map(fn($k) => "`$k`", array_keys($data)));
    $plh  = implode(',', array_fill(0, count($data), '?'));
    return dbInsert("INSERT INTO `$table` ($cols) VALUES ($plh)", array_values($data));
}

/** Tablo'da dinamik UPDATE
 * Kullanım A — dizi where: dbUpdateRow('tablo', $data, ['id'=>5])
 * Kullanım B — eski format: dbUpdateRow('tablo', $data, 'id', 5)
 */
function dbUpdateRow(string $table, array $data, string|array $whereOrCol, mixed $whereVal = null): int {
    // Eski 4-arg format: ('table', $data, 'id', 5) → where = ['id'=>5]
    if (is_string($whereOrCol)) {
        $where = [$whereOrCol => $whereVal];
    } else {
        $where = $whereOrCol;
    }
    $sets   = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
    $conds  = implode(' AND ', array_map(fn($k) => "`$k`=?", array_keys($where)));
    $params = array_merge(array_values($data), array_values($where));
    return dbExec("UPDATE `$table` SET $sets WHERE $conds", $params);
}

/** Dahili: setting cache referansı */
function &_settingCache(): array {
    static $cache = [];
    return $cache;
}

/** Ayar oku */
function setting(string $key, mixed $default = ''): string {
    $cache = &_settingCache();
    if (array_key_exists($key, $cache)) return $cache[$key];
    $val = dbVal("SELECT sval FROM b2b_settings WHERE skey=?", [$key]);
    $cache[$key] = $val ?? $default;
    return $cache[$key];
}

/** Ayar cache'ini tamamen temizle */
function settingClearCache(): void {
    $cache = &_settingCache();
    $cache = [];
}

/** Ayar yaz */
function settingSave(string $key, string $val): void {
    dbExec("INSERT INTO b2b_settings (skey,sval,updated_at) VALUES (?,?,NOW())
            ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_at=NOW()", [$key, $val]);
    // Cache'i de güncelle (aynı request'te setting() doğru değeri dönsün)
    $cache = &_settingCache();
    $cache[$key] = $val;
}

/**
 * Asset cache busting için sürüm string'i.
 *
 * Mantık:
 * - version.txt + dosyanın mtime'ı (varsa) → md5 ilk 10 hane
 * - version.txt update sistem tarafından güncelleniyor (her release'te yeni)
 * - Mtime ile aynı versiyon içinde dosya değişirse de URL değişir
 * - Sonuç: tarayıcı her gerçek değişiklikten sonra yeni dosyayı çeker,
 *   değişiklik yoksa cache'i kullanmaya devam eder.
 *
 * Kullanım:
 *   <link href="...main.css?v=<?= assetVersion('assets/css/main.css') ?>">
 *   <script src="...main.js?v=<?= assetVersion('assets/js/main.js') ?>"></script>
 */
function assetVersion(string $relativePath = ''): string {
    static $appVersion = null;
    if ($appVersion === null) {
        $vFile = defined('B2B_ROOT') ? B2B_ROOT . '/version.txt' : __DIR__ . '/../version.txt';
        $appVersion = is_file($vFile) ? trim((string) @file_get_contents($vFile)) : '1.0.0';
        if ($appVersion === '') $appVersion = '1.0.0';
    }
    if ($relativePath === '') return $appVersion;

    $absPath = (defined('B2B_ROOT') ? B2B_ROOT : __DIR__ . '/..') . '/' . ltrim($relativePath, '/');
    $mtime   = is_file($absPath) ? @filemtime($absPath) : 0;
    return substr(md5($appVersion . '|' . $mtime), 0, 10);
}

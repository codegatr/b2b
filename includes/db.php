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

/** Tablo'da dinamik UPDATE — $where dizi: ['col'=>'val', ...] */
function dbUpdateRow(string $table, array $data, array $where): int {
    $sets  = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
    $conds = implode(' AND ', array_map(fn($k) => "`$k`=?", array_keys($where)));
    $params = array_merge(array_values($data), array_values($where));
    return dbExec("UPDATE `$table` SET $sets WHERE $conds", $params);
}

/** Ayar oku */
function setting(string $key, mixed $default = ''): string {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    $val = dbVal("SELECT sval FROM b2b_settings WHERE skey=?", [$key]);
    $cache[$key] = $val ?? $default;
    return $cache[$key];
}

/** Ayar yaz */
function settingSave(string $key, string $val): void {
    dbExec("INSERT INTO b2b_settings (skey,sval,updated_at) VALUES (?,?,NOW())
            ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_at=NOW()", [$key, $val]);
}

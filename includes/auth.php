<?php
/**
 * CODEGA B2B — Auth / Oturum Yönetimi
 */
defined('B2B_ROOT') || define('B2B_ROOT', dirname(__DIR__));

function b2b_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $cfg = require B2B_ROOT . '/config.php';
        session_name('b2b_sess');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            ini_set('session.cookie_secure', '1');
        }
        session_start();
    }
}

// ──────────────────────────────────────────────────────────────
// BAYİ AUTH
// ──────────────────────────────────────────────────────────────

function dealerLogin(string $email, string $password): bool {
    $dealer = dbRow("SELECT * FROM b2b_dealers WHERE email=? AND is_active=1", [trim($email)]);
    if (!$dealer) return false;
    if (!password_verify($password, $dealer['password'])) return false;

    $_SESSION['dealer_id']   = $dealer['id'];
    $_SESSION['dealer_name'] = $dealer['company_name'] ?: ($dealer['first_name'].' '.$dealer['last_name']);
    $_SESSION['dealer_type'] = $dealer['type'];
    $_SESSION['dealer_email']= $dealer['email'];
    $_SESSION['dealer_pl']   = $dealer['price_list_id'];

    dbExec("UPDATE b2b_dealers SET last_login=NOW() WHERE id=?", [$dealer['id']]);
    session_regenerate_id(true);
    return true;
}

function dealerLogout(): void {
    unset($_SESSION['dealer_id'], $_SESSION['dealer_name'], $_SESSION['dealer_type'], $_SESSION['dealer_email'], $_SESSION['dealer_pl']);
    session_destroy();
}

function isDealer(): bool {
    return !empty($_SESSION['dealer_id']);
}

function requireDealer(): void {
    if (!isDealer()) {
        header('Location: ' . B2B_URL . '/?page=login&next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

function currentDealer(): ?array {
    if (!isDealer()) return null;
    static $dealer = null;
    if ($dealer === null) {
        $dealer = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$_SESSION['dealer_id']]);
    }
    return $dealer;
}

// ──────────────────────────────────────────────────────────────
// ADMİN AUTH
// ──────────────────────────────────────────────────────────────

function adminLogin(string $email, string $password): bool {
    $admin = dbRow("SELECT * FROM b2b_admin_users WHERE email=? AND is_active=1", [trim($email)]);
    if (!$admin) return false;
    if (!password_verify($password, $admin['password'])) return false;

    $_SESSION['admin_id']   = $admin['id'];
    $_SESSION['admin_name'] = $admin['name'];
    $_SESSION['admin_role'] = $admin['role'];

    dbExec("UPDATE b2b_admin_users SET last_login=NOW() WHERE id=?", [$admin['id']]);
    session_regenerate_id(true);
    return true;
}

function adminLogout(): void {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
    session_destroy();
}

function isAdmin(): bool {
    return !empty($_SESSION['admin_id']);
}

function requireAdmin(string $role = 'staff'): void {
    if (!isAdmin()) {
        header('Location: ' . B2B_URL . '/?page=login');
        exit;
    }
    $roles = ['staff'=>1,'admin'=>2,'superadmin'=>3];
    $current = $roles[$_SESSION['admin_role'] ?? 'staff'] ?? 0;
    $required = $roles[$role] ?? 1;
    if ($current < $required) {
        http_response_code(403);
        die('Bu işlem için yetkiniz yok.');
    }
}

function currentAdmin(): ?array {
    if (!isAdmin()) return null;
    static $admin = null;
    if ($admin === null) {
        $admin = dbRow("SELECT * FROM b2b_admin_users WHERE id=?", [$_SESSION['admin_id']]);
    }
    return $admin;
}

/** CSRF token üret ve oturuma kaydet */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function csrfCheck(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die(json_encode(['error' => 'Geçersiz istek.']));
    }
}

/** Şifre sıfırlama token'ı üret */
function generateResetToken(string $email, string $userType = 'dealer'): ?string {
    $table = $userType === 'admin' ? 'b2b_admin_users' : 'b2b_dealers';
    $user = dbRow("SELECT id FROM $table WHERE email=?", [$email]);
    if (!$user) return null;
    $token = bin2hex(random_bytes(40));
    settingSave("reset_{$userType}_{$user['id']}", $token . '|' . (time() + 3600));
    return $token;
}

/** IP adresi */
function clientIp(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Oturumdaki admin ID'sini döndür */
function adminId(): int {
    return (int)($_SESSION['admin_id'] ?? 0);
}

/** Oturumdaki bayi ID'sini döndür */
function dealerId(): int {
    return (int)($_SESSION['dealer_id'] ?? 0);
}

<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isDealer()) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

$db     = Database::getInstance();
$dealer = currentDealer();
$action = $_POST['action'] ?? '';

if ($action === 'mark_read') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->query(
            "UPDATE b2b_notifications SET is_read = 1 WHERE id = ? AND dealer_id = ?",
            [$id, $dealer['id']]
        );
    } else {
        $db->query(
            "UPDATE b2b_notifications SET is_read = 1 WHERE dealer_id = ?",
            [$dealer['id']]
        );
    }
    $unread = $db->fetch(
        "SELECT COUNT(*) AS cnt FROM b2b_notifications WHERE dealer_id = ? AND is_read = 0",
        [$dealer['id']]
    );
    echo json_encode(['ok' => true, 'unread' => (int)$unread['cnt']]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Geçersiz işlem']);
}

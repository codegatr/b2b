<?php
// admin/pages/logs.php - Son islem loglari
requireAdmin();

$type = $_GET['type'] ?? '';
$type = in_array($type, ['admin', 'dealer'], true) ? $type : '';
$limit = 150;

$where = ['1=1'];
$params = [];
if ($type !== '') {
    $where[] = 'a.user_type=?';
    $params[] = $type;
}
$w = implode(' AND ', $where);

$logs = dbRows(
    "SELECT a.*,
            au.name AS admin_name,
            d.company_name AS dealer_name,
            d.first_name AS dealer_first_name,
            d.last_name AS dealer_last_name
     FROM b2b_audit_log a
     LEFT JOIN b2b_admin_users au ON a.user_type='admin' AND au.id=a.user_id
     LEFT JOIN b2b_dealers d ON a.user_type='dealer' AND d.id=a.user_id
     WHERE $w
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT $limit",
    $params
);

$actionLabels = [
    'order_created' => 'Sipariş oluşturuldu',
    'order_approved' => 'Sipariş onaylandı',
    'order_cancelled' => 'Sipariş iptal edildi',
    'order_deleted' => 'Sipariş silindi',
    'order_archived' => 'Sipariş arşivlendi',
    'order_unarchived' => 'Sipariş arşivden çıkarıldı',
    'payment_created' => 'Ödeme bildirimi',
    'payment_approved' => 'Tahsilat onaylandı',
    'payment_deleted' => 'Tahsilat silindi',
    'payment_manual' => 'Manuel tahsilat',
    'dealer_created' => 'Bayi oluşturuldu',
    'dealer_updated' => 'Bayi güncellendi',
    'dealer_deleted' => 'Bayi silindi',
    'dealer_toggle' => 'Bayi durumu değişti',
    'product_created' => 'Ürün oluşturuldu',
    'product_updated' => 'Ürün güncellendi',
    'product_deleted' => 'Ürün silindi',
    'stock_update' => 'Stok güncellendi',
    'tahsilat' => 'Cari tahsilat',
    'tediye' => 'Cari tediye',
];

function logUserName(array $log): string {
    if (($log['user_type'] ?? '') === 'admin') {
        return $log['admin_name'] ?: ('Admin #' . (int)$log['user_id']);
    }
    $dealerName = $log['dealer_name'] ?: trim(($log['dealer_first_name'] ?? '') . ' ' . ($log['dealer_last_name'] ?? ''));
    return $dealerName ?: ('Bayi #' . (int)$log['user_id']);
}

function logPrettyJson(?string $json): string {
    if (!$json) return '';
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return $json;
    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Log Kaydı</h1>
    <p class="page-sub">Son yapılan işlemler</p>
  </div>
</div>

<div class="tab-bar">
  <a href="?page=logs" class="tab-item <?= $type===''?'active':'' ?>">Tümü</a>
  <a href="?page=logs&type=admin" class="tab-item <?= $type==='admin'?'active':'' ?>">Admin</a>
  <a href="?page=logs&type=dealer" class="tab-item <?= $type==='dealer'?'active':'' ?>">Bayi</a>
</div>

<div class="card">
  <div class="card-header">
    <h3 class="card-title">Son <?= $limit ?> İşlem</h3>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Tarih</th>
          <th>Kullanıcı</th>
          <th>İşlem</th>
          <th>Tablo / Kayıt</th>
          <th>IP</th>
          <th>Detay</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $log):
          $label = $actionLabels[$log['action']] ?? ucfirst(str_replace('_', ' ', $log['action']));
          $old = logPrettyJson($log['old_values'] ?? null);
          $new = logPrettyJson($log['new_values'] ?? null);
        ?>
        <tr>
          <td class="text-muted fs-12"><?= fmtDateTime($log['created_at']) ?></td>
          <td>
            <div class="fw-600"><?= h(logUserName($log)) ?></div>
            <div class="text-muted fs-12"><?= h($log['user_type']) ?> #<?= (int)$log['user_id'] ?></div>
          </td>
          <td><span class="badge badge-blue"><?= h($label) ?></span></td>
          <td>
            <div class="fw-600"><?= h($log['table_name'] ?: '-') ?></div>
            <?php if (!empty($log['record_id'])): ?><div class="text-muted fs-12">#<?= (int)$log['record_id'] ?></div><?php endif; ?>
          </td>
          <td class="text-muted fs-12"><?= h($log['ip'] ?: '-') ?></td>
          <td style="min-width:220px">
            <?php if ($old || $new): ?>
            <details>
              <summary style="cursor:pointer;color:var(--red);font-weight:600;font-size:12px">Göster</summary>
              <?php if ($old): ?><pre style="white-space:pre-wrap;font-size:11px;background:#f8fafc;border:1px solid var(--border);border-radius:6px;padding:8px;margin:8px 0 0;max-width:520px;overflow:auto"><?= h($old) ?></pre><?php endif; ?>
              <?php if ($new): ?><pre style="white-space:pre-wrap;font-size:11px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px;margin:8px 0 0;max-width:520px;overflow:auto"><?= h($new) ?></pre><?php endif; ?>
            </details>
            <?php else: ?>
            <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?>
        <tr><td colspan="6" class="text-center text-muted" style="padding:32px">Log kaydı yok.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

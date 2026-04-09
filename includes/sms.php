<?php
// includes/sms.php — NETGSM SMS Entegrasyonu

/**
 * NETGSM ile SMS gönder
 * @return array{ok: bool, message: string, code: string}
 */
function smsSend(string $phone, string $message): array {
    $user    = setting('netgsm_user', '');
    $pass    = setting('netgsm_pass', '');
    $header  = setting('netgsm_header', '');
    $enabled = setting('netgsm_enabled', '0');

    if ($enabled !== '1' || !$user || !$pass || !$header) {
        return ['ok' => false, 'message' => 'SMS modülü aktif değil veya ayarlar eksik.', 'code' => 'disabled'];
    }

    // Telefon numarasını temizle
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (str_starts_with($phone, '0')) $phone = '90' . substr($phone, 1);
    if (strlen($phone) === 10) $phone = '90' . $phone;
    if (strlen($phone) !== 12) {
        return ['ok' => false, 'message' => "Geçersiz telefon: $phone", 'code' => 'invalid_phone'];
    }

    // Mesajı kısalt (1 SMS = 160 karakter)
    $message = mb_substr($message, 0, 155);

    $url  = 'https://api.netgsm.com.tr/sms/send/get/';
    $params = http_build_query([
        'usercode'  => $user,
        'password'  => $pass,
        'gsmno'     => $phone,
        'message'   => $message,
        'msgheader' => $header,
        'dil'       => 'TR',
    ]);

    $ch = curl_init($url . '?' . $params);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        smsLog($phone, $message, false, "cURL: $curlErr");
        return ['ok' => false, 'message' => "Bağlantı hatası: $curlErr", 'code' => 'curl_error'];
    }

    // NETGSM başarılı yanıt: "00 XXXXXXXX" (00 = başarı)
    $code = trim(substr($response, 0, 2));
    $ok   = $code === '00';
    $msg  = $ok ? 'SMS gönderildi.' : netgsmErrorMsg($code);

    smsLog($phone, $message, $ok, $response);
    return ['ok' => $ok, 'message' => $msg, 'code' => $code];
}

/**
 * Yöneticiye SMS gönder (settings'teki admin numarasını kullan)
 */
function smsAdmin(string $message): array {
    $phone = setting('netgsm_admin_phone', '');
    if (!$phone) {
        return ['ok' => false, 'message' => 'Admin telefon numarası tanımlı değil.', 'code' => 'no_phone'];
    }
    return smsSend($phone, $message);
}

/**
 * Birden fazla numaraya SMS gönder
 */
function smsBulk(array $phones, string $message): array {
    $results = [];
    foreach ($phones as $phone) {
        $results[$phone] = smsSend($phone, $message);
    }
    return $results;
}

function netgsmErrorMsg(string $code): string {
    return match($code) {
        '20' => 'Üye bilgileri hatalı.',
        '30' => 'Geçersiz mesaj başlığı.',
        '40' => 'Mesaj metni boş.',
        '70' => 'Hatalı sorgulama.',
        '100' => 'API servisi hatası.',
        default => "NETGSM hata kodu: $code",
    };
}

function smsLog(string $phone, string $message, bool $ok, string $response): void {
    try {
        dbExec(
            "INSERT INTO b2b_sms_log (phone, message, status, response, created_at) VALUES (?,?,?,?,NOW())",
            [$phone, mb_substr($message, 0, 160), $ok ? 'success' : 'error', mb_substr($response, 0, 255)]
        );
    } catch (Exception $e) { /* Tablo yoksa sessizce geç */ }
}

/**
 * SMS şablonları — tetikleyici olaylara göre
 */
function smsTrigger(string $event, array $data = []): void {
    if (setting('netgsm_enabled', '0') !== '1') return;

    $siteName = setting('site_name', 'B2B');

    $msg = match($event) {
        'new_order' =>
            "{$siteName}: Yeni sipariş! #{$data['order_no']} - {$data['dealer']} - {$data['total']} ₺",

        'order_cancel_request' =>
            "{$siteName}: İptal talebi! #{$data['order_no']} - {$data['dealer']}: {$data['reason']}",

        'new_payment' =>
            "{$siteName}: Ödeme bildirimi! {$data['dealer']} - {$data['amount']} ₺ ({$data['method']})",

        'new_dealer' =>
            "{$siteName}: Yeni bayi başvurusu! {$data['company']} - {$data['email']}",

        'new_ticket' =>
            "{$siteName}: Destek talebi! {$data['dealer']}: {$data['subject']}",

        'low_stock' =>
            "{$siteName}: Kritik stok! {$data['product']} - {$data['stock']} adet kaldı.",

        default => null,
    };

    if (!$msg) return;

    // Hangi olaylar aktif?
    $activeEvents = json_decode(setting('netgsm_events', '[]'), true) ?: [];
    if (!in_array($event, $activeEvents)) return;

    smsAdmin($msg);
}

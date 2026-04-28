<?php
/**
 * CODEGA B2B — Rubikpara (PF Gateway) Ödeme Entegrasyonu
 * Dok: https://developer.rubikpara.com
 *
 * Akış (3D Secure):
 *   1. imza()          → nonce, signature, conversationId
 *   2. kartTokenize()  → cardToken
 *   3. threeDSOturum() → threeDSessionId
 *   4. threeDSBaslat() → htmlContent (bankaya yönlendirme)
 *   5. threeDSSonuc()  → mdStatus kontrolü
 *   6. odeme()         → provizyon
 */

class Rubikpara {

    private string $publicKey;
    private string $secretKey;    // Base64 encoded
    private string $merchantNo;
    private string $baseUrl;
    private bool   $testMode;

    public function __construct() {
        $this->publicKey  = setting('rubikpara_public_key',  '');
        $this->secretKey  = setting('rubikpara_secret_key',  '');
        $this->merchantNo = setting('rubikpara_merchant_no', '');
        $this->testMode   = (bool) setting('rubikpara_test_mode', '1');
        $this->baseUrl    = $this->testMode
            ? 'https://testpfapi.rubikpara.com'
            : 'https://pfapi.rubikpara.com';
    }

    // ─────────────────────────────────────────────────────────────
    // 1. İmza oluştur
    // ─────────────────────────────────────────────────────────────

    public function imza(?string $conversationId = null): array {
        $conversationId ??= substr(bin2hex(random_bytes(4)), 0, 8);

        if ($this->testMode) {
            // Test ortamı — API'den imza al
            $res = $this->post('/v1/Signatures/generate-test-signature', [
                'publicKey'      => $this->publicKey,
                'merchantNumber' => $this->merchantNo,
                'conversationId' => $conversationId,
            ], 'json', false);
            return $res;
        }

        // Production — kendi sunucumuzda HMAC-SHA256
        return $this->imzaHesapla($conversationId);
    }

    public function imzaHesapla(?string $conversationId = null): array {
        $secretKeyBytes = base64_decode($this->secretKey);
        $nonce          = (string) round(microtime(true) * 1000);
        $conversationId ??= substr(bin2hex(random_bytes(4)), 0, 8);

        // Adım 1: SecurityData
        $securityData = base64_encode(
            hash_hmac('sha256', $this->publicKey . $nonce, $secretKeyBytes, true)
        );

        // Adım 2: Signature
        $signature = base64_encode(
            hash_hmac('sha256',
                $this->secretKey . $conversationId . $nonce . $securityData,
                $secretKeyBytes, true
            )
        );

        return [
            'publicKey'      => $this->publicKey,
            'nonce'          => $nonce,
            'signature'      => $signature,
            'conversationId' => $conversationId,
            'merchantNumber' => $this->merchantNo,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 2. Kart tokenizasyon
    // ─────────────────────────────────────────────────────────────

    public function kartTokenize(
        string $cardNumber,
        string $expireMonth,
        string $expireYear,
        string $cvv
    ): array {
        // Rubikpara: ExpireYear 2 haneli, ExpireMonth 2 haneli (zero-padded)
        $expireMonth = str_pad(preg_replace('/\D/', '', $expireMonth), 2, '0', STR_PAD_LEFT);
        $expireYear  = preg_replace('/\D/', '', $expireYear);
        if (strlen($expireYear) === 4) $expireYear = substr($expireYear, -2);

        $auth = $this->imza();
        $data = [
            'CardNumber'     => preg_replace('/\D/', '', $cardNumber),
            'ExpireMonth'    => $expireMonth,
            'ExpireYear'     => $expireYear,
            'Cvv'            => $cvv,
            'PublicKey'      => $auth['publicKey'],
            'Nonce'          => $auth['nonce'],
            'Signature'      => $auth['signature'],
            'ConversationId' => $auth['conversationId'],
            'MerchantNumber' => $this->merchantNo,
        ];
        $res = $this->post('/v1/Tokens', $data, 'form');

        // Field isim varyantlarını destekle (PascalCase / camelCase / snake_case)
        $token = $res['cardToken']
              ?? $res['CardToken']
              ?? $res['token']
              ?? $res['Token']
              ?? $res['card_token']
              ?? '';

        if (!$token) {
            // API kabul etti ama token üretmedi — tam response'u kullanıcıya göster
            $dump = json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            error_log('Rubikpara /v1/Tokens cardToken boş — yanıt: ' . $dump);
            $msg = $res['message'] ?? $res['errorMessage'] ?? $res['description'] ?? '';
            throw new \Exception(
                'cardToken boş döndü' . ($msg ? " — $msg" : '') .
                ' | Yanıt: ' . substr($dump, 0, 400)
            );
        }

        $res['cardToken'] = $token; // normalize
        return $res;
    }

    // ─────────────────────────────────────────────────────────────
    // 3. 3D Secure oturum oluştur
    // ─────────────────────────────────────────────────────────────

    public function threeDSOturum(
        string $cardToken,
        float  $amount,
        int    $installment = 1,
        string $currency    = 'TRY'
    ): array {
        $auth = $this->imza();
        return $this->post('/v1/ThreeDS/getthreedsession', [
            'amount'           => $amount,
            'pointAmount'      => 0,
            'cardToken'        => $cardToken,
            'currency'         => $currency,
            'paymentType'      => 'Auth',
            'installmentCount' => $installment,
            'languageCode'     => 'tr',
        ], 'json', true, $auth);
    }

    // ─────────────────────────────────────────────────────────────
    // 4. 3D Secure başlat (HTML döner)
    // ─────────────────────────────────────────────────────────────

    public function threeDSBaslat(
        string $threeDSessionId,
        string $callbackUrl,
        string $cardHolderName,
        string $clientIp
    ): array {
        $auth = $this->imza();
        return $this->post('/v1/ThreeDS/init3ds', [
            'ThreeDSessionId' => $threeDSessionId,
            'CallbackUrl'     => $callbackUrl,
            'LanguageCode'    => 'tr',
            'ClientIpAddress' => $clientIp,
            'CardHolderName'  => $cardHolderName,
            'PublicKey'       => $auth['publicKey'],
            'Nonce'           => $auth['nonce'],
            'Signature'       => $auth['signature'],
            'ConversationId'  => $auth['conversationId'],
            'MerchantNumber'  => $this->merchantNo,
        ], 'form');
    }

    // ─────────────────────────────────────────────────────────────
    // 5. 3D Secure sonuç sorgula
    // ─────────────────────────────────────────────────────────────

    public function threeDSSonuc(string $threeDSessionId): array {
        $auth = $this->imza();
        return $this->post('/v1/ThreeDS/getthreedsessionresult', [
            'threeDSessionId' => $threeDSessionId,
            'languageCode'    => 'tr',
        ], 'json', true, $auth);
    }

    // ─────────────────────────────────────────────────────────────
    // 6. Provizyon (ödeme tamamla)
    // ─────────────────────────────────────────────────────────────

    public function odeme(
        string $threeDSessionId,
        float  $amount,
        int    $installment     = 1,
        float  $pointAmount     = 0.0
    ): array {
        $auth = $this->imza();
        return $this->post('/v1/Payments/provision', [
            'amount'           => $amount,
            'pointAmount'      => $pointAmount,
            'threeDSessionId'  => $threeDSessionId,
            'paymentType'      => 'Auth',
            'installmentCount' => $installment,
            'currency'         => 'TRY',
            'languageCode'     => 'tr',
        ], 'json', true, $auth);
    }

    // ─────────────────────────────────────────────────────────────
    // 7. İade
    // ─────────────────────────────────────────────────────────────

    public function iade(string $transactionId, float $amount): array {
        $auth = $this->imza();
        return $this->post('/v1/Payments/return', [
            'transactionId' => $transactionId,
            'amount'        => $amount,
            'languageCode'  => 'tr',
        ], 'json', true, $auth);
    }

    // ─────────────────────────────────────────────────────────────
    // Bağlantı testi
    // ─────────────────────────────────────────────────────────────

    public function baglantiTest(): array {
        $sonuclar = [];

        // Adım 1: İmza
        try {
            $auth = $this->imza();
            if (empty($auth['signature'])) throw new \Exception('İmza yanıtı eksik (signature alanı boş).');
            $sonuclar[] = ['adim'=>'1. İmza Oluşturma', 'ok'=>true,
                           'detay'=>'nonce: ' . substr($auth['nonce'] ?? '', 0, 13) . '… alındı'];
        } catch (\Exception $e) {
            $sonuclar[] = ['adim'=>'1. İmza Oluşturma', 'ok'=>false, 'detay'=>$e->getMessage()];
            return ['ok'=>false, 'message'=>'İmza adımında takıldı.', 'sonuclar'=>$sonuclar];
        }

        // Adım 2: Tokenize (Rubikpara resmi test kartı: Akbank Visa, 12/29 000)
        $cardToken = '';
        try {
            $res = $this->kartTokenize('4256691944867646', '12', '29', '000');
            $cardToken = $res['cardToken'];
            $sonuclar[] = ['adim'=>'2. Kart Tokenize (test kartı)', 'ok'=>true,
                           'detay'=>'cardToken: ' . substr($cardToken, 0, 16) . '…'];
        } catch (\Exception $e) {
            $sonuclar[] = ['adim'=>'2. Kart Tokenize (test kartı)', 'ok'=>false, 'detay'=>$e->getMessage()];
            return ['ok'=>false, 'message'=>'Tokenize adımında takıldı.', 'sonuclar'=>$sonuclar];
        }

        // Adım 3: BIN/Taksit sorgu
        try {
            $list = $this->taksitSorgula('425669', 100.00);
            $sonuclar[] = ['adim'=>'3. Taksit Sorgulama (BIN 425669)', 'ok'=>true,
                           'detay'=>count($list) . ' seçenek döndü'];
        } catch (\Exception $e) {
            $sonuclar[] = ['adim'=>'3. Taksit Sorgulama (BIN 425669)', 'ok'=>false, 'detay'=>$e->getMessage()];
            // Bu adım kritik değil, devam et
        }

        // Adım 4: 3DS oturum
        try {
            $res = $this->threeDSOturum($cardToken, 100.00, 1);
            $sid = $res['threeDSessionId'] ?? '';
            if (!$sid) throw new \Exception('threeDSessionId boş döndü.');
            $sonuclar[] = ['adim'=>'4. 3DS Oturum Oluşturma', 'ok'=>true,
                           'detay'=>'sessionId: ' . substr($sid, 0, 16) . '…'];
        } catch (\Exception $e) {
            $sonuclar[] = ['adim'=>'4. 3DS Oturum Oluşturma', 'ok'=>false, 'detay'=>$e->getMessage()];
            return ['ok'=>false, 'message'=>'3DS oturum adımında takıldı.', 'sonuclar'=>$sonuclar];
        }

        return ['ok'=>true, 'message'=>'Tüm uçtan uca adımlar başarılı.', 'sonuclar'=>$sonuclar];
    }

    public function ayarliMi(): bool {
        return !empty($this->publicKey) && !empty($this->merchantNo);
    }

    // ─────────────────────────────────────────────────────────────
    // HTTP yardımcıları
    // ─────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────
    // Taksit Sorgulama  (GET /v1/Installment)
    // Bayi kart numarasını girince çağrılır; banka/karta göre
    // taksit seçeneklerini ve komisyon oranlarını döndürür.
    // ─────────────────────────────────────────────────────────────

    public function taksitSorgula(string $bin, float $amount): array {
        if (!$this->ayarliMi()) {
            throw new \Exception('Rubikpara yapılandırılmamış.');
        }

        $bin = preg_replace('/\D/', '', $bin);
        if (strlen($bin) < 6) {
            throw new \Exception('Geçersiz BIN (en az 6 hane gerekli).');
        }
        $bin = substr($bin, 0, 6);

        $auth  = $this->imza();
        $query = http_build_query([
            'BinNumber' => $bin,
            'Amount'    => number_format($amount, 2, '.', ''),
        ]);

        $res = $this->get('/v1/Installment?' . $query, $auth);

        // Response normalize — Rubikpara alan isimleri çeşitli olabilir,
        // hangi varsa onu yakalayalım.
        $list = $res['installmentInfos']
              ?? $res['installments']
              ?? $res['data']
              ?? [];

        $out = [];
        foreach ($list as $row) {
            $count = (int)($row['installmentCount'] ?? $row['installmentNumber'] ?? $row['count'] ?? 0);
            if ($count < 1) continue;
            $total = (float)($row['totalAmount']
                          ?? $row['totalPayment']
                          ?? $row['total']
                          ?? $amount);
            $perInstall = $count > 0 ? round($total / $count, 2) : $total;
            $commission = round($total - $amount, 2);
            $rate       = $amount > 0 ? round(($commission / $amount) * 100, 2) : 0.0;
            $out[] = [
                'installmentCount'  => $count,
                'totalAmount'       => $total,
                'installmentAmount' => $perInstall,
                'commission'        => $commission,
                'commissionRate'    => $rate,
                'cardFamily'        => $row['cardFamilyName'] ?? $row['bankName'] ?? '',
            ];
        }
        usort($out, fn($a,$b) => $a['installmentCount'] <=> $b['installmentCount']);
        return $out;
    }

    // ─────────────────────────────────────────────────────────────
    // HTTP yardımcıları
    // ─────────────────────────────────────────────────────────────

    // Rubikpara API'si bazen Latin-1 (Windows-1254) encoding ile yanıt veriyor.
    // JSON spec UTF-8 zorunlu olsa da bazı .NET tabanlı sunucular bu kuralı
    // ihlal ediyor. Bayinin ekranında 'ba�ar�s�z' yerine 'başarısız' görmek
    // için body'i UTF-8'e zorla normalize ediyoruz.
    private function normalizeUtf8(string $body): string {
        if ($body === '' || mb_check_encoding($body, 'UTF-8')) return $body;
        // Önce Windows-1254 (Türkçe Windows), sonra ISO-8859-9 dene
        $converted = @mb_convert_encoding($body, 'UTF-8', 'Windows-1254, ISO-8859-9, UTF-8');
        return $converted !== false ? $converted : $body;
    }

    private function get(string $endpoint, array $auth): array {
        if (!function_exists('curl_init')) {
            throw new \Exception('cURL PHP eklentisi yüklü değil.');
        }

        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'PublicKey: '      . $this->publicKey,
                'Nonce: '          . $auth['nonce'],
                'Signature: '      . $auth['signature'],
                'ConversationId: ' . $auth['conversationId'],
                'MerchantNumber: ' . $this->merchantNo,
                'ClientIpAddress: ' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body    = curl_exec($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("Rubikpara cURL hatası [$endpoint]: $curlErr");
            throw new \Exception('cURL hatası: ' . $curlErr);
        }

        $body = $this->normalizeUtf8($body);
        $parsed = json_decode($body, true);
        if ($parsed === null) {
            $snippet = substr(trim((string)$body), 0, 500);
            error_log("Rubikpara non-JSON [$endpoint] HTTP $code: $snippet");
            throw new \Exception("Rubikpara $endpoint HTTP $code — yanıt: " . ($snippet ?: '(boş)'));
        }
        if (isset($parsed['isSucceed']) && $parsed['isSucceed'] === false) {
            $msg = $parsed['message'] ?? $parsed['errorMessage'] ?? 'API hatası';
            error_log("Rubikpara API error [$endpoint] HTTP $code: $msg");
            throw new \Exception($msg);
        }
        return $parsed;
    }

    private function post(
        string  $endpoint,
        array   $data,
        string  $type      = 'json',   // 'json' | 'form'
        bool    $withAuth  = false,
        ?array  $auth      = null
    ): array {
        if (!function_exists('curl_init')) {
            throw new \Exception('cURL PHP eklentisi yüklü değil.');
        }

        $url = $this->baseUrl . $endpoint;
        $ch  = curl_init($url);

        $headers = ['Accept: application/json'];

        if ($withAuth && $auth) {
            $headers[] = 'PublicKey: '      . ($auth['publicKey'] ?? $this->publicKey);
            $headers[] = 'Nonce: '          . $auth['nonce'];
            $headers[] = 'Signature: '      . $auth['signature'];
            $headers[] = 'ConversationId: ' . $auth['conversationId'];
            $headers[] = 'MerchantNumber: ' . $this->merchantNo;
            $headers[] = 'ClientIpAddress: ' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        }

        if ($type === 'json') {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($type === 'multipart') {
            // multipart/form-data — array verince cURL otomatik boundary üretir
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            // 'form' = application/x-www-form-urlencoded (varsayılan & en uyumlu)
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body    = curl_exec($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("Rubikpara cURL hatası [$endpoint]: $curlErr");
            throw new \Exception('cURL hatası: ' . $curlErr);
        }

        $body = $this->normalizeUtf8($body);
        $parsed = json_decode($body, true);
        if ($parsed === null) {
            $snippet = substr(trim((string)$body), 0, 500);
            error_log("Rubikpara non-JSON [$endpoint] HTTP $code: $snippet");
            throw new \Exception("Rubikpara $endpoint HTTP $code — yanıt: " . ($snippet ?: '(boş)'));
        }

        // Hata kontrolü
        if (isset($parsed['isSucceed']) && $parsed['isSucceed'] === false) {
            $msg = $parsed['message'] ?? $parsed['errorMessage'] ?? 'API hatası';
            error_log("Rubikpara API error [$endpoint] HTTP $code: $msg");
            throw new \Exception($msg);
        }

        return $parsed;
    }
}

function rubikpara(): Rubikpara {
    static $r = null;
    if ($r === null) $r = new Rubikpara();
    return $r;
}

/**
 * Rubikpara'dan dönen taksit seçeneklerini admin'in belirlediği oranlarla
 * zenginleştirir. Şu an sadece tek çekim için manuel oran (settings:
 * rubikpara_single_rate) destekleniyor.
 *
 * - Liste boşsa veya tek çekim option'u yoksa, baseAmount + admin% ile ekler
 * - Tek çekim option'u varsa ve admin oranı > 0 ise mevcut komisyonu override eder
 * - Diğer taksitler dokunulmaz (API'den geldiği gibi kalır)
 *
 * @param array $options taksitSorgula() çıktısı
 * @param float $baseAmount sipariş tutarı (komisyonsuz)
 * @return array zenginleştirilmiş seçenekler, installmentCount'a göre sıralı
 */
function rubikparaTaksitleriZenginlestir(array $options, float $baseAmount): array {
    $singleRate = (float) setting('rubikpara_single_rate', '0');

    $hasSingle = false;
    foreach ($options as &$o) {
        if ((int)$o['installmentCount'] === 1) {
            $hasSingle = true;
            if ($singleRate > 0) {
                $commission = round($baseAmount * $singleRate / 100, 2);
                $total      = round($baseAmount + $commission, 2);
                $o['totalAmount']       = $total;
                $o['installmentAmount'] = $total;
                $o['commission']        = $commission;
                $o['commissionRate']    = $singleRate;
            }
            break;
        }
    }
    unset($o);

    if (!$hasSingle) {
        $commission = $singleRate > 0 ? round($baseAmount * $singleRate / 100, 2) : 0.0;
        $total      = round($baseAmount + $commission, 2);
        $options[]  = [
            'installmentCount'  => 1,
            'totalAmount'       => $total,
            'installmentAmount' => $total,
            'commission'        => $commission,
            'commissionRate'    => $singleRate,
            'cardFamily'        => '',
        ];
    }

    usort($options, fn($a, $b) => $a['installmentCount'] <=> $b['installmentCount']);
    return $options;
}

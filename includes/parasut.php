<?php
/**
 * CODEGA B2B — Paraşüt API v4 Entegrasyonu
 * OAuth2 password grant — token cache DB'de tutulur
 */

class Parasut {
    private string $baseUrl  = 'https://api.parasut.com/v4';
    private string $authUrl  = 'https://api.parasut.com/oauth/token';
    private ?string $token   = null;
    private ?string $companyId = null;
    private bool $enabled    = false;

    public function __construct() {
        $this->enabled   = setting('parasut_enabled') === '1';
        $this->companyId = setting('parasut_company_id');
    }

    public function isEnabled(): bool {
        return $this->enabled && !empty($this->companyId);
    }

    /**
     * Convenience wrapper: sadece order_id ile fatura oluştur.
     * Order, items ve dealer'ı DB'den çeker, createInvoice() çağırır.
     * Hata yutar (false döner), çağıran taraf Throwable yakalamak zorunda kalmaz.
     */
    public function syncInvoice(int $orderId): ?string {
        if (!$this->isEnabled()) return null;
        try {
            $order = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$orderId]);
            if (!$order) return null;
            $items = dbRows("SELECT * FROM b2b_order_items WHERE order_id=?", [$orderId]);
            $dealer = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$order['dealer_id']]);
            if (!$dealer) return null;
            return $this->createInvoice($order, $items, $dealer);
        } catch (\Throwable $e) {
            error_log('Parasut::syncInvoice hatası: ' . $e->getMessage());
            return null;
        }
    }

    /** OAuth2 token al (cache'li) */
    private function getToken(): ?string {
        if ($this->token) return $this->token;

        // Cache'den oku
        $cached = setting('parasut_token_cache');
        if ($cached) {
            $parts = explode('|', $cached);
            if (count($parts) === 2 && (int)$parts[1] > time()) {
                $this->token = $parts[0];
                return $this->token;
            }
        }

        // Ön kontrol — eksik ayar varsa açık hata ver
        $clientId     = setting('parasut_client_id');
        $clientSecret = setting('parasut_client_secret');
        $email        = setting('parasut_email');
        $password     = setting('parasut_password');

        $missing = [];
        if (empty($clientId))     $missing[] = 'Client ID';
        if (empty($clientSecret)) $missing[] = 'Client Secret';
        if (empty($email))        $missing[] = 'E-posta';
        if (empty($password))     $missing[] = 'Şifre';
        if (!empty($missing)) {
            $this->lastErrorDetail = 'Eksik ayar: ' . implode(', ', $missing) . '. Settings → Paraşüt sekmesinde girilmesi gerekli.';
            return null;
        }

        // Yeni token al
        $response = $this->http('POST', $this->authUrl, [
            'grant_type'    => 'password',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'username'      => $email,
            'password'      => $password,
            'redirect_uri'  => 'urn:ietf:wg:oauth:2.0:oob',
        ], false);

        if (isset($response['access_token'])) {
            $this->token = $response['access_token'];
            $expiry = time() + ($response['expires_in'] ?? 7200) - 60;
            settingSave('parasut_token_cache', $this->token . '|' . $expiry);
            $this->lastErrorDetail = null;
            return $this->token;
        }

        // Token alınamadı — hata mesajını saklayıp null dön
        $this->lastErrorDetail = $this->getErrorDetail($response);
        return null;
    }

    /** HTTP isteği
     * $auth=false → OAuth token endpoint (form-urlencoded)
     * $auth=true  → API endpoint (JSON + Bearer token)
     */
    private function http(string $method, string $url, array $data = [], bool $auth = true): array {
        $ch = curl_init();

        // Token endpoint: application/x-www-form-urlencoded
        // API endpoint:   application/json
        if ($auth) {
            $headers = ['Content-Type: application/json', 'Accept: application/json'];
            $token = $this->getToken();
            if ($token) $headers[] = 'Authorization: Bearer ' . $token;
        } else {
            $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $auth ? json_encode($data) : http_build_query($data));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $result    = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $parsed = json_decode($result ?: '{}', true) ?? [];

        // HTTP kodunu ve curl hatasını parsed'a meta olarak ekle (debug için)
        $parsed['__meta'] = [
            'http_code'   => $httpCode,
            'curl_error'  => $curlError,
            'raw_snippet' => is_string($result) ? mb_substr($result, 0, 500) : '',
        ];

        // Sensitive field'leri loga yazmadan önce maskele (client_secret, password)
        $logReq = $data;
        if (isset($logReq['client_secret'])) $logReq['client_secret'] = '***';
        if (isset($logReq['password']))      $logReq['password'] = '***';

        $this->logAction(
            $method . ' ' . preg_replace('/^https?:\/\/[^\/]+/', '', $url) . ' [HTTP ' . $httpCode . ']',
            $logReq,
            $parsed,
            $httpCode > 0 && $httpCode < 400 ? 'success' : 'error'
        );

        return $parsed;
    }

    /**
     * Son hata mesajını oku — testConnection ve syncDealer/createInvoice
     * sonrası kullanıcıya gösterilebilir.
     */
    public ?string $lastErrorDetail = null;

    private function getErrorDetail(array $response): string {
        $meta = $response['__meta'] ?? [];
        $http = $meta['http_code'] ?? 0;
        $curl = $meta['curl_error'] ?? '';

        if ($curl) {
            return "Bağlantı hatası: $curl";
        }

        // Paraşüt token endpoint error formatı: {"error":"invalid_grant","error_description":"..."}
        if (!empty($response['error'])) {
            $err = $response['error'];
            $desc = $response['error_description'] ?? '';
            return "Paraşüt API hatası: $err" . ($desc ? " — $desc" : "") . " (HTTP $http)";
        }

        // API endpoint error formatı: {"errors":[{"title":"...","detail":"..."}]}
        if (!empty($response['errors']) && is_array($response['errors'])) {
            $msgs = [];
            foreach ($response['errors'] as $e) {
                $msgs[] = ($e['title'] ?? 'Hata') . ($e['detail'] ?? '' ? ': ' . $e['detail'] : '');
            }
            return "Paraşüt API hatası (HTTP $http): " . implode(' / ', $msgs);
        }

        return "Bilinmeyen hata (HTTP $http)";
    }

    private function endpoint(string $path): string {
        return "{$this->baseUrl}/{$this->companyId}/{$path}";
    }

    private function logAction(string $action, array $req, array $res, string $status): void {
        try {
            dbInsert(
                "INSERT INTO b2b_parasut_log (action,request,response,status,created_at) VALUES (?,?,?,?,NOW())",
                [$action, json_encode($req), json_encode($res), $status]
            );
        } catch (Exception) {}
    }

    // ──────────────────────────────────────────────────────────
    // CARI (Bayi = Müşteri)
    // ──────────────────────────────────────────────────────────

    /** Paraşüt'te müşteri oluştur veya güncelle. $dealer int ID veya tam dealer satırı olabilir. */
    public function syncDealer(int|array $dealer): ?string {
        if (!$this->isEnabled()) return null;

        // Eğer sadece ID geldiyse, tam kaydı çek
        if (is_int($dealer)) {
            $dealer = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$dealer]);
            if (!$dealer) return null;
        }

        $isKurumsal = $dealer['type'] === 'kurumsal';
        $name = $isKurumsal ? $dealer['company_name'] : ($dealer['first_name'] . ' ' . $dealer['last_name']);

        $body = ['data' => ['type' => 'contacts', 'attributes' => [
            'contact_type'       => $isKurumsal ? 'company' : 'person',
            'name'               => $name,
            'email'              => $dealer['email'],
            'phone'              => $dealer['phone'] ?? '',
            'city'               => $dealer['city'] ?? '',
            'district'           => $dealer['district'] ?? '',
            'account_type'       => 'customer',
            'tax_office'         => $dealer['tax_office'] ?? '',
            'tax_number'         => $dealer['tax_number'] ?? '',
            'is_abroad'          => false,
            'archived'           => false,
        ]]];

        if (!empty($dealer['parasut_contact_id'])) {
            $res = $this->http('PATCH', $this->endpoint("contacts/{$dealer['parasut_contact_id']}"), $body);
            return $dealer['parasut_contact_id'];
        }

        $res = $this->http('POST', $this->endpoint('contacts'), $body);
        return $res['data']['id'] ?? null;
    }

    // ──────────────────────────────────────────────────────────
    // FATURA
    // ──────────────────────────────────────────────────────────

    /** Sipariş onaylandığında satış faturası oluştur */
    public function createInvoice(array $order, array $items, array $dealer): ?string {
        if (!$this->isEnabled()) return null;

        // Müşteri Paraşüt'te yoksa oluştur
        $contactId = $dealer['parasut_contact_id'];
        if (!$contactId) {
            $contactId = $this->syncDealer($dealer);
            if ($contactId) {
                dbExec("UPDATE b2b_dealers SET parasut_contact_id=? WHERE id=?", [$contactId, $dealer['id']]);
            }
        }

        $lines = [];
        foreach ($items as $item) {
            $lines[] = [
                'type'       => 'sales_invoice_details',
                'attributes' => [
                    'quantity'         => (float)$item['qty'],
                    'unit_price'       => (float)$item['unit_price'],
                    'vat_rate'         => (float)$item['vat_rate'],
                    'discount_type'    => 'percentage',
                    'discount_value'   => (float)($item['discount_percent'] ?? 0),
                    'description'      => $item['product_name'],
                    'deliverable_type' => 'Product',
                ]
            ];
        }

        $body = ['data' => ['type' => 'sales_invoices', 'attributes' => [
            'item_type'         => 'invoice',
            'description'       => 'Sipariş No: ' . $order['order_no'],
            'issue_date'        => date('Y-m-d'),
            'due_date'          => $order['due_date'] ?? date('Y-m-d', strtotime('+30 days')),
            'currency'          => setting('currency', 'TRY'),
            'exchange_rate'     => 1,
            'invoice_discount_type' => 'percentage',
            'invoice_discount'  => 0,
            'billing_address'   => $order['shipping_address'] ?? '',
            'billing_phone'     => $dealer['phone'] ?? '',
            'tax_office'        => $dealer['tax_office'] ?? '',
            'tax_number'        => $dealer['tax_number'] ?? '',
            'city'              => $dealer['city'] ?? '',
            'is_archived'       => false,
            'cash_sale'         => false,
            'shipment_included' => false,
        ], 'relationships' => [
            'details' => ['data' => $lines],
            'contact' => ['data' => ['type'=>'contacts','id'=>$contactId]],
        ]]];

        $res = $this->http('POST', $this->endpoint('sales_invoices'), $body);
        return $res['data']['id'] ?? null;
    }

    // ──────────────────────────────────────────────────────────
    // TAHSİLAT
    // ──────────────────────────────────────────────────────────

    /** Ödeme kaydı oluştur */
    public function createPayment(string $invoiceId, float $amount, string $date, string $note = ''): ?string {
        if (!$this->isEnabled() || !$invoiceId) return null;

        $body = ['data' => ['type' => 'payments', 'attributes' => [
            'description'  => $note ?: 'Tahsilat',
            'payment_date' => $date,
            'amount'       => $amount,
            'currency'     => setting('currency', 'TRY'),
            'exchange_rate'=> 1,
        ], 'relationships' => [
            'payable' => ['data' => ['type'=>'SalesInvoice','id'=>$invoiceId]],
        ]]];

        $res = $this->http('POST', $this->endpoint('payments'), $body);
        return $res['data']['id'] ?? null;
    }

    // ──────────────────────────────────────────────────────────
    // ÜRÜN (Stok senkronizasyonu)
    // ──────────────────────────────────────────────────────────

    /** Ürün listesi getir (ilk 25) */
    public function getProducts(): array {
        if (!$this->isEnabled()) return [];
        $res = $this->http('GET', $this->endpoint('products?page[size]=25'));
        return $res['data'] ?? [];
    }

    /** Bağlantı testi */
    public function testConnection(): array {
        if (empty($this->companyId)) {
            throw new Exception('Firma ID girilmemiş.');
        }
        $token = $this->getToken();
        if (!$token) {
            $detail = $this->lastErrorDetail ?: 'bilinmeyen sebep';
            throw new Exception("Token alınamadı: $detail");
        }
        // Firma bilgisi çek — /me endpoint companyId'siz
        $r = $this->http('GET', "{$this->baseUrl}/me");
        if (empty($r['data'])) {
            $detail = $this->getErrorDetail($r);
            throw new Exception("Firma bilgisi alınamadı. $detail");
        }
        return $r;
    }

    // ──────────────────────────────────────────────────────────
    // ÜRÜN SENKRONİZASYONU
    // ──────────────────────────────────────────────────────────

    /**
     * Paraşüt'te ürün oluştur veya güncelle (parasut_product_id varsa update).
     * Return: Paraşüt product ID veya null.
     */
    public function syncProduct(int|array $product): ?string {
        if (!$this->isEnabled()) return null;

        if (is_int($product)) {
            $product = dbRow("SELECT * FROM b2b_products WHERE id=?", [$product]);
            if (!$product) return null;
        }

        $parasutId = $product['parasut_product_id'] ?? null;

        $attributes = [
            'name'               => $product['name'] ?? 'Ürün',
            'code'               => $product['sku']  ?? null,
            'vat_rate'           => (float)($product['vat_rate'] ?? 20),
            'unit'               => $product['unit'] ?? 'Adet',
            'currency'           => setting('currency', 'TRY'),
            'list_price'         => (float)($product['base_price'] ?? 0),
            'inventory_tracking' => false, // Stok takibi sistemimizde yapılıyor
        ];

        $body = ['data' => ['type' => 'products', 'attributes' => $attributes]];

        try {
            if ($parasutId) {
                // Güncelleme
                $body['data']['id'] = $parasutId;
                $res = $this->http('PUT', $this->endpoint("products/{$parasutId}"), $body);
            } else {
                // Yeni oluşturma
                $res = $this->http('POST', $this->endpoint('products'), $body);
                $newId = $res['data']['id'] ?? null;
                if ($newId && !empty($product['id'])) {
                    try {
                        dbExec("UPDATE b2b_products SET parasut_product_id=? WHERE id=?", [$newId, $product['id']]);
                    } catch (\Throwable $e) {
                        // Kolon yoksa sessiz geç
                    }
                }
            }
            return $res['data']['id'] ?? $parasutId;
        } catch (\Throwable $e) {
            error_log('Parasut syncProduct hatası: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Tüm aktif ürünleri toplu senkronize et. (array $results) [success, fail, total]
     */
    public function bulkSyncProducts(): array {
        if (!$this->isEnabled()) return ['success'=>0,'fail'=>0,'total'=>0];
        $products = dbRows("SELECT * FROM b2b_products WHERE is_active=1");
        $success = 0; $fail = 0;
        foreach ($products as $p) {
            $id = $this->syncProduct($p);
            if ($id) $success++; else $fail++;
        }
        return ['success'=>$success, 'fail'=>$fail, 'total'=>count($products)];
    }

    // ──────────────────────────────────────────────────────────
    // LİSTELEME (eşleme/önizleme için)
    // ──────────────────────────────────────────────────────────

    /**
     * Paraşüt'teki tek sayfa cari (contact) listesini çek.
     * Filter destekler: ['name'=>'Acme', 'tax_number'=>'1234567890', 'email'=>'a@b.c']
     * @return array{data: array, meta: array}
     */
    public function listContacts(int $page = 1, int $size = 100, array $filter = []): array {
        if (!$this->isEnabled()) return ['data'=>[], 'meta'=>['error'=>'Entegrasyon kapalı.']];
        $size = max(1, min(100, $size)); // Paraşüt max 100
        $url  = $this->endpoint('contacts') . '?page[number]=' . $page . '&page[size]=' . $size;
        foreach ($filter as $k => $v) {
            if ($v !== '' && $v !== null) $url .= '&filter[' . urlencode($k) . ']=' . urlencode((string)$v);
        }
        $r = $this->http('GET', $url);
        return [
            'data' => $r['data'] ?? [],
            'meta' => $r['meta'] ?? [],
            'err'  => empty($r['data']) ? $this->getErrorDetail($r) : null,
        ];
    }

    /**
     * Paraşüt'teki TÜM cari kayıtlarını otomatik pagination ile çek.
     * Şirket başına 1000 kayıt sınırı (10 sayfa * 100). Bunun üstü için
     * arama/filter kullanılmalı.
     */
    public function listAllContacts(int $maxPages = 10): array {
        $all = [];
        for ($p = 1; $p <= $maxPages; $p++) {
            $res = $this->listContacts($p, 100);
            if (empty($res['data'])) break;
            $all = array_merge($all, $res['data']);
            if (count($res['data']) < 100) break; // Son sayfa
        }
        return $all;
    }

    /** Paraşüt'teki tek sayfa ürün listesi */
    public function listProducts(int $page = 1, int $size = 100, array $filter = []): array {
        if (!$this->isEnabled()) return ['data'=>[], 'meta'=>['error'=>'Entegrasyon kapalı.']];
        $size = max(1, min(100, $size));
        $url  = $this->endpoint('products') . '?page[number]=' . $page . '&page[size]=' . $size;
        foreach ($filter as $k => $v) {
            if ($v !== '' && $v !== null) $url .= '&filter[' . urlencode($k) . ']=' . urlencode((string)$v);
        }
        $r = $this->http('GET', $url);
        return [
            'data' => $r['data'] ?? [],
            'meta' => $r['meta'] ?? [],
            'err'  => empty($r['data']) ? $this->getErrorDetail($r) : null,
        ];
    }

    /** Paraşüt'teki TÜM ürünleri otomatik pagination ile çek */
    public function listAllProducts(int $maxPages = 10): array {
        $all = [];
        for ($p = 1; $p <= $maxPages; $p++) {
            $res = $this->listProducts($p, 100);
            if (empty($res['data'])) break;
            $all = array_merge($all, $res['data']);
            if (count($res['data']) < 100) break;
        }
        return $all;
    }
}

/** Global paraşüt instance */
function parasut(): Parasut {
    static $p = null;
    if ($p === null) $p = new Parasut();
    return $p;
}

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

    /**
     * Entegrasyon kullanılabilir mi?
     * Eski 'parasut_enabled' flag'i veya
     * tüm gerekli credentials dolu ise true.
     */
    public function isEnabled(): bool {
        // Ayrı bir 'enabled' flag'i set edilmişse onu kullan
        if ($this->enabled) return !empty($this->companyId);

        // Yoksa credentials varlığına bak — tüm gerekli alanlar doluysa aktif
        return !empty($this->companyId)
            && !empty(setting('parasut_email'))
            && !empty(setting('parasut_password'))
            && !empty(setting('parasut_client_id'))
            && !empty(setting('parasut_client_secret'));
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

            $res = $this->createInvoiceFull($order, $items, $dealer);
            if (!$res || empty($res['id'])) return null;

            // parasut_invoice_id güncelle (eski mantık)
            dbExec("UPDATE b2b_orders SET parasut_invoice_id=?, parasut_synced_at=NOW() WHERE id=?",
                [$res['id'], $orderId]);

            // Fatura no varsa otomatik kaydet (manuel yoksa)
            if (!empty($res['invoice_no'])) {
                $existing = dbRow("SELECT invoice_no, invoice_no_source FROM b2b_orders WHERE id=?", [$orderId]);
                // Sadece henüz invoice_no boşsa veya zaten parasut kaynaklıysa güncelle
                if (empty($existing['invoice_no']) || $existing['invoice_no_source'] === 'parasut') {
                    dbExec(
                        "UPDATE b2b_orders SET invoice_no=?, invoice_no_source='parasut', invoice_no_updated_at=NOW(), invoice_no_updated_by=NULL WHERE id=?",
                        [$res['invoice_no'], $orderId]
                    );
                }
            }
            return $res['id'];
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
    /**
     * Paraşüt'te SalesInvoice oluştur.
     * @return array|null ['id'=>'12345', 'invoice_no'=>'SLS2026...'] veya null
     */
    public function createInvoiceFull(array $order, array $items, array $dealer): ?array {
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
        if (empty($res['data']['id'])) return null;
        return [
            'id'         => $res['data']['id'],
            'invoice_no' => $res['data']['attributes']['invoice_no'] ?? null,
        ];
    }

    /** Geriye uyumluluk — sadece ID döner */
    public function createInvoice(array $order, array $items, array $dealer): ?string {
        $r = $this->createInvoiceFull($order, $items, $dealer);
        return $r['id'] ?? null;
    }

    // ──────────────────────────────────────────────────────────
    // TAHSİLAT
    // ──────────────────────────────────────────────────────────

    /**
     * Ödeme kaydı oluştur — bir SalesInvoice'a ödeme bağlar.
     *
     * @param string $invoiceId Paraşüt sales_invoice ID
     * @param float  $amount    Tahsilat tutarı
     * @param string $date      Ödeme tarihi (Y-m-d)
     * @param string $note      Açıklama
     * @param string|null $accountId Hangi banka/kasa hesabına (yoksa settings'ten default alınır)
     */
    public function createPayment(string $invoiceId, float $amount, string $date, string $note = '', ?string $accountId = null): ?string {
        if (!$this->isEnabled() || !$invoiceId || $amount <= 0) return null;

        // Hesap ID — parametre yoksa settings'ten oku
        if (!$accountId) {
            $accountId = setting('parasut_collection_account_id', '') ?: null;
        }

        $body = ['data' => [
            'type' => 'payments',
            'attributes' => [
                'description'   => $note ?: 'Tahsilat',
                'payment_date'  => $date,
                'amount'        => $amount,
                'currency'      => setting('currency', 'TRY'),
                'exchange_rate' => 1,
            ],
            'relationships' => [
                'payable' => ['data' => ['type'=>'SalesInvoice', 'id'=>$invoiceId]],
            ],
        ]];

        if ($accountId) {
            $body['data']['relationships']['account'] = [
                'data' => ['type'=>'accounts', 'id'=>$accountId],
            ];
        }

        try {
            $res = $this->http('POST', $this->endpoint('payments'), $body);
            return $res['data']['id'] ?? null;
        } catch (\Throwable $e) {
            error_log('Paraşüt createPayment: ' . $e->getMessage());
            return null;
        }
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
     *
     * NOT: Paraşüt API V4 page[size] MAX 25 — fazlası 422 hata verir.
     *
     * @param string $sort Sıralama: 'name', '-name', 'created_at' vb (boş = default)
     * @return array{data: array, meta: array}
     */
    public function listContacts(int $page = 1, int $size = 25, array $filter = [], string $sort = ''): array {
        if (!$this->isEnabled()) return ['data'=>[], 'meta'=>['error'=>'Entegrasyon kapalı.']];
        $size = max(1, min(25, $size)); // Paraşüt API V4 hard limit: 25
        $url  = $this->endpoint('contacts') . '?page[number]=' . $page . '&page[size]=' . $size;
        foreach ($filter as $k => $v) {
            if ($v !== '' && $v !== null) $url .= '&filter[' . urlencode($k) . ']=' . urlencode((string)$v);
        }
        if ($sort !== '') $url .= '&sort=' . urlencode($sort);
        $r = $this->http('GET', $url);
        return [
            'data' => $r['data'] ?? [],
            'meta' => $r['meta'] ?? [],
            'err'  => empty($r['data']) ? $this->getErrorDetail($r) : null,
        ];
    }

    /**
     * Paraşüt'teki TÜM cari kayıtlarını otomatik pagination ile çek.
     * KRITIK FIX: "count<25 break" bug'ı kaldırıldı, sadece tamamen boşsa dur.
     */
    public function listAllContacts(int $maxPages = 200): array {
        $all = []; $totalPages = 0;
        for ($p = 1; $p <= $maxPages; $p++) {
            $res = $this->listContacts($p, 25, [], 'name');
            $cnt = count($res['data'] ?? []);
            if ($p === 1) {
                $totalPages = (int)($res['meta']['total_pages'] ?? 0);
            }
            if ($cnt === 0) break;
            $all = array_merge($all, $res['data']);
            if ($totalPages > 0 && $p >= $totalPages) break;
        }
        return $all;
    }

    /**
     * Cariler + metadata. Default sort=name (alfabetik).
     * BOŞ SAYFA RESCUE + 250ms throttle ile rate limit'e takılmaya karşı korumalı.
     */
    public function listAllContactsWithMeta(int $maxPages = 200, array $filter = [], string $sort = 'name'): array {
        $all = []; $totalCount = 0; $totalPages = 0; $perPage = 25;
        $rescued = 0;
        for ($p = 1; $p <= $maxPages; $p++) {
            if ($p > 1) usleep(250000); // 250ms throttle

            $res = $this->listContacts($p, 25, $filter, $sort);
            $cnt = count($res['data'] ?? []);

            if ($p === 1 && !empty($res['meta'])) {
                $totalCount = (int)($res['meta']['total_count'] ?? 0);
                $totalPages = (int)($res['meta']['total_pages'] ?? 0);
                $perPage    = (int)($res['meta']['per_page']    ?? 25);
            }

            // BOŞ SAYFA RESCUE: rate limit ihtimali
            if ($cnt === 0 && $totalPages > 0 && $p < $totalPages) {
                for ($retry = 1; $retry <= 3; $retry++) {
                    sleep(5);
                    $res = $this->listContacts($p, 25, $filter, $sort);
                    $cnt = count($res['data'] ?? []);
                    if ($cnt > 0) {
                        $rescued++;
                        break;
                    }
                }
            }

            if ($cnt === 0) break;
            $all = array_merge($all, $res['data']);
            if ($totalPages > 0 && $p >= $totalPages) break;
        }
        return [
            'data'        => $all,
            'fetched'     => count($all),
            'total_count' => $totalCount,
            'total_pages' => $totalPages,
            'per_page'    => $perPage,
            'rescued'     => $rescued,
        ];
    }

    /** Paraşüt'teki tek sayfa ürün listesi (page size max 25)
     *  İSTEK CACHE: aynı URL aynı request içinde tekrar çağrılırsa önbellekten döner.
     *  RETRY: rate limit (429) veya boş response durumunda 2 kez retry.
     */
    public function listProducts(int $page = 1, int $size = 25, array $filter = [], string $sort = '', bool $forceRefresh = false): array {
        if (!$this->isEnabled()) return ['data'=>[], 'meta'=>['error'=>'Entegrasyon kapalı.']];
        $size = max(1, min(25, $size));
        $url  = $this->endpoint('products') . '?page[number]=' . $page . '&page[size]=' . $size . '&include=category';
        foreach ($filter as $k => $v) {
            if ($v !== '' && $v !== null) $url .= '&filter[' . urlencode($k) . ']=' . urlencode((string)$v);
        }
        if ($sort !== '') $url .= '&sort=' . urlencode($sort);

        // ─── REQUEST-LEVEL CACHE ───
        // Tanı paneli aynı sayfada 2. kez aynı sayfayı çağırırsa Paraşüt rate
        // limit'e takılıyor → 0 döner. Cache ile bunu çözüyoruz.
        static $cache = [];
        if (!$forceRefresh && isset($cache[$url])) return $cache[$url];

        // ─── RETRY MANTIĞI ───
        // Boş data veya 429 (rate limit) durumunda 2 kez retry, her seferinde 500ms bekle
        $r = null;
        $attempts = 0;
        $maxAttempts = 4;
        while ($attempts < $maxAttempts) {
            $r = $this->http('GET', $url);
            $httpCode = $r['__meta']['http_code'] ?? 0;
            $dataCount = count($r['data'] ?? []);
            // Başarılı veya geri dönüş yok-veri-yok → çık
            if ($dataCount > 0 || $httpCode === 200) break;
            // Rate limit veya geçici hata → bekle ve retry
            if ($httpCode === 429 || $httpCode === 503 || $httpCode === 0) {
                $attempts++;
                usleep(min(8000000, 1000000 * $attempts));
                continue;
            }
            break;
        }

        // included[] (kategoriler) ile data'yı zenginleştir
        $catById = [];
        foreach (($r['included'] ?? []) as $inc) {
            if (($inc['type'] ?? '') === 'item_categories') {
                $catById[(string)$inc['id']] = $inc['attributes']['name'] ?? '';
            }
        }
        $data = $r['data'] ?? [];
        foreach ($data as &$d) {
            $catId = $d['relationships']['category']['data']['id'] ?? null;
            if ($catId && isset($catById[(string)$catId])) {
                $d['attributes']['_category_name'] = $catById[(string)$catId];
            }
        }
        unset($d);

        $meta = $r['meta'] ?? [];
        $meta['_url']       = $url;
        $meta['_http']      = $r['__meta']['http_code'] ?? null;
        $meta['_returned']  = count($data);
        $meta['_attempts']  = $attempts + 1;

        $result = [
            'data' => $data,
            'meta' => $meta,
            'err'  => empty($data) ? $this->getErrorDetail($r) : null,
        ];
        $isTransientEmpty = empty($data) && (($meta['_http'] ?? 0) === 0 || ($meta['_http'] ?? 0) >= 400);
        if (!$isTransientEmpty) {
            $cache[$url] = $result;
        }
        return $result;
    }

    /** Paraşüt'teki TÜM ürünleri otomatik pagination ile çek (KRITIK FIX: erken kesme bug'ı yok) */
    public function listAllProducts(int $maxPages = 200): array {
        $res = $this->listAllProductsWithMeta($maxPages);
        return $res['data'] ?? [];
    }

    /**
    /**
     * Ürünler + metadata + sayfa sayfa log (debug için).
     * - maxPages 200 = 5000 kayıt destek
     * - Sayfalar arası 250ms throttle (rate limit'ten kaçınmak için)
     * - BOŞ SAYFA RESCUE: total_pages > current_page ama 0 dönüyorsa rate limit'e
     *   takıldık demek → 5 saniye bekle ve aynı sayfayı tekrar dene (3 kez)
     * - RESULT CACHE: aynı filter+sort kombinasyonu aynı request içinde tekrar
     *   çağrılırsa önbellekten döner.
     */
    public function listAllProductsWithMeta(int $maxPages = 200, array $filter = [], string $sort = ''): array {
        // Result cache
        static $resultCache = [];
        $cacheKey = md5(serialize($filter) . '|' . $sort);
        if (isset($resultCache[$cacheKey])) return $resultCache[$cacheKey];

        $all = []; $totalCount = 0; $totalPages = 0; $perPage = 25;
        $pageLog = [];
        $rescued = 0; // rate limit rescue sayisi

        for ($p = 1; $p <= $maxPages; $p++) {
            // İlk sayfa hariç sayfalar arası 250ms throttle
            if ($p > 1) usleep(250000);

            $res = $this->listProducts($p, 25, $filter, $sort);
            $cnt = count($res['data'] ?? []);

            if ($p === 1 && !empty($res['meta'])) {
                $totalCount = (int)($res['meta']['total_count'] ?? 0);
                $totalPages = (int)($res['meta']['total_pages'] ?? 0);
                $perPage    = (int)($res['meta']['per_page']    ?? 25);
            }

            // ─── BOŞ SAYFA RESCUE ───
            // total_pages > current ama 0 döndü → büyük olasılıkla rate limit
            // 5 saniye bekle ve tekrar dene (3 kez)
            if ($cnt === 0 && $totalPages > 0 && $p < $totalPages) {
                for ($retry = 1; $retry <= 3; $retry++) {
                    sleep(5); // 5 saniye sabırlı bekleme
                    $res = $this->listProducts($p, 25, $filter, $sort, true);
                    $cnt = count($res['data'] ?? []);
                    if ($cnt > 0) {
                        $rescued++;
                        break;
                    }
                }
            }

            $pageLog[] = [
                'page'    => $p,
                'count'   => $cnt,
                'err'     => $res['err'] ?? null,
                'http'    => $res['meta']['_http'] ?? null,
                'attempts'=> $res['meta']['_attempts'] ?? 1,
            ];

            if ($cnt === 0) break;
            $all = array_merge($all, $res['data']);
            if ($totalPages > 0 && $p >= $totalPages) break;
        }

        $result = [
            'data'        => $all,
            'fetched'     => count($all),
            'total_count' => $totalCount,
            'total_pages' => $totalPages,
            'per_page'    => $perPage,
            'rescued'     => $rescued,
            'page_log'    => $pageLog,
        ];
        $resultCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Paraşüt'te ad/kod ile FUZZY arama — PHP-side filter (Paraşüt filter[name] EXACT yapıyor).
     * Tüm ürünleri çek, PHP'de contains ile filtrele.
     * Tek başına kullanılmıyor; parasut-mapping.php direkt liste çekip filtreliyor.
     * Geriye uyumluluk için bırakıldı.
     */
    public function searchProducts(string $query, int $maxResults = 100): array {
        if (!$this->isEnabled() || trim($query) === '') return [];

        // PHP-side fuzzy search — Paraşüt'ün filter[name] EXACT match yapıyor!
        $all = [];
        // Aktif + arşivli tüm ürünler
        $activeRes = $this->listAllProductsWithMeta(200);
        $all = array_merge($all, $activeRes['data']);
        $archivedRes = $this->listAllProductsWithMeta(200, ['archived' => 'true']);
        $all = array_merge($all, $archivedRes['data']);

        $q = mb_strtolower(trim($query));
        $matched = [];
        foreach ($all as $p) {
            $name = mb_strtolower($p['attributes']['name'] ?? '');
            $code = mb_strtolower($p['attributes']['code'] ?? '');
            if (str_contains($name, $q) || str_contains($code, $q)) {
                $matched[] = $p;
                if (count($matched) >= $maxResults) break;
            }
        }
        return $matched;
    }

    // ──────────────────────────────────────────────────────────
    // VKN SORGULAMA (e-fatura mı e-arşiv mi karar için)
    // ──────────────────────────────────────────────────────────

    /**
     * Vergi numarası Paraşüt e-fatura sisteminde kayıtlı mı?
     * Kayıtlıysa array dönülür (e_invoice_address, alias bilgisi), değilse boş.
     *
     * GET /v4/{company_id}/e_invoice_inboxes?filter[vkn]=XXXX
     *
     * @return array{registered: bool, address: ?string, raw: array}
     */
    public function checkVKN(string $vkn): array {
        if (!$this->isEnabled() || empty($vkn)) {
            return ['registered'=>false, 'address'=>null, 'raw'=>[]];
        }
        $vkn = preg_replace('/[^0-9]/', '', $vkn);
        $url = $this->endpoint('e_invoice_inboxes') . '?filter[vkn]=' . urlencode($vkn);
        $r = $this->http('GET', $url);

        $data = $r['data'] ?? [];
        if (!empty($data) && is_array($data) && isset($data[0]['attributes']['e_invoice_address'])) {
            return [
                'registered' => true,
                'address'    => $data[0]['attributes']['e_invoice_address'],
                'raw'        => $data[0],
            ];
        }
        return ['registered'=>false, 'address'=>null, 'raw'=>$data];
    }

    // ──────────────────────────────────────────────────────────
    // E-ARŞİV / E-FATURA RESMİLEŞTİRME
    // ──────────────────────────────────────────────────────────

    /**
     * Sales invoice'u E-ARŞİV olarak resmileştir (VKN e-fatura kayıtlı DEĞİLSE).
     *
     * POST /v4/{company_id}/e_archives
     * Body: { data: { type: 'e_archives', relationships: { sales_invoice: {...} } } }
     *
     * Bu işlem async — trackable_jobs döndürür. Tamamlanması saniyeler sürer.
     *
     * @return string|null e-arşiv ID veya null
     */
    public function createEArchive(string $salesInvoiceId): ?string {
        if (!$this->isEnabled()) return null;

        $body = [
            'data' => [
                'type' => 'e_archives',
                'relationships' => [
                    'sales_invoice' => [
                        'data' => ['id' => $salesInvoiceId, 'type' => 'sales_invoices'],
                    ],
                ],
            ],
        ];

        try {
            $r = $this->http('POST', $this->endpoint('e_archives'), $body);
            return $r['data']['id'] ?? null;
        } catch (\Throwable $e) {
            error_log('Paraşüt createEArchive: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Sales invoice'u E-FATURA olarak resmileştir (VKN e-fatura sisteminde kayıtlıysa).
     *
     * POST /v4/{company_id}/e_invoices
     * Body: { data: { type: 'e_invoices', attributes: { scenario, to, note },
     *                 relationships: { invoice: {...} } } }
     *
     * @param string $eInvoiceAddress checkVKN()'nın döndürdüğü 'address'
     * @param string $scenario 'basic' veya 'commercial'
     * @return string|null e-fatura ID
     */
    public function createEInvoice(string $salesInvoiceId, string $eInvoiceAddress, string $scenario = 'basic', string $note = ''): ?string {
        if (!$this->isEnabled()) return null;

        $body = [
            'data' => [
                'type' => 'e_invoices',
                'attributes' => [
                    'scenario' => in_array($scenario, ['basic','commercial'], true) ? $scenario : 'basic',
                    'to'       => $eInvoiceAddress,
                ],
                'relationships' => [
                    'invoice' => [
                        'data' => ['id' => $salesInvoiceId, 'type' => 'sales_invoices'],
                    ],
                ],
            ],
        ];
        if (!empty($note)) $body['data']['attributes']['note'] = $note;

        try {
            $r = $this->http('POST', $this->endpoint('e_invoices'), $body);
            return $r['data']['id'] ?? null;
        } catch (\Throwable $e) {
            error_log('Paraşüt createEInvoice: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * KOMPLE AKIŞ: Bir B2B siparişi için Paraşüt'te
     * (1) cari yoksa oluştur, (2) fatura kes, (3) VKN sorgula,
     * (4) e-fatura veya e-arşiv olarak resmileştir, (5) PDF URL çek.
     *
     * Tüm aşamaları otomatik yürütür ve b2b_orders kayıtlarını günceller.
     *
     * @return array{ok:bool, invoice_id:?string, einvoice_id:?string, einvoice_type:?string, pdf_url:?string, msg:string}
     */
    public function fullInvoiceFlow(int $orderId): array {
        if (!$this->isEnabled()) {
            return ['ok'=>false, 'invoice_id'=>null, 'einvoice_id'=>null, 'einvoice_type'=>null, 'pdf_url'=>null, 'msg'=>'Entegrasyon kapalı.'];
        }

        // 1. Mevcut fatura var mı?
        $order = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$orderId]);
        if (!$order) {
            return ['ok'=>false, 'invoice_id'=>null, 'einvoice_id'=>null, 'einvoice_type'=>null, 'pdf_url'=>null, 'msg'=>'Sipariş bulunamadı.'];
        }

        $invoiceId = $order['parasut_invoice_id'] ?? null;

        // 2. Fatura yoksa oluştur (syncInvoice tetikleyici)
        if (!$invoiceId) {
            $invoiceId = $this->syncInvoice($orderId);
            if (!$invoiceId) {
                return ['ok'=>false, 'invoice_id'=>null, 'einvoice_id'=>null, 'einvoice_type'=>null, 'pdf_url'=>null, 'msg'=>'Fatura oluşturulamadı.'];
            }
        }

        // 3. E-resmi belge zaten varsa atla
        $einvoiceId   = $order['parasut_einvoice_id']   ?? null;
        $einvoiceType = $order['parasut_einvoice_type'] ?? null;

        if (!$einvoiceId && (int)setting('parasut_auto_einvoice', 1) === 1) {
            // 4. Bayinin VKN'ını al, e-fatura sorgu yap
            $dealer = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$order['dealer_id']]);
            $vkn = trim($dealer['tax_number'] ?? '');

            if ($vkn !== '' && strlen($vkn) >= 10) {
                $vknCheck = $this->checkVKN($vkn);
                if ($vknCheck['registered'] && !empty($vknCheck['address'])) {
                    // E-FATURA
                    $scenario   = setting('parasut_einvoice_scenario', 'basic');
                    $einvoiceId = $this->createEInvoice($invoiceId, $vknCheck['address'], $scenario);
                    $einvoiceType = 'e_invoice';
                } else {
                    // E-ARŞİV (VKN sistemde yok)
                    $einvoiceId = $this->createEArchive($invoiceId);
                    $einvoiceType = 'e_archive';
                }
            } else {
                // VKN yok → e-arşiv (bireysel müşteri gibi)
                $einvoiceId = $this->createEArchive($invoiceId);
                $einvoiceType = 'e_archive';
            }

            if ($einvoiceId) {
                dbExec("UPDATE b2b_orders SET parasut_einvoice_id=?, parasut_einvoice_type=? WHERE id=?",
                    [$einvoiceId, $einvoiceType, $orderId]);
            }
        }

        // 5. PDF URL çek
        $pdfUrl = null;
        if ((int)setting('parasut_save_pdf', 1) === 1) {
            $pdfUrl = $this->getInvoicePdfUrl($invoiceId);
            if ($pdfUrl) {
                dbExec("UPDATE b2b_orders SET parasut_invoice_pdf_url=?, parasut_synced_at=NOW() WHERE id=?",
                    [$pdfUrl, $orderId]);
            }
        }

        // 6. Fatura durumu güncelle
        $status = $this->getInvoiceStatus($invoiceId);
        if ($status) {
            dbExec("UPDATE b2b_orders SET parasut_invoice_status=? WHERE id=?", [$status, $orderId]);
        }

        return [
            'ok'            => true,
            'invoice_id'    => $invoiceId,
            'einvoice_id'   => $einvoiceId,
            'einvoice_type' => $einvoiceType,
            'pdf_url'       => $pdfUrl,
            'msg'           => 'Fatura işlemleri tamamlandı.',
        ];
    }

    // ──────────────────────────────────────────────────────────
    // FATURA YÖNETİMİ
    // ──────────────────────────────────────────────────────────

    /**
     * Fatura PDF URL'sini çek (geçici signed URL — birkaç dakika geçerli).
     *
     * GET /v4/{company_id}/sales_invoices/{id}/pdf
     */
    public function getInvoicePdfUrl(string $invoiceId): ?string {
        if (!$this->isEnabled() || empty($invoiceId)) return null;
        try {
            $r = $this->http('GET', $this->endpoint("sales_invoices/{$invoiceId}/pdf"));
            return $r['url'] ?? $r['data']['attributes']['url'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Fatura durumunu çek: draft, unpaid, partially_paid, paid, overdue, cancelled
     */
    public function getInvoiceStatus(string $invoiceId): ?string {
        if (!$this->isEnabled() || empty($invoiceId)) return null;
        try {
            $r = $this->http('GET', $this->endpoint("sales_invoices/{$invoiceId}"));
            return $r['data']['attributes']['payment_status'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Fatura iptal et (Paraşüt resmi prosedürü).
     * PATCH /v4/{company_id}/sales_invoices/{id}/cancel
     */
    public function cancelInvoice(string $invoiceId): bool {
        if (!$this->isEnabled() || empty($invoiceId)) return false;
        try {
            $r = $this->http('PATCH', $this->endpoint("sales_invoices/{$invoiceId}/cancel"));
            return !empty($r['data']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * İptal edilmiş faturayı geri al.
     * PATCH /v4/{company_id}/sales_invoices/{id}/recover
     */
    public function recoverInvoice(string $invoiceId): bool {
        if (!$this->isEnabled() || empty($invoiceId)) return false;
        try {
            $r = $this->http('PATCH', $this->endpoint("sales_invoices/{$invoiceId}/recover"));
            return !empty($r['data']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Bir cariye ait tüm satış faturalarını çek.
     * GET /v4/{company_id}/sales_invoices?filter[contact_id]=X
     */
    public function listInvoicesByContact(string $contactId, int $page = 1, int $size = 100): array {
        if (!$this->isEnabled() || empty($contactId)) return ['data'=>[]];
        $size = max(1, min(100, $size));
        $url = $this->endpoint('sales_invoices')
             . '?filter[contact_id]=' . urlencode($contactId)
             . '&page[number]=' . $page
             . '&page[size]='   . $size
             . '&sort=-issue_date';
        $r = $this->http('GET', $url);
        return ['data'=>$r['data'] ?? [], 'meta'=>$r['meta'] ?? []];
    }

    // ──────────────────────────────────────────────────────────
    // BANKA / KASA HESAPLARI
    // ──────────────────────────────────────────────────────────

    /**
     * Tüm hesapları (banka, kasa, kredi kartı) listele.
     * Tahsilat oluştururken hangi hesaba yatacağını seçmek için.
     */
    public function listAccounts(): array {
        if (!$this->isEnabled()) return [];
        try {
            $r = $this->http('GET', $this->endpoint('accounts') . '?page[size]=25');
            return $r['data'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────
    // CARİ BAKİYE
    // ──────────────────────────────────────────────────────────

    /**
     * Bir cariye ait güncel bakiye bilgisini çek.
     * Contact detayında 'balance' alanı var.
     *
     * @return float|null Negatif = bayinin borcu, pozitif = bayinin alacağı
     */
    public function getContactBalance(string $contactId): ?float {
        if (!$this->isEnabled() || empty($contactId)) return null;
        try {
            $r = $this->http('GET', $this->endpoint("contacts/{$contactId}"));
            $bal = $r['data']['attributes']['balance'] ?? null;
            return $bal !== null ? (float)$bal : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Tüm bayilerin Paraşüt bakiyesini senkronla.
     */
    public function syncAllBalances(): array {
        if (!$this->isEnabled()) return ['success'=>0, 'fail'=>0, 'total'=>0];
        $dealers = dbRows("SELECT id, parasut_contact_id FROM b2b_dealers
                           WHERE is_active=1 AND parasut_contact_id IS NOT NULL AND parasut_contact_id != ''");
        $success = 0; $fail = 0;
        foreach ($dealers as $d) {
            $bal = $this->getContactBalance($d['parasut_contact_id']);
            if ($bal !== null) {
                dbExec("UPDATE b2b_dealers SET parasut_balance=?, parasut_balance_updated=NOW() WHERE id=?",
                    [$bal, $d['id']]);
                $success++;
            } else {
                $fail++;
            }
        }
        return ['success'=>$success, 'fail'=>$fail, 'total'=>count($dealers)];
    }

    // ──────────────────────────────────────────────────────────
    // CARİ HAREKET (manuel borçlandırma/alacaklandırma)
    // ──────────────────────────────────────────────────────────

    /**
     * Bir cariye manuel borçlandırma yaz (örn ceza, indirim).
     * POST /v4/{company_id}/contacts/{id}/contact_debit_transactions
     */
    public function debitContact(string $contactId, float $amount, string $description, ?string $accountId = null): ?string {
        if (!$this->isEnabled() || empty($contactId) || $amount <= 0) return null;

        $body = [
            'data' => [
                'type' => 'contact_debit_transactions',
                'attributes' => [
                    'amount'      => $amount,
                    'description' => $description,
                    'currency'    => setting('currency', 'TRY'),
                    'date'        => date('Y-m-d'),
                ],
            ],
        ];
        if ($accountId) {
            $body['data']['relationships'] = [
                'account' => ['data' => ['id'=>$accountId, 'type'=>'accounts']],
            ];
        }

        try {
            $r = $this->http('POST', $this->endpoint("contacts/{$contactId}/contact_debit_transactions"), $body);
            return $r['data']['id'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Bir cariye manuel alacaklandırma yaz.
     * POST /v4/{company_id}/contacts/{id}/contact_credit_transactions
     */
    public function creditContact(string $contactId, float $amount, string $description, ?string $accountId = null): ?string {
        if (!$this->isEnabled() || empty($contactId) || $amount <= 0) return null;

        $body = [
            'data' => [
                'type' => 'contact_credit_transactions',
                'attributes' => [
                    'amount'      => $amount,
                    'description' => $description,
                    'currency'    => setting('currency', 'TRY'),
                    'date'        => date('Y-m-d'),
                ],
            ],
        ];
        if ($accountId) {
            $body['data']['relationships'] = [
                'account' => ['data' => ['id'=>$accountId, 'type'=>'accounts']],
            ];
        }

        try {
            $r = $this->http('POST', $this->endpoint("contacts/{$contactId}/contact_credit_transactions"), $body);
            return $r['data']['id'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // V4 API GENİŞLETMELER (categories, shipment_documents, sales_offers,
    // inventory_levels, trackable_jobs, webhooks, tags, e_invoice_inboxes)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Paraşüt'teki kategorileri çek. Ürün/cari kategorileri.
     * @param string $type 'Product' | 'Contact' | 'SalesInvoice' | 'PurchaseBill' | '' (hepsi)
     */
    public function listCategories(string $type = ''): array {
        if (!$this->isEnabled()) return [];
        $url = $this->endpoint('item_categories') . '?page[size]=25&sort=name';
        if ($type !== '') $url .= '&filter[category_type]=' . urlencode($type);
        $all = [];
        for ($p = 1; $p <= 10; $p++) {
            $r = $this->http('GET', $url . '&page[number]=' . $p);
            $data = $r['data'] ?? [];
            if (empty($data)) break;
            $all = array_merge($all, $data);
            if (count($data) < 25) break;
        }
        return $all;
    }

    /**
     * Etiket (tag) listesini çek.
     */
    public function listTags(): array {
        if (!$this->isEnabled()) return [];
        $all = [];
        for ($p = 1; $p <= 10; $p++) {
            $r = $this->http('GET', $this->endpoint('tags') . '?page[size]=25&page[number]=' . $p);
            $data = $r['data'] ?? [];
            if (empty($data)) break;
            $all = array_merge($all, $data);
            if (count($data) < 25) break;
        }
        return $all;
    }

    /**
     * Satış teklifleri (sales_offers) — taslak/teklif yönetimi.
     */
    public function listSalesOffers(int $page = 1, int $size = 25, array $filter = []): array {
        if (!$this->isEnabled()) return ['data'=>[], 'meta'=>[]];
        $size = max(1, min(25, $size));
        $url  = $this->endpoint('sales_offers') . '?page[number]=' . $page . '&page[size]=' . $size;
        foreach ($filter as $k => $v) {
            if ($v !== '' && $v !== null) $url .= '&filter[' . urlencode($k) . ']=' . urlencode((string)$v);
        }
        $r = $this->http('GET', $url);
        return ['data' => $r['data'] ?? [], 'meta' => $r['meta'] ?? []];
    }

    /**
     * İrsaliye (shipment_documents) listele.
     * Sipariş onaylandığında otomatik irsaliye oluşturma için temel.
     */
    public function listShipmentDocuments(int $page = 1, int $size = 25, array $filter = []): array {
        if (!$this->isEnabled()) return ['data'=>[], 'meta'=>[]];
        $size = max(1, min(25, $size));
        $url  = $this->endpoint('shipment_documents') . '?page[number]=' . $page . '&page[size]=' . $size;
        foreach ($filter as $k => $v) {
            if ($v !== '' && $v !== null) $url .= '&filter[' . urlencode($k) . ']=' . urlencode((string)$v);
        }
        $r = $this->http('GET', $url);
        return ['data' => $r['data'] ?? [], 'meta' => $r['meta'] ?? []];
    }

    /**
     * Yeni irsaliye oluştur (basit yapı — bir sipariş için).
     *
     * @param int    $contactId       Paraşüt cari ID
     * @param array  $items           [['product_id'=>X, 'quantity'=>Y, 'unit_price'=>Z, 'vat_rate'=>20], ...]
     * @param string $issueDate       'YYYY-MM-DD'
     * @param string $description     İrsaliye açıklaması
     * @return ?string Oluşan irsaliye ID
     */
    public function createShipmentDocument(int $contactId, array $items, string $issueDate = '', string $description = ''): ?string {
        if (!$this->isEnabled()) return null;
        if ($issueDate === '') $issueDate = date('Y-m-d');

        $details = [];
        foreach ($items as $i => $it) {
            $details[] = [
                'type'       => 'shipment_document_details',
                'attributes' => [
                    'quantity'   => (float)($it['quantity'] ?? 1),
                    'unit_price' => (float)($it['unit_price'] ?? 0),
                    'vat_rate'   => (float)($it['vat_rate'] ?? 20),
                ],
                'relationships' => [
                    'product' => ['data' => ['id'=>(string)$it['product_id'], 'type'=>'products']],
                ],
            ];
        }

        $body = [
            'data' => [
                'type' => 'shipment_documents',
                'attributes' => [
                    'issue_date'  => $issueDate,
                    'description' => $description,
                ],
                'relationships' => [
                    'contact' => ['data' => ['id'=>(string)$contactId, 'type'=>'contacts']],
                    'details' => ['data' => $details],
                ],
            ],
        ];

        try {
            $r = $this->http('POST', $this->endpoint('shipment_documents'), $body);
            return $r['data']['id'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Stok envanter seviyesi (inventory_levels) bir ürün için.
     * Paraşüt'teki gerçek stok miktarını B2B'ye senkronlamak için.
     *
     * @return ?float Mevcut stok miktarı (Paraşüt tarafında)
     */
    public function getProductInventory(string $productId): ?float {
        if (!$this->isEnabled()) return null;
        try {
            $r = $this->http('GET', $this->endpoint("products/{$productId}") . '?include=inventory_levels');
            $included = $r['included'] ?? [];
            $total = 0;
            $found = false;
            foreach ($included as $inc) {
                if (($inc['type'] ?? '') === 'inventory_levels') {
                    $total += (float)($inc['attributes']['total_quantity'] ?? 0);
                    $found = true;
                }
            }
            return $found ? $total : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Trackable job (asenkron iş) durumu sorgula.
     * E-fatura/E-arşiv oluşturma sonrası iş kuyruğa girer, status bu metodla takip edilir.
     *
     * @return array{status: string, errors: array}
     */
    public function getTrackableJob(string $jobId): array {
        if (!$this->isEnabled()) return ['status' => 'unknown', 'errors' => []];
        try {
            $r = $this->http('GET', $this->endpoint("trackable_jobs/{$jobId}"));
            $a = $r['data']['attributes'] ?? [];
            return [
                'status' => $a['status'] ?? 'unknown',
                'errors' => $a['errors'] ?? [],
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * Bekleyen async işin tamamlanmasını bekle (polling, max 30 saniye).
     */
    public function waitForJob(string $jobId, int $maxSeconds = 30): array {
        $deadline = time() + $maxSeconds;
        $result = ['status' => 'pending', 'errors' => []];
        while (time() < $deadline) {
            $result = $this->getTrackableJob($jobId);
            if (in_array($result['status'], ['done', 'completed', 'failed', 'error'], true)) break;
            sleep(1);
        }
        return $result;
    }

    /**
     * Webhook listesi - aktif olarak kayıtlı webhook'ları döner.
     */
    public function listWebhooks(): array {
        if (!$this->isEnabled()) return [];
        try {
            $r = $this->http('GET', $this->endpoint('webhooks'));
            return $r['data'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Yeni webhook oluştur.
     *
     * @param string $url    Bizim endpoint (örn https://b2b.site.com/parasut-webhook)
     * @param array  $events ['Contact.create', 'Product.update', 'SalesInvoice.archive', ...]
     */
    public function createWebhook(string $url, array $events): ?string {
        if (!$this->isEnabled()) return null;
        $body = [
            'data' => [
                'type' => 'webhooks',
                'attributes' => [
                    'url'           => $url,
                    'event_filters' => $events,
                ],
            ],
        ];
        try {
            $r = $this->http('POST', $this->endpoint('webhooks'), $body);
            return $r['data']['id'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Webhook sil */
    public function deleteWebhook(string $webhookId): bool {
        if (!$this->isEnabled()) return false;
        try {
            $this->http('DELETE', $this->endpoint("webhooks/{$webhookId}"));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * E-Fatura kayıtlı kullanıcı kontrolü (e_invoice_inboxes).
     * Bir VKN/TCKN'nin e-fatura mükellefi olup olmadığını sorgular.
     *
     * @return bool true = e-fatura mükellefi, false = e-arşiv'a düşmeli
     */
    public function isEInvoiceUser(string $vkn): bool {
        if (!$this->isEnabled()) return false;
        try {
            $r = $this->http('GET', $this->endpoint("e_invoice_inboxes") . '?filter[vkn]=' . urlencode($vkn));
            $data = $r['data'] ?? [];
            return !empty($data);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Cari arşivleme (silmek yerine pasif yap). Paraşüt UX uyumu için.
     */
    public function archiveContact(string $contactId): bool {
        if (!$this->isEnabled()) return false;
        $body = [
            'data' => [
                'id'         => $contactId,
                'type'       => 'contacts',
                'attributes' => ['archived' => true],
            ],
        ];
        try {
            $this->http('PUT', $this->endpoint("contacts/{$contactId}"), $body);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Cari arşivden çıkar (aktifleştir) */
    public function unarchiveContact(string $contactId): bool {
        if (!$this->isEnabled()) return false;
        $body = [
            'data' => [
                'id'         => $contactId,
                'type'       => 'contacts',
                'attributes' => ['archived' => false],
            ],
        ];
        try {
            $this->http('PUT', $this->endpoint("contacts/{$contactId}"), $body);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

/** Global paraşüt instance */
function parasut(): Parasut {
    static $p = null;
    if ($p === null) $p = new Parasut();
    return $p;
}

// ════════════════════════════════════════════════════════════════
// PARAŞÜT CACHE — Arka planda DB'de tutulan ürün/cari listesi
// Sayfa açılışında Paraşüt'e gitmiyoruz, sadece manuel sync.
// ════════════════════════════════════════════════════════════════

/**
 * Cache'lenmiş Paraşüt ürünlerini getir (arama destekli).
 * @param string $query  arama metni (boş = hepsi)
 * @param bool   $includeArchived  arşivli ürünleri dahil et
 * @return array Paraşüt ürün objesi formatında ([{id, type, attributes:{...}}])
 */
function parasut_cache_get_products(string $query = '', bool $includeArchived = true): array {
    $sql = "SELECT * FROM b2b_parasut_cache WHERE kind='products'";
    $params = [];
    if (!$includeArchived) {
        $sql .= " AND archived=0";
    }
    if ($query !== '') {
        $sql .= " AND (LOWER(name) LIKE ? OR LOWER(code) LIKE ? OR parasut_id=?)";
        $q = '%' . mb_strtolower(trim($query), 'UTF-8') . '%';
        $params[] = $q; $params[] = $q; $params[] = trim($query);
    }
    $sql .= " ORDER BY name ASC LIMIT 5000";
    $rows = dbRows($sql, $params);

    $out = [];
    foreach ($rows as $r) {
        // raw_data varsa kullan, yoksa minimal yapı oluştur
        $attrs = !empty($r['raw_data']) ? (json_decode($r['raw_data'], true) ?: []) : [];
        if (empty($attrs['name']))     $attrs['name'] = $r['name'];
        if (empty($attrs['code']))     $attrs['code'] = $r['code'];
        if (!isset($attrs['vat_rate']))   $attrs['vat_rate'] = (float)$r['vat_rate'];
        if (!isset($attrs['list_price'])) $attrs['list_price'] = $r['list_price'] !== null ? (float)$r['list_price'] : null;
        if (!isset($attrs['archived']))   $attrs['archived'] = (bool)$r['archived'];
        if (empty($attrs['_category_name']) && !empty($r['category_name'])) {
            $attrs['_category_name'] = $r['category_name'];
        }
        $out[] = [
            'id'         => $r['parasut_id'],
            'type'       => 'products',
            'attributes' => $attrs,
        ];
    }
    return $out;
}

/**
 * Cache'lenmiş ürün sayısı (statistic).
 */
function parasut_cache_stats(): array {
    $row = dbRow(
        "SELECT
           COUNT(*) AS total,
           SUM(CASE WHEN archived=0 THEN 1 ELSE 0 END) AS active,
           SUM(CASE WHEN archived=1 THEN 1 ELSE 0 END) AS archived,
           MAX(synced_at) AS last_synced
         FROM b2b_parasut_cache WHERE kind='products'"
    );
    return [
        'total'       => (int)($row['total'] ?? 0),
        'active'      => (int)($row['active'] ?? 0),
        'archived'    => (int)($row['archived'] ?? 0),
        'last_synced' => $row['last_synced'] ?? null,
    ];
}

/**
 * Paraşüt'ten TÜM ürünleri çek ve DB'ye yaz (SAFE cache update).
 *
 * KRITIK MANTIK (v1.1.83):
 * - Eski cache'i SILMEZ (mevcut eşleşmeler korunur)
 * - UPSERT ile günceller (ON DUPLICATE KEY)
 * - Bu sync'te dönen ID'leri "synced_at = NOW()" ile işaretler
 * - Eski synced_at değeri kalan kayıtlar → "kayıp/silinmiş" sayılır
 * - Minimum threshold: eğer Paraşüt < 50 ürün döndüyse şüpheli sync → cache'e dokunmaz
 *
 * @return array{success: bool, total: int, active: int, archived: int, duration: float, error: ?string, stale: int}
 */
function parasut_cache_sync_products(): array {
    $start = microtime(true);
    $result = ['success'=>false, 'total'=>0, 'active'=>0, 'archived'=>0, 'duration'=>0.0, 'error'=>null, 'stale'=>0];

    if (!parasut()->isEnabled()) {
        $result['error'] = 'Paraşüt entegrasyonu kapalı.';
        return $result;
    }

    try {
        // Aktif + arşivli tüm ürünleri çek (rate-limit-aware fonksiyonlar)
        $active   = parasut()->listAllProductsWithMeta(200);
        $archived = parasut()->listAllProductsWithMeta(200, ['archived' => 'true']);
        $allProducts = array_merge($active['data'], $archived['data']);

        if (empty($allProducts)) {
            $result['error'] = 'Paraşüt boş döndü. Token veya bağlantı sorunu olabilir. Cache korundu.';
            return $result;
        }

        // GÜVENLİK: Eğer çok az ürün döndüyse şüphelidir → cache'e dokunma
        // Sadece DÜŞÜŞ durumunda uyarı ver (artış normaldir, ürün eklenmiş olabilir)
        $activeTotal = (int)($active['total_count'] ?? 0);
        $archivedTotal = (int)($archived['total_count'] ?? 0);
        $missingActive = max(0, $activeTotal - count($active['data']));
        $missingArchived = max(0, $archivedTotal - count($archived['data']));
        if ($missingActive > 0 || $missingArchived > 0) {
            $result['parasut_total_active'] = $activeTotal;
            $result['parasut_total_archived'] = $archivedTotal;
            $result['rescued_active'] = (int)($active['rescued'] ?? 0);
            $result['rescued_archived'] = (int)($archived['rescued'] ?? 0);
            $result['duration'] = round(microtime(true) - $start, 2);
            $result['error'] = sprintf(
                'Eksik sync iptal edildi: Parasut %d aktif + %d arsivli diyor, API %d + %d dondu. Cache korunuyor; rate limit/pagination duzelince tekrar deneyin.',
                $activeTotal, $archivedTotal,
                count($active['data']), count($archived['data'])
            );
            return $result;
        }

        $existingTotal = (int)(parasut_cache_stats()['total'] ?? 0);
        $newCount = count($allProducts);
        // YENİ MANTIK: cache 200+ ise ve yeni sync %50'den az ise şüpheli
        // (örn: 1144 cache vardı, 250 dönerse → ŞÜPHELİ; 500 vardı 600 dönerse → NORMAL)
        if ($existingTotal > 200 && $newCount < ($existingTotal * 0.5)) {
            $result['error'] = sprintf(
                'Şüpheli sync: Paraşüt sadece %d ürün döndü (eskiden %d vardı, %.0f%% düşüş). Rate limit veya pagination sorunu olabilir. Cache korundu, lütfen birkaç dakika sonra tekrar deneyin.',
                $newCount, $existingTotal, (1 - $newCount/$existingTotal) * 100
            );
            return $result;
        }

        // Sync timestamp — bu sync'te dönenleri işaretleyeceğiz
        $syncTime = date('Y-m-d H:i:s');
        $foundIds = []; // bu sync'te bulunan Paraşüt ID'leri

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO b2b_parasut_cache
                 (kind, parasut_id, name, code, category_name, vat_rate, list_price, archived, raw_data, synced_at)
                 VALUES ('products', ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   name=VALUES(name), code=VALUES(code), category_name=VALUES(category_name),
                   vat_rate=VALUES(vat_rate), list_price=VALUES(list_price),
                   archived=VALUES(archived), raw_data=VALUES(raw_data), synced_at=VALUES(synced_at)"
            );

            foreach ($allProducts as $p) {
                $attrs = $p['attributes'] ?? [];
                $foundIds[] = $p['id'];
                $stmt->execute([
                    $p['id'],
                    mb_substr(trim($attrs['name'] ?? ''), 0, 255),
                    mb_substr(trim($attrs['code'] ?? ''), 0, 128),
                    mb_substr(trim($attrs['_category_name'] ?? ''), 0, 128),
                    (float)($attrs['vat_rate'] ?? 0),
                    isset($attrs['list_price']) ? (float)$attrs['list_price'] : null,
                    !empty($attrs['archived']) ? 1 : 0,
                    json_encode($attrs, JSON_UNESCAPED_UNICODE),
                    $syncTime,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // STALE kayıtları say (bu sync'te dönmeyenler)
        // SILMEYIZ — eşleşmeler korunur, sadece UI'da uyarı veririz
        $stale = (int)dbVal(
            "SELECT COUNT(*) FROM b2b_parasut_cache WHERE kind='products' AND synced_at < ?",
            [$syncTime]
        );

        $stats = parasut_cache_stats();
        $result['success']  = true;
        $result['total']    = $stats['total'];
        $result['active']   = $stats['active'];
        $result['archived'] = $stats['archived'];
        $result['stale']    = $stale;
        $result['parasut_total_active']   = $activeTotal;
        $result['parasut_total_archived'] = $archivedTotal;
        $result['rescued_active']         = (int)($active['rescued'] ?? 0);
        $result['rescued_archived']       = (int)($archived['rescued'] ?? 0);
        $result['duration'] = round(microtime(true) - $start, 2);

        // Hâlâ eksik var mı?
        $missingActive   = max(0, $result['parasut_total_active']   - count($active['data']));
        $missingArchived = max(0, $result['parasut_total_archived'] - count($archived['data']));
        if ($missingActive > 0 || $missingArchived > 0) {
            $result['warning'] = sprintf(
                'Eksik var: Paraşüt %d aktif + %d arşivli diyor, biz %d + %d çektik. Rate limit nedeniyle %d aktif + %d arşivli ürün çekilemedi.',
                $result['parasut_total_active'], $result['parasut_total_archived'],
                count($active['data']), count($archived['data']),
                $missingActive, $missingArchived
            );
        }

        settingSave('parasut_cache_last_sync_at', $syncTime);
        settingSave('parasut_cache_last_sync_status', json_encode([
            'success'  => true,
            'total'    => $stats['total'],
            'active'   => $stats['active'],
            'archived' => $stats['archived'],
            'stale'    => $stale,
            'duration' => $result['duration'],
        ]));
    } catch (\Throwable $e) {
        $result['error'] = $e->getMessage();
        error_log('parasut_cache_sync_products error: ' . $e->getMessage());
    }
    return $result;
}

/**
 * Cache'lenmiş Paraşüt CARİ kartlarını getir (arama destekli).
 */
function parasut_cache_get_contacts(string $query = ''): array {
    $sql = "SELECT * FROM b2b_parasut_cache WHERE kind='contacts'";
    $params = [];
    if ($query !== '') {
        $sql .= " AND (LOWER(name) LIKE ? OR LOWER(code) LIKE ? OR parasut_id=?)";
        $q = '%' . mb_strtolower(trim($query), 'UTF-8') . '%';
        $params[] = $q; $params[] = $q; $params[] = trim($query);
    }
    $sql .= " ORDER BY name ASC LIMIT 5000";
    $rows = dbRows($sql, $params);

    $out = [];
    foreach ($rows as $r) {
        $attrs = !empty($r['raw_data']) ? (json_decode($r['raw_data'], true) ?: []) : [];
        if (empty($attrs['name'])) $attrs['name'] = $r['name'];
        $out[] = [
            'id'         => $r['parasut_id'],
            'type'       => 'contacts',
            'attributes' => $attrs,
        ];
    }
    return $out;
}

/**
 * Cache contacts istatistik.
 */
function parasut_cache_contacts_stats(): array {
    $row = dbRow("SELECT COUNT(*) AS total, MAX(synced_at) AS last_synced FROM b2b_parasut_cache WHERE kind='contacts'");
    return [
        'total'       => (int)($row['total'] ?? 0),
        'last_synced' => $row['last_synced'] ?? null,
    ];
}

/**
 * Paraşüt'ten TÜM cari kartlarını çek ve cache'e yaz (SAFE).
 * DELETE etmez, UPSERT yapar — mevcut eşleşmeler korunur.
 */
function parasut_cache_sync_contacts(): array {
    $start = microtime(true);
    $result = ['success'=>false, 'total'=>0, 'duration'=>0.0, 'error'=>null, 'stale'=>0];

    if (!parasut()->isEnabled()) {
        $result['error'] = 'Paraşüt entegrasyonu kapalı.';
        return $result;
    }

    try {
        $res = parasut()->listAllContactsWithMeta(200);
        $contacts = $res['data'] ?? [];

        if (empty($contacts)) {
            $result['error'] = 'Paraşüt cari listesi boş döndü. Cache korundu.';
            return $result;
        }

        // GÜVENLİK: şüpheli sync kontrolü
        $existingTotal = (int)(parasut_cache_contacts_stats()['total'] ?? 0);
        $newCount = count($contacts);
        if ($existingTotal > 100 && $newCount < ($existingTotal * 0.3)) {
            $result['error'] = sprintf(
                'Şüpheli cari sync: %d kayıt döndü (eskiden %d vardı). Cache korundu.',
                $newCount, $existingTotal
            );
            return $result;
        }

        $syncTime = date('Y-m-d H:i:s');

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO b2b_parasut_cache
                 (kind, parasut_id, name, code, category_name, archived, raw_data, synced_at)
                 VALUES ('contacts', ?, ?, ?, ?, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   name=VALUES(name), code=VALUES(code), category_name=VALUES(category_name),
                   raw_data=VALUES(raw_data), synced_at=VALUES(synced_at)"
            );

            foreach ($contacts as $c) {
                $attrs = $c['attributes'] ?? [];
                $stmt->execute([
                    $c['id'],
                    mb_substr(trim($attrs['name'] ?? ''), 0, 255),
                    mb_substr(trim($attrs['tax_number'] ?? ''), 0, 128),
                    mb_substr(trim($attrs['account_type'] ?? ''), 0, 128),
                    json_encode($attrs, JSON_UNESCAPED_UNICODE),
                    $syncTime,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $stale = (int)dbVal(
            "SELECT COUNT(*) FROM b2b_parasut_cache WHERE kind='contacts' AND synced_at < ?",
            [$syncTime]
        );

        $stats = parasut_cache_contacts_stats();
        $result['success']  = true;
        $result['total']    = $stats['total'];
        $result['stale']    = $stale;
        $result['duration'] = round(microtime(true) - $start, 2);

        settingSave('parasut_cache_contacts_last_sync_at', $syncTime);
    } catch (\Throwable $e) {
        $result['error'] = $e->getMessage();
        error_log('parasut_cache_sync_contacts error: ' . $e->getMessage());
    }
    return $result;
}

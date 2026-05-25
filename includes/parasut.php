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
     * 25 kayıt/sayfa x maxPages = max kayıt.
     */
    public function listAllContacts(int $maxPages = 40): array {
        $all = [];
        for ($p = 1; $p <= $maxPages; $p++) {
            $res = $this->listContacts($p, 25, [], 'name');
            if (empty($res['data'])) break;
            $all = array_merge($all, $res['data']);
            if (count($res['data']) < 25) break; // Son sayfa
        }
        return $all;
    }

    /**
     * Cariler + metadata. Default sort=name (alfabetik).
     */
    public function listAllContactsWithMeta(int $maxPages = 40, array $filter = [], string $sort = 'name'): array {
        $all = []; $totalCount = 0; $totalPages = 0; $perPage = 25;
        for ($p = 1; $p <= $maxPages; $p++) {
            $res = $this->listContacts($p, 25, $filter, $sort);
            if (empty($res['data'])) break;
            $all = array_merge($all, $res['data']);
            if ($p === 1 && !empty($res['meta'])) {
                $totalCount = (int)($res['meta']['total_count'] ?? 0);
                $totalPages = (int)($res['meta']['total_pages'] ?? 0);
                $perPage    = (int)($res['meta']['per_page']    ?? 25);
            }
            if (count($res['data']) < 25) break;
        }
        return [
            'data'        => $all,
            'fetched'     => count($all),
            'total_count' => $totalCount,
            'total_pages' => $totalPages,
            'per_page'    => $perPage,
        ];
    }

    /** Paraşüt'teki tek sayfa ürün listesi (page size max 25) */
    public function listProducts(int $page = 1, int $size = 25, array $filter = [], string $sort = ''): array {
        if (!$this->isEnabled()) return ['data'=>[], 'meta'=>['error'=>'Entegrasyon kapalı.']];
        $size = max(1, min(25, $size));
        $url  = $this->endpoint('products') . '?page[number]=' . $page . '&page[size]=' . $size . '&include=category';
        foreach ($filter as $k => $v) {
            if ($v !== '' && $v !== null) $url .= '&filter[' . urlencode($k) . ']=' . urlencode((string)$v);
        }
        if ($sort !== '') $url .= '&sort=' . urlencode($sort);
        $r = $this->http('GET', $url);

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

        return [
            'data' => $data,
            'meta' => $r['meta'] ?? [],
            'err'  => empty($data) ? $this->getErrorDetail($r) : null,
        ];
    }

    /** Paraşüt'teki TÜM stok takipli ürünleri otomatik pagination ile çek */
    public function listAllProducts(int $maxPages = 40): array {
        $all = [];
        for ($p = 1; $p <= $maxPages; $p++) {
            $res = $this->listProducts($p, 25, ['inventory_tracking' => 'true'], 'name');
            if (empty($res['data'])) break;
            $all = array_merge($all, $res['data']);
            if (count($res['data']) < 25) break;
        }
        return $all;
    }

    /**
     * Ürünler + metadata. Default sort=name + filter[inventory_tracking]=true.
     * Yani sadece GERÇEK STOK takipli ürünler (Paraşüt 'Hizmet ve Ürünler' listesi).
     * Muhasebe kalemleri (01.3.01.0... gibi) hariç tutulur.
     *
     * Tümünü görmek için filter['inventory_tracking'] vermeden çağır.
     */
    public function listAllProductsWithMeta(int $maxPages = 40, array $filter = [], string $sort = 'name'): array {
        // Default: sadece stok takipli ürünler
        if (!array_key_exists('inventory_tracking', $filter)) {
            $filter['inventory_tracking'] = 'true';
        }
        // 'inventory_tracking' filter değerinde boş veya 'all' ise filtreyi kaldır
        if (in_array($filter['inventory_tracking'] ?? '', ['all', ''], true)) {
            unset($filter['inventory_tracking']);
        }

        $all = []; $totalCount = 0; $totalPages = 0; $perPage = 25;
        for ($p = 1; $p <= $maxPages; $p++) {
            $res = $this->listProducts($p, 25, $filter, $sort);
            if (empty($res['data'])) break;
            $all = array_merge($all, $res['data']);
            if ($p === 1 && !empty($res['meta'])) {
                $totalCount = (int)($res['meta']['total_count'] ?? 0);
                $totalPages = (int)($res['meta']['total_pages'] ?? 0);
                $perPage    = (int)($res['meta']['per_page']    ?? 25);
            }
            if (count($res['data']) < 25) break;
        }
        return [
            'data'        => $all,
            'fetched'     => count($all),
            'total_count' => $totalCount,
            'total_pages' => $totalPages,
            'per_page'    => $perPage,
        ];
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

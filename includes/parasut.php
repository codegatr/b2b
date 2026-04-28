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

        // Yeni token al
        $response = $this->http('POST', $this->authUrl, [
            'grant_type'    => 'password',
            'client_id'     => setting('parasut_client_id'),
            'client_secret' => setting('parasut_client_secret'),
            'username'      => setting('parasut_email'),
            'password'      => setting('parasut_password'),
            'redirect_uri'  => 'urn:ietf:wg:oauth:2.0:oob',
        ], false);

        if (isset($response['access_token'])) {
            $this->token = $response['access_token'];
            $expiry = time() + ($response['expires_in'] ?? 7200) - 60;
            settingSave('parasut_token_cache', $this->token . '|' . $expiry);
            return $this->token;
        }
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
            // Token isteği: form-urlencoded, API isteği: JSON
            curl_setopt($ch, CURLOPT_POSTFIELDS, $auth ? json_encode($data) : http_build_query($data));
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $parsed = json_decode($result ?: '{}', true) ?? [];
        $this->logAction($method . ' ' . $url, $data, $parsed, $httpCode < 400 ? 'success' : 'error');
        return $parsed;
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

    /** Paraşüt'te müşteri oluştur veya güncelle */
    public function syncDealer(array $dealer): ?string {
        if (!$this->isEnabled()) return null;

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
            throw new Exception('Token alınamadı. E-posta/şifre/client bilgilerini kontrol edin.');
        }
        // Firma bilgisi çek
        $r = $this->http('GET', $this->endpoint('me'));
        if (empty($r['data'])) {
            throw new Exception('Firma bilgisi alınamadı (HTTP hata). Firma ID doğru mu?');
        }
        return $r;
    }
}

/** Global paraşüt instance */
function parasut(): Parasut {
    static $p = null;
    if ($p === null) $p = new Parasut();
    return $p;
}

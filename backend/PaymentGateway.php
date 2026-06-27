<?php
// backend/PaymentGateway.php

class PaymentGateway {
    private $gateway;
    private $config;

    public function __construct($gateway, array $credentials = []) {
        $this->gateway = $gateway;
        $this->config  = $credentials;
    }

    public function processPayment($userData, $planData, $method) {
        switch ($this->gateway) {
            case 'abacatepay':   return $this->abacatepaySubscriptionCheckout($userData, $planData);
            case 'mercadopago':  return $this->mercadoPago($userData, $planData, $method);
            case 'pagseguro':    return $this->pagSeguro($userData, $planData, $method);
            case 'cielo':        return $this->cielo($userData, $planData, $method);
            case 'efi':          return $this->efi($userData, $planData, $method);
            default:             throw new Exception("Gateway '{$this->gateway}' não suportado.");
        }
    }

    /** Chave HMAC documentada em https://docs.abacatepay.com/pages/webhooks/security */
    private const ABACATEPAY_WEBHOOK_HMAC_PUBLIC = 't9dXRhHHo3yDEj5pVDYz0frf7q6bMKyMRmxxCPIPp3RCplBfXRxqlC6ZpiWmOqj4L63qEaeUOtrCI8P0VMUgo6iIga2ri9ogaHFs0WIIywSMg0q7RmBfybe1E5XJcfC4IW3alNqym0tXoAKkzvfEjZxV6bE0oG2zJrNNYmUCKZyV0KZ3JS8Votf9EAWWYdiDkMkpbMdPggfh1EqHlVkMiTady6jOR3hyzGEHrIz2Ret0xHKMbiqkr9HS1JhNHDX9';

    /**
     * Verifica X-Webhook-Signature (HMAC-SHA256, digest base64) conforme doc AbacatePay.
     */
    public static function abacatepayVerifyWebhookSignature($rawBody, $signatureFromHeader) {
        $key = defined('ABACATEPAY_WEBHOOK_HMAC_KEY') && ABACATEPAY_WEBHOOK_HMAC_KEY !== ''
            ? ABACATEPAY_WEBHOOK_HMAC_KEY
            : self::ABACATEPAY_WEBHOOK_HMAC_PUBLIC;
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $key, true));
        if ($signatureFromHeader === '' || strlen($expected) !== strlen($signatureFromHeader)) {
            return false;
        }
        return hash_equals($expected, $signatureFromHeader);
    }

    /** Extrai mensagem amigável do JSON de erro da API. */
    private static function abacatepayFormatError(array $res): string {
        $err = $res['error'] ?? null;
        if (is_string($err) && $err !== '') {
            return $err;
        }
        if (is_array($err)) {
            if (!empty($err['message']) && is_string($err['message'])) {
                return $err['message'];
            }
            // erros de validação em lista
            if (isset($err[0]) && is_string($err[0])) {
                return implode('; ', array_filter(array_map('strval', $err)));
            }
            return json_encode($err, JSON_UNESCAPED_UNICODE);
        }
        $data = $res['data'] ?? null;
        if (is_array($data)) {
            if (!empty($data['message']) && is_string($data['message'])) {
                return $data['message'];
            }
            if (!empty($data['error']) && is_string($data['error'])) {
                return $data['error'];
            }
        }
        if (!empty($res['message']) && is_string($res['message'])) {
            return $res['message'];
        }
        $enc = json_encode($res, JSON_UNESCAPED_UNICODE);
        if ($enc !== false && strlen($enc) < 900) {
            return 'resposta: ' . $enc;
        }
        return 'falha sem mensagem detalhada.';
    }

    /** POST /v2/subscriptions/create — redireciona para data.url (checkout de assinatura; método CARD). */
    public function abacatepaySubscriptionCheckout($userData, $planData) {
        $apiKey = trim((string)($this->config['api_key'] ?? (defined('ABACATEPAY_API_KEY') ? ABACATEPAY_API_KEY : '')));
        if ($apiKey === '') {
            throw new Exception('AbacatePay: ABACATEPAY_API_KEY não configurada.');
        }

        $productId = trim((string)($planData['abacatepay_product_id'] ?? ''));
        if ($productId === '') {
            throw new Exception(
                'AbacatePay: ID do produto não configurado. Defina ABACATEPAY_PRODUCT_MENSAL / ABACATEPAY_PRODUCT_ANUAL no .env (produtos com ciclo na AbacatePay).'
            );
        }

        $email = $userData['email'] ?? '';
        if ($email === '') {
            throw new Exception('AbacatePay: e-mail do cliente ausente.');
        }

        $name = trim((string)($userData['name'] ?? ''));
        $custPayload = ['email' => $email];
        if ($name !== '') {
            $custPayload['name'] = $name;
        }

        $custRes = $this->abacatepayRequestJson('POST', '/customers/create', $custPayload, $apiKey);
        $customerId = $custRes['data']['id'] ?? '';
        if ($customerId === '' || ($custRes['success'] ?? null) === false) {
            throw new Exception('AbacatePay (cliente): ' . self::abacatepayFormatError($custRes));
        }

        $planSlug   = $planData['id'] ?? 'anual';
        $returnUrl  = app_http_url('checkout.php?plan=' . rawurlencode($planSlug) . '&canceled=1');
        $completionUrl = app_http_url('backend/abacatepay_success.php');

        $userId = (int)($userData['user_id'] ?? 0);
        $pendingToken = trim((string)($userData['pending_token'] ?? ''));
        if ($pendingToken !== '') {
            $externalId = 'auvvo_pending_' . $pendingToken . '_' . $planSlug;
        } else {
            $externalId = 'auvvo_u' . $userId . '_' . $planSlug;
        }

        $body = [
            'items'         => [['id' => $productId, 'quantity' => 1]],
            'customerId'    => $customerId,
            // Doc: checkout de assinatura — padrão e suporte principal é CARD; PIX costuma ser recusado.
            'methods'       => ['CARD'],
            'returnUrl'     => $returnUrl,
            'completionUrl' => $completionUrl,
            'externalId'    => $externalId,
            'metadata'      => [
                'user_id'  => $userId > 0 ? (string)$userId : '',
                'plan_id'  => (string)$planSlug,
                'app'      => 'auvvo',
            ],
        ];

        $billRes = $this->abacatepayRequestJson('POST', '/subscriptions/create', $body, $apiKey);
        $payUrl  = $billRes['data']['url'] ?? '';
        if (($billRes['success'] ?? null) === false || $payUrl === '') {
            throw new Exception('AbacatePay: ' . self::abacatepayFormatError($billRes));
        }

        return [
            'status'        => 'redirect',
            'gateway'       => 'abacatepay',
            'redirect_url'  => $payUrl,
            'checkout_id'   => $billRes['data']['id'] ?? null,
        ];
    }

    /** GET /v2/checkouts/get?id= */
    public static function abacatepayGetCheckout($checkoutId) {
        $apiKey = defined('ABACATEPAY_API_KEY') ? ABACATEPAY_API_KEY : '';
        if ($apiKey === '' || $checkoutId === '') {
            return null;
        }
        $gw = new self('abacatepay');
        return $gw->abacatepayRequestJson(
            'GET',
            '/checkouts/get?id=' . rawurlencode($checkoutId),
            null,
            $apiKey
        );
    }

    private function abacatepayRequestJson($method, $path, $body, $apiKey) {
        $url = 'https://api.abacatepay.com/v2' . $path;
        $ch  = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err) {
            throw new Exception('AbacatePay cURL: ' . $err);
        }
        if ($code >= 400) {
            error_log('[AbacatePay] HTTP ' . $code . ' ' . $path . ' body=' . substr((string)$raw, 0, 2000));
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new Exception('AbacatePay: resposta inválida (HTTP ' . $code . '): ' . substr((string)$raw, 0, 280));
        }
        if ($code >= 400) {
            throw new Exception('AbacatePay HTTP ' . $code . ': ' . self::abacatepayFormatError($decoded));
        }
        return $decoded;
    }

    // ── Gateways legados (não implementados) ─────────────────────────────────
    private function mercadoPago($u, $p, $method) {
        throw new Exception('Gateway Mercado Pago não está implementado.');
    }

    private function pagSeguro($u, $p, $method) {
        throw new Exception('Gateway PagSeguro não está implementado.');
    }

    private function cielo($u, $p, $method) {
        throw new Exception('Gateway Cielo não está implementado.');
    }

    private function efi($u, $p, $method) {
        throw new Exception('Gateway Efí não está implementado.');
    }
}
?>

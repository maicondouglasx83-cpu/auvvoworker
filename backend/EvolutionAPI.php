<?php
/**
 * EvolutionAPI.php
 * Cliente REST para o Evolution Go (WhatsApp â€” implementaÃ§Ã£o em Go/whatsmeow).
 * DocumentaÃ§Ã£o: https://docs.evolutionfoundation.com.br/evolution-go
 *
 * ARQUITETURA CHAVE (diferente da versÃ£o Node.js):
 *   - O instanceName NÃƒO aparece nas URLs de send/status/qr.
 *   - A instÃ¢ncia Ã© identificada pelo token dela no header "apikey".
 *   - Cada instÃ¢ncia tem seu prÃ³prio token (retornado ao criar via createInstance).
 *   - A globalApiKey Ã© usada apenas para criar instÃ¢ncias e listar todas.
 *
 * FLUXO:
 *   1. createInstance($name)           â†’ salvar $result['data']['token'] no banco
 *   2. connectInstance($token, $wh)    â†’ inicia conexÃ£o, configura webhook
 *   3. getQRCode($token)               â†’ retorna QR base64 para escanear
 *   4. getStatus($token)               â†’ { Connected: bool, LoggedIn: bool }
 *   5. sendText($token, $number, $msg) â†’ envia mensagem usando o token da instÃ¢ncia
 */
class EvolutionAPI {

    private string $baseUrl;
    private string $globalApiKey;
    /** Timeout maior para redes lentas sob carga (send/text). */
    private int $timeout = 35;

    public function __construct(string $baseUrl, string $globalApiKey) {
        $this->baseUrl      = rtrim($baseUrl, '/');
        $this->globalApiKey = $globalApiKey;
    }

    // ================================================================
    // INSTÃ‚NCIAS (usam globalApiKey)
    // ================================================================

    /**
     * Cria uma nova instÃ¢ncia WhatsApp.
     * Retorna o token da instÃ¢ncia em: $result['data']['token']
     * SALVE esse token no banco â€” ele Ã© necessÃ¡rio para todas as outras operaÃ§Ãµes.
     */
    public function createInstance(string $name): array {
        $token = bin2hex(random_bytes(16));
        return $this->post('/instance/create', [
            'name' => $name,
            'token' => $token
        ], $this->globalApiKey);
    }

    /**
     * Conecta a instÃ¢ncia ao WhatsApp e configura o webhook.
     * Usar o TOKEN da instÃ¢ncia (nÃ£o a global key).
     * O webhook receberÃ¡ eventos: MESSAGE, CONNECTION, QRCODE, etc.
     *
     * @param string $instanceToken  Token retornado pelo createInstance
     * @param string $webhookUrl     URL do seu backend para receber eventos
     */
    public function connectInstance(string $instanceToken, string $webhookUrl = ''): array {
        // Documentação: se subscribe vazio, só MESSAGE. Mantemos o mínimo usado pelo PHP (status/QR no painel).
        // Não incluir SEND_MESSAGE (evita eco); READ_RECEIPT/PRESENCE não são tratados neste webhook.
        $payload = [
            'immediate'  => true,
            'subscribe'  => ['MESSAGE', 'CONNECTION', 'QRCODE'],
        ];
        if ($webhookUrl) {
        
            $payload['webhookUrl'] = $webhookUrl;
        }
        return $this->post('/instance/connect', $payload, $instanceToken);
    }

    /**
     * Busca o QR Code para escanear com o WhatsApp.
     * Retorna em: $result['data']['Qrcode'] (base64 png, pronto para <img src="...">)
     * e $result['data']['Code'] (texto do cÃ³digo).
     *
     * @param string $instanceToken  Token da instÃ¢ncia
     */
    public function getQRCode(string $instanceToken): array {
        return $this->get('/instance/qr', $instanceToken);
    }

    /**
     * Verifica o status de conexÃ£o da instÃ¢ncia.
     * Retorna: $result['data']['Connected'] (bool) e $result['data']['LoggedIn'] (bool)
     * InstÃ¢ncia pronta para uso = Connected true AND LoggedIn true.
     *
     * @param string $instanceToken  Token da instÃ¢ncia
     */
    public function getStatus(string $instanceToken): array {
        return $this->get('/instance/status', $instanceToken);
    }

    /**
     * Lista todas as instÃ¢ncias (usa global key).
     */
    public function listInstances(): array {
        return $this->get('/instance/all', $this->globalApiKey);
    }

    /**
     * Deleta/desconecta uma instÃ¢ncia (usa token da instÃ¢ncia).
     *
     * @param string $instanceToken  Token da instÃ¢ncia
     */
    public function deleteInstance(string $instanceToken): array {
        return $this->delete('/instance/delete', $instanceToken);
    }

    /**
     * Reinicia a conexÃ£o da instÃ¢ncia (usa token da instÃ¢ncia).
     *
     * @param string $instanceToken  Token da instÃ¢ncia
     */
    public function restartInstance(string $instanceToken): array {
        return $this->post('/instance/restart', [], $instanceToken);
    }

    // ================================================================
    // MENSAGENS (todas usam o TOKEN da instÃ¢ncia)
    // ================================================================

    /**
     * Envia mensagem de texto.
     *
     * @param string $instanceToken  Token da instÃ¢ncia remetente
     * @param string $number         NÃºmero destino (ex: 5511999999999)
     * @param string $text           Texto da mensagem
     * @param int    $delay          Delay em ms antes de enviar (simula digitaÃ§Ã£o)
     */
    public function sendText(string $instanceToken, string $number, string $text, int $delay = 500): array {
        return $this->post('/send/text', [
            'number' => $this->formatNumber($number),
            'text'   => $text,
            'delay'  => $delay,
        ], $instanceToken);
    }

    /**
     * Envia mÃ­dia (imagem, documento, vÃ­deo).
     *
     * @param string $instanceToken  Token da instÃ¢ncia
     * @param string $number         NÃºmero destino
     * @param string $url            URL pÃºblica do arquivo
     * @param string $type           Tipo: 'image', 'document', 'video', 'audio'
     * @param string $caption        Legenda (opcional)
     * @param string $filename       Nome do arquivo (para documentos)
     */
    public function sendMedia(string $instanceToken, string $number, string $url, string $type = 'image', string $caption = '', string $filename = ''): array {
        $payload = [
            'number'  => $this->formatNumber($number),
            'url'     => $url,
            'type'    => $type,
            'caption' => $caption,
        ];
        if ($filename) {
            $payload['filename'] = $filename;
        }
        return $this->post('/send/media', $payload, $instanceToken);
    }

    /**
     * Atalho para enviar imagem.
     */
    public function sendImage(string $instanceToken, string $number, string $imageUrl, string $caption = ''): array {
        return $this->sendMedia($instanceToken, $number, $imageUrl, 'image', $caption);
    }

    /**
     * Envia Ã¡udio (URL pÃºblica de arquivo .ogg/.mp4).
     */
    public function sendAudio(string $instanceToken, string $number, string $audioUrl): array {
        return $this->sendMedia($instanceToken, $number, $audioUrl, 'audio');
    }

    // ================================================================
    // UTILITÃRIOS
    // ================================================================

    /**
     * Testa se a API estÃ¡ acessÃ­vel usando a global key.
     */
    public function ping(): bool {
        try {
            $result = $this->get('/instance/all', $this->globalApiKey);
            return !isset($result['error']) || !$result['error'];
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Extrai o token da instÃ¢ncia da resposta do createInstance.
     * Retorna null se nÃ£o encontrado.
     */
    public static function extractToken(array $createResponse): ?string {
        return $createResponse['data']['token'] ?? null;
    }

    /**
     * Verifica se a instÃ¢ncia estÃ¡ totalmente conectada e pronta.
     * Usa a resposta do getStatus().
     */
    public static function isConnected(array $statusResponse): bool {
        $data = $statusResponse['data'] ?? [];
        return ($data['Connected'] ?? false) === true
            && ($data['LoggedIn'] ?? false) === true;
    }

    /**
     * Extrai o QR Code base64 da resposta do getQRCode.
     * Retorna a string pronta para usar em <img src="...">.
     */
    public static function extractQRCode(array $qrResponse): ?string {
        return $qrResponse['data']['Qrcode'] ?? null;
    }

    /**
     * Formata nÃºmero para o padrÃ£o Evolution Go.
     * Entrada: 11999999999 ou 5511999999999
     * SaÃ­da:   5511999999999@s.whatsapp.net
     */
    private function formatNumber(string $number): string {
        $clean = preg_replace('/\D/', '', $number);
        // Adiciona DDI Brasil se nÃ£o tiver
        if (strlen($clean) <= 11 && substr($clean, 0, 2) !== '55') {
            $clean = '55' . $clean;
        }
        return $clean . '@s.whatsapp.net';
    }

    // ================================================================
    // HTTP HELPERS
    // ================================================================

    private function get(string $endpoint, string $apiKey): array {
        return $this->request('GET', $endpoint, [], $apiKey);
    }

    private function post(string $endpoint, array $body, string $apiKey): array {
        return $this->request('POST', $endpoint, $body, $apiKey);
    }

    private function delete(string $endpoint, string $apiKey): array {
        return $this->request('DELETE', $endpoint, [], $apiKey);
    }

    /**
     * Executa a requisiÃ§Ã£o HTTP com cURL.
     * O header "apikey" Ã© sempre o token passado â€” seja global key ou token de instÃ¢ncia.
     */
    private function request(string $method, string $endpoint, array $body, string $apiKey): array {
        $url = $this->baseUrl . $endpoint;
        $ch  = curl_init($url);

        $headers = [
            'Content-Type: application/json',
            'apikey: ' . $apiKey,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['error' => true, 'message' => "Erro cURL: {$curlError}", 'code' => 0];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return ['error' => true, 'message' => "Resposta invÃ¡lida da API (HTTP {$httpCode}): {$response}", 'code' => $httpCode];
        }

        if ($httpCode >= 400) {
            $msg = $decoded['error']['message'] ?? $decoded['message'] ?? "HTTP {$httpCode}";
            return ['error' => true, 'message' => $msg, 'code' => $httpCode, 'raw' => $decoded];
        }

        return $decoded;
    }
    public function sendWhatsAppAudioBase64(string $instanceToken, string $instanceName, string $number, string $base64, string $mimetype = 'audio/mpeg'): array {
        $endpoint = "/message/sendWhatsAppAudio/{$instanceName}";
        $body = [
            'number'   => $this->formatNumber($number),
            'audio'    => $base64,
            'mimetype' => $mimetype,
            'delay'    => 500, // Mostra status gravando (ms); 1200 atrasava a entrega percebida
        ];

        return $this->request('POST', $endpoint, $body, $instanceToken);
    }
}
?>


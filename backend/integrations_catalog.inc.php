<?php
declare(strict_types=1);

/**
 * Catálogo de integrações disponíveis na plataforma.
 */
function auvvo_integrations_catalog(): array
{
    return [
        [
            'id'          => 'google_sheets',
            'name'        => 'Google Sheets',
            'description' => 'Envie leads e eventos do CRM para uma planilha automaticamente.',
            'icon'        => 'ph-table',
            'type'        => 'oauth',
            'connect_url' => 'backend/google_sheets_connect.php',
            'config_url'  => 'integracoes?panel=sheets',
            'category'    => 'google',
        ],
        [
            'id'          => 'google_calendar',
            'name'        => 'Google Calendar',
            'description' => 'Agendamentos automáticos pelos agentes de IA.',
            'icon'        => 'ph-calendar',
            'type'        => 'oauth',
            'connect_url' => 'backend/google_calendar_connect.php',
            'config_url'  => 'configuracoes#gcal',
            'category'    => 'google',
        ],
        [
            'id'          => 'webhooks',
            'name'        => 'Webhooks',
            'description' => 'Receba e envie dados (Hotmart, Shopify, APIs custom).',
            'icon'        => 'ph-plugs-connected',
            'type'        => 'native',
            'connect_url' => 'webhooks',
            'config_url'  => 'webhooks',
            'category'    => 'api',
        ],
        [
            'id'          => 'api_interna',
            'name'        => 'API REST Auvvo',
            'description' => 'Chaves de API para CRM, agentes e automações via HTTP.',
            'icon'        => 'ph-code',
            'type'        => 'api_key',
            'connect_url' => 'integracoes?panel=api',
            'config_url'  => 'integracoes?panel=api',
            'category'    => 'api',
        ],
        [
            'id'          => 'automacoes',
            'name'        => 'Automações',
            'description' => 'Regras quando o lead muda de estágio ou recebe tag.',
            'icon'        => 'ph-lightning',
            'type'        => 'native',
            'connect_url' => 'automacoes',
            'config_url'  => 'automacoes',
            'category'    => 'internal',
        ],
        [
            'id'          => 'http_custom',
            'name'        => 'HTTP / REST custom',
            'description' => 'Dispare URLs externas com corpo JSON e variáveis do CRM.',
            'icon'        => 'ph-globe',
            'type'        => 'http',
            'connect_url' => 'integracoes?panel=http',
            'config_url'  => 'integracoes?panel=http',
            'category'    => 'api',
        ],
        [
            'id'          => 'evolution',
            'name'        => 'WhatsApp (Evolution)',
            'description' => 'Linhas WhatsApp nomeadas — conecte QR e use em automações.',
            'icon'        => 'ph-whatsapp-logo',
            'type'        => 'native',
            'connect_url' => 'conexoes',
            'config_url'  => 'conexoes',
            'category'    => 'comms',
        ],
        [
            'id'          => 'openrouter',
            'name'        => 'IA (OpenRouter / Gemini)',
            'description' => 'Motor de linguagem dos agentes.',
            'icon'        => 'ph-brain',
            'type'        => 'config',
            'connect_url' => 'configuracoes#ai',
            'config_url'  => 'configuracoes#ai',
            'category'    => 'ai',
        ],
    ];
}

function auvvo_integration_status(PDO $pdo, int $userId, string $integrationId): array
{
    switch ($integrationId) {
        case 'google_sheets':
            require_once __DIR__ . '/GoogleSheets.php';
            $t = GoogleSheets::loadToken($pdo, $userId);

            return [
                'connected' => $t !== null,
                'detail'    => $t ? ('Planilha: ' . ($t['spreadsheet_id'] ?: 'não configurada')) : 'Não conectado',
            ];
        case 'google_calendar':
            require_once __DIR__ . '/GoogleCalendar.php';
            $t = GoogleCalendar::loadToken($pdo, $userId);

            return [
                'connected' => $t !== null,
                'detail'    => $t ? 'Conectado' : 'Não conectado',
            ];
        case 'webhooks':
            try {
                auvvo_run_migrations($pdo);
                $st = $pdo->prepare('SELECT COUNT(*) FROM inbound_webhooks WHERE user_id = ?');
                $st->execute([$userId]);
                $c = (int) $st->fetchColumn();
                $st2 = $pdo->prepare('SELECT COUNT(*) FROM outbound_webhooks WHERE user_id = ?');
                $st2->execute([$userId]);
                $c += (int) $st2->fetchColumn();

                return ['connected' => $c > 0, 'detail' => "{$c} webhook(s)"];
            } catch (PDOException $e) {
                return ['connected' => false, 'detail' => '—'];
            }
        case 'api_interna':
            require_once __DIR__ . '/ApiAuth.php';
            $keys = ApiAuth::listKeys($pdo, $userId);
            $active = count(array_filter($keys, static fn($k) => (int) ($k['is_active'] ?? 0) === 1));

            return ['connected' => $active > 0, 'detail' => "{$active} chave(s) ativa(s)"];
        case 'evolution':
            require_once __DIR__ . '/whatsapp_connections.inc.php';
            $list = auvvo_whatsapp_connections_list($pdo, $userId);
            $online = count(array_filter($list, static fn ($c) => ($c['status'] ?? '') === 'online'));

            return [
                'connected' => $online > 0,
                'detail'    => count($list) ? ($online . ' online · ' . count($list) . ' linha(s)') : 'Nenhuma linha',
            ];
        default:
            return ['connected' => true, 'detail' => 'Nativo'];
    }
}

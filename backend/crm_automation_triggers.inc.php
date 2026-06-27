<?php

declare(strict_types=1);



require_once __DIR__ . '/crm_automation.inc.php';

require_once __DIR__ . '/crm_automation_motor.inc.php';



/**

 * Dispara automações (regras + fluxos visuais) para eventos WhatsApp / Evolution.

 * Um único passe por par (gatilho, valor) — evita varrer fluxos repetidas vezes.

 */

function auvvo_crm_fire_whatsapp_triggers(

    PDO $pdo,

    int $userId,

    int $agentId,

    array $contact,

    bool $isFirstMessage,

    string $messageBody = '',

    int $connectionId = 0

): void {

    if ($userId <= 0 || empty($contact['id'])) {

        return;

    }



    $events = [];
    if ($isFirstMessage) {
        if ($connectionId > 0) {
            $events[] = ['whatsapp_first', (string) $connectionId];
        }
        $events[] = ['whatsapp_first', (string) $agentId];
        $events[] = ['contact_created', 'whatsapp'];
    } else {
        if ($connectionId > 0) {
            $events[] = ['whatsapp_message', (string) $connectionId];
        }
        $events[] = ['whatsapp_message', (string) $agentId];
    }


    auvvo_crm_run_automation_events($pdo, $userId, $events, $contact, [

        'message_body'            => $messageBody,

        'trigger_agent_id'        => $agentId,

        'whatsapp_connection_id'  => $connectionId,

    ]);

}

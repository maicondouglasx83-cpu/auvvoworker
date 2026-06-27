<?php
declare(strict_types=1);

/**
 * Monta mensagens de um thread a partir de linhas de conversation_logs.
 *
 * @param list<array> $logs
 * @return list<array{t:string,text:string,time?:string}>
 */
function auvvo_conv_messages_from_logs(array $logs): array
{
    $messages = [];
    foreach ($logs as $log) {
        $time = date('H:i', strtotime((string) ($log['created_at'] ?? 'now')));
        if (!empty($log['incoming_msg'])) {
            $messages[] = ['t' => 'received', 'text' => (string) $log['incoming_msg'], 'time' => $time];
            if (trim((string) ($log['response_msg'] ?? '')) === '' && ($log['type'] ?? '') === 'ai') {
                $messages[] = ['t' => 'system', 'text' => '⏳ Processando resposta da IA…', 'time' => $time];
            }
        }
        if (!empty($log['response_msg'])) {
            if (($log['type'] ?? '') === 'handoff') {
                $messages[] = ['t' => 'system', 'text' => '⚠️ ' . (string) $log['response_msg']];
            } else {
                $messages[] = ['t' => 'sent', 'text' => (string) $log['response_msg'], 'time' => $time];
            }
        }
    }

    return $messages;
}

/**
 * Metadados de conversa a partir da última linha do thread (+ opcional status).
 *
 * @return array{id:string,name:string,avatar:string,avatarBg:string,avatarColor:string,agentId:int,contactJid:string,status:string,badge:string,badgeClass:string,lastMsg:string,time:string,messages:list}
 */
function auvvo_conv_thread_from_last_log(array $log, string $iaActiveLabel, string $waitingLabel): array
{
    $peer = auvvo_whatsapp_peer_digits($log['contact_jid'] ?? '');
    $cidKey = $peer !== '' ? $peer : ('lid_' . md5((string) $log['contact_jid']));
    $cid = (int) $log['agent_id'] . '_' . $cidKey;
    $displayPhone = $peer !== '' ? $peer : preg_replace('/\D/', '', explode('@', (string) $log['contact_jid'])[0]);
    $contactJid = $peer !== '' ? ($peer . '@s.whatsapp.net') : (string) $log['contact_jid'];
    $name = $peer !== '' ? ('+' . $peer) : ('Contato ' . mb_substr((string) $displayPhone, 0, 12));

    $lastMsg = '';
    $time = '';
    $status = 'ia_active';
    $badge = $iaActiveLabel;
    $badgeClass = 'badge-success';

    if (!empty($log['incoming_msg'])) {
        $lastMsg = (string) $log['incoming_msg'];
        $time = date('H:i', strtotime((string) $log['created_at']));
    }
    if (!empty($log['response_msg'])) {
        $lastMsg = (string) $log['response_msg'];
        $time = date('H:i', strtotime((string) $log['created_at']));
        if (($log['type'] ?? '') === 'handoff') {
            $status = 'waiting_human';
            $badge = $waitingLabel;
            $badgeClass = 'badge-danger';
        }
    }

    return [
        'id'          => $cid,
        'name'        => $name,
        'avatar'      => strtoupper(substr((string) $displayPhone, 0, 1)),
        'avatarBg'    => '#F3F4F6',
        'avatarColor' => '#4B5563',
        'agentId'     => (int) $log['agent_id'],
        'contactJid'  => $contactJid,
        'status'      => $status,
        'badge'       => $badge,
        'badgeClass'  => $badgeClass,
        'lastMsg'     => $lastMsg,
        'time'        => $time,
        'messages'    => auvvo_conv_messages_from_logs([$log]),
    ];
}

<?php
declare(strict_types=1);

/**
 * Estado de agendamento conversacional — interpreta "amanhã pela manhã" etc.
 * e evita calendar.create_event com payload incompleto (travava a resposta).
 */
require_once __DIR__ . '/context_memory.inc.php';

const AUVVO_SCHED_TZ = 'America/Sao_Paulo';

/** @return array<string, string> */
function auvvo_scheduling_keys(): array
{
    return [
        '_sched_date'    => 'Data preferida (Y-m-d)',
        '_sched_period'  => 'Período (manha|tarde|noite)',
        '_sched_time'    => 'Horário (H:i)',
        '_sched_status'  => 'Status (collecting|ready|confirmed)',
        '_sched_label'   => 'Resumo legível',
    ];
}

/**
 * Atualiza memória do contato a partir da mensagem recebida.
 *
 * @return array{date:?string,period:?string,time:?string,status:string,label:string,changed:bool}
 */
function auvvo_scheduling_process_inbound(PDO $pdo, int $userId, string $jid, string $body): array
{
    $jid = trim($jid);
    if ($userId <= 0 || $jid === '') {
        return auvvo_scheduling_empty_state(false);
    }

    $mem = auvvo_contact_memory_get_resolved($pdo, $userId, $jid);
    $parsed = auvvo_scheduling_parse_message($body);
    $patch = [];
    $changed = false;

    if ($parsed['date'] !== null) {
        $patch['_sched_date'] = $parsed['date'];
        $changed = true;
    }
    if ($parsed['period'] !== null) {
        $patch['_sched_period'] = $parsed['period'];
        $changed = true;
    }
    if ($parsed['time'] !== null) {
        $patch['_sched_time'] = $parsed['time'];
        $changed = true;
    }

    if ($changed) {
        $state = array_merge($mem, $patch);
        $state['_sched_status'] = auvvo_scheduling_derive_status($state);
        $state['_sched_label'] = auvvo_scheduling_human_label($state);
        auvvo_contact_memory_merge($pdo, $userId, $jid, [
            '_sched_date'   => $state['_sched_date'] ?? null,
            '_sched_period' => $state['_sched_period'] ?? null,
            '_sched_time'   => $state['_sched_time'] ?? null,
            '_sched_status' => $state['_sched_status'],
            '_sched_label'  => $state['_sched_label'],
        ]);

        return [
            'date'    => $state['_sched_date'] ?? null,
            'period'  => $state['_sched_period'] ?? null,
            'time'    => $state['_sched_time'] ?? null,
            'status'  => (string) ($state['_sched_status'] ?? 'collecting'),
            'label'   => (string) ($state['_sched_label'] ?? ''),
            'changed' => true,
        ];
    }

    return auvvo_scheduling_state_from_memory($mem, false);
}

/**
 * @return array{date:?string,period:?string,time:?string}
 */
function auvvo_scheduling_parse_message(string $body): array
{
    $s = mb_strtolower(trim($body));
    if ($s === '') {
        return ['date' => null, 'period' => null, 'time' => null];
    }

    $date = null;
    $period = null;
    $time = null;

    if (preg_match('/\b(hoje)\b/u', $s)) {
        $date = auvvo_scheduling_today();
    } elseif (preg_match('/\b(depois de amanh[aã]|depois-de-amanh[aã])\b/u', $s)) {
        $date = auvvo_scheduling_offset_days(2);
    } elseif (preg_match('/\b(amanh[aã])\b/u', $s)) {
        $date = auvvo_scheduling_offset_days(1);
    } elseif (preg_match('/\b(segunda|ter[cç]a|quarta|quinta|sexta|s[aá]bado|domingo)(?:-feira)?\b/u', $s, $m)) {
        $date = auvvo_scheduling_next_weekday($m[1]);
    } elseif (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/u', $s, $m)) {
        $date = auvvo_scheduling_parse_slash_date($m);
    }

    if (preg_match('/\b(pela?\s+)?(manh[aã]|de\s+manh[aã])\b/u', $s)) {
        $period = 'manha';
        $time = $time ?? '10:00';
    } elseif (preg_match('/\b(pela?\s+)?(tarde|de\s+tarde|à\s+tarde|a\s+tarde)\b/u', $s)) {
        $period = 'tarde';
        $time = $time ?? '14:00';
    } elseif (preg_match('/\b(pela?\s+)?(noite|de\s+noite|à\s+noite|a\s+noite)\b/u', $s)) {
        $period = 'noite';
        $time = $time ?? '19:00';
    }

    if (preg_match('/\b(\d{1,2})\s*h\s*(\d{2})?\b/u', $s, $m)) {
        $h = min(23, max(0, (int) $m[1]));
        $min = isset($m[2]) && $m[2] !== '' ? min(59, max(0, (int) $m[2])) : 0;
        $time = sprintf('%02d:%02d', $h, $min);
    } elseif (preg_match('/\b(\d{1,2}):(\d{2})\b/u', $s, $m)) {
        $time = sprintf('%02d:%02d', min(23, (int) $m[1]), min(59, (int) $m[2]));
    } elseif (preg_match('/\b(\d{1,2})\s*horas?\b/u', $s, $m)) {
        $time = sprintf('%02d:00', min(23, (int) $m[1]));
    }

    return ['date' => $date, 'period' => $period, 'time' => $time];
}

/**
 * Monta payload calendar.create_event a partir da memória quando a IA omitiu horários.
 *
 * @return array{start:string,end:string,timezone:string,summary:string}|null
 */
function auvvo_scheduling_build_event_payload(PDO $pdo, int $userId, string $jid, array $payload, int $durationMin = 20): ?array
{
    $start = trim((string) ($payload['start'] ?? ''));
    $end = trim((string) ($payload['end'] ?? ''));
    if ($start !== '' && $end !== '') {
        return null;
    }

    $mem = auvvo_contact_memory_get_resolved($pdo, $userId, $jid);
    $state = auvvo_scheduling_state_from_memory($mem, false);
    if ($state['date'] === null) {
        return null;
    }

    $time = $state['time'];
    if ($time === null && $state['period'] !== null) {
        $time = match ($state['period']) {
            'tarde' => '14:00',
            'noite' => '19:00',
            default => '10:00',
        };
    }
    if ($time === null) {
        return null;
    }

    try {
        $tz = new DateTimeZone(AUVVO_SCHED_TZ);
        $dt = new DateTimeImmutable($state['date'] . ' ' . $time . ':00', $tz);
    } catch (Throwable $e) {
        return null;
    }

    $endDt = $dt->modify('+' . max(5, $durationMin) . ' minutes');
    $summary = trim((string) ($payload['summary'] ?? ''));
    if ($summary === '') {
        $summary = 'Reunião agendada via WhatsApp';
    }

    return [
        'start'    => $dt->format('Y-m-d\TH:i:s'),
        'end'      => $endDt->format('Y-m-d\TH:i:s'),
        'timezone' => AUVVO_SCHED_TZ,
        'summary'  => $summary,
    ];
}

function auvvo_scheduling_prompt_block(array $mem): string
{
    $state = auvvo_scheduling_state_from_memory($mem, false);
    if ($state['date'] === null && $state['period'] === null && $state['time'] === null) {
        return '';
    }

    $lines = [
        'ESTADO DE AGENDAMENTO (memória do lead — use na conversa):',
        '- Preferência: ' . ($state['label'] !== '' ? $state['label'] : 'em coleta'),
        '- Status: ' . $state['status'],
    ];

    if ($state['status'] === 'ready') {
        $lines[] = '- O cliente já indicou dia e período/horário. Confirme em uma frase curta e use calendar.create_event.';
        $lines[] = '- Se faltar só confirmação explícita (OK/sim), proponha o horário sugerido e aguarde OK antes de agendar.';
    } elseif ($state['status'] === 'collecting') {
        if ($state['date'] !== null && $state['time'] === null) {
            $lines[] = '- Falta o horário exato. Pergunte: "Qual horário funciona melhor?" — NÃO chame calendar.create_event ainda.';
        } elseif ($state['date'] === null) {
            $lines[] = '- Falta o dia. Pergunte a data preferida — NÃO chame calendar.create_event ainda.';
        }
    }

    return implode("\n", $lines);
}

function auvvo_scheduling_fallback_reply(array $mem, string $inbound): string
{
    $state = auvvo_scheduling_state_from_memory($mem, false);
    if ($state['date'] !== null && $state['time'] === null) {
        $label = $state['label'] !== '' ? $state['label'] : 'esse dia';

        return "Perfeito! Anotei {$label}. Qual horário funciona melhor — por exemplo 10h, 14h ou 16h?";
    }
    if ($state['date'] !== null && $state['time'] !== null) {
        return 'Ótimo! Só confirmando: ' . $state['label'] . '. Posso reservar esse horário? Responda SIM para confirmar.';
    }
    if (preg_match('/\b(agendar|reuni[aã]o|hor[aá]rio|call|demo)\b/ui', $inbound)) {
        return 'Claro! Qual dia e horário funcionam melhor para você essa semana?';
    }

    return 'Entendi! Para confirmar o agendamento, qual horário funciona melhor para você?';
}

function auvvo_scheduling_mark_confirmed(PDO $pdo, int $userId, string $jid): void
{
    auvvo_contact_memory_merge($pdo, $userId, $jid, ['_sched_status' => 'confirmed']);
}

/** @param array<string, mixed> $mem */
function auvvo_scheduling_state_from_memory(array $mem, bool $changed): array
{
    return [
        'date'    => isset($mem['_sched_date']) ? (string) $mem['_sched_date'] : null,
        'period'  => isset($mem['_sched_period']) ? (string) $mem['_sched_period'] : null,
        'time'    => isset($mem['_sched_time']) ? (string) $mem['_sched_time'] : null,
        'status'  => (string) ($mem['_sched_status'] ?? 'collecting'),
        'label'   => (string) ($mem['_sched_label'] ?? ''),
        'changed' => $changed,
    ];
}

/** @return array{date:?string,period:?string,time:?string,status:string,label:string,changed:bool} */
function auvvo_scheduling_empty_state(bool $changed): array
{
    return [
        'date'    => null,
        'period'  => null,
        'time'    => null,
        'status'  => 'collecting',
        'label'   => '',
        'changed' => $changed,
    ];
}

/** @param array<string, mixed> $state */
function auvvo_scheduling_derive_status(array $state): string
{
    $date = trim((string) ($state['_sched_date'] ?? ''));
    $time = trim((string) ($state['_sched_time'] ?? ''));
    $period = trim((string) ($state['_sched_period'] ?? ''));

    if ($date !== '' && ($time !== '' || $period !== '')) {
        return 'ready';
    }

    return 'collecting';
}

/** @param array<string, mixed> $state */
function auvvo_scheduling_human_label(array $state): string
{
    $date = trim((string) ($state['_sched_date'] ?? ''));
    $time = trim((string) ($state['_sched_time'] ?? ''));
    $period = trim((string) ($state['_sched_period'] ?? ''));

    if ($date === '') {
        return '';
    }

    try {
        $tz = new DateTimeZone(AUVVO_SCHED_TZ);
        $dt = new DateTimeImmutable($date, $tz);
        $today = new DateTimeImmutable('today', $tz);
        $diff = (int) $today->diff($dt)->format('%r%a');
        $dayLabel = match ($diff) {
            0       => 'hoje',
            1       => 'amanhã',
            2       => 'depois de amanhã',
            default => $dt->format('d/m/Y'),
        };
    } catch (Throwable $e) {
        $dayLabel = $date;
    }

    $periodLabel = match ($period) {
        'manha' => 'de manhã',
        'tarde' => 'à tarde',
        'noite' => 'à noite',
        default => '',
    };

    if ($time !== '') {
        $parts = explode(':', $time);

        return $dayLabel . ' às ' . (int) ($parts[0] ?? 0) . 'h' . (isset($parts[1]) && (int) $parts[1] > 0 ? (int) $parts[1] : '');
    }
    if ($periodLabel !== '') {
        return $dayLabel . ' ' . $periodLabel;
    }

    return $dayLabel;
}

function auvvo_scheduling_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(AUVVO_SCHED_TZ)))->format('Y-m-d');
}

function auvvo_scheduling_offset_days(int $days): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(AUVVO_SCHED_TZ)))
        ->modify('+' . $days . ' days')
        ->format('Y-m-d');
}

function auvvo_scheduling_next_weekday(string $name): ?string
{
    $map = [
        'domingo' => 0,
        'segunda' => 1,
        'terca'   => 2,
        'terça'   => 2,
        'quarta'  => 3,
        'quinta'  => 4,
        'sexta'   => 5,
        'sabado'  => 6,
        'sábado'  => 6,
    ];
    $key = mb_strtolower(preg_replace('/-feira/u', '', $name) ?? $name);
    if (!isset($map[$key])) {
        return null;
    }
    $target = $map[$key];
    $tz = new DateTimeZone(AUVVO_SCHED_TZ);
    $dt = new DateTimeImmutable('now', $tz);
    for ($i = 0; $i < 8; $i++) {
        if ((int) $dt->format('w') === $target) {
            return $dt->format('Y-m-d');
        }
        $dt = $dt->modify('+1 day');
    }

    return null;
}

/** @param array<int, string> $m */
function auvvo_scheduling_parse_slash_date(array $m): ?string
{
    $d = (int) ($m[1] ?? 0);
    $mo = (int) ($m[2] ?? 0);
    $y = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) date('Y');
    if ($y < 100) {
        $y += 2000;
    }
    if ($d < 1 || $d > 31 || $mo < 1 || $mo > 12) {
        return null;
    }
    if (!checkdate($mo, $d, $y)) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

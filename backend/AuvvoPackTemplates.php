<?php
declare(strict_types=1);

require_once __DIR__ . '/AgentTemplates.php';
require_once __DIR__ . '/migrations.php';

/**
 * Pacotes (stacks): vários agentes + metadados para fluxos vinculados no frontend.
 */
class AuvvoPackTemplates
{
    /** @return list<array<string, mixed>> */
    public static function listForUi(): array
    {
        return [
            [
                'id'          => 'agencia_stack',
                'sector'      => 'Agência',
                'name'        => 'Agência digital — equipe IA',
                'description' => '3 agentes + 3 fluxos (primeiro contato, estágio proposta, tag suporte). Cérebro IA para proposta/Calendar.',
                'icon'        => 'ph-megaphone',
                'color'       => '#6366f1',
                'agent_count' => 3,
                'flow_count'  => 3,
                'highlights'    => ['Primeiro contato', 'Estágio proposta', 'Tag suporte', 'Memória interesse', 'Missão cérebro'],
                'agent_labels'  => ['Recepção — Agência (Auvvo)', 'Comercial — Agência (Vendas)', 'Suporte — Agência (Técnico)'],
            ],
            [
                'id'          => 'clinica_stack',
                'sector'      => 'Clínica',
                'name'        => 'Clínica — jornada do paciente',
                'description' => '2 agentes + lembrete por estágio, triagem por tag e NPS pós-consulta com missão IA.',
                'icon'        => 'ph-heartbeat',
                'color'       => '#ec4899',
                'agent_count' => 2,
                'flow_count'  => 3,
                'highlights'    => ['Lembrete 24h', 'Tag triagem', 'NPS cérebro', 'Agendamento no agente'],
                'agent_labels'  => ['Secretária — Clínica', 'Pós-consulta — Clínica'],
            ],
            [
                'id'          => 'ecommerce_stack',
                'sector'      => 'E-commerce',
                'name'        => 'Loja online — vendas e recuperação',
                'description' => '2 agentes + A/B primeiro contato, carrinho por tag e pós-compra por tag (sem keyword).',
                'icon'        => 'ph-shopping-cart',
                'color'       => '#f59e0b',
                'agent_count' => 2,
                'flow_count'  => 3,
                'highlights'    => ['Primeiro contato A/B', 'Tag carrinho', 'Tag comprou', 'Recuperação IA'],
                'agent_labels'  => ['Vendas — Loja', 'Recuperação — Loja (carrinho)'],
            ],
            [
                'id'          => 'saas_stack',
                'sector'      => 'SaaS / B2B',
                'name'        => 'SaaS — SDR até Customer Success',
                'description' => '4 agentes + primeiro contato BANT, demo por tag com Calendar e CS por tag urgente.',
                'icon'        => 'ph-rocket-launch',
                'color'       => '#0ea5e9',
                'agent_count' => 4,
                'flow_count'  => 3,
                'highlights'    => ['Primeiro contato', 'Tag demo', 'Missão Calendar', 'Tag suporte CS'],
                'agent_labels'  => ['SDR — Qualificação', 'Demo — Pré-vendas', 'Onboarding — CS', 'Customer Success'],
            ],
            [
                'id'          => 'restaurante_stack',
                'sector'      => 'Restaurante',
                'name'        => 'Delivery — pedidos automáticos',
                'description' => '1 agente + horário comercial e confirmação por tag pedido-confirmado (agente marca a tag).',
                'icon'        => 'ph-pizza',
                'color'       => '#ef4444',
                'agent_count' => 1,
                'flow_count'  => 2,
                'highlights'    => ['Horário 18h–23h', 'Tag pedido', 'Memória pedido', 'Cardápio no agente'],
                'agent_labels'  => ['Atendente — Delivery'],
            ],
            [
                'id'          => 'barbearia_stack',
                'sector'      => 'Agendamento',
                'name'        => 'Barbearia / Salão / Clínica',
                'description' => '1 agente de agendamentos + lembrete 24h, confirmação e follow-up pós-serviço.',
                'icon'        => 'ph-scissors',
                'color'       => '#06b6d4',
                'agent_count' => 1,
                'flow_count'  => 3,
                'highlights'    => ['Agendamento conversacional', 'Lembrete 24h', 'Confirmação automática', 'Pós-serviço NPS'],
                'agent_labels'  => ['Recepcionista — Agendamentos'],
            ],
            [
                'id'          => 'recuperacao_stack',
                'sector'      => 'E-commerce',
                'name'        => 'Recuperação de Carrinho + Pix',
                'description' => '1 agente + 3 fluxos: abandono em 2h, cupom em 24h, urgência de estoque em 48h. Webhook-ready.',
                'icon'        => 'ph-arrow-u-up-left',
                'color'       => '#f97316',
                'agent_count' => 1,
                'flow_count'  => 3,
                'highlights'    => ['Recuperação 2h/24h/48h', 'Cupom automático', 'Link de Pix/Boleto', 'Webhook integrado'],
                'agent_labels'  => ['Recuperação — Carrinho'],
            ],
            [
                'id'          => 'imobiliaria_stack',
                'sector'      => 'Imobiliária',
                'name'        => 'Corretor Virtual — Imóveis',
                'description' => '1 agente imobiliário + filtragem por chat, envio de links e agendamento de visita.',
                'icon'        => 'ph-buildings',
                'color'       => '#84cc16',
                'agent_count' => 1,
                'flow_count'  => 2,
                'highlights'    => ['Filtragem conversacional', 'Top 3 imóveis', 'Agendamento de visita', 'Follow-up pós-visita'],
                'agent_labels'  => ['Corretor — Imobiliária'],
            ],
            [
                'id'          => 'faq_stack',
                'sector'      => 'Atendimento',
                'name'        => 'FAQ Inteligente + Transbordo',
                'description' => '1 agente FAQ alimentado com base de conhecimento + transbordo automático para humano quando cliente irritado ou dúvida complexa.',
                'icon'        => 'ph-chat-centered-text',
                'color'       => '#a855f7',
                'agent_count' => 1,
                'flow_count'  => 2,
                'highlights'    => ['Base de conhecimento', 'Resolução 80%+', 'Detecção de irritação', 'Transbordo humano'],
                'agent_labels'  => ['FAQ — Atendimento'],
            ],
        ];
    }

    public static function getPackId(string $packId): ?array
    {
        foreach (self::listForUi() as $p) {
            if ($p['id'] === $packId) {
                return $p;
            }
        }

        return null;
    }

    /**
     * @return array{agents: array<string, int>, agent_names: array<string, string>, agent_rows: list<array>}
     */
    public static function provisionAgents(PDO $pdo, int $userId, string $packId): array
    {
        auvvo_run_migrations($pdo);
        $specs = self::agentSpecsForPack($packId);
        if ($specs === []) {
            throw new InvalidArgumentException('Pacote inválido.');
        }

        $company = '';
        $niche = '';
        try {
            $st = $pdo->prepare('SELECT company_name, company_niche FROM settings WHERE user_id = ? LIMIT 1');
            $st->execute([$userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $company = trim((string) ($row['company_name'] ?? ''));
                $niche = trim((string) ($row['company_niche'] ?? ''));
            }
        } catch (PDOException $e) {
        }

        $map = [];
        $names = [];
        $rows = [];
        $partners = [];

        foreach ($specs as $spec) {
            $key = (string) $spec['key'];
            $type = (string) $spec['agent_type'];
            $name = (string) $spec['name'];
            $role = (string) ($spec['role'] ?? 'Atendente');
            $custom = (string) ($spec['prompt_base'] ?? '');
            $prompt = AgentTemplates::get($type, $name, $company, $niche, $custom);
            $prompt .= self::brainPlaybookAppend($packId, $key);

            $handoffRules = (string) ($spec['handoff_rules'] ?? 'humano, atendente, especialista');
            $handoffMsg = (string) ($spec['handoff_message'] ?? 'Vou chamar um especialista para continuar com você. Um momento! 🙂');

            $stmt = $pdo->prepare(
                'INSERT INTO agents (user_id, agent_type, name, role, prompt_base, type_config, model, temperature,
                    max_tokens, response_delay, audio_enabled, audio_voice, handoff_rules, handoff_enabled,
                    handoff_message, bot_language, flow_mode, flow_config, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'waiting_qr\')'
            );
            $stmt->execute([
                $userId,
                $type,
                $name,
                $role,
                $prompt,
                isset($spec['type_config']) ? json_encode($spec['type_config'], JSON_UNESCAPED_UNICODE) : null,
                (string) ($spec['model'] ?? 'gpt-4o'),
                (float) ($spec['temperature'] ?? 0.7),
                (int) ($spec['max_tokens'] ?? 1200),
                (int) ($spec['response_delay'] ?? 2),
                0,
                '',
                $handoffRules,
                1,
                $handoffMsg,
                'pt-BR',
                'easy',
                '{}',
            ]);

            $id = (int) $pdo->lastInsertId();
            $map[$key] = $id;
            $names[$key] = $name;
            $rows[] = ['id' => $id, 'name' => $name, 'key' => $key, 'agent_type' => $type];
            if (!empty($spec['partner_key'])) {
                $partners[$key] = (string) $spec['partner_key'];
            }
        }

        foreach ($partners as $fromKey => $toKey) {
            if (!isset($map[$fromKey], $map[$toKey])) {
                continue;
            }
            $flowConfig = json_encode([
                'partner_agent_id' => $map[$toKey],
                'steps'            => [],
            ], JSON_UNESCAPED_UNICODE);
            $pdo->prepare('UPDATE agents SET flow_config = ? WHERE id = ? AND user_id = ?')
                ->execute([$flowConfig, $map[$fromKey], $userId]);
        }

        return ['agents' => $map, 'agent_names' => $names, 'agent_rows' => $rows];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function agentSpecsForPack(string $packId): array
    {
        switch ($packId) {
            case 'agencia_stack':
                return [
                    [
                        'key'             => 'recepcao',
                        'name'            => 'Recepção — Agência',
                        'agent_type'      => 'Auvvo',
                        'role'            => 'Recepção',
                        'partner_key'     => 'comercial',
                        'prompt_base'     => "Direcione orçamentos e propostas para o agente Comercial. Dúvidas técnicas de projeto para Suporte.\nMencione que a agência atende: tráfego pago, social media, sites e branding.",
                    ],
                    [
                        'key'             => 'comercial',
                        'name'            => 'Comercial — Agência',
                        'agent_type'      => 'vendedor',
                        'role'            => 'Vendas',
                        'partner_key'     => 'suporte',
                        'prompt_base'     => "Foco em briefings e propostas. Pergunte orçamento mensal, prazo e canais desejados.\nApós fechar escopo, o Suporte técnico pode detalhar implementação.",
                    ],
                    [
                        'key'             => 'suporte',
                        'name'            => 'Suporte — Agência',
                        'agent_type'      => 'suporte',
                        'role'            => 'Suporte técnico',
                        'prompt_base'     => "Ajude com dúvidas de campanha, pixel, criativos e prazos de entrega.\nEscale para humano se o cliente pedir reunião presencial ou contrato jurídico.",
                    ],
                ];

            case 'clinica_stack':
                return [
                    [
                        'key'         => 'secretaria',
                        'name'        => 'Secretária — Clínica',
                        'agent_type'  => 'atendente',
                        'role'        => 'Secretária',
                        'partner_key' => 'pos',
                        'prompt_base' => "Agende consultas, informe horários e convênios.\nNão dê diagnóstico médico — apenas orientações administrativas.",
                    ],
                    [
                        'key'         => 'pos',
                        'name'        => 'Pós-consulta — Clínica',
                        'agent_type'  => 'atendente',
                        'role'        => 'Pós-atendimento',
                        'prompt_base' => "Acompanhe satisfação após consulta, lembretes de retorno e exames.\nTom acolhedor e profissional.",
                    ],
                ];

            case 'ecommerce_stack':
                return [
                    [
                        'key'             => 'vendas',
                        'name'            => 'Vendas — Loja',
                        'agent_type'      => 'vendedor',
                        'role'            => 'Vendas online',
                        'partner_key'     => 'recuperacao',
                        'prompt_base'     => "Ajude na escolha de produtos, frete e formas de pagamento.\nOfereça cupom BEMVINDO10 na primeira compra quando fizer sentido.",
                    ],
                    [
                        'key'         => 'recuperacao',
                        'name'        => 'Recuperação — Loja',
                        'agent_type'  => 'vendedor',
                        'role'        => 'Recuperação de carrinho',
                        'prompt_base' => "Foco em carrinho abandonado e reativação. Tom persuasivo mas respeitoso.\nNão pressione mais de 2 vezes seguidas.",
                    ],
                ];

            case 'saas_stack':
                return [
                    [
                        'key'             => 'sdr',
                        'name'            => 'SDR — Qualificação',
                        'agent_type'      => 'sdr',
                        'role'            => 'SDR',
                        'partner_key'     => 'demo',
                        'prompt_base'     => "Qualifique com BANT. Meta: agendar demo de 20 min.\nICP: empresas 10–200 funcionários com dor em automação de atendimento.",
                    ],
                    [
                        'key'             => 'demo',
                        'name'            => 'Demo — Especialista',
                        'agent_type'      => 'vendedor',
                        'role'            => 'Pré-vendas',
                        'partner_key'     => 'onboarding',
                        'prompt_base'     => "Conduza demonstração do produto, tire objeções de preço e integração.\nApós assinatura, passe contexto ao Onboarding.",
                    ],
                    [
                        'key'             => 'onboarding',
                        'name'            => 'Onboarding — CS',
                        'agent_type'      => 'atendente',
                        'role'            => 'Onboarding',
                        'partner_key'     => 'cs',
                        'prompt_base'     => "Guie primeiros passos: conectar WhatsApp, criar agente, publicar fluxo.\nChecklist de go-live em 7 dias.",
                    ],
                    [
                        'key'         => 'cs',
                        'name'        => 'Customer Success',
                        'agent_type'  => 'suporte',
                        'role'        => 'Suporte & retenção',
                        'prompt_base' => "Suporte contínuo, renovação e upsell.\nEscale bugs críticos para humano com resumo em {{mensagens_hoje}}.",
                    ],
                ];

            case 'restaurante_stack':
                return [
                    [
                        'key'         => 'delivery',
                        'name'        => 'Atendente — Delivery',
                        'agent_type'  => 'restaurante',
                        'role'        => 'Pedidos',
                        'prompt_base' => "Cardapio, taxa de entrega e horario de funcionamento seg-dom 18h-23h.\nPIX e cartao na entrega. Sempre confirme endereco completo.\nOfereca combo ou sobremesa no fechamento.",
                    ],
                ];

            case 'barbearia_stack':
                return [
                    [
                        'key'         => 'recepcionista',
                        'name'        => 'Recepcionista — Agendamentos',
                        'agent_type'  => 'agendamentos',
                        'role'        => 'Recepcionista',
                        'prompt_base' => "Atendo barbearia, salao e clinica.\nServicos: Corte R$40, Barba R$30, Combo R$60.\nHorarios: Seg-Sab 9h-19h. Nao agende domingos e feriados.\nSempre confirme nome, telefone, servico, data e hora antes de finalizar.",
                    ],
                ];

            case 'recuperacao_stack':
                return [
                    [
                        'key'         => 'recuperacao',
                        'name'        => 'Recuperacao — Carrinho',
                        'agent_type'  => 'recuperacao',
                        'role'        => 'Recuperacao de vendas',
                        'prompt_base' => "Cupom ativo: VOLTA10 (10% OFF valido 48h).\nPix vence em 24h, boleto em 3 dias uteis.\nLink checkout: https://loja.com/checkout\nNao pressione mais de 2 vezes. Se cliente disser NAO, agradeca e deixe porta aberta.",
                    ],
                ];

            case 'imobiliaria_stack':
                return [
                    [
                        'key'         => 'corretor',
                        'name'        => 'Corretor — Imobiliaria',
                        'agent_type'  => 'imobiliaria',
                        'role'        => 'Corretor de imoveis',
                        'prompt_base' => "Atuo em Curitiba e regiao metropolitana.\nImoveis de R$200k a R$2M. Apartamentos, casas, coberturas e comerciais.\nAgende visitas com 24h de antecedencia. Documentos: RG, CPF, comprovante de renda.\nFiltre UM criterio por vez: tipo → finalidade → quartos → regiao → orcamento.",
                    ],
                ];

            case 'faq_stack':
                return [
                    [
                        'key'         => 'faq',
                        'name'        => 'FAQ — Atendimento',
                        'agent_type'  => 'faq_inteligente',
                        'role'        => 'Central de duvidas',
                        'prompt_base' => "Base de conhecimento carregada. Responda duvidas frequentes com precisao.\nPalavras de irritacao: absurdo, palhacada, processo, Procon, quero falar com gerente.\nAo detectar irritacao, acione transbordo IMEDIATAMENTE. Nao insista.\nHorario atendimento humano: Seg-Sex 9h-18h.",
                    ],
                ];

            default:
                return [];
        }
    }

    /**
     * Instruções do cérebro + tags que disparam fluxos do pacote (append ao prompt_base).
     */
    private static function brainPlaybookAppend(string $packId, string $agentKey): string
    {
        $tags = match ($packId) {
            'agencia_stack' => match ($agentKey) {
                'recepcao'  => 'lead-agencia | suporte-tecnico (escala técnico)',
                'comercial' => 'proposta-em-andamento | briefing-capturado — use crm.add_tag ao fechar escopo',
                'suporte'   => 'suporte-tecnico | ticket-aberto',
                default     => 'lead-agencia',
            },
            'clinica_stack' => match ($agentKey) {
                'secretaria' => 'paciente-novo | triagem-clinica | agendar-consulta → consulta-agendada (Calendar)',
                'pos'        => 'consulta-realizada (NPS) — tag dispara fluxo pós-consulta',
                default      => 'agendar-consulta',
            },
            'ecommerce_stack' => match ($agentKey) {
                'vendas'      => 'lead-loja | comprou',
                'recuperacao' => 'carrinho-abandonado — use crm.add_tag quando detectar abandono',
                default       => 'lead-loja',
            },
            'saas_stack' => match ($agentKey) {
                'sdr'        => 'lead-saas | demo-agendada — qualifique BANT na memória',
                'demo'       => 'demo-agendada | demo-confirmada — calendar.create_event após confirmar',
                'onboarding' => 'onboarding-ativo',
                'cs'         => 'suporte-urgente — crm.add_tag se caso crítico',
                default      => 'lead-saas',
            },
            'restaurante_stack' => 'pedido-confirmado — confirme pedido na sessao antes da tag',
            'barbearia_stack' => match ($agentKey) {
                'recepcionista' => 'agendamento-confirmado | reagendamento-solicitado | nps-coletado — use calendar.create_event se tiver Google Calendar conectado',
                default          => 'agendamento-confirmado',
            },
            'recuperacao_stack' => match ($agentKey) {
                'recuperacao' => 'carrinho-abandonado | pix-expirado | boleto-vencido | pagamento-confirmado — dispare tag ao detectar abandono',
                default        => 'carrinho-abandonado',
            },
            'imobiliaria_stack' => match ($agentKey) {
                'corretor' => 'lead-imobiliario | visita-agendada | visita-realizada | proposta-enviada — colete criterios na memoria',
                default    => 'lead-imobiliario',
            },
            'faq_stack' => match ($agentKey) {
                'faq'    => 'duvida-resolvida | transbordo-humano | cliente-irritado — dispare webhook.outbound no transbordo se configurado',
                default  => 'duvida-resolvida',
            },
            default => 'lead-novo',
        };

        return "\n\n--- CEREBRO AUVVO (backend executa; cliente nao ve o JSON) ---\n"
            . "FORMATO CORRETO (siga exatamente):\n"
            . "1. Escreva sua resposta normal para o cliente.\n"
            . "2. Deixe UMA LINHA EM BRANCO.\n"
            . "3. Escreva [[AUVO_ACTIONS]] sozinho na linha.\n"
            . "4. Na linha seguinte, o JSON array com as acoes.\n\n"
            . "Exemplo:\n"
            . "Tudo certo! Agendado pra voce.\n\n"
            . "[[AUVO_ACTIONS]]\n"
            . '[ {"tool":"crm.add_tag","payload":{"tag":"agendamento-confirmado"}}, {"tool":"calendar.create_event","payload":{"start":"2026-06-01T14:00:00-03:00","end":"2026-06-01T15:00:00-03:00","timezone":"America/Sao_Paulo","summary":"Consulta"}} ]' . "\n\n"
            . "Ferramentas disponiveis: crm.add_tag, crm.move_stage, crm.set_memory, calendar.create_event (se conectado), "
            . "sheets.append_row, webhook.outbound, http.preset, crm.clear_mission.\n"
            . "Tags uteis neste pacote: {$tags}.\n"
            . "Ao concluir objetivo, use tag de conclusao ou crm.clear_mission. NUNCA cole [[AUVO_ACTIONS]] grudado no texto — sempre com linha em branco antes.";
    }
}

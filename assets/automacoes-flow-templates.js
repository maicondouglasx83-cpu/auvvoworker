/**
 * Templates de fluxo — playbooks prontos alinhados ao motor atual.
 * Cada template define um grafo Drawflow completo com gatilhos, condicoes e acoes.
 */
(function () {
  const SECTOR_ORDER = [
    'Recomendados',
    'Agendamentos',
    'Recuperacao de Vendas',
    'Imobiliaria',
    'FAQ & Transbordo',
    'WhatsApp',
    'IA & conversacao',
    'CRM & funil',
    'E-commerce',
    'Integracoes',
    'Personalizado',
  ];

  function cardHtml(type, data) {
    const titles = {
      flow_trigger: ['Quando começa', 'Gatilho'],
      flow_condition: ['Filtro', 'Condição'],
      flow_randomizer: ['Teste A/B', 'Random'],
      flow_delay: ['Esperar', 'Tempo'],
      flow_action: ['Ação CRM', 'Funil'],
      flow_message: ['Boas-vindas', 'WhatsApp'],
      flow_memory: ['Memória', 'Contexto'],
      flow_agent: ['Agente IA', 'Uma resposta'],
      flow_think: ['Resposta IA', 'Por turno'],
      flow_wait_reply: ['Aguardar', 'Resposta'],
      flow_converse: ['Atendimento IA', 'Contínuo'],
    };
    const t = titles[type] || ['No', ''];
    const body = (data && data._preview) || 'Configure no painel →';
    const fnClass = type.replace('flow_', '');
    return '<div class="fn-node fn-' + fnClass + '"><div class="fn-head"><div class="fn-icon"></div><div><div class="fn-title">' + t[0] + '</div><div class="fn-sub">' + t[1] + '</div></div></div><div class="fn-body">' + body + '</div><div class="fn-stats"><div class="fn-stat"><span>Entraram</span><em>0</em></div><div class="fn-stat"><span>Sucesso</span><em>0</em></div><div class="fn-stat"><span>Erro</span><em>0</em></div></div></div>';
  }

  function makeGraph(nodes) {
    const data = {};
    nodes.forEach((n) => {
      const ins = n.ins || 0;
      const outs = n.outCount || 1;
      const inputs = {};
      const outputs = {};
      for (let i = 1; i <= ins; i++) inputs['input_' + i] = { connections: [] };
      for (let o = 1; o <= outs; o++) outputs['output_' + o] = { connections: [] };
      data[n.id] = {
        id: n.id,
        name: n.class,
        data: n.data || {},
        class: n.class,
        html: cardHtml(n.class, n.data),
        typenode: false,
        inputs,
        outputs,
        pos_x: n.x,
        pos_y: n.y,
      };
    });
    nodes.forEach((n) => {
      (n.links || []).forEach((lnk) => {
        const from = data[n.id];
        const to = data[lnk.to];
        if (!from || !to) return;
        from.outputs[lnk.fromOut || 'output_1'].connections.push({ node: String(lnk.to), output: lnk.toIn || 'input_1' });
        to.inputs[lnk.toIn || 'input_1'].connections.push({ node: String(n.id), input: lnk.fromOut || 'output_1' });
      });
    });
    return { drawflow: { Home: { data } } };
  }

  function ctx(agents, connections) {
    const ag = agents && agents[0] ? parseInt(agents[0].id, 10) : 0;
    const ag2 = agents && agents[1] ? parseInt(agents[1].id, 10) : ag;
    const conn = connections && connections[0] ? parseInt(connections[0].id, 10) : 0;
    const connKey = conn > 0 ? String(conn) : '*';
    return { ag, ag2, conn, connKey };
  }

  window.AUVVO_FLOW_TEMPLATES = [
    // ============================
    // RECOMENDADOS
    // ============================
    {
      id: 'clinica_agendamento',
      sector: 'Recomendados',
      featured: true,
      icon: 'ph-heartbeat',
      color: '#0891b2',
      name: 'Clínica — secretária + agendamento',
      description: 'Boas-vindas da secretária virtual e IA conduz agendamento, convênio e dúvidas.',
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Clínica — agendamento',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'whatsapp_first', trigger_value: connKey, sync_pipeline_on_enter: 1, label: 'Quando começa', _preview: 'Primeira mensagem' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_converse', x: 320, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, label: 'Atendimento IA', instructions: 'Você é secretária de clínica. Cumprimente o lead e conduza agendamento, convênio ou dúvidas. Para agendamento: especialidade, data/horário, confirme nome e telefone, resuma e peça OK.', max_turns: 25, end_keywords: 'tchau,obrigado,encerrar', end_tag: 'agendamento-concluido', _preview: 'Secretária virtual' } },
          ]),
        };
      },
    },
    {
      id: 'starter_atendimento_fluido',
      sector: 'Recomendados',
      featured: true,
      icon: 'ph-chats-circle',
      color: '#7c3aed',
      name: 'Atendimento com IA (recomendado)',
      description: 'Gatilho → IA responde na 1ª mensagem e continua com histórico.',
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Atendimento WhatsApp',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'whatsapp_first', trigger_value: connKey, sync_pipeline_on_enter: 1, label: 'Quando começa', _preview: 'Primeira mensagem' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_converse', x: 320, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, label: 'Atendimento IA', instructions: 'Conduza o atendimento de forma natural: cumprimente o lead, entenda a necessidade, faça perguntas curtas e indique o próximo passo. Seja humano e objetivo.', max_turns: 30, end_keywords: 'tchau,obrigado,encerrar', end_tag: 'atendido', _preview: 'IA na 1ª mensagem' } },
          ]),
        };
      },
    },
    {
      id: 'starter_primeiro_contato',
      sector: 'Recomendados',
      featured: false,
      icon: 'ph-whatsapp-logo',
      color: '#059669',
      name: 'Boas-vindas personalizadas + IA',
      description: 'Mesmo padrão recomendado com texto de boas-vindas mais acolhedor.',
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Atendimento acolhedor',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'whatsapp_first', trigger_value: connKey, sync_pipeline_on_enter: 1, label: 'Quando começa', _preview: 'Primeira mensagem' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_message', x: 300, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, label: 'Boas-vindas', message: 'Olá {{nome}}! Seja bem-vindo. Como posso te ajudar hoje?', _preview: 'Boas-vindas' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_converse', x: 580, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, label: 'Atendimento IA', instructions: 'Conduza o atendimento de forma natural e humana. Entenda a necessidade, faça perguntas curtas, tire dúvidas e ofereça o próximo passo. Seja educado e objetivo.', max_turns: 30, end_keywords: 'tchau,obrigado,encerrar,adeus', end_tag: 'atendido', _preview: 'IA contínua' } },
          ]),
        };
      },
    },
    {
      id: 'starter_qualificacao_chat',
      sector: 'Recomendados',
      featured: true,
      icon: 'ph-chat-teardrop-dots',
      color: '#0d9488',
      name: 'Qualificação com tags (script)',
      description: 'Boas-vindas → IA por turno → aguarda resposta → tag qualificado ou timeout 24h.',
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Qualificacao conversacional',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 160, ins: 0, data: { trigger_type: 'whatsapp_first', trigger_value: connKey, sync_pipeline_on_enter: 1, label: 'Quando começa', _preview: 'Primeira mensagem' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_message', x: 280, y: 140, ins: 1, data: { connection_id: conn, agent_id: ag, label: 'Boas-vindas', message: 'Oi {{nome}}! Posso te fazer 2 perguntas rápidas?', _preview: 'Abertura' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_think', x: 540, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, label: 'Resposta IA (turno)', instructions: 'Faça 2 perguntas de qualificação (necessidade + prazo/orçamento). Seja objetivo.', message_count: 2, include_context: 1, send_whatsapp: 1, _preview: '2 mensagens IA' }, links: [{ to: 4 }] },
            { id: 4, class: 'flow_wait_reply', x: 800, y: 120, ins: 1, outCount: 2, data: { timeout_hours: 24, keyword_contains: '', _preview: '24h timeout' }, links: [{ to: 5, fromOut: 'output_1' }, { to: 6, fromOut: 'output_2' }] },
            { id: 5, class: 'flow_action', x: 1060, y: 60, ins: 1, data: { action_type: 'add_tag', tag: 'qualificado', _preview: 'Respondeu' } },
            { id: 6, class: 'flow_action', x: 1060, y: 220, ins: 1, data: { action_type: 'add_tag', tag: 'sem-resposta', _preview: 'Timeout' } },
          ]),
        };
      },
    },
    {
      id: 'starter_so_mensagem',
      sector: 'Recomendados',
      icon: 'ph-paper-plane-tilt',
      color: '#047857',
      name: 'Só boas-vindas (sem IA)',
      description: 'Primeira mensagem → texto fixo automático. Sem atendimento IA depois.',
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Boas-vindas automáticas',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 80, y: 140, ins: 0, data: { trigger_type: 'whatsapp_first', trigger_value: connKey, sync_pipeline_on_enter: 1, label: 'Quando começa', _preview: 'Primeira mensagem' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_message', x: 380, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, label: 'Boas-vindas', message: 'Olá {{nome}}! Recebemos sua mensagem. Retornaremos em breve.', _preview: 'Texto fixo' } },
          ]),
        };
      },
    },

    // ============================
    // AGENDAMENTOS (Barbearia/Salao/Clinica/Escritorio)
    // ============================
    {
      id: 'agenda_bemvindo',
      sector: 'Agendamentos',
      icon: 'ph-calendar-check',
      color: '#06b6d4',
      name: 'Agendamento — recepcao + conversa guiada',
      description: 'Primeiro contato com filtro de horario. IA assume a conversa como recepcionista e agenda servicos.',
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Agendamento guiado',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 160, ins: 0, data: { trigger_type: 'whatsapp_first', trigger_value: connKey, sync_pipeline_on_enter: 1, label: 'Quando começa', _preview: 'Primeira msg' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_condition', x: 260, y: 140, ins: 1, outCount: 2, data: { business_hours_only: 1, bh_start: '09:00', bh_end: '19:00', bh_weekdays: '1,2,3,4,5,6', _preview: 'Seg-Sab 9h-19h' }, links: [{ to: 3, fromOut: 'output_1' }, { to: 4, fromOut: 'output_2' }] },
            { id: 3, class: 'flow_message', x: 520, y: 60, ins: 1, data: { connection_id: conn, agent_id: ag, message: 'Ola {{nome}}! Sou a recepcionista virtual. Qual servico gostaria de agendar?', _preview: 'Aberto' }, links: [{ to: 5 }] },
            { id: 4, class: 'flow_message', x: 520, y: 240, ins: 1, data: { connection_id: conn, agent_id: ag, message: 'Ola {{nome}}! Estamos fechados (Seg-Sab 9h-19h). Assim que abrir confirmamos seu agendamento. Deixe seu nome e servico desejado.', _preview: 'Fechado' }, links: [{ to: 5 }] },
            { id: 5, class: 'flow_converse', x: 800, y: 150, ins: 1, data: { connection_id: conn, agent_id: ag, instructions: 'Voce e recepcionista. Siga este roteiro EXATO: 1. Pergunte qual servico (corte, barba, combo, etc). 2. Pergunte data e horario preferido. 3. Confirme nome e telefone. 4. Envie resumo e peca OK. 5. Ao confirmar, use tag agendamento-confirmado. Seja sempre simpatica e objetiva. Nao ofereca outros servicos.', max_turns: 15, end_keywords: 'tchau,obrigado,encerrar', end_tag: 'agendamento-concluido', _preview: 'Recepcionista assume' } },
          ]),
        };
      },
    },
    {
      id: 'agenda_lembrete',
      sector: 'Agendamentos',
      icon: 'ph-bell-ringing',
      color: '#0891b2',
      name: 'Agendamento — lembrete 24h antes',
      description: 'Tag agendamento-confirmado → delay 23h → mensagem de lembrete.',
      build(agents, connections) {
        const { ag, conn } = ctx(agents, connections);
        return {
          name: 'Lembrete agendamento',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'tag_added', trigger_value: 'agendamento-confirmado', _preview: 'Tag confirmado' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_memory', x: 280, y: 120, ins: 1, data: { memory_key: 'agendamento', value_mode: 'session_last', _preview: 'memoria.agendamento' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_delay', x: 520, y: 120, ins: 1, data: { delay_minutes: 1380, _preview: '23h (1 dia antes)' }, links: [{ to: 4 }] },
            { id: 4, class: 'flow_message', x: 760, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, message: 'Oi {{nome}}! Lembrete do seu agendamento amanha: {{memoria.agendamento}}. Tudo certo? Confirme com SIM.', _preview: 'Lembrete' } },
          ]),
        };
      },
    },
    {
      id: 'agenda_pos_servico',
      sector: 'Agendamentos',
      icon: 'ph-star',
      color: '#0e7490',
      name: 'Agendamento — pos-servico + NPS',
      description: 'Tag nps-coletado → espera 1h → mensagem de reagendamento e fidelizacao.',
      build(agents, connections) {
        const { ag, conn } = ctx(agents, connections);
        return {
          name: 'Pos-servico',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'tag_added', trigger_value: 'nps-coletado', _preview: 'Tag NPS' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_delay', x: 280, y: 130, ins: 1, data: { delay_minutes: 60, _preview: '1h' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_message', x: 520, y: 110, ins: 1, data: { connection_id: conn, agent_id: ag, message: '{{nome}}, obrigado pela visita! Quer agendar seu proximo horario? Ja deixo marcado.', _preview: 'Reagendamento' }, links: [{ to: 4 }] },
            { id: 4, class: 'flow_action', x: 780, y: 110, ins: 1, data: { action_type: 'brain_mission', mission: 'Ofereca reagendamento. Se cliente topar, colete data/hora e tag agendamento-recorrente.', _preview: 'Missao fidelizar' } },
          ]),
        };
      },
    },

    // ============================
    // RECUPERACAO DE VENDAS
    // ============================
    {
      id: 'recup_abandono_2h',
      sector: 'Recuperacao de Vendas',
      icon: 'ph-shopping-cart',
      color: '#f97316',
      name: 'Recuperacao — abandono 2h (tag)',
      description: 'Tag carrinho-abandonado → delay 2h → msg amigavel + missao IA para reengajar.',
      build(agents, connections) {
        const { ag, conn } = ctx(agents, connections);
        return {
          name: 'Abandono 2h',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'tag_added', trigger_value: 'carrinho-abandonado', _preview: 'Tag carrinho' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_delay', x: 280, y: 120, ins: 1, data: { delay_minutes: 120, _preview: '2 horas' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_message', x: 520, y: 100, ins: 1, data: { connection_id: conn, agent_id: ag, message: 'Oi {{nome}}! Vi que deixou itens no carrinho. Aconteceu algo? Posso ajudar?', _preview: 'Abordagem amigavel' }, links: [{ to: 4 }] },
            { id: 4, class: 'flow_action', x: 780, y: 100, ins: 1, data: { action_type: 'brain_mission', mission: 'Recuperar venda. Pergunte se foi duvida, pagamento ou frete. Ofereca ajuda personalizada.', _preview: 'Missao IA' } },
          ]),
        };
      },
    },
    {
      id: 'recup_cupom_24h',
      sector: 'Recuperacao de Vendas',
      icon: 'ph-ticket',
      color: '#ea580c',
      name: 'Recuperacao — cupom 24h (tag)',
      description: 'Tag carrinho-abandonado → delay 24h → msg com cupom de desconto.',
      build(agents, connections) {
        const { ag, conn } = ctx(agents, connections);
        return {
          name: 'Cupom 24h',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'tag_added', trigger_value: 'carrinho-abandonado', _preview: 'Tag carrinho' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_delay', x: 280, y: 130, ins: 1, data: { delay_minutes: 1440, _preview: '24 horas' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_message', x: 540, y: 110, ins: 1, data: { connection_id: conn, agent_id: ag, message: '{{nome}}, seu carrinho esta guardado! Use cupom VOLTA10 pra 10% OFF. So clicar: https://loja.com/checkout', _preview: 'Cupom' }, links: [{ to: 4 }] },
            { id: 4, class: 'flow_action', x: 800, y: 110, ins: 1, data: { action_type: 'add_tag', tag: 'cupom-enviado', _preview: 'Tag cupom' } },
          ]),
        };
      },
    },
    {
      id: 'recup_urgencia_48h',
      sector: 'Recuperacao de Vendas',
      icon: 'ph-timer',
      color: '#c2410c',
      name: 'Recuperacao — urgencia 48h (tag)',
      description: 'Tag carrinho-abandonado → delay 48h → msg de urgencia de estoque.',
      build(agents, connections) {
        const { ag, conn } = ctx(agents, connections);
        return {
          name: 'Urgencia 48h',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'tag_added', trigger_value: 'carrinho-abandonado', _preview: 'Tag carrinho' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_delay', x: 280, y: 130, ins: 1, data: { delay_minutes: 2880, _preview: '48 horas' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_message', x: 540, y: 110, ins: 1, data: { connection_id: conn, agent_id: ag, message: '{{nome}}, ultima chance! Itens do seu carrinho estao quase esgotando. Finaliza hoje?', _preview: 'Urgencia' } },
          ]),
        };
      },
    },
    {
      id: 'recup_pix_expirado',
      sector: 'Recuperacao de Vendas',
      icon: 'ph-currency-circle-dollar',
      color: '#d97706',
      name: 'Recuperacao — Pix/Boleto expirado',
      description: 'Tag pix-expirado → msg + webhook para gerar novo link de pagamento.',
      build(agents, connections) {
        const { ag, conn } = ctx(agents, connections);
        return {
          name: 'Pix expirado',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'tag_added', trigger_value: 'pix-expirado', _preview: 'Tag Pix expirado' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_message', x: 280, y: 100, ins: 1, data: { connection_id: conn, agent_id: ag, message: '{{nome}}, seu Pix expirou. Quer que eu gere um novo link?', _preview: 'Novo Pix' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_action', x: 540, y: 100, ins: 1, data: { action_type: 'call_webhook', url: '', method: 'POST', body: '{"nome":"{{nome}}","acao":"gerar-pix"}', _preview: 'Webhook Pix' }, links: [{ to: 4 }] },
            { id: 4, class: 'flow_action', x: 800, y: 100, ins: 1, data: { action_type: 'brain_mission', mission: 'Informe que novo link foi gerado. Se cliente pagar, tag pagamento-confirmado.', _preview: 'Missao pagamento' } },
          ]),
        };
      },
    },

    // ============================
    // IMOBILIARIA
    // ============================
    {
      id: 'imob_bemvindo',
      sector: 'Imobiliaria',
      icon: 'ph-house-line',
      color: '#84cc16',
      name: 'Imobiliaria — corretor guiado',
      description: 'Primeiro contato → msg fixa → IA assume como corretor filtrando criterios na conversa inteira.',
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Corretor guiado',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'whatsapp_first', trigger_value: connKey, sync_pipeline_on_enter: 1, label: 'Quando começa', _preview: 'Primeira msg' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_message', x: 280, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, message: 'Ola {{nome}}! Sou corretor virtual. Procura imovel para comprar ou alugar? Casa ou apartamento?', _preview: 'Boas-vindas' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_converse', x: 560, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, instructions: 'Voce e corretor de imoveis. Roteiro EXATO: 1. Pergunte tipo (casa/apto) e finalidade (comprar/alugar). 2. Pergunte quantos quartos. 3. Pergunte regiao/bairro. 4. Pergunte orcamento maximo (por ULTIMO). 5. Com os criterios, envie os 3 melhores imoveis com links. 6. Ofereca agendar visita. Colete UM criterio por vez. Seja consultivo, nao pressione.', max_turns: 20, end_keywords: 'tchau,obrigado,encerrar', end_tag: 'imobiliaria-concluido', _preview: 'Corretor assume' } },
          ]),
        };
      },
    },
    {
      id: 'imob_visita',
      sector: 'Imobiliaria',
      icon: 'ph-map-pin',
      color: '#65a30d',
      name: 'Imobiliaria — lembrete de visita',
      description: 'Tag visita-agendada → delay ate 2h antes → msg de confirmacao.',
      build(agents, connections) {
        const { ag, conn } = ctx(agents, connections);
        return {
          name: 'Lembrete visita',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'tag_added', trigger_value: 'visita-agendada', _preview: 'Tag visita' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_memory', x: 260, y: 120, ins: 1, data: { memory_key: 'visita', value_mode: 'session_last', _preview: 'memoria.visita' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_delay', x: 480, y: 120, ins: 1, data: { delay_minutes: 120, _preview: '2h antes' }, links: [{ to: 4 }] },
            { id: 4, class: 'flow_message', x: 700, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, message: '{{nome}}, lembrete da visita ao imovel! {{memoria.visita}}. Nao esqueca RG e CPF. Confirmado?', _preview: 'Confirma visita' } },
          ]),
        };
      },
    },
    {
      id: 'imob_pos_visita',
      sector: 'Imobiliaria',
      icon: 'ph-chart-bar',
      color: '#4d7c0f',
      name: 'Imobiliaria — pos-visita + follow-up',
      description: 'Tag visita-realizada → delay 3h → pergunta feedback + proposta.',
      build(agents, connections) {
        const { ag, conn } = ctx(agents, connections);
        return {
          name: 'Pos-visita',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'tag_added', trigger_value: 'visita-realizada', _preview: 'Tag visita OK' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_delay', x: 280, y: 130, ins: 1, data: { delay_minutes: 180, _preview: '3h' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_message', x: 520, y: 110, ins: 1, data: { connection_id: conn, agent_id: ag, message: '{{nome}}, o que achou do imovel? Ficou com alguma duvida? Posso enviar proposta.', _preview: 'Feedback' }, links: [{ to: 4 }] },
            { id: 4, class: 'flow_action', x: 780, y: 110, ins: 1, data: { action_type: 'brain_mission', mission: 'Coletar feedback da visita. Se interesse, avancar para proposta. Tag proposta-solicitada se avancar.', _preview: 'Missao proposta' } },
          ]),
        };
      },
    },

    // ============================
    // FAQ & TRANSBORDO
    // ============================
    {
      id: 'faq_atendimento',
      sector: 'FAQ & Transbordo',
      icon: 'ph-chat-centered-text',
      color: '#a855f7',
      name: 'FAQ — atendimento guiado + transbordo',
      description: 'Mensagem → IA assume toda conversa com base de conhecimento + transbordo se irritado.',
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'FAQ guiado',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 160, ins: 0, data: { trigger_type: 'whatsapp_message', trigger_value: connKey, sync_pipeline_on_enter: 1, label: 'Quando começa', _preview: 'Toda msg' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_condition', x: 260, y: 140, ins: 1, outCount: 2, data: { business_hours_only: 1, bh_start: '08:00', bh_end: '22:00', bh_weekdays: '1,2,3,4,5,6,7', _preview: '8h-22h' }, links: [{ to: 3, fromOut: 'output_1' }, { to: 4, fromOut: 'output_2' }] },
            { id: 3, class: 'flow_message', x: 520, y: 60, ins: 1, data: { connection_id: conn, agent_id: ag, message: 'Ola {{nome}}! Sou assistente virtual. Como posso ajudar?', _preview: 'Boas-vindas' }, links: [{ to: 5 }] },
            { id: 4, class: 'flow_message', x: 520, y: 240, ins: 1, data: { connection_id: conn, agent_id: ag, message: 'Ola {{nome}}! Time humano offline (8h-22h). Deixe sua duvida que retornamos.', _preview: 'Offline' }, links: [{ to: 5 }] },
            { id: 5, class: 'flow_converse', x: 800, y: 150, ins: 1, data: { connection_id: conn, agent_id: ag, instructions: 'Voce e assistente de atendimento. Regras: 1. Responda duvidas com base EXATA no conhecimento treinado. 2. NUNCA invente informacoes. 3. Se o cliente usar palavras como absurdo, palhacada, processo, Procon, quero falar com gerente: PECA DESCULPAS e diga que vai acionar um especialista. Use tag cliente-irritado e encerre. 4. Ao final de cada resposta, pergunte se resolveu.', max_turns: 25, end_keywords: 'tchau,obrigado,encerrar,resolveu', end_tag: 'duvida-resolvida', _preview: 'IA assume conversa' } },
          ]),
        };
      },
    },
    {
      id: 'faq_transbordo',
      sector: 'FAQ & Transbordo',
      icon: 'ph-git-branch',
      color: '#9333ea',
      name: 'FAQ — transbordo humano (tag)',
      description: 'Tag cliente-irritado → pausa IA 2h → msg de transbordo + webhook de alerta.',
      build(agents, connections) {
        const { ag, conn } = ctx(agents, connections);
        return {
          name: 'Transbordo humano',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 160, ins: 0, data: { trigger_type: 'tag_added', trigger_value: 'cliente-irritado', _preview: 'Tag irritacao' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_action', x: 280, y: 100, ins: 1, data: { action_type: 'pause_ai', agent_id: ag, minutes: 120, _preview: 'Pausa IA 2h' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_message', x: 520, y: 80, ins: 1, data: { connection_id: conn, agent_id: ag, message: '{{nome}}, entendi sua situacao. Vou acionar um especialista agora. Um momento.', _preview: 'Transbordo' }, links: [{ to: 4 }] },
            { id: 4, class: 'flow_action', x: 780, y: 80, ins: 1, data: { action_type: 'call_webhook', url: '', method: 'POST', body: '{"nome":"{{nome}}","motivo":"cliente-irritado","mensagens":"{{mensagens_hoje}}"}', _preview: 'Webhook alerta' } },
          ]),
        };
      },
    },
    {
      id: 'faq_palavra_chave',
      sector: 'FAQ & Transbordo',
      icon: 'ph-text-aa',
      color: '#7e22ce',
      name: 'FAQ — filtro por palavra-chave',
      description: 'Msg com "reembolso" ou "cancelar" → transbordo imediato. Resto → IA responde.',
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Filtro palavra-chave',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 160, ins: 0, data: { trigger_type: 'whatsapp_message', trigger_value: connKey, sync_pipeline_on_enter: 1, label: 'Quando começa', _preview: 'Toda msg' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_condition', x: 280, y: 140, ins: 1, outCount: 2, data: { keyword_contains: 'reembolso,cancelar,processo,procon,reclame aqui', _preview: 'Palavra grave?' }, links: [{ to: 3, fromOut: 'output_1' }, { to: 5, fromOut: 'output_2' }] },
            { id: 3, class: 'flow_action', x: 540, y: 60, ins: 1, data: { action_type: 'pause_ai', agent_id: ag, minutes: 120, _preview: 'Pausa IA' }, links: [{ to: 4 }] },
            { id: 4, class: 'flow_message', x: 780, y: 60, ins: 1, data: { connection_id: conn, agent_id: ag, message: '{{nome}}, sua solicitacao e importante. Vou acionar time especializado agora.', _preview: 'Escalado' } },
            { id: 5, class: 'flow_converse', x: 540, y: 240, ins: 1, data: { connection_id: conn, agent_id: ag, label: 'Atendimento IA', instructions: 'Responda dúvidas com base no conhecimento da empresa. Seja objetivo e pergunte se resolveu.', max_turns: 20, end_keywords: 'tchau,obrigado,encerrar', end_tag: 'duvida-resolvida', _preview: 'IA contínua' } },
          ]),
        };
      },
    },

    // ============================
    // WHATSAPP
    // ============================
    {
      id: 'wa_toda_mensagem',
      sector: 'WhatsApp',
      icon: 'ph-chats-circle',
      color: '#0d9488',
      name: 'Toda mensagem + filtro palavra',
      description: 'Dispara a cada msg. Filtra por palavra-chave. Ex: "preco" → agente de precos.',
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Msg com filtro',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 160, ins: 0, data: { trigger_type: 'whatsapp_message', trigger_value: connKey, cooldown_mode: 'once_per_day', _preview: 'Toda msg · 1x/dia' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_condition', x: 280, y: 140, ins: 1, outCount: 2, data: { keyword_contains: 'preco,valor,orcamento', _preview: 'Contem preco?' }, links: [{ to: 3, fromOut: 'output_1' }] },
            { id: 3, class: 'flow_converse', x: 540, y: 100, ins: 1, data: { connection_id: conn, agent_id: ag, label: 'Atendimento IA', instructions: 'Envie tabela de preços quando perguntado, tire dúvidas e convide para demonstração.', max_turns: 15, end_keywords: 'tchau,obrigado,encerrar', _preview: 'IA preços' } },
          ]),
        };
      },
    },
    {
      id: 'wa_horario',
      sector: 'WhatsApp',
      icon: 'ph-clock',
      color: '#0891b2',
      name: 'Horario comercial',
      description: 'Aberto → IA atende. Fechado → msg automatica de retorno.',
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Horario comercial',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 160, ins: 0, data: { trigger_type: 'whatsapp_message', trigger_value: connKey, sync_pipeline_on_enter: 1, label: 'Quando começa', _preview: 'Toda msg' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_condition', x: 280, y: 140, ins: 1, outCount: 2, data: { business_hours_only: 1, bh_start: '08:00', bh_end: '18:00', bh_weekdays: '1,2,3,4,5', _preview: 'Seg-Sex 8-18h' }, links: [{ to: 3, fromOut: 'output_1' }, { to: 4, fromOut: 'output_2' }] },
            { id: 3, class: 'flow_converse', x: 540, y: 60, ins: 1, data: { connection_id: conn, agent_id: ag, label: 'Atendimento IA', instructions: 'Atenda o lead em horário comercial. Seja acolhedor e objetivo.', max_turns: 25, end_keywords: 'tchau,obrigado,encerrar', _preview: 'IA em horário' } },
            { id: 4, class: 'flow_message', x: 540, y: 220, ins: 1, data: { connection_id: conn, agent_id: ag, label: 'Boas-vindas', message: 'Oi {{nome}}! Fora do horário (8h–18h). Retornamos no próximo dia útil.', _preview: 'Fechado' } },
          ]),
        };
      },
    },

    // ============================
    // IA & CONVERSACAO
    // ============================
    {
      id: 'ia_pensar_responder',
      sector: 'IA & conversacao',
      icon: 'ph-lightbulb',
      color: '#b45309',
      name: 'Resposta IA por turno (script)',
      description: 'Boas-vindas → IA envia até 3 mensagens com instruções — fluxo continua depois.',
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Resposta IA (turno)',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'whatsapp_first', trigger_value: connKey, sync_pipeline_on_enter: 1, label: 'Quando começa', _preview: 'Primeira mensagem' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_message', x: 280, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, label: 'Boas-vindas', message: 'Olá {{nome}}! Vou te fazer algumas perguntas rápidas.', _preview: 'Abertura' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_think', x: 540, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, label: 'Resposta IA (turno)', instructions: 'Apresente a empresa, faça 2 perguntas de qualificação e convide para o próximo passo.', message_count: 3, include_context: 1, send_whatsapp: 1, memory_key: 'qualificacao', _preview: '3 mensagens IA' } },
          ]),
        };
      },
    },
    {
      id: 'ia_missao_tag',
      sector: 'IA & conversacao',
      icon: 'ph-brain',
      color: '#6d28d9',
      name: 'Tag → missao no cerebro',
      description: 'Tag dispara missao temporaria — Calendar, CRM e ferramentas na proxima resposta IA.',
      build(agents, connections) {
        const { ag, conn } = ctx(agents, connections);
        return {
          name: 'Missao por tag',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'tag_added', trigger_value: 'agendar-demo', _preview: 'Tag agendar-demo' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_action', x: 300, y: 120, ins: 1, data: { action_type: 'brain_mission', mission: 'Confirmar horario com o lead. Se aceitar, criar evento 30min no Calendar, tag demo-agendada, estagio qualified.', _preview: 'Missao Calendar' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_message', x: 560, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, message: '{{nome}}, vou confirmar o melhor horario agora.', _preview: 'Transicao' } },
          ]),
        };
      },
    },

    // ============================
    // CRM & FUNIL
    // ============================
    {
      id: 'crm_estagio_handoff',
      sector: 'CRM & funil',
      icon: 'ph-columns',
      color: '#4338ca',
      name: 'Estagio → handoff agente',
      description: 'Lead entra em Proposta: troca agente + msg de abertura + tag negociacao.',
      build(agents, connections) {
        const { ag, ag2, conn } = ctx(agents, connections);
        return {
          name: 'Handoff por estagio',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'stage_enter', trigger_value: 'proposal', _preview: 'Estagio Proposta' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_action', x: 300, y: 120, ins: 1, data: { action_type: 'invoke_agent', agent_id: ag2, switch_agent: 1, message: 'Oi {{nome}}! Assumi sua negociacao. Como prefere avancar?', _preview: '→ Agente 2' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_action', x: 560, y: 120, ins: 1, data: { action_type: 'add_tag', tag: 'em-negociacao', _preview: 'Tag' } },
          ]),
        };
      },
    },
    {
      id: 'crm_tag_followup',
      sector: 'CRM & funil',
      icon: 'ph-tag',
      color: '#b45309',
      name: 'Tag → espera → follow-up',
      description: 'Tag follow-up: delay 2h → msg automatica de reengajamento.',
      build(agents, connections) {
        const { ag, conn } = ctx(agents, connections);
        return {
          name: 'Follow-up por tag',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'tag_added', trigger_value: 'follow-up', _preview: 'Tag follow-up' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_delay', x: 280, y: 130, ins: 1, data: { delay_minutes: 120, _preview: '2 horas' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_message', x: 540, y: 110, ins: 1, data: { connection_id: conn, agent_id: ag, message: '{{nome}}, passando para saber se ainda posso ajudar. Posso retomar?', _preview: 'Follow-up' } },
          ]),
        };
      },
    },

    // ============================
    // E-COMMERCE
    // ============================
    {
      id: 'ecom_boasvindas_ab',
      sector: 'E-commerce',
      icon: 'ph-shopping-cart',
      color: '#f59e0b',
      name: 'Loja — A/B boas-vindas + vendedor guiado',
      description: 'Teste A/B de oferta na primeira msg + IA assume como vendedor consultivo na conversa inteira.',
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Loja A/B guiada',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 160, ins: 0, data: { trigger_type: 'whatsapp_first', trigger_value: connKey, sync_pipeline_on_enter: 1, label: 'Quando começa', _preview: 'Primeiro contato' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_randomizer', x: 260, y: 140, ins: 1, outCount: 2, data: { pct_a: 50, _preview: 'A/B 50%' }, links: [{ to: 3, fromOut: 'output_1' }, { to: 4, fromOut: 'output_2' }] },
            { id: 3, class: 'flow_message', x: 520, y: 60, ins: 1, data: { connection_id: conn, agent_id: ag, message: '{{nome}}! 10% OFF na primeira compra: BEMVINDO10. Veja: https://loja.com', _preview: 'Promo A' }, links: [{ to: 5 }] },
            { id: 4, class: 'flow_message', x: 520, y: 220, ins: 1, data: { connection_id: conn, agent_id: ag, message: '{{nome}}! Frete gratis acima de R$150 esta semana. Confira: https://loja.com', _preview: 'Promo B' }, links: [{ to: 5 }] },
            { id: 5, class: 'flow_converse', x: 800, y: 140, ins: 1, data: { connection_id: conn, agent_id: ag, instructions: 'Voce e vendedor da loja. Roteiro: 1. Pergunte o que o cliente procura. 2. Sugira produtos com base no catalogo. 3. Tire duvidas sobre preco, frete, pagamento. 4. Ofereca cupom se o cliente hesitar. 5. Faca o fechamento com link de checkout. Seja consultivo, nao pressione. Tag comprou quando cliente finalizar.', max_turns: 20, end_keywords: 'tchau,obrigado,encerrar', end_tag: 'loja-concluido', _preview: 'Vendedor assume' } },
          ]),
        };
      },
    },
    {
      id: 'ecom_pos_compra',
      sector: 'E-commerce',
      icon: 'ph-package',
      color: '#d97706',
      name: 'Loja — pos-compra + atualizacao',
      description: 'Tag comprou → msg de obrigado + missao de rastreio. Tag enviado → atualiza cliente.',
      build(agents, connections) {
        const { ag, conn } = ctx(agents, connections);
        return {
          name: 'Pos-compra',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'tag_added', trigger_value: 'comprou', _preview: 'Tag comprou' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_message', x: 280, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, message: '{{nome}}, obrigado pela compra! Seu pedido esta sendo preparado. Rastreio em breve.', _preview: 'Obrigado' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_action', x: 540, y: 120, ins: 1, data: { action_type: 'brain_mission', mission: 'Acompanhe satisfacao. Se cliente perguntar sobre prazo, informe 3-5 dias uteis. Tag recompra em 30 dias.', _preview: 'Missao pos-venda' } },
          ]),
        };
      },
    },

    // ============================
    // INTEGRACOES
    // ============================
    {
      id: 'int_webhook',
      sector: 'Integracoes',
      icon: 'ph-plugs-connected',
      color: '#7c3aed',
      name: 'Webhook → WhatsApp',
      description: 'Lead de formulario/Hotmart: tag + msg de boas-vindas via WhatsApp.',
      build(agents, connections) {
        const { ag, conn } = ctx(agents, connections);
        return {
          name: 'Webhook lead',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'webhook_received', trigger_value: 'lead-form', _preview: 'Webhook lead-form' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_action', x: 300, y: 120, ins: 1, data: { action_type: 'add_tag', tag: 'lead-web', _preview: 'Tag' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_message', x: 560, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, message: 'Ola {{nome}}! Recebemos seu cadastro. Em que posso ajudar?', _preview: 'WhatsApp' } },
          ]),
        };
      },
    },
    {
      id: 'int_ltv',
      sector: 'Integracoes',
      icon: 'ph-chart-line-down',
      color: '#ca8a04',
      name: 'LTV — reativacao de inativo',
      description: 'Cliente inativo: msg personalizada + tag ltv-reativacao.',
      build(agents, connections) {
        const { ag, conn } = ctx(agents, connections);
        return {
          name: 'LTV reativacao',
          export: makeGraph([
            { id: 1, class: 'flow_trigger', x: 40, y: 140, ins: 0, data: { trigger_type: 'ltv_inactive', trigger_value: 'default', _preview: 'LTV inativo' }, links: [{ to: 2 }] },
            { id: 2, class: 'flow_message', x: 300, y: 120, ins: 1, data: { connection_id: conn, agent_id: ag, message: '{{nome}}, sentimos sua falta! Temos condicao especial. Posso contar?', _preview: 'Reativacao' }, links: [{ to: 3 }] },
            { id: 3, class: 'flow_action', x: 560, y: 120, ins: 1, data: { action_type: 'add_tag', tag: 'ltv-reativacao', _preview: 'Tag LTV' } },
          ]),
        };
      },
    },

    // ============================
    // PERSONALIZADO
    // ============================
    {
      id: 'blank',
      sector: 'Personalizado',
      icon: 'ph-plus-circle',
      color: '#64748b',
      name: 'Em branco (só gatilho)',
      description: 'Canvas com nó de início — monte passo a passo na paleta.',
      build() {
        return { name: 'Nova automacao', export: null };
      },
    },
  ];

  window.AUVVO_FLOW_TEMPLATE_SECTORS = SECTOR_ORDER;
})();

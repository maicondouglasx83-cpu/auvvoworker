/**
 * Fluxos dos pacotes — gatilhos por tag/estágio/primeira msg (evita keyword routing).
 */
(function () {
  function cardHtml(type, data) {
    const titles = {
      flow_trigger: ['Quando começa', 'Gatilho'],
      flow_condition: ['Filtro', 'Condição'],
      flow_randomizer: ['Teste A/B', 'Random'],
      flow_delay: ['Esperar', 'Tempo'],
      flow_action: ['Ação CRM', 'Funil'],
      flow_message: ['Boas-vindas', 'WhatsApp'],
      flow_memory: ['Memória', 'Contexto'],
      flow_converse: ['Atendimento IA', 'Contínuo'],
      flow_think: ['Resposta IA', 'Por turno'],
    };
    const t = titles[type] || ['Nó', ''];
    const body = (data && data._preview) || 'Configure →';
    return `<div class="fn-node fn-${type.replace('flow_', '')}"><div class="fn-head"><div class="fn-icon"></div><div><div class="fn-title">${t[0]}</div><div class="fn-sub">${t[1]}</div></div></div><div class="fn-body">${body}</div><div class="fn-stats"><div class="fn-stat"><span>Entraram</span><em>0</em></div><div class="fn-stat"><span>Sucesso</span><em>0</em></div><div class="fn-stat"><span>Erro</span><em>0</em></div></div></div>`;
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

  function idOf(byKey, key, fallbackKey) {
    const a = byKey[key] || byKey[fallbackKey];
    return a && a.id ? parseInt(a.id, 10) : 0;
  }

  function keyOf(byKey, key, fallbackKey) {
    const id = idOf(byKey, key, fallbackKey);
    return id > 0 ? String(id) : '*';
  }

  window.AUVVO_PACK_FLOWS = {
    agencia_stack: [
      {
        name: '[Pacote] Agência — primeiro contato',
        build(byKey) {
          const rec = idOf(byKey, 'recepcao');
          const recK = keyOf(byKey, 'recepcao');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 140,
              ins: 0,
              data: { trigger_type: 'whatsapp_first', trigger_value: recK, _preview: 'Primeira msg · Recepção' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_message',
              x: 300,
              y: 120,
              ins: 1,
              data: {
                agent_id: rec,
                message:
                  'Olá {{nome}}! Recepção da agência. Conte seu projeto em uma frase — anoto para o comercial.',
                _preview: 'Recepção',
              },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_memory',
              x: 560,
              y: 100,
              ins: 1,
              data: { memory_key: 'interesse', value_mode: 'session_today', _preview: 'memoria.interesse' },
              links: [{ to: 4 }],
            },
            {
              id: 4,
              class: 'flow_action',
              x: 820,
              y: 100,
              ins: 1,
              data: { action_type: 'add_tag', tag: 'lead-agencia', _preview: 'Tag lead' },
              links: [{ to: 5 }],
            },
            {
              id: 5,
              class: 'flow_converse',
              x: 1080,
              y: 100,
              ins: 1,
              data: { connection_id: 0, agent_id: rec, instructions: 'Voce e recepcionista de agencia. Roteiro: 1. Entenda o projeto do cliente (trafego, site, social, branding). 2. Pergunte orcamento e prazo. 3. Anote na memoria.interesse. 4. Se for orcamento, encaminhe para comercial. 5. Tag lead-agencia. Seja profissional e acolhedor.', max_turns: 15, end_keywords: 'tchau,obrigado,encerrar', end_tag: 'agencia-atendido', _preview: 'Recepcao assume' },
            },
          ]);
        },
      },
      {
        name: '[Pacote] Agência — orçamento (estágio)',
        build(byKey) {
          const com = idOf(byKey, 'comercial');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 150,
              ins: 0,
              data: { trigger_type: 'stage_enter', trigger_value: 'proposal', _preview: 'Estágio Proposta' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_action',
              x: 300,
              y: 130,
              ins: 1,
              data: {
                action_type: 'invoke_agent',
                agent_id: com,
                switch_agent: 1,
                message: 'Oi {{nome}}! Comercial aqui. Interesse: {{memoria.interesse}}\nPreparo proposta em 24h.',
                _preview: '→ Comercial',
              },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_action',
              x: 560,
              y: 130,
              ins: 1,
              data: {
                action_type: 'brain_mission',
                mission: 'Fechar escopo e budget. Memória briefing. Tag proposta-enviada quando enviar.',
                _preview: 'Missão proposta',
              },
            },
          ]);
        },
      },
      {
        name: '[Pacote] Agência — tag suporte',
        build(byKey) {
          const sup = idOf(byKey, 'suporte');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 140,
              ins: 0,
              data: { trigger_type: 'tag_added', trigger_value: 'suporte-tecnico', _preview: 'Tag suporte' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_action',
              x: 300,
              y: 120,
              ins: 1,
              data: {
                action_type: 'invoke_agent',
                agent_id: sup,
                switch_agent: 1,
                message: '{{nome}}, suporte técnico. Qual campanha/site apresenta problema?',
                _preview: '→ Suporte',
              },
            },
          ]);
        },
      },
    ],

    clinica_stack: [
      {
        name: '[Pacote] Clínica — lembrete consulta',
        build(byKey) {
          const sec = idOf(byKey, 'secretaria');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 50,
              y: 140,
              ins: 0,
              data: { trigger_type: 'stage_enter', trigger_value: 'qualified', _preview: 'Estágio Qualificado' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_delay',
              x: 280,
              y: 130,
              ins: 1,
              data: { delay_minutes: 1440, _preview: '24h' },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_message',
              x: 520,
              y: 110,
              ins: 1,
              data: {
                agent_id: sec,
                message: '{{nome}}, lembrete da consulta. Confirma? (SIM / reagendar)',
                _preview: 'Secretária',
              },
            },
          ]);
        },
      },
      {
        name: '[Pacote] Clínica — triagem (tag)',
        build(byKey) {
          const sec = idOf(byKey, 'secretaria');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 150,
              ins: 0,
              data: { trigger_type: 'tag_added', trigger_value: 'triagem-clinica', _preview: 'Tag triagem' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_memory',
              x: 280,
              y: 130,
              ins: 1,
              data: { memory_key: 'sintomas', value_mode: 'session_today', _preview: 'memoria.sintomas' },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_action',
              x: 520,
              y: 130,
              ins: 1,
              data: { action_type: 'add_tag', tag: 'triagem-registrada', _preview: 'Tag OK' },
            },
          ]);
        },
      },
      {
        name: '[Pacote] Clínica — pós-consulta',
        build(byKey) {
          const pos = idOf(byKey, 'pos');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 50,
              y: 140,
              ins: 0,
              data: { trigger_type: 'tag_added', trigger_value: 'consulta-realizada', _preview: 'Tag pós-consulta' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_action',
              x: 300,
              y: 120,
              ins: 1,
              data: {
                action_type: 'brain_mission',
                mission: 'Coletar NPS 0-10, memoria.nps, tag alerta se ≤6.',
                _preview: 'Missão NPS',
              },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_message',
              x: 560,
              y: 120,
              ins: 1,
              data: {
                agent_id: pos,
                message: '{{nome}}, como foi a consulta? Nota de 0 a 10?',
                _preview: 'Pós-consulta',
              },
              links: [{ to: 4 }],
            },
            {
              id: 4,
              class: 'flow_action',
              x: 820,
              y: 120,
              ins: 1,
              data: { action_type: 'pause_ai', agent_id: pos, minutes: 180, _preview: 'Pausa IA' },
            },
          ]);
        },
      },
    ],

    ecommerce_stack: [
      {
        name: '[Pacote] Loja — primeiro contato A/B',
        build(byKey) {
          const vend = idOf(byKey, 'vendas');
          const vendK = keyOf(byKey, 'vendas');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 160,
              ins: 0,
              data: { trigger_type: 'whatsapp_first', trigger_value: vendK, _preview: 'Primeiro contato' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_randomizer',
              x: 260,
              y: 140,
              ins: 1,
              outCount: 2,
              data: { pct_a: 50, _preview: 'A/B' },
              links: [{ to: 3, fromOut: 'output_1' }, { to: 4, fromOut: 'output_2' }],
            },
            {
              id: 3,
              class: 'flow_message',
              x: 520,
              y: 60,
              ins: 1,
              data: { agent_id: vend, message: '{{nome}}! 10% OFF com BEMVINDO10.', _preview: 'Promo A' },
              links: [{ to: 5 }],
            },
            {
              id: 4,
              class: 'flow_message',
              x: 520,
              y: 220,
              ins: 1,
              data: { agent_id: vend, message: '{{nome}}! Frete gratis acima de R$150.', _preview: 'Promo B' },
              links: [{ to: 5 }],
            },
            {
              id: 5,
              class: 'flow_action',
              x: 780,
              y: 140,
              ins: 1,
              data: { action_type: 'add_tag', tag: 'lead-loja', _preview: 'Tag lead' },
              links: [{ to: 6 }],
            },
            {
              id: 6,
              class: 'flow_converse',
              x: 1040,
              y: 140,
              ins: 1,
              data: { connection_id: 0, agent_id: vend, label: 'Atendimento IA', instructions: 'Pergunte o que o cliente busca e ajude na escolha do produto. Seja consultivo.', max_turns: 20, end_keywords: 'tchau,obrigado,encerrar', _preview: 'IA contínua' },
            },
          ]);
        },
      },
      {
        name: '[Pacote] Loja — carrinho (tag)',
        build(byKey) {
          const rec = idOf(byKey, 'recuperacao');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 50,
              y: 140,
              ins: 0,
              data: { trigger_type: 'tag_added', trigger_value: 'carrinho-abandonado', _preview: 'Tag carrinho' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_delay',
              x: 280,
              y: 130,
              ins: 1,
              data: { delay_minutes: 60, _preview: '1h' },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_message',
              x: 520,
              y: 110,
              ins: 1,
              data: {
                agent_id: rec,
                message: '{{nome}}, carrinho reservado. Cupom VOLTA5 — finalizo com você?',
                _preview: 'Recuperação',
              },
            },
          ]);
        },
      },
      {
        name: '[Pacote] Loja — pós-compra (tag)',
        build(byKey) {
          const vend = idOf(byKey, 'vendas');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 140,
              ins: 0,
              data: { trigger_type: 'tag_added', trigger_value: 'comprou', _preview: 'Tag comprou' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_message',
              x: 300,
              y: 120,
              ins: 1,
              data: {
                agent_id: vend,
                message: '{{nome}}, obrigado pela compra! 🎉 Rastreio em breve.',
                _preview: 'Pós-compra',
              },
            },
          ]);
        },
      },
    ],

    saas_stack: [
      {
        name: '[Pacote] SaaS — primeiro contato SDR',
        build(byKey) {
          const sdr = idOf(byKey, 'sdr');
          const sdrK = keyOf(byKey, 'sdr');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 150,
              ins: 0,
              data: { trigger_type: 'whatsapp_first', trigger_value: sdrK, _preview: 'Primeira msg · SDR' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_memory',
              x: 280,
              y: 130,
              ins: 1,
              data: { memory_key: 'bant', value_mode: 'session_recent', session_limit: 8, _preview: 'memoria.bant' },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_action',
              x: 520,
              y: 130,
              ins: 1,
              data: { action_type: 'add_tag', tag: 'lead-saas', _preview: 'Tag lead' },
            },
          ]);
        },
      },
      {
        name: '[Pacote] SaaS — demo (tag)',
        build(byKey) {
          const demo = idOf(byKey, 'demo');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 140,
              ins: 0,
              data: { trigger_type: 'tag_added', trigger_value: 'demo-agendada', _preview: 'Tag demo' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_action',
              x: 300,
              y: 120,
              ins: 1,
              data: {
                action_type: 'invoke_agent',
                agent_id: demo,
                switch_agent: 1,
                message: 'Oi {{nome}}! Demo confirmada. Contexto: {{memoria.bant}}',
                _preview: '→ Demo',
              },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_action',
              x: 560,
              y: 120,
              ins: 1,
              data: {
                action_type: 'brain_mission',
                mission: 'Calendar 20min + link Meet + tag demo-confirmada.',
                _preview: 'Missão Calendar',
              },
            },
          ]);
        },
      },
      {
        name: '[Pacote] SaaS — CS (tag)',
        build(byKey) {
          const cs = idOf(byKey, 'cs');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 140,
              ins: 0,
              data: { trigger_type: 'tag_added', trigger_value: 'suporte-urgente', _preview: 'Tag suporte' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_action',
              x: 300,
              y: 120,
              ins: 1,
              data: { action_type: 'pause_ai', agent_id: cs, minutes: 120, _preview: 'Pausa IA' },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_message',
              x: 560,
              y: 120,
              ins: 1,
              data: {
                agent_id: cs,
                message: '{{nome}}, prioridade alta.\n{{mensagens_hoje}}\nEspecialista em breve.',
                _preview: 'CS',
              },
            },
          ]);
        },
      },
    ],

    restaurante_stack: [
      {
        name: '[Pacote] Delivery — horário',
        build(byKey) {
          const del = idOf(byKey, 'delivery');
          const delK = keyOf(byKey, 'delivery');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 160,
              ins: 0,
              data: { trigger_type: 'whatsapp_message', trigger_value: delK, _preview: 'Msg delivery' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_condition',
              x: 260,
              y: 140,
              ins: 1,
              outCount: 2,
              data: { business_hours_only: 1, bh_start: '18:00', bh_end: '23:00', bh_weekdays: '1,2,3,4,5,6,7', _preview: '18h-23h' },
              links: [{ to: 3, fromOut: 'output_1' }, { to: 4, fromOut: 'output_2' }],
            },
            {
              id: 3,
              class: 'flow_message',
              x: 520,
              y: 60,
              ins: 1,
              data: { agent_id: del, message: 'Ola {{nome}}! Estamos abertos. O que vai pedir hoje?', _preview: 'Aberto' },
            },
            {
              id: 4,
              class: 'flow_message',
              x: 520,
              y: 240,
              ins: 1,
              data: {
                agent_id: del,
                message: 'Estamos fechados agora (18h as 23h). Deixe seu pedido que priorizamos na abertura!',
                _preview: 'Fechado',
              },
            },
          ]);
        },
      },
      {
        name: '[Pacote] Delivery — pedido confirmado (tag)',
        build(byKey) {
          const del = idOf(byKey, 'delivery');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 140,
              ins: 0,
              data: { trigger_type: 'tag_added', trigger_value: 'pedido-confirmado', _preview: 'Tag pedido' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_memory',
              x: 280,
              y: 120,
              ins: 1,
              data: { memory_key: 'pedido', value_mode: 'session_today', _preview: 'memoria.pedido' },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_message',
              x: 520,
              y: 120,
              ins: 1,
              data: {
                agent_id: del,
                message: 'Pedido confirmado! Resumo:\n{{memoria.pedido}}\n\nPrevisao: 40-50 min.\nObrigado {{nome}}!',
                _preview: 'Confirmacao',
              },
            },
          ]);
        },
      },
    ],

    // ===== NOVOS PACOTES =====

    barbearia_stack: [
      {
        name: '[Pacote] Agendamento — primeiro contato',
        build(byKey) {
          const rec = idOf(byKey, 'recepcionista');
          const recK = keyOf(byKey, 'recepcionista');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 160,
              ins: 0,
              data: { trigger_type: 'whatsapp_first', trigger_value: recK, _preview: 'Primeira msg' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_condition',
              x: 260,
              y: 140,
              ins: 1,
              outCount: 2,
              data: { business_hours_only: 1, bh_start: '09:00', bh_end: '19:00', bh_weekdays: '1,2,3,4,5,6', _preview: 'Seg-Sab 9h-19h' },
              links: [{ to: 3, fromOut: 'output_1' }, { to: 4, fromOut: 'output_2' }],
            },
            {
              id: 3,
              class: 'flow_message',
              x: 520,
              y: 60,
              ins: 1,
              data: {
                agent_id: rec,
                message: 'Ola {{nome}}! Sou a recepcionista virtual. Que servico gostaria de agendar?',
                _preview: 'Horario comercial',
              },
              links: [{ to: 5 }],
            },
            {
              id: 4,
              class: 'flow_message',
              x: 520,
              y: 240,
              ins: 1,
              data: {
                agent_id: rec,
                message: 'Ola {{nome}}! Estamos fechados agora (Seg-Sab 9h-19h). Assim que abrir eu confirmo seu agendamento!',
                _preview: 'Fora do horario',
              },
              links: [{ to: 5 }],
            },
            {
              id: 5,
              class: 'flow_action',
              x: 780,
              y: 150,
              ins: 1,
              data: { action_type: 'add_tag', tag: 'lead-agendamento', _preview: 'Tag lead' },
              links: [{ to: 6 }],
            },
            {
              id: 6,
              class: 'flow_converse',
              x: 1040,
              y: 150,
              ins: 1,
              data: { connection_id: 0, agent_id: rec, label: 'Atendimento IA', instructions: 'Pergunte qual serviço e horário prefere. Seja receptivo e colete um dado por vez.', max_turns: 20, end_keywords: 'tchau,obrigado,encerrar', end_tag: 'agendamento-concluido', _preview: 'Recepcionista IA' },
            },
          ]);
        },
      },
      {
        name: '[Pacote] Agendamento — confirmado (tag)',
        build(byKey) {
          const rec = idOf(byKey, 'recepcionista');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 150,
              ins: 0,
              data: { trigger_type: 'tag_added', trigger_value: 'agendamento-confirmado', _preview: 'Tag confirmado' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_memory',
              x: 260,
              y: 130,
              ins: 1,
              data: { memory_key: 'agendamento', value_mode: 'session_last', _preview: 'memoria.agendamento' },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_delay',
              x: 480,
              y: 130,
              ins: 1,
              data: { delay_minutes: 1380, _preview: '23h (1 dia antes)' },
              links: [{ to: 4 }],
            },
            {
              id: 4,
              class: 'flow_message',
              x: 700,
              y: 130,
              ins: 1,
              data: {
                agent_id: rec,
                message: 'Oi {{nome}}! So lembrando do seu agendamento: {{memoria.agendamento}}. Tudo certo? Confirme com SIM.',
                _preview: 'Lembrete',
              },
            },
          ]);
        },
      },
      {
        name: '[Pacote] Agendamento — pós-serviço (tag NPS)',
        build(byKey) {
          const rec = idOf(byKey, 'recepcionista');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 140,
              ins: 0,
              data: { trigger_type: 'tag_added', trigger_value: 'nps-coletado', _preview: 'Tag NPS' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_delay',
              x: 260,
              y: 130,
              ins: 1,
              data: { delay_minutes: 60, _preview: '1h' },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_message',
              x: 480,
              y: 130,
              ins: 1,
              data: {
                agent_id: rec,
                message: '{{nome}}, obrigado pela visita! Quer agendar seu proximo horario? Ja deixo marcado pra voce.',
                _preview: 'Reagendamento',
              },
            },
          ]);
        },
      },
    ],

    recuperacao_stack: [
      {
        name: '[Pacote] Recuperacao — abandono (tag via webhook)',
        build(byKey) {
          const rec = idOf(byKey, 'recuperacao');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 180,
              ins: 0,
              data: { trigger_type: 'tag_added', trigger_value: 'carrinho-abandonado', _preview: 'Tag carrinho' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_delay',
              x: 260,
              y: 80,
              ins: 1,
              data: { delay_minutes: 120, _preview: '2h' },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_message',
              x: 480,
              y: 60,
              ins: 1,
              data: {
                agent_id: rec,
                message: 'Oi {{nome}}! Vi que voce deixou itens no carrinho. Aconteceu alguma coisa? Posso ajudar?',
                _preview: 'Abordagem 2h',
              },
              links: [{ to: 6 }],
            },
            {
              id: 4,
              class: 'flow_delay',
              x: 260,
              y: 180,
              ins: 1,
              data: { delay_minutes: 1440, _preview: '24h' },
              links: [{ to: 5 }],
            },
            {
              id: 5,
              class: 'flow_message',
              x: 480,
              y: 160,
              ins: 1,
              data: {
                agent_id: rec,
                message: '{{nome}}, seu carrinho esta guardado! Use o cupom VOLTA10 pra ganhar 10% OFF. So clicar: https://loja.com/checkout',
                _preview: 'Cupom 24h',
              },
              links: [{ to: 6 }],
            },
            {
              id: 6,
              class: 'flow_condition',
              x: 720,
              y: 110,
              ins: 1,
              outCount: 2,
              data: { business_hours_only: 1, bh_start: '08:00', bh_end: '22:00', bh_weekdays: '1,2,3,4,5,6,7', _preview: 'Dentro horario?' },
              links: [{ to: 7, fromOut: 'output_1' }],
            },
            {
              id: 7,
              class: 'flow_action',
              x: 940,
              y: 110,
              ins: 1,
              data: { action_type: 'brain_mission', mission: 'Recuperar venda do carrinho. Ofereca ajuda com produto, pagamento ou frete. Se cliente comprar, tag pagamento-confirmado.', _preview: 'Missao IA' },
            },
          ]);
        },
      },
      {
        name: '[Pacote] Recuperacao — urgência estoque (48h)',
        build(byKey) {
          const rec = idOf(byKey, 'recuperacao');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 140,
              ins: 0,
              data: { trigger_type: 'tag_added', trigger_value: 'carrinho-abandonado', _preview: 'Tag carrinho' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_delay',
              x: 260,
              y: 130,
              ins: 1,
              data: { delay_minutes: 2880, _preview: '48h' },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_message',
              x: 480,
              y: 130,
              ins: 1,
              data: {
                agent_id: rec,
                message: '{{nome}}, ultima chance! Os itens do seu carrinho estao quase esgotando. Ainda tem interesse?',
                _preview: 'Urgencia 48h',
              },
            },
          ]);
        },
      },
      {
        name: '[Pacote] Recuperacao — pagamento expirado (tag)',
        build(byKey) {
          const rec = idOf(byKey, 'recuperacao');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 140,
              ins: 0,
              data: { trigger_type: 'tag_added', trigger_value: 'pix-expirado', _preview: 'Pix expirado' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_message',
              x: 280,
              y: 120,
              ins: 1,
              data: {
                agent_id: rec,
                message: '{{nome}}, seu Pix expirou. Quer que eu gere um novo link com a validade estendida?',
                _preview: 'Reenvio Pix',
              },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_action',
              x: 520,
              y: 120,
              ins: 1,
              data: { action_type: 'call_webhook', url: '', method: 'POST', _preview: 'Webhook Pix' },
            },
          ]);
        },
      },
    ],

    imobiliaria_stack: [
      {
        name: '[Pacote] Imobiliaria — primeiro contato',
        build(byKey) {
          const cor = idOf(byKey, 'corretor');
          const corK = keyOf(byKey, 'corretor');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 150,
              ins: 0,
              data: { trigger_type: 'whatsapp_first', trigger_value: corK, _preview: 'Primeira msg' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_message',
              x: 280,
              y: 130,
              ins: 1,
              data: {
                agent_id: cor,
                message: 'Ola {{nome}}! Sou corretor virtual. Esta procurando imovel para comprar ou alugar? Casa ou apartamento?',
                _preview: 'Boas-vindas',
              },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_memory',
              x: 540,
              y: 130,
              ins: 1,
              data: { memory_key: 'filtros', value_mode: 'session_recent', session_limit: 10, _preview: 'memoria.filtros' },
              links: [{ to: 4 }],
            },
            {
              id: 4,
              class: 'flow_action',
              x: 780,
              y: 130,
              ins: 1,
              data: { action_type: 'add_tag', tag: 'lead-imobiliario', _preview: 'Tag lead' },
              links: [{ to: 5 }],
            },
            {
              id: 5,
              class: 'flow_converse',
              x: 1040,
              y: 130,
              ins: 1,
              data: { connection_id: 0, agent_id: cor, label: 'Atendimento IA', instructions: 'Filtre um critério por vez: tipo → finalidade → quartos → região → orçamento. Envie top 3 imóveis.', max_turns: 20, end_keywords: 'tchau,obrigado,encerrar', end_tag: 'imobiliaria-concluido', _preview: 'Corretor IA' },
            },
          ]);
        },
      },
      {
        name: '[Pacote] Imobiliaria — visita agendada (tag)',
        build(byKey) {
          const cor = idOf(byKey, 'corretor');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 140,
              ins: 0,
              data: { trigger_type: 'tag_added', trigger_value: 'visita-agendada', _preview: 'Tag visita' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_memory',
              x: 260,
              y: 120,
              ins: 1,
              data: { memory_key: 'visita', value_mode: 'session_last', _preview: 'memoria.visita' },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_delay',
              x: 480,
              y: 120,
              ins: 1,
              data: { delay_minutes: 120, _preview: '2h antes' },
              links: [{ to: 4 }],
            },
            {
              id: 4,
              class: 'flow_message',
              x: 700,
              y: 120,
              ins: 1,
              data: {
                agent_id: cor,
                message: '{{nome}}, lembrete da visita ao imovel hoje! {{memoria.visita}}. Nao esqueca RG e CPF. Confirmado?',
                _preview: 'Lembrete visita',
              },
            },
          ]);
        },
      },
    ],

    faq_stack: [
      {
        name: '[Pacote] FAQ — atendimento + transbordo',
        build(byKey) {
          const faq = idOf(byKey, 'faq');
          const faqK = keyOf(byKey, 'faq');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 180,
              ins: 0,
              data: { trigger_type: 'whatsapp_message', trigger_value: faqK, _preview: 'Toda mensagem' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_condition',
              x: 260,
              y: 160,
              ins: 1,
              outCount: 2,
              data: { business_hours_only: 1, bh_start: '08:00', bh_end: '22:00', bh_weekdays: '1,2,3,4,5,6,7', _preview: '8h-22h' },
              links: [{ to: 3, fromOut: 'output_1' }, { to: 5, fromOut: 'output_2' }],
            },
            {
              id: 3,
              class: 'flow_message',
              x: 520,
              y: 80,
              ins: 1,
              data: {
                agent_id: faq,
                message: 'Ola {{nome}}! Sou assistente virtual da empresa. Como posso ajudar?',
                _preview: 'Atendimento',
              },
              links: [{ to: 4 }],
            },
            {
              id: 4,
              class: 'flow_converse',
              x: 760,
              y: 80,
              ins: 1,
              data: { connection_id: 0, agent_id: faq, label: 'Atendimento IA', instructions: 'Responda dúvidas com base no conhecimento. Se cliente irritado ou dúvida complexa, acione transbordo humano com tag cliente-irritado.', max_turns: 25, end_keywords: 'tchau,obrigado,encerrar', end_tag: 'duvida-resolvida', _preview: 'IA FAQ' },
            },
            {
              id: 5,
              class: 'flow_message',
              x: 520,
              y: 220,
              ins: 1,
              data: {
                agent_id: faq,
                message: 'Ola {{nome}}! Nosso time humano esta offline agora (8h-22h). Deixe sua duvida que retornamos assim que possivel.',
                _preview: 'Offline',
              },
            },
          ]);
        },
      },
      {
        name: '[Pacote] FAQ — transbordo humano (tag)',
        build(byKey) {
          const faq = idOf(byKey, 'faq');
          return makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 160,
              ins: 0,
              data: { trigger_type: 'tag_added', trigger_value: 'cliente-irritado', _preview: 'Tag irritacao' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_action',
              x: 280,
              y: 100,
              ins: 1,
              data: { action_type: 'pause_ai', agent_id: faq, minutes: 120, _preview: 'Pausa IA 2h' },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_message',
              x: 520,
              y: 80,
              ins: 1,
              data: {
                agent_id: faq,
                message: '{{nome}}, entendi sua situacao. Vou acionar um especialista agora. Um momento.',
                _preview: 'Transbordo',
              },
              links: [{ to: 4 }],
            },
            {
              id: 4,
              class: 'flow_action',
              x: 760,
              y: 80,
              ins: 1,
              data: { action_type: 'call_webhook', url: '', method: 'POST', body: '{"nome":"{{nome}}","motivo":"cliente-irritado","mensagens":"{{mensagens_hoje}}"}', _preview: 'Webhook alerta' },
            },
          ]);
        },
      },
    ],
  };
})();

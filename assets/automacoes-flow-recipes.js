/**
 * Jornadas prontas — modelos curtos e funcionais para WhatsApp.
 * Cada receita monta um grafo completo (gatilho → passos → IA/CRM).
 */
(function () {
  function cardHtml(type, data) {
    const titles = {
      flow_trigger: ['Quando começa', 'Gatilho'],
      flow_condition: ['Filtro', 'Condição'],
      flow_message: ['Boas-vindas', 'WhatsApp'],
      flow_converse: ['Atendimento IA', 'Contínuo'],
      flow_think: ['Resposta IA', 'Por turno'],
      flow_wait_reply: ['Aguardar', 'Resposta'],
      flow_action: ['Ação CRM', 'Funil'],
    };
    const t = titles[type] || ['Bloco', ''];
    const body = (data && data._preview) || '';
    const fnClass = type.replace('flow_', '');
    return (
      '<div class="fn-node fn-' +
      fnClass +
      '"><div class="fn-head"><div class="fn-icon"></div><div><div class="fn-title">' +
      t[0] +
      '</div><div class="fn-sub">' +
      t[1] +
      '</div></div></div><div class="fn-body">' +
      body +
      '</div></div>'
    );
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
        from.outputs[lnk.fromOut || 'output_1'].connections.push({
          node: String(lnk.to),
          output: lnk.toIn || 'input_1',
        });
        to.inputs[lnk.toIn || 'input_1'].connections.push({
          node: String(n.id),
          input: lnk.fromOut || 'output_1',
        });
      });
    });
    return { drawflow: { Home: { data } } };
  }

  function ctx(agents, connections) {
    const ag = agents && agents[0] ? parseInt(agents[0].id, 10) : 0;
    const conn = connections && connections[0] ? parseInt(connections[0].id, 10) : 0;
    const connKey = conn > 0 ? String(conn) : '*';
    return { ag, conn, connKey };
  }

  window.AUVVO_FLOW_RECIPES = [
    {
      id: 'atendimento_continuo',
      icon: 'ph-chats-circle',
      color: '#7c3aed',
      featured: true,
      name: 'Atendimento com IA (recomendado)',
      description: 'Lead manda a 1ª mensagem → IA responde direto com histórico nas próximas.',
      steps: ['Gatilho WhatsApp', 'Atendimento IA contínuo'],
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Atendimento WhatsApp',
          export: makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 140,
              ins: 0,
              data: {
                trigger_type: 'whatsapp_first',
                trigger_value: connKey,
                sync_pipeline_on_enter: 1,
                label: 'Quando começa',
                _preview: 'Primeira mensagem',
              },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_converse',
              x: 320,
              y: 120,
              ins: 1,
              data: {
                connection_id: conn,
                agent_id: ag,
                instructions:
                  'Conduza o atendimento de forma natural: cumprimente o lead, entenda a necessidade, faça perguntas curtas e indique o próximo passo. Seja humano e objetivo.',
                max_turns: 30,
                end_keywords: 'tchau,obrigado,encerrar,finalizar',
                end_tag: 'atendido',
                label: 'Atendimento IA',
                _preview: 'IA responde na 1ª mensagem',
              },
            },
          ]),
        };
      },
    },
    {
      id: 'clinica_agendamento',
      icon: 'ph-heartbeat',
      color: '#0891b2',
      featured: true,
      name: 'Clínica — secretária + agendamento',
      description: 'IA responde na 1ª mensagem e conduz agendamento, convênio e dúvidas.',
      steps: ['Gatilho WhatsApp', 'IA recepcionista'],
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Clínica — agendamento',
          export: makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 140,
              ins: 0,
              data: {
                trigger_type: 'whatsapp_first',
                trigger_value: connKey,
                sync_pipeline_on_enter: 1,
                label: 'Quando começa',
                _preview: 'Primeira mensagem',
              },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_converse',
              x: 320,
              y: 120,
              ins: 1,
              data: {
                connection_id: conn,
                agent_id: ag,
                label: 'Atendimento IA',
                instructions:
                  'Você é secretária de clínica. Cumprimente o lead e conduza: agendamento, convênio ou dúvidas. Para agendamento: especialidade, data/horário, confirme nome e telefone, resuma e peça OK. Seja acolhedora e objetiva.',
                max_turns: 25,
                end_keywords: 'tchau,obrigado,encerrar',
                end_tag: 'agendamento-concluido',
                _preview: 'Secretária virtual',
              },
            },
          ]),
        };
      },
    },
    {
      id: 'qualificacao_tags',
      icon: 'ph-funnel',
      color: '#0d9488',
      name: 'Qualificação com tags',
      description: 'Boas-vindas → IA faz perguntas → aguarda resposta → tag qualificado ou sem resposta.',
      steps: ['Gatilho', 'Abertura', 'IA pergunta', 'Aguarda', 'Tags CRM'],
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Qualificação WhatsApp',
          export: makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 40,
              y: 160,
              ins: 0,
              data: { trigger_type: 'whatsapp_first', trigger_value: connKey, _preview: 'Primeira msg' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_message',
              x: 280,
              y: 140,
              ins: 1,
              data: {
                connection_id: conn,
                agent_id: ag,
                message: 'Oi {{nome}}! Posso te fazer 2 perguntas rápidas para entender melhor?',
                _preview: 'Abertura',
              },
              links: [{ to: 3 }],
            },
            {
              id: 3,
              class: 'flow_think',
              x: 540,
              y: 120,
              ins: 1,
              data: {
                connection_id: conn,
                agent_id: ag,
                instructions: 'Faça 2 perguntas de qualificação (necessidade + prazo/orçamento). Seja objetivo.',
                message_count: 2,
                include_context: 1,
                send_whatsapp: 1,
                _preview: '2 mensagens IA',
              },
              links: [{ to: 4 }],
            },
            {
              id: 4,
              class: 'flow_wait_reply',
              x: 800,
              y: 120,
              ins: 1,
              outCount: 2,
              data: { timeout_hours: 24, _preview: 'Espera até 24h' },
              links: [{ to: 5, fromOut: 'output_1' }, { to: 6, fromOut: 'output_2' }],
            },
            {
              id: 5,
              class: 'flow_action',
              x: 1060,
              y: 60,
              ins: 1,
              data: { action_type: 'add_tag', tag: 'qualificado', label: 'Tag qualificado', _preview: 'Respondeu' },
            },
            {
              id: 6,
              class: 'flow_action',
              x: 1060,
              y: 220,
              ins: 1,
              data: { action_type: 'add_tag', tag: 'sem-resposta', label: 'Tag timeout', _preview: 'Não respondeu' },
            },
          ]),
        };
      },
    },
    {
      id: 'so_boas_vindas',
      icon: 'ph-whatsapp-logo',
      color: '#059669',
      name: 'Só boas-vindas (sem IA)',
      description: 'Resposta automática fixa na primeira mensagem — útil para avisos ou horário.',
      steps: ['Gatilho', 'Mensagem fixa'],
      build(agents, connections) {
        const { ag, conn, connKey } = ctx(agents, connections);
        return {
          name: 'Boas-vindas automáticas',
          export: makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 80,
              y: 140,
              ins: 0,
              data: { trigger_type: 'whatsapp_first', trigger_value: connKey, _preview: 'Primeira msg' },
              links: [{ to: 2 }],
            },
            {
              id: 2,
              class: 'flow_message',
              x: 360,
              y: 120,
              ins: 1,
              data: {
                connection_id: conn,
                agent_id: ag,
                message: 'Olá {{nome}}! Recebemos sua mensagem e retornaremos em breve.',
                _preview: 'Texto fixo',
              },
            },
          ]),
        };
      },
    },
    {
      id: 'blank_trigger',
      icon: 'ph-play-circle',
      color: '#64748b',
      name: 'Em branco (só gatilho)',
      description: 'Canvas vazio com nó de início — monte passo a passo na paleta.',
      steps: ['Gatilho'],
      build(agents, connections) {
        const { connKey } = ctx(agents, connections);
        return {
          name: 'Nova automação',
          export: makeGraph([
            {
              id: 1,
              class: 'flow_trigger',
              x: 80,
              y: 120,
              ins: 0,
              data: {
                trigger_type: 'whatsapp_first',
                trigger_value: connKey,
                sync_pipeline_on_enter: 1,
                _preview: 'Configure o gatilho →',
              },
            },
          ]),
        };
      },
    },
  ];

  window.AUVVO_FLOW_RECIPE_ORDER = [
    'atendimento_continuo',
    'clinica_agendamento',
    'qualificacao_tags',
    'so_boas_vindas',
    'blank_trigger',
  ];
})();

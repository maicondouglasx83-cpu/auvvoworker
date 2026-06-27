/**
 * Editor visual de automações (Drawflow)
 */
(function () {
  const B = window.FLOW_BOOT || {};
  const API = B.api || 'backend/api.php';

  function getCsrf() {
    return B.csrf
      || document.querySelector('meta[name="csrf-token"]')?.content
      || document.querySelector('input[name="csrf_token"]')?.value
      || window.CSRF
      || window.CSRF_TOKEN
      || '';
  }

  async function apiRequest(url, options) {
    if (window.AuvvoApi && typeof window.AuvvoApi.apiFetch === 'function') {
      return window.AuvvoApi.apiFetch(url, options);
    }
    const res = await fetch(url, options);
    if (!res.ok) {
      throw new Error('Erro HTTP ' + res.status);
    }
    return res.json();
  }

  function flowStages() {
    const pid = getFlowPipelineId();
    if (B.stagesByPipeline && pid && B.stagesByPipeline[pid]) {
      return B.stagesByPipeline[pid];
    }
    return B.stages || {};
  }

  function getFlowPipelineId() {
    const sel = document.getElementById('flow-pipeline');
    if (sel && sel.value) {
      return parseInt(sel.value, 10) || B.defaultPipelineId || 0;
    }
    return B.automationPipelineId || B.defaultPipelineId || 0;
  }

  function pipelineNameById(pid) {
    const list = B.pipelines || [];
    const p = list.find((x) => parseInt(x.id, 10) === parseInt(pid, 10));
    return p ? p.name : 'Funil';
  }

  function syncFlowPipelineToBoot() {
    const pid = getFlowPipelineId();
    B.automationPipelineId = pid;
    B.stages = flowStages();
    const autoSel = document.getElementById('auto-pipeline');
    if (autoSel) autoSel.value = String(pid);
    if (typeof window.fillAutomationStageSelects === 'function') {
      window.fillAutomationStageSelects();
    }
    if (typeof loadFlowList === 'function') loadFlowList();
    updateFlowRoutingSummary();
  }

  function onFlowPipelineChange() {
    syncFlowPipelineToBoot();
    if (editor) {
      try {
        const { export: remapped, changed } = remapFlowExportStages(editor.export(), getFlowPipelineId());
        if (changed) {
          editor.clear();
          editor.import(remapped);
          Object.keys(editor.drawflow.drawflow.Home.data || {}).forEach((nid) => refreshNodeVisual(nid));
          window.toast?.('Estágios ajustados ao funil deste fluxo.', 'info');
        }
      } catch (e) {}
    }
    if (selectedNodeId) renderPropsPanel(selectedNodeId);
    updateFlowRoutingSummary();
  }

  function initFlowPipelineSelect() {
    const sel = document.getElementById('flow-pipeline');
    if (!sel || !B.pipelines) return;
    sel.innerHTML = B.pipelines
      .map((p) => `<option value="${p.id}">${esc(p.name)}</option>`)
      .join('');
    const pid = B.automationPipelineId || B.defaultPipelineId || B.pipelines[0]?.id || 0;
    sel.value = String(pid);
    sel.addEventListener('change', onFlowPipelineChange);
  }

  window.onFlowPipelineChange = onFlowPipelineChange;

  const C = window.FlowConfig || {};
  const TRIGGER_LABELS = C.TRIGGER_LABELS || {};
  const TRIGGER_OPTIONS = C.TRIGGER_OPTIONS || [];
  const ACTION_LABELS = C.ACTION_LABELS || {};
  const MESSAGE_VARS = C.MESSAGE_VARS || [];
  const ACTION_ICON = C.ACTION_ICON || {};
  const ACTION_OPTIONS = C.ACTION_OPTIONS || [];
  const ACTION_OPTIONS_GROUPED = C.ACTION_OPTIONS_GROUPED || [];

  const NODE_ROLE = {
    flow_trigger: { title: 'Quando começa', role: 'Gatilho', hint: 'Define o evento que inicia este fluxo.' },
    flow_message: { title: 'Boas-vindas', role: 'Mensagem WhatsApp', hint: 'Texto fixo enviado imediatamente ao lead.' },
    flow_converse: { title: 'Atendimento IA', role: 'Contínuo', hint: 'IA responde cada mensagem com histórico — pode ir direto após o gatilho.' },
    flow_think: { title: 'Resposta IA', role: 'Por turno', hint: 'IA envia N mensagens e o fluxo continua — ideal antes de «Aguardar resposta».' },
    flow_agent: { title: 'Agente IA', role: 'Uma resposta', hint: 'Agente responde uma vez e segue o fluxo.' },
    flow_condition: { title: 'Filtro', role: 'Condição', hint: 'Azul = passa · vermelho = não passa.' },
    flow_wait_reply: { title: 'Aguardar resposta', role: 'Pausa', hint: 'Espera o lead responder no WhatsApp.' },
    flow_action: { title: 'Ação CRM', role: 'Funil / tags', hint: 'Move estágio, tags ou integrações.' },
    flow_delay: { title: 'Esperar', role: 'Tempo', hint: 'Atraso antes do próximo passo.' },
    flow_memory: { title: 'Memória', role: 'Contexto IA', hint: 'Grava o que o lead disse para usar depois.' },
    flow_randomizer: { title: 'Teste A/B', role: 'Random', hint: 'Divide leads em dois caminhos.' },
  };

  const FLOW_PROPS_CATALOG = [
    { icon: 'ph-play-circle', color: '#6366f1', title: 'Gatilho', desc: 'Primeira mensagem WhatsApp, estágio, tag ou webhook.' },
    { icon: 'ph-chats-circle', color: '#7c3aed', title: 'Atendimento IA', desc: 'Modo contínuo — IA responde cada mensagem com histórico.' },
    { icon: 'ph-lightbulb', color: '#f59e0b', title: 'IA por turno', desc: 'Script: IA envia N mensagens e o fluxo segue em frente.' },
    { icon: 'ph-robot', color: '#8b5cf6', title: 'Agente IA (1×)', desc: 'Uma resposta com ferramentas e segue o fluxo.' },
    { icon: 'ph-chat-teardrop-dots', color: '#0ea5e9', title: 'Aguardar resposta', desc: 'Pausa até o lead responder (azul) ou estourar timeout (vermelho).' },
    { icon: 'ph-columns', color: '#4338ca', title: 'Mover estágio', desc: 'Move o card do lead no funil escolhido.' },
    { icon: 'ph-tag', color: '#b45309', title: 'Tag CRM', desc: 'Adiciona ou remove tags para segmentação.' },
    { icon: 'ph-clock', color: '#eab308', title: 'Esperar tempo', desc: 'Atraso em minutos antes do próximo passo (worker).' },
    { icon: 'ph-plugs-connected', color: '#0f766e', title: 'Integração', desc: 'HTTP preset ou webhook outbound com dados do lead.' },
    { icon: 'ph-shuffle', color: '#f97316', title: 'Random A/B', desc: 'Divide leads em dois caminhos por percentual.' },
    { icon: 'ph-brain', color: '#a855f7', title: 'Memória IA', desc: 'Grava mensagens da sessão para {{memoria.chave}}.' },
    { icon: 'ph-lightning', color: '#10b981', title: 'Ações avançadas', desc: 'Pausar IA, missão do cérebro, Sheets e mais.' },
  ];

  function propsCatalogHtml() {
    return `<div class="flow-props-catalog">${FLOW_PROPS_CATALOG.map(
      (it) =>
        `<div class="flow-props-catalog__item"><i class="ph-bold ${it.icon}" style="color:${it.color}"></i><div><strong>${esc(it.title)}</strong><p>${esc(it.desc)}</p></div></div>`
    ).join('')}</div>`;
  }

  let editor = null;
  let currentFlowId = 0;
  let selectedNodeId = null;
  let inboundWebhooks = [];
  let outboundWebhooks = [];
  let httpPresets = [];
  let nodeStatsMap = {};
  let nodeErrorsMap = {};

  async function loadFlowNodeStats(flowId) {
    if (!flowId) {
      nodeStatsMap = {};
      return;
    }
    try {
      const d = await apiRequest(API + '?action=crm_flow_node_stats&flow_id=' + flowId);
      nodeStatsMap = d.stats || {};
    } catch (e) {
      nodeStatsMap = {};
    }
  }

  async function loadFlowNodeErrors(flowId) {
    if (!flowId) {
      nodeErrorsMap = {};
      return;
    }
    try {
      const d = await apiRequest(API + '?action=crm_flow_node_errors&flow_id=' + flowId);
      nodeErrorsMap = d.errors || {};
    } catch (e) {
      nodeErrorsMap = {};
    }
  }

  function nodeErrorFor(nodeId) {
    return nodeErrorsMap[String(nodeId)] || null;
  }

  function statsForNode(nodeId) {
    return nodeStatsMap[String(nodeId)] || { in: 0, ok: 0, err: 0 };
  }

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/"/g, '&quot;');
  }

  function nodeHtml(type, title, sub, body, stats) {
    const cls = {
      flow_trigger: 'fn-trigger',
      flow_condition: 'fn-cond',
      flow_randomizer: 'fn-rand',
      flow_delay: 'fn-wait',
      flow_action: 'fn-action',
      flow_message: 'fn-msg',
      flow_memory: 'fn-memory',
      flow_agent: 'fn-agent',
      flow_think: 'fn-think',
      flow_wait_reply: 'fn-wait-reply',
      flow_converse: 'fn-converse',
    }[type] || 'fn-action';
    const icon = {
      flow_trigger: 'ph-play-circle',
      flow_condition: 'ph-funnel',
      flow_randomizer: 'ph-shuffle',
      flow_delay: 'ph-clock',
      flow_action: 'ph-lightning',
      flow_message: 'ph-whatsapp-logo',
      flow_memory: 'ph-brain',
      flow_agent: 'ph-robot',
      flow_think: 'ph-lightbulb',
      flow_wait_reply: 'ph-chat-teardrop-dots',
      flow_converse: 'ph-chats-circle',
    }[type] || 'ph-circle';
    const st = stats || { in: 0, ok: 0, err: 0 };
    const errMsg = stats && stats._errMsg ? String(stats._errMsg) : '';
    const errClass = errMsg ? ' fn-node--has-error' : '';
    const err = errMsg
      ? `<div class="fn-err" title="${esc(errMsg)}"><strong>Erro na execução</strong>${esc(errMsg.length > 120 ? errMsg.slice(0, 117) + '…' : errMsg)}</div>`
      : '';
    const errStat = st.err > 0 || errMsg ? ' fn-stat--err' : '';
    return `
      <div class="fn-node ${cls}${errClass}">
        <div class="fn-head">
          <div class="fn-icon"><i class="ph-bold ${icon}"></i></div>
          <div>
            <div class="fn-title">${esc(title)}</div>
            <div class="fn-sub">${esc(sub)}</div>
          </div>
        </div>
        <div class="fn-body">${body}</div>
        ${err}
        <div class="fn-stats">
          <div class="fn-stat"><span>Entraram</span><em>${st.in}</em></div>
          <div class="fn-stat"><span>Sucesso</span><em>${st.ok}</em></div>
          <div class="fn-stat${errStat}"><span>Erro</span><em>${st.err}</em></div>
        </div>
      </div>`;
  }

  function defaultNodeData(type) {
    switch (type) {
      case 'flow_trigger':
        return {
          trigger_type: 'whatsapp_first',
          trigger_value: (B.whatsappConnections && B.whatsappConnections[0]) ? String(B.whatsappConnections[0].id) : '*',
          sync_pipeline_on_enter: 1,
          label: 'Primeira mensagem WhatsApp',
        };
      case 'flow_condition':
        return {
          pipeline_id: 0,
          require_tag: '',
          exclude_tag: '',
          stage_is: '',
          stage_not: '',
          agent_id: 0,
          agent_unassigned: 0,
          keyword_contains: '',
          keyword_not_contains: '',
          require_email: 0,
          require_phone: 0,
          business_hours_only: 0,
          outside_business_hours: 0,
          bh_start: '08:00',
          bh_end: '18:00',
          bh_weekdays: '1,2,3,4,5',
          ab_chance: 100,
          label: 'Condição',
        };
      case 'flow_memory':
        return {
          memory_key: 'resposta',
          value_mode: 'session_today',
          session_limit: 8,
          value: '',
          label: 'Gravar memória',
        };
      case 'flow_randomizer':
        return { pct_a: 50, label_a: 'Ramificação A', label_b: 'Ramificação B' };
      case 'flow_delay':
        return { delay_minutes: 5, label: 'Espera' };
      case 'flow_message':
        return {
          connection_id: (B.whatsappConnections && B.whatsappConnections[0]) ? B.whatsappConnections[0].id : 0,
          agent_id: (B.agents && B.agents[0]) ? B.agents[0].id : 0,
          message: 'Olá {{nome}}!',
          label: 'Mensagem WhatsApp',
        };
      case 'flow_agent':
        return {
          connection_id: (B.whatsappConnections && B.whatsappConnections[0]) ? B.whatsappConnections[0].id : 0,
          agent_id: (B.agents && B.agents[0]) ? B.agents[0].id : 0,
          mission: '',
          mode: 'respond',
          label: 'Agente IA',
        };
      case 'flow_think':
        return {
          connection_id: (B.whatsappConnections && B.whatsappConnections[0]) ? B.whatsappConnections[0].id : 0,
          agent_id: (B.agents && B.agents[0]) ? B.agents[0].id : 0,
          instructions: 'Analise o contexto do lead e responda de forma acolhedora.',
          message_count: 1,
          include_context: 1,
          send_whatsapp: 1,
          memory_key: '',
          label: 'Pensar & Responder',
        };
      case 'flow_converse':
        return {
          connection_id: (B.whatsappConnections && B.whatsappConnections[0]) ? B.whatsappConnections[0].id : 0,
          agent_id: (B.agents && B.agents[0]) ? B.agents[0].id : 0,
          instructions: 'Conduza um atendimento natural: entenda a necessidade, faça perguntas curtas e ajude o lead até resolver ou encaminhar.',
          max_turns: 30,
          end_keywords: 'tchau,obrigado,encerrar,finalizar',
          end_tag: '',
          label: 'Atendimento fluido',
        };
      case 'flow_wait_reply':
        return {
          timeout_hours: 24,
          keyword_contains: '',
          label: 'Aguardar resposta',
        };
      case 'flow_action':
        return { action_type: 'add_tag', tag: 'novo-lead', pipeline_id: 0, label: 'CRM — Tag' };
      default:
        return {};
    }
  }

  function summarizeNode(type, data) {
    data = data || {};
    if (type === 'flow_trigger') {
      const t = TRIGGER_LABELS[data.trigger_type] || data.trigger_type;
      let v = data.trigger_value || '*';
      const stg = flowStages();
      if (data.trigger_type === 'stage_enter' && stg[v]) v = stg[v];
      else if ((data.trigger_type === 'whatsapp_first' || data.trigger_type === 'whatsapp_message')) {
        v = connectionLabelById(v);
      } else if (data.trigger_type === 'contact_created' && v === 'whatsapp') v = 'Origem WhatsApp';
      const syncNote = ['whatsapp_first', 'whatsapp_message', 'contact_created'].includes(data.trigger_type)
        ? (data.sync_pipeline_on_enter === 0 ? ' · lead não muda de funil' : ' · → ' + pipelineNameById(getFlowPipelineId()))
        : '';
      return { title: 'Quando começa', sub: 'Gatilho', body: `<strong>${esc(t)}</strong><br>${esc(v)}${syncNote}` };
    }
    if (type === 'flow_condition') {
      const parts = [];
      const cpid = parseInt(data.pipeline_id, 10) || getFlowPipelineId();
      if (parseInt(data.pipeline_id, 10) > 0) {
        parts.push('Funil: ' + pipelineNameById(cpid));
      }
      if (data.require_tag) parts.push('Tag «' + data.require_tag + '»');
      if (data.exclude_tag) parts.push('Sem «' + data.exclude_tag + '»');
      const stg = stagesForPipeline(cpid);
      if (data.stage_is) parts.push('Estágio: ' + (stg[data.stage_is] || data.stage_is));
      if (data.stage_not) parts.push('≠ ' + (stg[data.stage_not] || data.stage_not));
      if (data.agent_id) {
        const ag = (B.agents || []).find((a) => String(a.id) === String(data.agent_id));
        parts.push('Agente: ' + (ag ? ag.name : '#' + data.agent_id));
      }
      if (data.agent_unassigned) parts.push('Sem agente');
      if (data.keyword_contains) parts.push('Contém «' + data.keyword_contains + '»');
      if (data.keyword_not_contains) parts.push('Não «' + data.keyword_not_contains + '»');
      if (data.require_email) parts.push('Com e-mail');
      if (data.require_phone) parts.push('Com telefone');
      if (data.business_hours_only) parts.push('Em horário (' + (data.bh_start || '08:00') + '–' + (data.bh_end || '18:00') + ')');
      if (data.outside_business_hours) parts.push('Fora do horário');
      if (data.ab_chance && data.ab_chance < 100) parts.push('A/B ' + data.ab_chance + '%');
      return {
        title: 'Condição',
        sub: 'Filtro',
        body: parts.length ? esc(parts.join(' · ')) : 'Sempre (sem filtros)',
      };
    }
    if (type === 'flow_randomizer') {
      return {
        title: 'Randomizador',
        sub: 'A/B teste',
        body: `<strong>A</strong> ${data.pct_a || 50}% · <strong>B</strong> ${100 - (data.pct_a || 50)}%`,
      };
    }
    if (type === 'flow_delay') {
      return {
        title: 'Espera',
        sub: 'Atraso',
        body: `Aguardar <strong>${data.delay_minutes || 5} min</strong> antes de continuar`,
      };
    }
    if (type === 'flow_message') {
      const conn = (B.whatsappConnections || []).find((c) => String(c.id) === String(data.connection_id));
      const ag = (B.agents || []).find((a) => String(a.id) === String(data.agent_id));
      const msg = (data.message || '').slice(0, 60);
      const via = conn ? conn.name : (ag ? ag.name : 'WhatsApp');
      return {
        title: 'Boas-vindas',
        sub: via + (ag && conn ? ' · ' + ag.name : ''),
        body: msg ? esc(msg) + (data.message.length > 60 ? '…' : '') : '<em>Configure a mensagem</em>',
      };
    }
    if (type === 'flow_agent') {
      const ag = (B.agents || []).find((a) => String(a.id) === String(data.agent_id));
      const conn = (B.whatsappConnections || []).find((c) => String(c.id) === String(data.connection_id));
      const mission = (data.mission || '').slice(0, 50);
      const modeLabel = data.mode === 'tools_only' ? ' · só ferramentas' : data.mode === 'proactive' ? ' · proativo' : '';
      return {
        title: 'Agente IA',
        sub: (ag ? ag.name : 'Agente') + (conn ? ' · ' + conn.name : '') + modeLabel,
        body: mission
          ? esc(mission) + (data.mission.length > 50 ? '…' : '')
          : '<em>Pensa e responde no WhatsApp</em>',
      };
    }
    if (type === 'flow_think') {
      const ag = (B.agents || []).find((a) => String(a.id) === String(data.agent_id));
      const instr = (data.instructions || '').slice(0, 55);
      const n = Math.max(1, Math.min(5, parseInt(data.message_count, 10) || 1));
      return {
        title: 'Resposta IA',
        sub: (ag ? ag.name : 'Agente') + ' · ' + n + ' msg(s)',
        body: instr ? esc(instr) + (data.instructions.length > 55 ? '…' : '') : '<em>Defina instruções</em>',
      };
    }
    if (type === 'flow_wait_reply') {
      const kw = (data.keyword_contains || '').trim();
      return {
        title: 'Aguardar resposta',
        sub: 'Script (1 pausa)',
        body: `Timeout <strong>${data.timeout_hours || 24}h</strong>${kw ? ' · «' + esc(kw) + '»' : ''}<br><small>Azul = respondeu · vermelho = timeout</small>`,
      };
    }
    if (type === 'flow_converse') {
      const ag = (B.agents || []).find((a) => String(a.id) === String(data.agent_id));
      const instr = (data.instructions || '').slice(0, 55);
      const turns = parseInt(data.max_turns, 10) || 30;
      return {
        title: 'Atendimento IA',
        sub: (ag ? ag.name : 'Agente') + ' · até ' + turns + ' turnos',
        body: instr
          ? esc(instr) + (data.instructions.length > 55 ? '…' : '')
          : '<em>Responde cada nova mensagem do lead</em>',
      };
    }
    if (type === 'flow_action') {
      const t = data.action_type || 'assign_agent';
      const al = ACTION_LABELS[t] || t;
      let title = 'Ação CRM';
      let sub = al;
      let body = esc(data.label || al);
      if (t === 'move_stage') {
        title = 'Mover estágio';
        const mp = parseInt(data.pipeline_id, 10) || getFlowPipelineId();
        const stg = stagesForPipeline(mp)[data.stage] || data.stage || '—';
        body = `<strong>${esc(pipelineNameById(mp))}</strong><br>→ ${esc(stg)}`;
      } else if (t === 'add_tag') {
        title = 'Tag CRM';
        sub = 'Adicionar';
        body = data.tag ? `«${esc(data.tag)}»` : '<em>Configure a tag</em>';
      } else if (t === 'remove_tag') {
        title = 'Tag CRM';
        sub = 'Remover';
        body = data.tag ? `− «${esc(data.tag)}»` : '<em>Configure a tag</em>';
      } else if (t === 'assign_agent') {
        title = 'Atribuir agente';
        const ag = (B.agents || []).find((a) => String(a.id) === String(data.agent_id));
        body = ag ? esc(ag.name) : '<em>Escolha o agente</em>';
      } else if (t === 'http_preset') {
        title = 'Integração';
        sub = 'HTTP preset';
        const pr = (httpPresets || []).find((p) => String(p.id) === String(data.preset_id));
        body = pr ? esc(pr.name) : '<em>Escolha o preset</em>';
      } else if (t === 'call_webhook') {
        title = 'Integração';
        sub = 'Webhook';
        const wh = (outboundWebhooks || []).find((w) => String(w.id) === String(data.webhook_id));
        body = wh ? esc(wh.name) : '<em>Escolha o webhook</em>';
      } else if (t === 'send_whatsapp') {
        title = 'WhatsApp';
        sub = 'Enviar texto';
        const msg = (data.message || '').slice(0, 55);
        body = msg ? esc(msg) + (data.message.length > 55 ? '…' : '') : '<em>Configure a mensagem</em>';
      } else if (t === 'brain_mission') {
        title = 'Missão IA';
        sub = 'Cérebro';
        const m = (data.mission || data.message || '').slice(0, 55);
        body = m ? esc(m) + ((data.mission || data.message || '').length > 55 ? '…' : '') : '<em>Instrução temporária</em>';
      } else if (t === 'pause_ai' || t === 'resume_ai') {
        title = t === 'pause_ai' ? 'Pausar IA' : 'Retomar IA';
        const ag = (B.agents || []).find((a) => String(a.id) === String(data.agent_id));
        body = ag ? esc(ag.name) : '<em>Agente da conversa</em>';
        if (t === 'pause_ai' && data.minutes) body += `<br><small>${esc(String(data.minutes))} min</small>`;
      }
      return { title, sub, body };
    }
    if (type === 'flow_memory') {
      const modes = {
        last_message: 'msg do gatilho',
        session_last: 'última da sessão',
        session_today: 'mensagens de hoje',
        session_recent: 'últimas ' + (data.session_limit || 8) + ' da sessão',
        fixed: 'valor fixo',
        template: 'template',
      };
      return {
        title: 'Memória IA',
        sub: data.memory_key || 'chave',
        body: `<strong>${esc(modes[data.value_mode] || data.value_mode)}</strong> → {{memoria.${esc(data.memory_key || 'chave')}}}`,
      };
    }
    return { title: 'Nó', sub: '', body: '' };
  }

  function refreshNodeVisual(nodeId) {
    if (!editor || nodeId == null) return;
    const node = editor.getNodeFromId(nodeId);
    if (!node) return;
    const sum = summarizeNode(node.class, node.data);
    const el = document.querySelector(`#node-${nodeId} .fn-node`);
    if (!el) return;
    const wrap = el.parentElement;
    if (!wrap) return;
    const stats = statsForNode(nodeId);
    const err = nodeErrorFor(nodeId);
    if (err) stats._errMsg = err.message;
    const tmp = document.createElement('div');
    tmp.innerHTML = nodeHtml(node.class, sum.title, sum.sub, sum.body, stats);
    const newInner = tmp.firstElementChild;
    if (newInner && el.parentNode) {
      el.replaceWith(newInner);
    }
  }

  function initEditor() {
    const el = document.getElementById('drawflow');
    if (!el || typeof Drawflow === 'undefined') return;
    editor = new Drawflow(el);
    editor.reroute = true;
    editor.reroute_fix_curvature = true;
    editor.curvature = 0.4;
    editor.start();
    window._flowEditor = editor;

    editor.on('nodeSelected', (id) => {
      selectedNodeId = id;
      renderPropsPanel(id);
      document.getElementById('flow-props')?.classList.add('flow-props--open');
    });
    editor.on('nodeUnselected', () => {
      selectedNodeId = null;
      renderPropsPanel(null);
    });
    editor.on('nodeCreated', (id) => {
      refreshNodeVisual(id);
      updateFlowRoutingSummary();
    });
    editor.on('nodeDataChanged', (id) => refreshNodeVisual(id));
    editor.on('connectionCreated', () => updateFlowRoutingSummary());
    editor.on('connectionRemoved', () => updateFlowRoutingSummary());

    if (window.innerWidth > 1200) {
      document.getElementById('btn-props-mobile')?.addEventListener('click', () => {
        document.getElementById('flow-props')?.classList.toggle('flow-props--open');
      });
    }
  }

  function addNode(type, x, y, preset) {
    if (!editor) return;
    const data = { ...defaultNodeData(type), ...(preset || {}) };
    const sum = summarizeNode(type, data);
    const html = nodeHtml(type, sum.title, sum.sub, sum.body);
    let inputs = 1;
    let outputs = 1;
    if (type === 'flow_trigger') {
      inputs = 0;
      outputs = 1;
    } else if (type === 'flow_condition' || type === 'flow_randomizer' || type === 'flow_wait_reply') {
      outputs = 2;
    }
    const id = editor.addNode(type, inputs, outputs, x || 80 + Math.random() * 80, y || 80 + Math.random() * 80, type, data, html);
    return id;
  }

  function defaultFlowExport() {
    const recipes = window.AUVVO_FLOW_RECIPES || [];
    const recipe = recipes.find((r) => r.id === 'atendimento_continuo');
    if (recipe && typeof recipe.build === 'function') {
      return recipe.build(B.agents || [], B.whatsappConnections || []).export;
    }
    const id1 = 1;
    const trigData = defaultNodeData('flow_trigger');
    const sum = summarizeNode('flow_trigger', trigData);
    const html = nodeHtml('flow_trigger', sum.title, sum.sub, sum.body);
    return {
      drawflow: {
        Home: {
          data: {
            [id1]: {
              id: id1,
              name: 'flow_trigger',
              data: trigData,
              class: 'flow_trigger',
              html,
              typenode: false,
              inputs: {},
              outputs: { output_1: { connections: [] } },
              pos_x: 80,
              pos_y: 120,
            },
          },
        },
      },
    };
  }

  function findBestConnectFrom() {
    if (!editor || selectedNodeId) {
      const sel = selectedNodeId ? editor.getNodeFromId(selectedNodeId) : null;
      if (sel && sel.outputs && Object.keys(sel.outputs).length) return selectedNodeId;
    }
    const nodes = editor?.drawflow?.drawflow?.Home?.data || {};
    let triggerId = null;
    let best = null;
    let bestX = -1;
    Object.keys(nodes).forEach((nid) => {
      const n = nodes[nid];
      if (n.name === 'flow_trigger' && !triggerId) triggerId = parseInt(nid, 10);
      const x = n.pos_x || 0;
      const outs = n.outputs || {};
      const hasFreeOut = Object.values(outs).some((o) => !(o.connections && o.connections.length));
      if (hasFreeOut && x >= bestX) {
        bestX = x;
        best = parseInt(nid, 10);
      }
    });
    return best || triggerId;
  }

  function autoConnectNewNode(fromId, toId) {
    if (!editor || !fromId || !toId || fromId === toId) return;
    try {
      editor.addConnection(fromId, toId, 'output_1', 'input_1');
    } catch (e) {}
  }

  function stagesForPipeline(pid) {
    const id = parseInt(pid, 10) || getFlowPipelineId();
    const map = (B.stagesByPipeline && B.stagesByPipeline[id]) || null;
    if (map && Object.keys(map).length) return map;
    return flowStages();
  }

  function pipelineOptionsHtml(selectedPid, includeFlowDefault) {
    const sel = parseInt(selectedPid, 10) || 0;
    let h = '';
    if (includeFlowDefault !== false) {
      h += `<option value="0" ${sel === 0 ? 'selected' : ''}>Funil deste fluxo (${esc(pipelineNameById(getFlowPipelineId()))})</option>`;
    }
    h += (B.pipelines || [])
      .map((p) => `<option value="${p.id}" ${sel === parseInt(p.id, 10) ? 'selected' : ''}>${esc(p.name)}</option>`)
      .join('');
    return h;
  }

  function stageOptions(sel) {
    return stageOptionsForPipeline(getFlowPipelineId(), sel);
  }

  function stageOptionsForPipeline(pid, sel) {
    const stages = stagesForPipeline(pid);
    return Object.keys(stages)
      .map((k) => `<option value="${esc(k)}" ${sel === k ? 'selected' : ''}>${esc(stages[k])}</option>`)
      .join('');
  }

  function connectionLabelById(id) {
    if (!id || id === '*') return 'Qualquer linha';
    const c = (B.whatsappConnections || []).find((x) => String(x.id) === String(id));
    if (!c) return 'Linha #' + id;
    const st = c.status === 'connected' || c.status === 'online' ? '' : ' (' + (c.status || 'offline') + ')';
    return (c.name || 'Conexão') + st;
  }

  function updateFlowRoutingSummary() {
    const el = document.getElementById('flow-routing-summary');
    if (!el) return;
    const pid = getFlowPipelineId();
    const chips = [];
    chips.push(`<span class="route-chip route-chip--pipe"><i class="ph-bold ph-funnel"></i> Funil: <strong>${esc(pipelineNameById(pid))}</strong></span>`);
    if (!editor) {
      el.innerHTML = chips.join('');
      return;
    }
    let exported;
    try {
      exported = editor.export();
    } catch (e) {
      el.innerHTML = chips.join('');
      return;
    }
    const nodes = exported?.drawflow?.Home?.data || {};
    const triggers = [];
    const crmMoves = [];
    let wildcardWa = false;
    Object.values(nodes).forEach((n) => {
      const d = n.data || {};
      if (n.name === 'flow_trigger') {
        const tt = d.trigger_type || '';
        let detail = TRIGGER_LABELS[tt] || tt;
        if (['whatsapp_first', 'whatsapp_message'].includes(tt)) {
          const connId = d.trigger_value || '*';
          detail += ' · ' + connectionLabelById(connId);
          if (connId === '*' || connId === '0' || connId === '') wildcardWa = true;
        } else if (d.trigger_value && d.trigger_value !== '*') {
          detail += ' · ' + d.trigger_value;
        }
        if (['whatsapp_first', 'whatsapp_message', 'contact_created'].includes(tt)) {
          detail += d.sync_pipeline_on_enter === 0 ? ' (sem mover funil)' : ' → funil do fluxo';
        }
        triggers.push(detail);
      }
      if (n.name === 'flow_action' && d.action_type === 'move_stage') {
        const mp = parseInt(d.pipeline_id, 10) || pid;
        const stg = stagesForPipeline(mp)[d.stage] || d.stage || '?';
        crmMoves.push(`${esc(pipelineNameById(mp))} → ${esc(stg)}`);
      }
    });
    if (triggers.length) {
      chips.push(`<span class="route-chip route-chip--trigger"><i class="ph-bold ph-play-circle"></i> Gatilhos: ${esc(triggers.join(' · '))}</span>`);
    }
    if (wildcardWa) {
      chips.push('<span class="route-chip route-chip--warn"><i class="ph-bold ph-warning"></i> «Qualquer linha» — defina uma conexão por fluxo</span>');
    }
    if (crmMoves.length) {
      chips.push(`<span class="route-chip route-chip--crm"><i class="ph-bold ph-columns"></i> CRM: ${crmMoves.join(' · ')}</span>`);
    }
    el.innerHTML = chips.join('');
    updateFlowStepsGuide();
  }

  function updateFlowStepsGuide() {
    const wrap = document.getElementById('flow-steps-guide-chips');
    if (!wrap) return;
    if (!editor) {
      wrap.innerHTML = '<span class="flow-step-chip flow-step-chip--muted">Carregue ou crie uma jornada</span>';
      return;
    }
    let nodes = {};
    try {
      nodes = editor.export()?.drawflow?.Home?.data || {};
    } catch (e) {
      wrap.innerHTML = '';
      return;
    }
    const types = Object.values(nodes).map((n) => n.name || n.class);
    const hasTrigger = types.includes('flow_trigger');
    const hasMessage = types.includes('flow_message');
    const hasIa = types.some((t) => ['flow_converse', 'flow_think', 'flow_agent'].includes(t));
    const hasConverse = types.includes('flow_converse');
    const hasCrm = types.includes('flow_action');
    const steps = [{ ok: hasTrigger, label: '1. Gatilho', warn: !hasTrigger }];
    if (hasConverse) {
      steps.push({ ok: true, label: '2. Atendimento IA', warn: false });
    } else if (hasIa) {
      steps.push({ ok: true, label: '2. IA (script)', warn: false });
    } else if (hasMessage) {
      steps.push({ ok: true, label: '2. Boas-vindas', warn: false });
    } else {
      steps.push({ ok: false, label: '2. Atendimento IA', warn: hasTrigger });
    }
    if (hasMessage && (hasConverse || hasIa)) {
      steps.push({ ok: true, label: 'Boas-vindas (extra)', warn: false });
    }
    if (hasCrm) steps.push({ ok: true, label: 'CRM', warn: false });
    wrap.innerHTML = steps
      .map((s) => {
        const cls = s.ok ? 'flow-step-chip--ok' : s.warn ? 'flow-step-chip--warn' : 'flow-step-chip--todo';
        const icon = s.ok ? 'ph-check-circle' : s.warn ? 'ph-warning' : 'ph-circle-dashed';
        return `<span class="flow-step-chip ${cls}"><i class="ph-bold ${icon}"></i> ${esc(s.label)}</span>`;
      })
      .join('');
    if (hasTrigger && hasConverse && !hasMessage) {
      wrap.innerHTML +=
        '<span class="flow-step-chip flow-step-chip--ok flow-step-chip--hint"><i class="ph-bold ph-seal-check"></i> IA responde na 1ª mensagem — jornada ideal</span>';
    } else if (hasTrigger && hasMessage && hasConverse) {
      wrap.innerHTML +=
        '<span class="flow-step-chip flow-step-chip--warn"><i class="ph-bold ph-info"></i> Boas-vindas + IA contínua pode duplicar — remova o bloco fixo ou use só a IA</span>';
    } else if (hasTrigger && hasMessage && !hasIa) {
      wrap.innerHTML +=
        '<span class="flow-step-chip flow-step-chip--ok flow-step-chip--hint"><i class="ph-bold ph-info"></i> Só texto fixo — sem IA neste fluxo</span>';
    }
  }

  window.updateFlowRoutingSummary = updateFlowRoutingSummary;

  function agentOptions(sel, includeEmpty) {
    let h = includeEmpty ? '<option value="0">— Lead sem filtro de agente —</option>' : '';
    h += (B.agents || [])
      .map((a) => `<option value="${a.id}" ${String(sel) === String(a.id) ? 'selected' : ''}>${esc(a.name)}</option>`)
      .join('');
    return h;
  }

  function varChipsHtml(targetId) {
    return `<div class="msg-var-chips" data-target="${esc(targetId)}">${MESSAGE_VARS.map((v) =>
      `<button type="button" class="msg-var-chip" data-var="${esc(v.key)}" title="${esc(v.label)}">${esc(v.label)}</button>`
    ).join('')}</div>`;
  }

  function bindVarChips(container) {
    container?.querySelectorAll('.msg-var-chip').forEach((btn) => {
      btn.addEventListener('click', () => {
        const targetId = btn.closest('.msg-var-chips')?.getAttribute('data-target');
        const ta = targetId ? document.getElementById(targetId) : null;
        if (!ta) return;
        let insert = btn.getAttribute('data-var') || '';
        if (insert === 'campo.') insert = '{{campo.slug}}';
        else insert = '{{' + insert + '}}';
        const start = ta.selectionStart ?? ta.value.length;
        const end = ta.selectionEnd ?? start;
        ta.value = ta.value.slice(0, start) + insert + ta.value.slice(end);
        ta.focus();
        ta.dispatchEvent(new Event('input', { bubbles: true }));
      });
    });
  }

  function previewMessageClient(text) {
    const sample = B.sampleContact || { name: 'Maria Silva', phone: '11999998888', email: 'maria@email.com', company: 'Acme', stage: 'new' };
    let out = text || '';
    const map = {
      nome: sample.name,
      telefone: sample.phone,
      email: sample.email,
      empresa: sample.company,
      estagio: flowStages()[sample.stage] || sample.stage,
      agente: (B.agents && B.agents[0]) ? B.agents[0].name : 'Agente',
      mensagem: 'Quero saber o preço',
      tags: 'lead, quente',
    };
    Object.keys(map).forEach((k) => {
      out = out.split('{{' + k + '}}').join(map[k]);
    });
    return out;
  }

  function agentPickerOptions(includeAny) {
    const items = [];
    if (includeAny !== false) {
      items.push({ value: '*', label: 'Qualquer agente', hint: 'Todos os cérebros IA', icon: 'ph-globe', color: '#64748b' });
    }
    (B.agents || []).forEach((a) => {
      const name = (a.name && String(a.name).trim()) ? String(a.name) : 'Agente #' + a.id;
      items.push({ value: String(a.id), label: name, hint: 'Cérebro IA', icon: 'ph-brain', color: '#4338ca' });
    });
    if (!items.length || (items.length === 1 && includeAny !== false)) {
      items.push({ value: '0', label: 'Nenhum agente cadastrado', hint: 'Crie em Agentes', icon: 'ph-warning', color: '#b45309' });
    }
    return items;
  }

  function connectionPickerOptions(includeAny) {
    const items = [];
    if (includeAny !== false) {
      items.push({ value: '*', label: 'Qualquer conexão', hint: 'Todas as linhas WhatsApp', icon: 'ph-globe', color: '#64748b' });
    }
    (B.whatsappConnections || []).forEach((c) => {
      const name = (c.name && String(c.name).trim()) ? String(c.name) : 'Conexão #' + c.id;
      let hint = 'Linha WhatsApp';
      if (c.status === 'online') hint = 'Conectado';
      else if (c.status === 'waiting_qr') hint = 'Aguardando QR Code';
      else hint = 'Desconectado';
      items.push({ value: String(c.id), label: name, hint, icon: 'ph-whatsapp-logo', color: '#059669' });
    });
    if (!items.length || (items.length === 1 && includeAny !== false)) {
      items.push({ value: '0', label: 'Nenhuma conexão cadastrada', hint: 'Aba Conexões WhatsApp', icon: 'ph-warning', color: '#b45309' });
    }
    return items;
  }

  function mountConnectionPickerEl(containerId, value, onChange, includeAny) {
    const opts = connectionPickerOptions(includeAny);
    const def = (B.whatsappConnections && B.whatsappConnections[0]) ? String(B.whatsappConnections[0].id) : '*';
    const v = value !== undefined && value !== null && value !== '' ? String(value) : def;
    return mountAuvPicker(containerId, [{ items: opts }], v, onChange);
  }

  function agentPickerOptionsForLead() {
    const items = [{ value: '0', label: 'Qualquer agente do lead', hint: 'Não filtra por agente', icon: 'ph-user', color: '#64748b' }];
    (B.agents || []).forEach((a) => {
      const name = (a.name && String(a.name).trim()) ? String(a.name) : 'Agente #' + a.id;
      items.push({ value: String(a.id), label: name, hint: 'Filtrar leads deste agente', icon: 'ph-whatsapp-logo', color: '#059669' });
    });
    return items;
  }

  function agentPickerOptionsAssign() {
    const items = [];
    (B.agents || []).forEach((a) => {
      const name = (a.name && String(a.name).trim()) ? String(a.name) : 'Agente #' + a.id;
      items.push({ value: String(a.id), label: name, hint: 'Cérebro IA', icon: 'ph-brain', color: '#4338ca' });
    });
    if (!items.length) {
      items.push({ value: '0', label: 'Cadastre um agente', hint: 'Menu Agentes', icon: 'ph-warning', color: '#b45309' });
    }
    return items;
  }

  function mountAgentPickerEl(containerId, value, onChange, mode) {
    let opts;
    let def = '*';
    if (mode === 'lead') {
      opts = agentPickerOptionsForLead();
      def = '0';
    } else if (mode === 'assign') {
      opts = agentPickerOptionsAssign();
      def = String((B.agents && B.agents[0]) ? B.agents[0].id : '0');
    } else {
      opts = agentPickerOptions(true);
    }
    const v = value !== undefined && value !== null && value !== '' ? String(value) : def;
    return mountAuvPicker(containerId, [{ items: opts }], v, onChange);
  }

  function propsField(label, inner, hint) {
    return `<div class="props-field">
      <label class="props-field-label">${label}</label>
      <div class="props-field-control">${inner}</div>
      ${hint ? `<p class="props-field-hint">${hint}</p>` : ''}
    </div>`;
  }

  function propsDetails(title, icon, body, open) {
    return `<details class="props-details"${open ? ' open' : ''}>
      <summary><i class="ph-bold ${icon}"></i> ${title}</summary>
      <div class="props-details-body">${body}</div>
    </details>`;
  }

  function flattenPickerOptions(options) {
    const flat = [];
    (options || []).forEach((entry) => {
      if (!entry) return;
      if (Array.isArray(entry.items)) {
        if (entry.group) flat.push({ group: entry.group });
        entry.items.forEach((it) => {
          if (it && it.value !== undefined && it.label) flat.push(it);
        });
      } else if (entry.group && entry.value === undefined) {
        flat.push({ group: entry.group });
      } else if (entry.value !== undefined && entry.label) {
        flat.push(entry);
      }
    });
    return flat;
  }

  function mountAuvPicker(containerId, options, value, onChange) {
    const wrap = document.getElementById(containerId);
    if (!wrap) return null;
    let flat = flattenPickerOptions(options);
    if (!flat.length) {
      wrap.innerHTML = '<p class="props-empty-opt">Nenhuma opção disponível.</p>';
      return { getValue: () => value, setValue: () => {} };
    }
    const find = () => flat.find((x) => !x.group && String(x.value) === String(value));
    let current = find() || flat.find((x) => !x.group) || null;

    function render() {
      current = find() || flat.find((x) => !x.group) || null;
      const label = current?.label || 'Selecione uma opção';
      const hint = current?.hint || '';
      const icon = current?.icon || 'ph-list';
      const color = current?.color || '#64748b';
      wrap.innerHTML = `
        <button type="button" class="auv-picker-trigger" aria-haspopup="listbox">
          <span class="auv-picker-icon" style="background:${color}18;color:${color}">
            <i class="ph-bold ${icon}"></i>
          </span>
          <span class="auv-picker-text">
            <span class="auv-picker-label">${esc(label)}</span>
            ${hint ? `<span class="auv-picker-hint">${esc(hint)}</span>` : ''}
          </span>
          <i class="ph-bold ph-caret-down auv-picker-caret"></i>
        </button>
        <div class="auv-picker-menu" role="listbox" hidden>
          ${flat
            .map((it) => {
              if (it.group) return `<div class="auv-picker-group">${esc(it.group)}</div>`;
              if (it.value === undefined || !it.label) return '';
              const sel = String(it.value) === String(value) ? ' is-selected' : '';
              return `<button type="button" class="auv-picker-option${sel}" data-value="${esc(String(it.value))}" role="option">
                <span class="auv-picker-icon" style="background:${it.color || '#e2e8f0'}18;color:${it.color || '#64748b'}"><i class="ph-bold ${it.icon || 'ph-circle'}"></i></span>
                <span class="auv-picker-text"><span class="auv-picker-label">${esc(it.label)}</span>${it.hint ? `<span class="auv-picker-hint">${esc(it.hint)}</span>` : ''}</span>
              </button>`;
            })
            .join('')}
        </div>`;
      const trigger = wrap.querySelector('.auv-picker-trigger');
      const menu = wrap.querySelector('.auv-picker-menu');
      trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        const open = !menu.hidden;
        document.querySelectorAll('.auv-picker-menu').forEach((m) => { m.hidden = true; });
        document.querySelectorAll('.auv-picker').forEach((p) => p.classList.remove('is-open'));
        if (!open) {
          menu.hidden = false;
          wrap.classList.add('is-open');
        }
      });
      wrap.querySelectorAll('.auv-picker-option').forEach((btn) => {
        btn.addEventListener('click', () => {
          value = btn.getAttribute('data-value');
          menu.hidden = true;
          wrap.classList.remove('is-open');
          render();
          onChange(value);
        });
      });
    }
    render();
    if (!window._auvPickerDocClose) {
      window._auvPickerDocClose = true;
      document.addEventListener('click', () => {
        document.querySelectorAll('.auv-picker-menu').forEach((m) => { m.hidden = true; });
        document.querySelectorAll('.auv-picker').forEach((p) => p.classList.remove('is-open'));
      });
    }
    return {
      getValue: () => value,
      setValue: (v) => { value = v; render(); },
    };
  }

  let triggerTypePicker = null;
  let triggerConnectionPicker = null;
  let actionTypePicker = null;
  let msgConnectionPicker = null;
  let msgAgentPicker = null;
  let condAgentPicker = null;
  let actionAgentPicker = null;
  let actionConnectionPicker = null;
  let agentNodeConnectionPicker = null;
  let agentNodeAgentPicker = null;
  let thinkConnectionPicker = null;
  let converseConnectionPicker = null;
  let converseAgentPicker = null;
  let thinkAgentPicker = null;

  function renderPropsPanel(nodeId) {
    const body = document.getElementById('flow-props-body');
    if (!body) return;
    if (!nodeId || !editor) {
      body.innerHTML =
        '<p class="flow-props-empty">Clique em um bloco do canvas ou adicione pela barra lateral. Jornada ideal: <strong>Gatilho</strong> → <strong>Atendimento IA</strong> (sem boas-vindas fixas na 1ª mensagem).</p>' +
        propsCatalogHtml();
      return;
    }
    const node = editor.getNodeFromId(nodeId);
    if (!node) return;
    const d = node.data || {};
    const type = node.class;
    const meta = NODE_ROLE[type] || { title: 'Bloco', role: type.replace('flow_', ''), hint: '' };
    let html = `<div class="props-node-head"><strong>${esc(meta.title)}</strong><span class="props-node-role">${esc(meta.role)}</span></div>`;
    if (meta.hint) html += `<p class="props-node-hint">${esc(meta.hint)}</p>`;

    if (type === 'flow_trigger') {
      const pipeLabel = esc(pipelineNameById(getFlowPipelineId()));
      html += `
        <div class="props-callout props-callout-info">Este fluxo começa quando o evento abaixo acontece. Para WhatsApp, use <strong>Primeira mensagem</strong> na maioria dos casos.</div>
        ${propsField('Quando disparar', '<div class="auv-picker" id="picker-trigger-type"></div>')}
        <div id="wrap-trigger-connection" style="${['whatsapp_first','whatsapp_message'].includes(d.trigger_type) ? '' : 'display:none'}">
          ${propsField('Linha WhatsApp que dispara', '<div class="auv-picker" id="picker-trigger-agent"></div>', 'Recomendado: uma linha específica por fluxo (evita conflito com «Qualquer»)')}
        </div>
        <div id="wrap-trigger-pipeline-sync" style="${['whatsapp_first','whatsapp_message','contact_created'].includes(d.trigger_type) ? '' : 'display:none'}">
          <label class="props-check"><input type="checkbox" id="p-trigger-sync-pipeline" ${d.sync_pipeline_on_enter !== 0 ? 'checked' : ''}> Mover lead para o funil deste fluxo ao disparar</label>
          <p class="props-field-hint">Ex.: lead novo no WhatsApp entra no funil <strong>${pipeLabel}</strong> antes das ações CRM.</p>
        </div>
        <div id="p-trigger-value-wrap" class="props-field">
          <label class="props-field-label" id="p-trigger-value-label">Detalhe do gatilho</label>
          <div class="props-field-control">
            <select class="auv-input auv-native-select" id="p-trigger-stage" style="${d.trigger_type === 'stage_enter' ? '' : 'display:none'}">${stageOptions(d.trigger_value)}</select>
            <input class="auv-input" id="p-trigger-tag" style="${d.trigger_type === 'tag_added' ? '' : 'display:none'}" value="${esc(d.trigger_value || '')}" placeholder="nome-da-tag">
            <select class="auv-input auv-native-select" id="p-trigger-webhook" style="${d.trigger_type === 'webhook_received' ? '' : 'display:none'}">
              ${inboundWebhooks.length ? inboundWebhooks.map((w) => `<option value="${esc(w.url_slug)}" ${d.trigger_value === w.url_slug ? 'selected' : ''}>${esc(w.name)}</option>`).join('') : '<option value="">Nenhum webhook</option>'}
            </select>
            <select class="auv-input auv-native-select" id="p-trigger-source" style="${d.trigger_type === 'contact_created' ? '' : 'display:none'}">
              <option value="*" ${d.trigger_value === '*' ? 'selected' : ''}>Qualquer origem</option>
              <option value="whatsapp" ${d.trigger_value === 'whatsapp' ? 'selected' : ''}>WhatsApp</option>
              <option value="webhook" ${d.trigger_value === 'webhook' ? 'selected' : ''}>Webhook</option>
              <option value="manual" ${d.trigger_value === 'manual' ? 'selected' : ''}>Manual</option>
            </select>
          </div>
          <p class="props-field-hint" id="p-trigger-hint"></p>
        </div>
        ${propsField('Cooldown (mensagens)', `<select class="auv-input auv-native-select" id="p-trigger-cooldown">
            <option value="none" ${(d.cooldown_mode || 'none') === 'none' ? 'selected' : ''}>Toda mensagem dispara</option>
            <option value="once_per_day" ${d.cooldown_mode === 'once_per_day' ? 'selected' : ''}>Máx. 1× por lead por dia</option>
          </select>`, 'Só para gatilho «Qualquer mensagem»')}`;
    } else if (type === 'flow_condition') {
      const tagsBlock = `
        ${propsField('Exigir tag', '<input class="auv-input" id="p-require-tag" value="' + esc(d.require_tag || '') + '" placeholder="ex: vip, cliente">')}
        ${propsField('Excluir tag', '<input class="auv-input" id="p-exclude-tag" value="' + esc(d.exclude_tag || '') + '" placeholder="ex: atendido">')}`;
      const funnelBlock = `
        ${propsField('Estágio deve ser', '<select class="auv-input auv-native-select" id="p-stage-is"><option value="">— Qualquer —</option>' + stageOptionsForPipeline(d.pipeline_id || getFlowPipelineId(), d.stage_is || '') + '</select>')}
        ${propsField('Estágio não pode ser', '<select class="auv-input auv-native-select" id="p-stage-not"><option value="">— Ignorar —</option>' + stageOptionsForPipeline(d.pipeline_id || getFlowPipelineId(), d.stage_not || '') + '</select>')}
        ${propsField('Agente do lead', '<div class="auv-picker" id="picker-cond-agent"></div>')}
        <label class="props-check"><input type="checkbox" id="p-agent-unassigned" ${d.agent_unassigned ? 'checked' : ''}> Apenas lead sem agente</label>`;
      const msgBlock = `
        ${propsField('Mensagem contém', '<input class="auv-input" id="p-kw-contains" value="' + esc(d.keyword_contains || '') + '" placeholder="preço, orçamento, valor">', 'Separe por vírgula (OR)')}
        ${propsField('Mensagem não contém', '<input class="auv-input" id="p-kw-not" value="' + esc(d.keyword_not_contains || '') + '">')}
        <label class="props-check"><input type="checkbox" id="p-req-email" ${d.require_email ? 'checked' : ''}> Exigir e-mail</label>
        <label class="props-check"><input type="checkbox" id="p-req-phone" ${d.require_phone ? 'checked' : ''}> Exigir telefone</label>`;
      const hoursBlock = `
        <label class="props-check"><input type="checkbox" id="p-bh-only" ${d.business_hours_only ? 'checked' : ''}> Só em horário comercial</label>
        <label class="props-check"><input type="checkbox" id="p-bh-out" ${d.outside_business_hours ? 'checked' : ''}> Só fora do horário</label>
        <div class="props-row-2">
          ${propsField('Início', '<input type="time" class="auv-input" id="p-bh-start" value="' + esc((d.bh_start || '08:00').slice(0, 5)) + '">')}
          ${propsField('Fim', '<input type="time" class="auv-input" id="p-bh-end" value="' + esc((d.bh_end || '18:00').slice(0, 5)) + '">')}
        </div>
        ${propsField('Dias úteis', '<input class="auv-input" id="p-bh-days" value="' + esc(d.bh_weekdays || '1,2,3,4,5') + '" placeholder="1=seg … 7=dom">')}`;
      html += `
        <div class="props-callout">Filtros em <strong>E</strong> (todos obrigatórios se preenchidos). <span class="props-legend"><i class="dot dot-ok"></i> azul = sim</span> <span class="props-legend"><i class="dot dot-no"></i> vermelho = não</span></div>
        ${propsField('Funil dos estágios', `<select class="auv-input auv-native-select" id="p-cond-pipeline">${pipelineOptionsHtml(d.pipeline_id || 0)}</select>`, 'Estágios «deve ser / não pode ser» usam colunas deste funil')}
        ${propsDetails('Tags', 'ph-tag', tagsBlock, true)}
        ${propsDetails('Funil e agente', 'ph-columns', funnelBlock, true)}
        ${propsDetails('Mensagem WhatsApp', 'ph-chats-circle', msgBlock, true)}
        ${propsDetails('Horário comercial', 'ph-clock', hoursBlock, false)}
        ${propsField('Teste A/B (%)', '<input type="number" class="auv-input" id="p-ab" min="1" max="100" value="' + (d.ab_chance ?? 100) + '">', '100 = sempre passa no ramo azul')}`;
    } else if (type === 'flow_memory') {
      const showLimit = d.value_mode === 'session_recent';
      html += `
        ${propsField('Chave da memória', '<input class="auv-input" id="p-mem-key" value="' + esc(d.memory_key || '') + '" placeholder="interesse, cidade, orcamento">', 'Use em mensagens: {{memoria.chave}}')}
        ${propsField('Origem do valor', `<select class="auv-input auv-native-select" id="p-mem-mode">
            <option value="session_today" ${d.value_mode === 'session_today' || !d.value_mode ? 'selected' : ''}>Mensagens de hoje (sessão WhatsApp)</option>
            <option value="session_recent" ${d.value_mode === 'session_recent' ? 'selected' : ''}>Últimas N mensagens da sessão</option>
            <option value="session_last" ${d.value_mode === 'session_last' ? 'selected' : ''}>Última mensagem da sessão</option>
            <option value="last_message" ${d.value_mode === 'last_message' ? 'selected' : ''}>Só mensagem do gatilho atual</option>
            <option value="fixed" ${d.value_mode === 'fixed' ? 'selected' : ''}>Texto fixo</option>
            <option value="template" ${d.value_mode === 'template' ? 'selected' : ''}>Template com variáveis</option>
          </select>`, 'Lê conversation_logs — histórico real do chat')}
        <div id="wrap-mem-limit" class="props-field" style="${showLimit ? '' : 'display:none'}">
          <label class="props-field-label">Quantidade (N)</label>
          <div class="props-field-control">
            <input type="number" class="auv-input" id="p-mem-limit" min="1" max="20" value="${d.session_limit ?? 8}">
          </div>
          <p class="props-field-hint">Máx. 20 mensagens recebidas do lead</p>
        </div>
        <div id="wrap-mem-value" style="${['fixed', 'template'].includes(d.value_mode) ? '' : 'display:none'}">
        ${propsField('Valor / template', varChipsHtml('p-mem-value') + '<textarea class="auv-input auv-textarea" id="p-mem-value" rows="4">' + esc(d.value || '') + '</textarea>')}
        </div>
        <div class="props-callout props-callout-info">Grava o que o lead disse hoje no WhatsApp. Condições «mensagem contém» também usam o texto do dia.</div>`;
    } else if (type === 'flow_randomizer') {
      html += `
        ${propsField('Ramificação A (%)', '<input type="number" class="auv-input" id="p-pct-a" min="1" max="99" value="' + (d.pct_a ?? 50) + '">', 'Saída azul = A · vermelha = B')}
        <div class="props-callout">O restante vai automaticamente para o ramo B.</div>`;
    } else if (type === 'flow_delay') {
      html += propsField('Aguardar (minutos)', '<input type="number" class="auv-input" id="p-delay" min="1" max="43200" value="' + (d.delay_minutes ?? 5) + '">', 'Processado pelo worker Node (sem cron PHP)');
    } else if (type === 'flow_wait_reply') {
      html += `
        <div class="props-callout props-callout-info">Pausa o fluxo até o lead responder no WhatsApp. Saída <strong>azul</strong> = respondeu · <strong>vermelha</strong> = timeout.</div>
        ${propsField('Timeout (horas)', '<input type="number" class="auv-input" id="p-wait-hours" min="1" max="168" value="' + (d.timeout_hours ?? 24) + '">')}
        ${propsField('Resposta deve conter (opcional)', '<input class="auv-input" id="p-wait-keyword" value="' + esc(d.keyword_contains || '') + '" placeholder="sim, quero, agendar">')}`;
    } else if (type === 'flow_message') {
      html += `
        <div class="props-callout props-callout-info">Opcional — texto fixo enviado na hora. Se houver <strong>Atendimento IA</strong> logo depois, pode haver duas respostas na 1ª mensagem.</div>
        ${propsField('Conexão (linha)', '<div class="auv-picker" id="picker-msg-connection"></div>', 'Número WhatsApp que envia')}
        ${propsField('Agente (cérebro)', '<div class="auv-picker" id="picker-msg-agent"></div>', 'Opcional — contexto do agente na mensagem')}
        ${propsField('Texto da mensagem', varChipsHtml('p-msg-text') + '<textarea class="auv-input auv-textarea" id="p-msg-text" rows="6">' + esc(d.message || '') + '</textarea>')}
        <div class="msg-preview"><span class="msg-preview-label">Prévia</span><p id="p-msg-preview"></p></div>`;
    } else if (type === 'flow_agent') {
      html += `
        <div class="props-callout props-callout-info">O agente lê a mensagem do gatilho, pensa com IA, responde no WhatsApp e pode executar ferramentas (estágio, tags, agenda).</div>
        ${propsField('Conexão (linha)', '<div class="auv-picker" id="picker-agent-connection"></div>')}
        ${propsField('Agente (cérebro)', '<div class="auv-picker" id="picker-agent-brain"></div>')}
        ${propsField('Modo', `<select class="auv-input auv-native-select" id="p-agent-mode">
            <option value="respond" ${d.mode !== 'tools_only' && d.mode !== 'proactive' ? 'selected' : ''}>Responder no WhatsApp</option>
            <option value="proactive" ${d.mode === 'proactive' ? 'selected' : ''}>Proativo (sem msg do lead — usa missão)</option>
            <option value="tools_only" ${d.mode === 'tools_only' ? 'selected' : ''}>Só executar ferramentas (sem texto extra)</option>
          </select>`)}
        ${propsField('Missão (opcional)', varChipsHtml('p-agent-mission') + '<textarea class="auv-input auv-textarea" id="p-agent-mission" rows="4" placeholder="Ex.: Qualificar o lead e agendar demo">' + esc(d.mission || '') + '</textarea>', 'Instrução temporária — obrigatória no modo proativo')}
        <div class="props-callout">Quando este nó responde, a IA padrão do webhook <strong>não</strong> dispara de novo.</div>`;
    } else if (type === 'flow_converse') {
      html += `
        <div class="props-callout props-callout-info"><strong>Modo contínuo (recomendado):</strong> depois deste bloco, cada mensagem nova do lead é respondida pela IA com histórico — sem repetir gatilho.</div>
        ${propsField('Conexão (linha)', '<div class="auv-picker" id="picker-converse-connection"></div>')}
        ${propsField('Agente (cérebro)', '<div class="auv-picker" id="picker-converse-agent"></div>')}
        ${propsField('Instruções do atendimento', varChipsHtml('p-converse-instructions') + '<textarea class="auv-input auv-textarea" id="p-converse-instructions" rows="6" placeholder="Ex.: Qualifique interesse, tire dúvidas sobre planos e convide para demo">' + esc(d.instructions || '') + '</textarea>', 'Missão enquanto a sessão estiver ativa')}
        ${propsField('Máx. turnos (0 = ilimitado)', '<input type="number" class="auv-input" id="p-converse-turns" min="0" max="100" value="' + (d.max_turns ?? 30) + '">', 'Conta respostas do lead na sessão')}
        ${propsField('Encerrar se mensagem contém', '<input class="auv-input" id="p-converse-end-kw" value="' + esc(d.end_keywords || 'tchau,obrigado,encerrar,finalizar') + '" placeholder="tchau, obrigado">')}
        ${propsField('Tag ao encerrar (opcional)', '<input class="auv-input" id="p-converse-end-tag" value="' + esc(d.end_tag || '') + '" placeholder="atendimento-concluido">')}
        <div class="props-callout">Pode ir direto após o gatilho (IA cumprimenta na 1ª resposta). Diferente de «Aguardar resposta» (script com timeout).</div>`;
    } else if (type === 'flow_think') {
      html += `
        <div class="props-callout props-callout-info"><strong>Modo script:</strong> a IA envia até N mensagens e o fluxo <em>continua</em> para o próximo bloco. Use antes de «Aguardar resposta» ou tags CRM.</div>
        ${propsField('Conexão (linha)', '<div class="auv-picker" id="picker-think-connection"></div>')}
        ${propsField('Agente (cérebro)', '<div class="auv-picker" id="picker-think-agent"></div>')}
        ${propsField('Instruções', varChipsHtml('p-think-instructions') + '<textarea class="auv-input auv-textarea" id="p-think-instructions" rows="5" placeholder="Ex.: Qualifique o interesse, apresente os 3 planos e convide para demo">' + esc(d.instructions || '') + '</textarea>', 'O que o agente deve pensar e como responder')}
        ${propsField('Quantidade de mensagens', '<input type="number" class="auv-input" id="p-think-count" min="1" max="5" value="' + (d.message_count ?? 1) + '">', 'Máx. 5 mensagens sequenciais no WhatsApp')}
        <label class="props-check"><input type="checkbox" id="p-think-context" ${d.include_context !== 0 ? 'checked' : ''}> Incluir mensagem do gatilho e dados do lead</label>
        <label class="props-check"><input type="checkbox" id="p-think-send" ${d.send_whatsapp !== 0 ? 'checked' : ''}> Enviar no WhatsApp</label>
        ${propsField('Gravar na memória (opcional)', '<input class="auv-input" id="p-think-memory" value="' + esc(d.memory_key || '') + '" placeholder="ex.: ultima_analise">', 'Salva JSON com mensagens e raciocínio')}`;
    } else if (type === 'flow_action') {
      const actionHints = {
        move_stage: 'Move o card do lead para outra coluna do funil.',
        add_tag: 'Aplica uma tag — útil após qualificação ou encerramento.',
        remove_tag: 'Remove tag existente do lead.',
        assign_agent: 'Define o agente humano responsável pelo lead.',
        http_preset: 'Chama integração HTTP configurada em Configurações.',
        call_webhook: 'Dispara webhook outbound com payload do lead.',
        send_whatsapp: 'Envia texto fixo (variáveis {{nome}}, etc.).',
        brain_mission: 'Próxima resposta IA seguirá esta missão temporária.',
        pause_ai: 'Silencia a IA por X minutos nesta conversa.',
        resume_ai: 'Reativa a IA após pausa manual ou automática.',
      };
      const ah = actionHints[d.action_type] || 'Escolha o tipo abaixo — CRM, WhatsApp ou integrações.';
      html += `
        <div class="props-callout props-callout-info">${esc(ah)}</div>
        ${propsField('Tipo de ação', '<div class="auv-picker" id="picker-action-type"></div>')}
        <div id="p-action-fields" class="props-action-fields"></div>`;
    }
    body.innerHTML = html;
    bindPropsEvents(nodeId, type, d);
  }

  function updateTriggerValueVisibility(tt) {
    const connWrap = document.getElementById('wrap-trigger-connection');
    const syncWrap = document.getElementById('wrap-trigger-pipeline-sync');
    const stage = document.getElementById('p-trigger-stage');
    const tag = document.getElementById('p-trigger-tag');
    const wh = document.getElementById('p-trigger-webhook');
    const src = document.getElementById('p-trigger-source');
    const hint = document.getElementById('p-trigger-hint');
    const lbl = document.getElementById('p-trigger-value-label');
    if (connWrap) connWrap.style.display = ['whatsapp_first', 'whatsapp_message'].includes(tt) ? 'block' : 'none';
    if (syncWrap) syncWrap.style.display = ['whatsapp_first', 'whatsapp_message', 'contact_created'].includes(tt) ? 'block' : 'none';
    if (stage) stage.style.display = tt === 'stage_enter' ? 'block' : 'none';
    if (tag) tag.style.display = tt === 'tag_added' ? 'block' : 'none';
    if (wh) wh.style.display = tt === 'webhook_received' ? 'block' : 'none';
    if (src) src.style.display = tt === 'contact_created' ? 'block' : 'none';
    if (lbl) lbl.style.display = ['whatsapp_first', 'whatsapp_message', 'ltv_inactive'].includes(tt) ? 'none' : 'block';
    const hints = {
      whatsapp_first: 'Dispara quando o lead manda a primeira mensagem no número conectado (Evolution).',
      whatsapp_message: 'Dispara a cada mensagem recebida — use condição para filtrar palavras ou tags.',
      stage_enter: 'Quando o card do lead entra no estágio escolhido no funil.',
      tag_added: 'Quando a tag é aplicada (manual, automação ou integração).',
      webhook_received: 'Lead criado/atualizado por URL de webhook (Hotmart, formulário…).',
      contact_created: 'Novo lead no CRM (qualquer origem ou filtro abaixo).',
      ltv_inactive: 'Cliente sumiu do ciclo de compra (worker LTV).',
    };
    if (hint) hint.textContent = hints[tt] || '';
  }

  function bindPropsEvents(nodeId, type, d) {
    const apply = () => {
      const node = editor.getNodeFromId(nodeId);
      if (!node) return;
      const data = { ...node.data };

      if (type === 'flow_trigger') {
        data.trigger_type = triggerTypePicker?.getValue() || data.trigger_type;
        const tt = data.trigger_type;
        if (tt === 'whatsapp_first' || tt === 'whatsapp_message') {
          data.trigger_value = triggerConnectionPicker?.getValue() || '*';
        } else if (tt === 'stage_enter') data.trigger_value = document.getElementById('p-trigger-stage')?.value || 'new';
        else if (tt === 'tag_added') data.trigger_value = document.getElementById('p-trigger-tag')?.value?.trim() || '';
        else if (tt === 'webhook_received') data.trigger_value = document.getElementById('p-trigger-webhook')?.value || '';
        else if (tt === 'contact_created') data.trigger_value = document.getElementById('p-trigger-source')?.value || '*';
        else if (tt === 'ltv_inactive') data.trigger_value = 'default';
        data.cooldown_mode = document.getElementById('p-trigger-cooldown')?.value || 'none';
        data.sync_pipeline_on_enter = document.getElementById('p-trigger-sync-pipeline')?.checked ? 1 : 0;
      } else if (type === 'flow_condition') {
        data.pipeline_id = parseInt(document.getElementById('p-cond-pipeline')?.value || '0', 10) || 0;
        data.require_tag = document.getElementById('p-require-tag')?.value?.trim() || '';
        data.exclude_tag = document.getElementById('p-exclude-tag')?.value?.trim() || '';
        data.stage_is = document.getElementById('p-stage-is')?.value || '';
        data.stage_not = document.getElementById('p-stage-not')?.value || '';
        data.agent_id = parseInt(condAgentPicker?.getValue() || '0', 10) || 0;
        data.agent_unassigned = document.getElementById('p-agent-unassigned')?.checked ? 1 : 0;
        data.keyword_contains = document.getElementById('p-kw-contains')?.value?.trim() || '';
        data.keyword_not_contains = document.getElementById('p-kw-not')?.value?.trim() || '';
        data.require_email = document.getElementById('p-req-email')?.checked ? 1 : 0;
        data.require_phone = document.getElementById('p-req-phone')?.checked ? 1 : 0;
        data.business_hours_only = document.getElementById('p-bh-only')?.checked ? 1 : 0;
        data.outside_business_hours = document.getElementById('p-bh-out')?.checked ? 1 : 0;
        data.bh_start = document.getElementById('p-bh-start')?.value || '08:00';
        data.bh_end = document.getElementById('p-bh-end')?.value || '18:00';
        data.bh_weekdays = document.getElementById('p-bh-days')?.value?.trim() || '1,2,3,4,5';
        data.ab_chance = parseInt(document.getElementById('p-ab')?.value, 10) || 100;
      } else if (type === 'flow_memory') {
        data.memory_key = document.getElementById('p-mem-key')?.value?.trim() || '';
        data.value_mode = document.getElementById('p-mem-mode')?.value || 'session_today';
        data.session_limit = parseInt(document.getElementById('p-mem-limit')?.value, 10) || 8;
        data.value = document.getElementById('p-mem-value')?.value || '';
      } else if (type === 'flow_randomizer') {
        data.pct_a = parseInt(document.getElementById('p-pct-a')?.value, 10) || 50;
      } else if (type === 'flow_delay') {
        data.delay_minutes = parseInt(document.getElementById('p-delay')?.value, 10) || 5;
      } else if (type === 'flow_wait_reply') {
        data.timeout_hours = parseInt(document.getElementById('p-wait-hours')?.value, 10) || 24;
        data.keyword_contains = document.getElementById('p-wait-keyword')?.value?.trim() || '';
      } else if (type === 'flow_message') {
        data.connection_id = parseInt(msgConnectionPicker?.getValue() || '0', 10) || 0;
        data.agent_id = parseInt(msgAgentPicker?.getValue() || '0', 10) || 0;
        data.message = document.getElementById('p-msg-text')?.value || '';
        const prev = document.getElementById('p-msg-preview');
        if (prev) prev.textContent = previewMessageClient(data.message) || '—';
      } else if (type === 'flow_agent') {
        data.connection_id = parseInt(agentNodeConnectionPicker?.getValue() || '0', 10) || 0;
        data.agent_id = parseInt(agentNodeAgentPicker?.getValue() || '0', 10) || 0;
        data.mode = document.getElementById('p-agent-mode')?.value || 'respond';
        data.mission = document.getElementById('p-agent-mission')?.value?.trim() || '';
        data.label = 'Agente IA';
      } else if (type === 'flow_think') {
        data.connection_id = parseInt(thinkConnectionPicker?.getValue() || '0', 10) || 0;
        data.agent_id = parseInt(thinkAgentPicker?.getValue() || '0', 10) || 0;
        data.instructions = document.getElementById('p-think-instructions')?.value?.trim() || '';
        data.message_count = parseInt(document.getElementById('p-think-count')?.value, 10) || 1;
        data.include_context = document.getElementById('p-think-context')?.checked ? 1 : 0;
        data.send_whatsapp = document.getElementById('p-think-send')?.checked ? 1 : 0;
        data.memory_key = document.getElementById('p-think-memory')?.value?.trim() || '';
        data.label = 'Pensar & Responder';
      } else if (type === 'flow_converse') {
        data.connection_id = parseInt(converseConnectionPicker?.getValue() || '0', 10) || 0;
        data.agent_id = parseInt(converseAgentPicker?.getValue() || '0', 10) || 0;
        data.instructions = document.getElementById('p-converse-instructions')?.value?.trim() || '';
        data.max_turns = parseInt(document.getElementById('p-converse-turns')?.value, 10);
        if (Number.isNaN(data.max_turns)) data.max_turns = 30;
        data.end_keywords = document.getElementById('p-converse-end-kw')?.value?.trim() || '';
        data.end_tag = document.getElementById('p-converse-end-tag')?.value?.trim() || '';
        data.label = 'Atendimento fluido';
      } else if (type === 'flow_action') {
        data.action_type = actionTypePicker?.getValue() || 'assign_agent';
        syncActionFieldsToData(data);
      }

      editor.updateNodeDataFromId(nodeId, data);
      refreshNodeVisual(nodeId);
      updateFlowRoutingSummary();
    };

    if (type === 'flow_trigger') {
      triggerTypePicker = mountAuvPicker('picker-trigger-type', TRIGGER_OPTIONS, d.trigger_type || 'whatsapp_first', (v) => {
        updateTriggerValueVisibility(v);
        apply();
      });
      const agVal = ['whatsapp_first', 'whatsapp_message'].includes(d.trigger_type)
        ? (d.trigger_value || '*')
        : '*';
      triggerConnectionPicker = mountConnectionPickerEl('picker-trigger-agent', agVal, () => apply(), true);
      updateTriggerValueVisibility(d.trigger_type || 'whatsapp_first');
    }

    if (type === 'flow_condition') {
      condAgentPicker = mountAgentPickerEl('picker-cond-agent', d.agent_id || 0, () => apply(), 'lead');
      const condPipe = document.getElementById('p-cond-pipeline');
      if (condPipe) {
        condPipe.addEventListener('change', () => {
          const np = parseInt(condPipe.value, 10) || getFlowPipelineId();
          const stIs = document.getElementById('p-stage-is');
          const stNot = document.getElementById('p-stage-not');
          if (stIs) {
            const cur = stIs.value;
            stIs.innerHTML = '<option value="">— Qualquer —</option>' + stageOptionsForPipeline(np, cur);
          }
          if (stNot) {
            const cur = stNot.value;
            stNot.innerHTML = '<option value="">— Ignorar —</option>' + stageOptionsForPipeline(np, cur);
          }
          apply();
        });
      }
    }

    if (type === 'flow_message') {
      const defaultConn = d.connection_id || (B.whatsappConnections && B.whatsappConnections[0] ? B.whatsappConnections[0].id : 0);
      const defaultAg = d.agent_id || (B.agents && B.agents[0] ? B.agents[0].id : 0);
      msgConnectionPicker = mountConnectionPickerEl('picker-msg-connection', defaultConn, () => apply(), false);
      msgAgentPicker = mountAgentPickerEl('picker-msg-agent', defaultAg, () => apply(), 'assign');
    }

    if (type === 'flow_agent') {
      const defaultConn = d.connection_id || (B.whatsappConnections && B.whatsappConnections[0] ? B.whatsappConnections[0].id : 0);
      const defaultAg = d.agent_id || (B.agents && B.agents[0] ? B.agents[0].id : 0);
      agentNodeConnectionPicker = mountConnectionPickerEl('picker-agent-connection', defaultConn, () => apply(), false);
      agentNodeAgentPicker = mountAgentPickerEl('picker-agent-brain', defaultAg, () => apply(), 'assign');
    }

    if (type === 'flow_think') {
      const defaultConn = d.connection_id || (B.whatsappConnections && B.whatsappConnections[0] ? B.whatsappConnections[0].id : 0);
      const defaultAg = d.agent_id || (B.agents && B.agents[0] ? B.agents[0].id : 0);
      thinkConnectionPicker = mountConnectionPickerEl('picker-think-connection', defaultConn, () => apply(), false);
      thinkAgentPicker = mountAgentPickerEl('picker-think-agent', defaultAg, () => apply(), 'assign');
    }

    if (type === 'flow_converse') {
      const defaultConn = d.connection_id || (B.whatsappConnections && B.whatsappConnections[0] ? B.whatsappConnections[0].id : 0);
      const defaultAg = d.agent_id || (B.agents && B.agents[0] ? B.agents[0].id : 0);
      converseConnectionPicker = mountConnectionPickerEl('picker-converse-connection', defaultConn, () => apply(), false);
      converseAgentPicker = mountAgentPickerEl('picker-converse-agent', defaultAg, () => apply(), 'assign');
    }

    if (type === 'flow_action') {
      actionTypePicker = mountAuvPicker('picker-action-type', ACTION_OPTIONS_GROUPED, d.action_type || 'assign_agent', (v) => {
        renderActionFields({ action_type: v }, nodeId, apply);
        apply();
      });
    }

    const propsBody = document.getElementById('flow-props-body');
    propsBody?.querySelectorAll('input, select, textarea').forEach((el) => {
      if (el.id === 'p-trigger-type') return;
      el.addEventListener('change', apply);
      el.addEventListener('input', apply);
    });

    bindVarChips(document.getElementById('flow-props-body'));
    bindVarChips(document.getElementById('p-agent-mission')?.parentElement);
    bindVarChips(document.getElementById('p-think-instructions')?.parentElement);
    bindVarChips(document.getElementById('p-converse-instructions')?.parentElement);
    const msgTa = document.getElementById('p-msg-text');
    if (msgTa) {
      const upd = () => {
        const prev = document.getElementById('p-msg-preview');
        if (prev) prev.textContent = previewMessageClient(msgTa.value) || '—';
      };
      msgTa.addEventListener('input', upd);
      upd();
    }

    if (type === 'flow_action') {
      renderActionFields(d, nodeId, apply);
    }

    const memMode = document.getElementById('p-mem-mode');
    if (memMode) {
      const syncMemUi = () => {
        const mode = memMode.value;
        const lim = document.getElementById('wrap-mem-limit');
        const val = document.getElementById('wrap-mem-value');
        if (lim) lim.style.display = mode === 'session_recent' ? '' : 'none';
        if (val) val.style.display = ['fixed', 'template'].includes(mode) ? '' : 'none';
      };
      memMode.addEventListener('change', () => {
        syncMemUi();
        apply();
      });
      syncMemUi();
    }
  }

  function renderActionFields(d, nodeId, apply) {
    const wrap = document.getElementById('p-action-fields');
    if (!wrap) return;
    actionAgentPicker = null;
    actionConnectionPicker = null;
    const t = d.action_type || 'assign_agent';
    let h = '';
    if (t === 'send_whatsapp' || t === 'invoke_agent') {
      if (t === 'send_whatsapp') {
        h = propsField('Conexão (linha)', '<div class="auv-picker" id="picker-action-connection"></div>', 'Número WhatsApp que envia');
        h += propsField('Agente (cérebro)', '<div class="auv-picker" id="picker-action-agent"></div>', 'Quem responde / contexto da mensagem');
      } else {
        h = propsField('Agente (cérebro)', '<div class="auv-picker" id="picker-action-agent"></div>');
      }
      if (t === 'invoke_agent') {
        h += `<label class="props-check"><input type="checkbox" class="p-f-switch" ${d.switch_agent ? 'checked' : ''}> Trocar agente do lead</label>`;
      }
      h += propsField('Mensagem', varChipsHtml('p-f-msg-ta') + `<textarea class="auv-input auv-textarea p-f-msg" id="p-f-msg-ta" rows="4">${esc(d.message || '')}</textarea>`);
    } else if (t === 'assign_agent') {
      h = propsField('Agente responsável', '<div class="auv-picker" id="picker-action-agent"></div>');
    } else if (t === 'move_stage') {
      const pid = parseInt(d.pipeline_id, 10) || 0;
      h = propsField('Funil destino', `<select class="auv-input auv-native-select p-f-pipeline">${pipelineOptionsHtml(pid)}</select>`, 'O lead será movido para este funil antes de trocar o estágio');
      h += propsField('Estágio destino', `<select class="auv-input auv-native-select p-f-stage">${stageOptionsForPipeline(pid, d.stage)}</select>`);
    } else if (t === 'add_tag' || t === 'remove_tag') {
      h = propsField('Nome da tag', `<input class="auv-input p-f-tag" value="${esc(d.tag || '')}" placeholder="nome-da-tag">`);
    } else if (t === 'pause_ai' || t === 'resume_ai') {
      h = propsField('Agente (conversa)', '<div class="auv-picker" id="picker-action-agent"></div>');
      if (t === 'pause_ai') {
        h += propsField('Pausar por (min)', `<input type="number" class="auv-input p-f-mins" value="${d.minutes || 60}" min="15">`);
      }
    } else if (t === 'call_webhook') {
      const whOpts = outboundWebhooks.length
        ? outboundWebhooks.map((w) => `<option value="${w.id}" ${String(d.webhook_id) === String(w.id) ? 'selected' : ''}>${esc(w.name)}</option>`).join('')
        : '<option value="">Nenhum webhook outbound</option>';
      h = propsField('Webhook outbound', `<select class="auv-input auv-native-select p-f-wh">${whOpts}</select>`);
    } else if (t === 'http_preset') {
      const prOpts = httpPresets.length
        ? httpPresets.map((p) => `<option value="${p.id}" ${String(d.preset_id) === String(p.id) ? 'selected' : ''}>${esc(p.name)}</option>`).join('')
        : '<option value="">Nenhum preset</option>';
      h = propsField('Preset HTTP', `<select class="auv-input auv-native-select p-f-preset">${prOpts}</select>`);
    } else if (t === 'set_memory') {
      h = propsField('Chave', `<input class="auv-input p-f-key" value="${esc(d.key || '')}" placeholder="ex: interesse">`)
        + propsField('Valor', `<input class="auv-input p-f-val" value="${esc(d.value || '')}">`);
    } else if (t === 'brain_mission') {
      h = propsField(
        'Missão para o cérebro',
        varChipsHtml('p-f-mission') + `<textarea class="auv-input auv-textarea p-f-mission" id="p-f-mission" rows="5" placeholder="Ex.: Confirmar horário e agendar no Google Calendar; marcar tag consulta-agendada.">${esc(d.mission || d.message || '')}</textarea>`,
        'A próxima resposta IA do agente seguirá esta missão (memória _brain_mission). Variáveis: {{nome}}, {{estagio}}, etc.'
      );
    } else if (t === 'clear_brain_mission') {
      h = propsField(
        'Limpar missão',
        '<p class="text-muted" style="font-size:.8125rem;margin:0">Remove <code>_brain_mission</code> após conclusão (tag, estágio ou ação do cérebro).</p>'
      );
    }
    wrap.innerHTML = h;
    bindVarChips(wrap);
    const pipeSel = wrap.querySelector('.p-f-pipeline');
    const stageSel = wrap.querySelector('.p-f-stage');
    if (pipeSel && stageSel && t === 'move_stage') {
      pipeSel.addEventListener('change', () => {
        const np = parseInt(pipeSel.value, 10) || getFlowPipelineId();
        stageSel.innerHTML = stageOptionsForPipeline(np, stageSel.value);
        apply();
      });
    }
    const needsAgent = ['send_whatsapp', 'invoke_agent', 'assign_agent', 'pause_ai', 'resume_ai'].includes(t);
    if (t === 'send_whatsapp' && document.getElementById('picker-action-connection')) {
      const defaultConn = d.connection_id || (B.whatsappConnections && B.whatsappConnections[0] ? B.whatsappConnections[0].id : 0);
      actionConnectionPicker = mountConnectionPickerEl('picker-action-connection', defaultConn, () => apply(), false);
    }
    if (needsAgent && document.getElementById('picker-action-agent')) {
      const mode = ['pause_ai', 'resume_ai'].includes(t) ? 'lead' : 'assign';
      actionAgentPicker = mountAgentPickerEl('picker-action-agent', d.agent_id || 0, () => apply(), mode);
    }
    wrap.querySelectorAll('input, select, textarea').forEach((el) => {
      el.addEventListener('change', apply);
      el.addEventListener('input', apply);
    });
  }

  function syncActionFieldsToData(data) {
    const t = data.action_type;
    if (actionConnectionPicker) data.connection_id = parseInt(actionConnectionPicker.getValue(), 10) || 0;
    if (actionAgentPicker) data.agent_id = parseInt(actionAgentPicker.getValue(), 10) || 0;
    const ag = document.querySelector('.p-f-agent');
    if (ag && !actionAgentPicker) data.agent_id = parseInt(ag.value, 10);
    const sw = document.querySelector('.p-f-switch');
    if (sw) data.switch_agent = sw.checked;
    const msg = document.querySelector('.p-f-msg');
    if (msg) data.message = msg.value;
    const st = document.querySelector('.p-f-stage');
    if (st) data.stage = st.value;
    const pipe = document.querySelector('.p-f-pipeline');
    if (pipe) data.pipeline_id = parseInt(pipe.value, 10) || 0;
    const tag = document.querySelector('.p-f-tag');
    if (tag) data.tag = tag.value.trim();
    const mins = document.querySelector('.p-f-mins');
    if (mins) data.minutes = parseInt(mins.value, 10) || 60;
    const wh = document.querySelector('.p-f-wh');
    if (wh) data.webhook_id = parseInt(wh.value, 10);
    const pr = document.querySelector('.p-f-preset');
    if (pr) data.preset_id = parseInt(pr.value, 10);
    const key = document.querySelector('.p-f-key');
    if (key) data.key = key.value.trim();
    const val = document.querySelector('.p-f-val');
    if (val) data.value = val.value.trim();
    const mission = document.querySelector('.p-f-mission');
    if (mission) data.mission = mission.value.trim();
    data.label = ACTION_LABELS[t] || t;
  }

  async function loadFlowList() {
    const d = await apiRequest(API + '?action=crm_list_flows');
    const el = document.getElementById('flow-list');
    if (!el) return;
    const filterPid = getFlowPipelineId();
    let flows = d.flows || [];
    if (filterPid > 0) {
      flows = flows.filter((f) => !f.pipeline_id || parseInt(f.pipeline_id, 10) === filterPid);
    }
    if (!flows.length) {
      el.innerHTML = '<p class="text-muted" style="padding:12px;font-size:.8rem">Nenhum fluxo neste pipeline. Crie ou troque o funil acima.</p>';
      return;
    }
    el.innerHTML = flows
      .map((f) => {
        const active = f.id == currentFlowId ? ' active' : '';
        const badge = f.is_active == 1 ? '<span class="flow-badge on">Ativo</span>' : '<span class="flow-badge off">Rascunho</span>';
        const pipe = f.pipeline_name ? `<span class="flow-badge pipe">${esc(f.pipeline_name)}</span>` : '';
        return `<button type="button" class="flow-list-item${active}" data-id="${f.id}">
          <strong>${esc(f.name)}</strong>
          <div class="flow-list-meta">Entraram ${f.stats_entered || 0} · OK ${f.stats_success || 0} ${pipe}</div>
          ${badge}
        </button>`;
      })
      .join('');
    el.querySelectorAll('.flow-list-item').forEach((btn) => {
      btn.addEventListener('click', () => loadFlow(parseInt(btn.dataset.id, 10)));
    });
  }

  let loadFlowSeq = 0;

  async function loadFlow(id) {
    if (!id) return;
    const seq = ++loadFlowSeq;
    const d = await apiRequest(API + '?action=crm_get_flow&id=' + id);
    if (seq !== loadFlowSeq) return;
    if (d.error || !d.flow) {
      window.toast?.(d.message || 'Erro ao carregar', 'error');
      return;
    }
    currentFlowId = id;
    await loadFlowNodeStats(id);
    if (seq !== loadFlowSeq) return;
    await loadFlowNodeErrors(id);
    if (seq !== loadFlowSeq) return;
    const f = d.flow;
    document.getElementById('flow-name').value = f.name || '';
    document.getElementById('flow-active').checked = f.is_active == 1;
    updateFlowPublishHint();
    const pipeSel = document.getElementById('flow-pipeline');
    if (pipeSel && f.pipeline_id) {
      pipeSel.value = String(f.pipeline_id);
      syncFlowPipelineToBoot();
    }
    let exported = {};
    try {
      exported = JSON.parse(f.flow_data || '{}');
    } catch (e) {
      exported = defaultFlowExport();
    }
    if (!exported.drawflow) exported = defaultFlowExport();
    if (seq !== loadFlowSeq) return;
    editor.clear();
    editor.import(exported);
    Object.keys(editor.drawflow.drawflow.Home.data).forEach((nid) => refreshNodeVisual(nid));
    selectedNodeId = null;
    renderPropsPanel(null);
    loadFlowList();
    updateFlowRoutingSummary();
  }

  function syncOpenPropsToEditor() {
    const body = document.getElementById('flow-props-body');
    if (!body || !selectedNodeId) return;
    body.querySelectorAll('input, select, textarea').forEach((el) => {
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  function updateFlowPublishHint() {
    const active = document.getElementById('flow-active');
    const hint = document.getElementById('flow-publish-hint');
    if (!hint || !active) return;
    if (active.checked) {
      hint.textContent = 'Publicado — dispara no WhatsApp';
      hint.hidden = false;
      hint.style.color = '#047857';
      hint.style.background = '#ecfdf5';
      hint.style.borderColor = '#6ee7b7';
    } else {
      hint.textContent = 'Rascunho — salve e publique quando estiver pronto';
      hint.hidden = false;
      hint.style.color = '#b45309';
      hint.style.background = '#fffbeb';
      hint.style.borderColor = '#fcd34d';
    }
  }

  async function saveCurrentFlow() {
    if (typeof window.saveCurrentFlowDraft === 'function') {
      return window.saveCurrentFlowDraft(false);
    }
  }

  const TEMPLATE_STAGE_ORDER = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'closed', 'lost', 'won'];
  const STAGE_SLUG_ALIASES = { won: 'closed', close: 'closed', fechado: 'closed', ganho: 'closed' };

  function pipelineStageSlugs(pid) {
    const ordered = B.stagesOrderedByPipeline && B.stagesOrderedByPipeline[pid];
    if (ordered && ordered.length) return ordered;
    return Object.keys(flowStages());
  }

  function buildStageRemap(pipelineSlugs) {
    const map = {};
    TEMPLATE_STAGE_ORDER.forEach((tpl, i) => {
      if (pipelineSlugs.includes(tpl)) {
        map[tpl] = tpl;
        return;
      }
      const ali = STAGE_SLUG_ALIASES[tpl];
      if (ali && pipelineSlugs.includes(ali)) {
        map[tpl] = ali;
        return;
      }
      map[tpl] = pipelineSlugs[i] || pipelineSlugs[pipelineSlugs.length - 1] || tpl;
    });
    pipelineSlugs.forEach((s) => {
      if (!map[s]) map[s] = s;
    });
    return map;
  }

  function remapStageSlug(slug, remap, pipelineSlugs) {
    if (!slug) return slug;
    if (remap[slug]) return remap[slug];
    if (pipelineSlugs.includes(slug)) return slug;
    return pipelineSlugs[0] || slug;
  }

  function remapFlowExportStages(exp, flowPipelineId) {
    const nodes = exp?.drawflow?.drawflow?.Home?.data;
    if (!nodes || typeof nodes !== 'object') return { export: exp, changed: false };
    let changed = false;
    Object.keys(nodes).forEach((nid) => {
      const node = nodes[nid];
      if (!node || !node.data) return;
      const d = node.data;
      const name = node.name || '';
      let targetPid = flowPipelineId;
      if (name === 'flow_condition' || (name === 'flow_action' && d.action_type === 'move_stage')) {
        targetPid = parseInt(d.pipeline_id, 10) || flowPipelineId;
      }
      const slugs = pipelineStageSlugs(targetPid);
      const remap = buildStageRemap(slugs);

      if (name === 'flow_trigger' && d.trigger_type === 'stage_enter' && d.trigger_value) {
        const flowSlugs = pipelineStageSlugs(flowPipelineId);
        const flowRemap = buildStageRemap(flowSlugs);
        const flowLabels = stagesForPipeline(flowPipelineId);
        const next = remapStageSlug(String(d.trigger_value), flowRemap, flowSlugs);
        if (next !== d.trigger_value) {
          d.trigger_value = next;
          d._preview = 'Estágio <strong>' + esc(flowLabels[next] || next) + '</strong>';
          changed = true;
        }
      }
      if (name === 'flow_condition') {
        if (d.stage_is) {
          const n = remapStageSlug(String(d.stage_is), remap, slugs);
          if (n !== d.stage_is) { d.stage_is = n; changed = true; }
        }
        if (d.stage_not) {
          const n = remapStageSlug(String(d.stage_not), remap, slugs);
          if (n !== d.stage_not) { d.stage_not = n; changed = true; }
        }
      }
      if (name === 'flow_action' && d.action_type === 'move_stage' && d.stage) {
        const n = remapStageSlug(String(d.stage), remap, slugs);
        if (n !== d.stage) { d.stage = n; changed = true; }
      }
    });
    return { export: exp, changed };
  }

  function applyTemplate(tpl) {
    closeTemplateModal();
    const built = tpl.build(B.agents || [], B.whatsappConnections || []);
    currentFlowId = 0;
    document.getElementById('flow-name').value = built.name || tpl.name;
    document.getElementById('flow-active').checked = tpl.id !== 'blank';
    syncFlowPipelineToBoot();
    editor.clear();
    let exp = built.export || defaultFlowExport();
    const remapped = remapFlowExportStages(exp, getFlowPipelineId());
    exp = remapped.export;
    editor.import(exp);
    Object.keys(editor.drawflow.drawflow.Home.data).forEach((nid) => refreshNodeVisual(nid));
    renderPropsPanel(null);
    loadFlowList();
    updateFlowRoutingSummary();
    if (typeof window.syncSimFromEditor === 'function') window.syncSimFromEditor();
    if (remapped.changed && typeof window.toast === 'function') {
      window.toast('Template aplicado. Estágios ajustados ao seu funil.', 'info');
    } else if (typeof window.toast === 'function') {
      window.toast('Template «' + (tpl.name || '') + '» aplicado — teste na aba Testar.', 'success');
    }
  }

  function openTemplateModal() {
    const modal = document.getElementById('flow-template-modal');
    const grid = document.getElementById('flow-template-grid');
    if (!modal || !grid) return newFlowBlank();
    const templates = window.AUVVO_FLOW_TEMPLATES || [];
    const sectorOrder = window.AUVVO_FLOW_TEMPLATE_SECTORS || [];
    const agentCount = (B.agents || []).length;
    const packHint =
      agentCount < 2
        ? `<div class="flow-tpl-hint"><i class="ph-bold ph-package"></i> Para testar <strong>vários agentes</strong> de uma vez, use <button type="button" class="flow-tpl-hint-link" id="flow-tpl-goto-pack">Pacote completo</button> na barra lateral.</div>`
        : '';
    const sectors = sectorOrder.length
      ? sectorOrder.filter((s) => templates.some((t) => t.sector === s))
      : [...new Set(templates.map((t) => t.sector))];
    grid.innerHTML = packHint + sectors
      .map((sec) => {
        const items = templates.filter((t) => t.sector === sec);
        return `<div class="flow-tpl-sector"><h4>${esc(sec)}</h4><div class="flow-tpl-cards">${items
          .map(
            (t) => `<button type="button" class="flow-tpl-card${t.featured ? ' flow-tpl-card--featured' : ''}" data-tpl="${esc(t.id)}">
              <span class="flow-tpl-icon" style="background:${t.color}18;color:${t.color}"><i class="ph-bold ${t.icon}"></i></span>
              <strong>${esc(t.name)}</strong>
              <span>${esc(t.description)}</span>
            </button>`
          )
          .join('')}</div></div>`;
      })
      .join('');
    grid.querySelectorAll('.flow-tpl-card').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-tpl');
        const tpl = templates.find((x) => x.id === id);
        if (tpl) applyTemplate(tpl);
      });
    });
    document.getElementById('flow-tpl-goto-pack')?.addEventListener('click', () => {
      closeTemplateModal();
      document.getElementById('btn-pack-templates')?.click();
    });
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeTemplateModal() {
    const modal = document.getElementById('flow-template-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  }

  function applyFlowRecipe(recipeId) {
    const recipes = window.AUVVO_FLOW_RECIPES || [];
    const order = window.AUVVO_FLOW_RECIPE_ORDER || recipes.map((r) => r.id);
    const recipe = recipes.find((r) => r.id === recipeId);
    if (!recipe || !editor || typeof recipe.build !== 'function') return false;
    closeJourneyModal();
    const built = recipe.build(B.agents || [], B.whatsappConnections || []);
    currentFlowId = 0;
    document.getElementById('flow-name').value = built.name || recipe.name;
    document.getElementById('flow-active').checked = false;
    syncFlowPipelineToBoot();
    editor.clear();
    let exp = built.export || defaultFlowExport();
    const remapped = remapFlowExportStages(exp, getFlowPipelineId());
    exp = remapped.export;
    editor.import(exp);
    Object.keys(editor.drawflow.drawflow.Home.data || {}).forEach((nid) => refreshNodeVisual(nid));
    renderPropsPanel(null);
    loadFlowList();
    updateFlowRoutingSummary();
    if (typeof window.syncSimFromEditor === 'function') window.syncSimFromEditor();
    window.toast?.('Jornada «' + (recipe.name || '') + '» criada — ajuste textos e publique.', 'success');
    return true;
  }

  function renderJourneyGrid() {
    const grid = document.getElementById('flow-journey-grid');
    if (!grid) return;
    const recipes = window.AUVVO_FLOW_RECIPES || [];
    const order = window.AUVVO_FLOW_RECIPE_ORDER || recipes.map((r) => r.id);
    grid.innerHTML = order
      .map((id) => recipes.find((r) => r.id === id))
      .filter(Boolean)
      .map(
        (r) => `<button type="button" class="flow-journey-card${r.featured ? ' flow-journey-card--featured' : ''}" data-recipe="${esc(r.id)}">
          <span class="flow-journey-icon" style="background:${r.color}18;color:${r.color}"><i class="ph-bold ${r.icon}"></i></span>
          <strong>${esc(r.name)}</strong>
          <span class="flow-journey-desc">${esc(r.description)}</span>
          ${r.steps ? `<span class="flow-journey-steps">${r.steps.map((s) => esc(s)).join(' → ')}</span>` : ''}
        </button>`
      )
      .join('');
    grid.querySelectorAll('.flow-journey-card').forEach((btn) => {
      btn.addEventListener('click', () => {
        applyFlowRecipe(btn.getAttribute('data-recipe'));
      });
    });
  }

  function openJourneyModal() {
    const modal = document.getElementById('flow-wizard-modal');
    if (!modal) return applyFlowRecipe('atendimento_continuo');
    renderJourneyGrid();
    modal.removeAttribute('hidden');
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeJourneyModal() {
    const modal = document.getElementById('flow-wizard-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    modal.hidden = true;
  }

  async function newFlowBlank() {
    applyFlowRecipe('blank_trigger');
  }

  function newFlow() {
    openJourneyModal();
  }

  function addWhatsAppJourney() {
    openJourneyModal();
  }

  async function deleteCurrentFlow() {
    if (!currentFlowId) return;
    if (!confirm('Excluir este fluxo permanentemente?')) return;
    const fd = new FormData();
    fd.append('csrf_token', getCsrf());
    fd.append('action', 'crm_delete_flow');
    fd.append('id', currentFlowId);
    await apiRequest(API, { method: 'POST', body: fd });
    currentFlowId = 0;
    await newFlowBlank();
    loadFlowList();
  }

  async function loadAuxData() {
    try {
      const [inb, out, http] = await Promise.all([
        apiRequest(API + '?action=inbound_webhook_list'),
        apiRequest(API + '?action=outbound_webhook_list'),
        apiRequest(API + '?action=http_preset_list'),
      ]);
      inboundWebhooks = inb.webhooks || [];
      outboundWebhooks = out.webhooks || [];
      httpPresets = http.presets || [];
    } catch (e) {}
  }

  function bindUi() {
    document.getElementById('btn-new-flow')?.addEventListener('click', newFlow);
    document.getElementById('flow-template-close')?.addEventListener('click', closeTemplateModal);
    document.querySelector('#flow-template-modal .flow-modal-backdrop')?.addEventListener('click', closeTemplateModal);
    document.getElementById('btn-flow-delete')?.addEventListener('click', deleteCurrentFlow);

    function closeAllPalettePopovers() {
      ['flow-palette-extra', 'flow-palette-crm', 'flow-palette-ia'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.hidden = true;
      });
      ['btn-palette-more', 'btn-palette-crm', 'btn-palette-ia'].forEach((id) => {
        const btn = document.getElementById(id);
        if (btn) {
          btn.classList.remove('active');
          btn.setAttribute('aria-expanded', 'false');
        }
      });
    }

    function togglePalettePopover(popoverId, btnId) {
      const pop = document.getElementById(popoverId);
      const btn = document.getElementById(btnId);
      if (!pop || !btn) return;
      const willOpen = pop.hidden;
      closeAllPalettePopovers();
      pop.hidden = !willOpen;
      btn.classList.toggle('active', willOpen);
      btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    }

    function bindPaletteBtn(btn) {
      btn.addEventListener('click', (e) => {
        if (btn.id === 'btn-palette-more') {
          e.stopPropagation();
          togglePalettePopover('flow-palette-extra', 'btn-palette-more');
          return;
        }
        if (btn.id === 'btn-palette-crm') {
          e.stopPropagation();
          togglePalettePopover('flow-palette-crm', 'btn-palette-crm');
          return;
        }
        if (btn.id === 'btn-palette-ia') {
          e.stopPropagation();
          togglePalettePopover('flow-palette-ia', 'btn-palette-ia');
          return;
        }
        const t = btn.getAttribute('data-add-node');
        if (!t) return;
        let preset = null;
        const presetRaw = btn.getAttribute('data-add-preset');
        if (presetRaw) {
          try {
            preset = JSON.parse(presetRaw);
          } catch (err) {}
        }
        const fromId = findBestConnectFrom();
        const fromNode = fromId ? editor.getNodeFromId(fromId) : null;
        const baseX = fromNode ? (fromNode.pos_x || 80) + 240 : 200 + Math.random() * 200;
        const baseY = fromNode ? fromNode.pos_y || 120 : 100 + Math.random() * 120;
        const id = addNode(t, baseX, baseY, preset);
        if (id) {
          if (fromId && t !== 'flow_trigger') autoConnectNewNode(fromId, id);
          editor.selectNode(id);
          updateFlowRoutingSummary();
        }
        closeAllPalettePopovers();
      });
    }

    document.querySelectorAll('[data-add-node], #btn-palette-more, #btn-palette-crm, #btn-palette-ia').forEach(bindPaletteBtn);
    document.addEventListener('click', (e) => {
      if (!e.target.closest('.flow-palette-wrap')) closeAllPalettePopovers();
    });
    document.getElementById('zoom-in')?.addEventListener('click', () => editor.zoom_in());
    document.getElementById('zoom-out')?.addEventListener('click', () => editor.zoom_out());
    document.getElementById('zoom-reset')?.addEventListener('click', () => editor.zoom_reset());
  }

  window.loadFlowList = loadFlowList;
  window.loadFlow = loadFlow;
  window.newFlowBlank = newFlowBlank;
  window.addWhatsAppJourney = addWhatsAppJourney;
  window.applyFlowRecipe = applyFlowRecipe;
  window.openJourneyModal = openJourneyModal;
  window.openTemplateModal = openTemplateModal;
  window.getCurrentFlowId = () => currentFlowId;
  window.getCurrentFlowExport = () => (editor ? editor.export() : null);

  /** Lê gatilho do nó Início no canvas (para simulador / playground). */
  window.extractFlowTriggerFromExport = function (exp) {
    const nodes = exp?.drawflow?.Home?.data || {};
    for (const node of Object.values(nodes)) {
      const cls = node.class || node.name || '';
      if (cls !== 'flow_trigger') continue;
      const d = node.data || {};
      const tt = d.trigger_type || 'whatsapp_first';
      let tv = String(d.trigger_value ?? '*').trim() || '*';
      if (tt === 'ltv_inactive') tv = 'default';
      return { trigger_type: tt, trigger_value: tv };
    }
    return null;
  };

  window.getFlowPipelineId = getFlowPipelineId;
  window.syncOpenPropsToEditor = syncOpenPropsToEditor;
  window.updateFlowPublishHint = updateFlowPublishHint;

  window.initAutomacoesFlow = async function () {
    initEditor();
    initFlowPipelineSelect();
    bindUi();
    await loadAuxData();
    await newFlowBlank();
    const d = await apiRequest(API + '?action=crm_list_flows');
    if (d.flows && d.flows.length) {
      await loadFlow(d.flows[0].id);
    }
  };

  // Expõe para automacoes-packs.js (remapeamento de estágios em pacotes completos)
  window.remapFlowExportStages = remapFlowExportStages;
  window.pipelineStageSlugs = pipelineStageSlugs;

  // Navegação de abas — implementação em automacoes.php (setAutomacoesTab)
})();

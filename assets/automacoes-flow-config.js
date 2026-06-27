/**
 * automacoes-flow-config.js
 * Dados estaticos compartilhados do editor de fluxo (triggers, acoes, icones).
 * Carregar ANTES de automacoes-flow.js
 */
(function () {
  const B = window.FLOW_BOOT || {};

  window.FlowConfig = {
    TRIGGER_LABELS: {
      whatsapp_first: 'Primeira mensagem no WhatsApp',
      whatsapp_message: 'Cada mensagem no WhatsApp',
      stage_enter: 'Lead entra no estágio',
      tag_added: 'Tag adicionada',
      contact_created: 'Lead criado no CRM',
      webhook_received: 'Webhook / integração',
      ltv_inactive: 'Cliente inativo (LTV)',
    },

    TRIGGER_OPTIONS: [
      {
        group: 'WhatsApp (mais usado)',
        items: [
          {
            value: 'whatsapp_first',
            icon: 'ph-whatsapp-logo',
            color: '#059669',
            label: 'Primeira mensagem no WhatsApp',
            hint: 'Recomendado — lead novo escreve pela 1ª vez',
          },
          {
            value: 'whatsapp_message',
            icon: 'ph-chats-circle',
            color: '#0d9488',
            label: 'Cada mensagem recebida',
            hint: 'Dispara em toda mensagem — combine com Filtro',
          },
        ],
      },
      {
        group: 'CRM & funil',
        items: [
          { value: 'stage_enter', icon: 'ph-columns', color: '#4338ca', label: 'Entrada em estágio', hint: 'Kanban / pipeline' },
          { value: 'tag_added', icon: 'ph-tag', color: '#b45309', label: 'Tag adicionada', hint: 'Quando ganha uma tag' },
          { value: 'contact_created', icon: 'ph-user-plus', color: '#0369a1', label: 'Lead criado', hint: 'Manual, webhook ou WhatsApp' },
        ],
      },
      {
        group: 'Integrações & LTV',
        items: [
          { value: 'webhook_received', icon: 'ph-plugs-connected', color: '#7c3aed', label: 'Webhook de entrada', hint: 'Hotmart, formulário, etc.' },
          { value: 'ltv_inactive', icon: 'ph-chart-line-down', color: '#ca8a04', label: 'LTV — cliente inativo', hint: 'Ciclo de compra' },
        ],
      },
    ],

    ACTION_LABELS: {
      send_whatsapp: 'Enviar WhatsApp',
      assign_agent: 'Atribuir agente',
      move_stage: 'Mover estágio',
      add_tag: 'Adicionar tag',
      remove_tag: 'Remover tag',
      pause_ai: 'Pausar IA',
      resume_ai: 'Retomar IA',
      invoke_agent: 'Acionar outro agente',
      call_webhook: 'Webhook outbound',
      set_memory: 'Memória IA',
      google_sheets_append: 'Google Sheets',
      http_preset: 'HTTP preset',
      brain_mission: 'Missão para o cérebro',
      clear_brain_mission: 'Limpar missão IA',
    },

    MESSAGE_VARS: [
      { key: 'nome', label: 'Nome' },
      { key: 'telefone', label: 'Telefone' },
      { key: 'email', label: 'E-mail' },
      { key: 'empresa', label: 'Empresa' },
      { key: 'estagio', label: 'Estágio' },
      { key: 'agente', label: 'Agente' },
      { key: 'mensagem', label: 'Msg gatilho' },
      { key: 'mensagens_hoje', label: 'Msgs hoje' },
      { key: 'sessao', label: 'Sessão (8)' },
      { key: 'ultima_sessao', label: 'Última sessão' },
      { key: 'tags', label: 'Tags' },
      { key: 'memoria.origem', label: 'Memória' },
    ],

    ACTION_ICON: {
      send_whatsapp: { icon: 'ph-whatsapp-logo', color: '#059669' },
      assign_agent: { icon: 'ph-user-switch', color: '#0369a1' },
      invoke_agent: { icon: 'ph-robot', color: '#7c3aed' },
      move_stage: { icon: 'ph-columns', color: '#4338ca' },
      add_tag: { icon: 'ph-tag', color: '#b45309' },
      remove_tag: { icon: 'ph-tag-simple', color: '#94a3b8' },
      pause_ai: { icon: 'ph-pause-circle', color: '#ca8a04' },
      resume_ai: { icon: 'ph-play-circle', color: '#047857' },
      call_webhook: { icon: 'ph-plugs-connected', color: '#7c3aed' },
      set_memory: { icon: 'ph-brain', color: '#6d28d9' },
      google_sheets_append: { icon: 'ph-table', color: '#15803d' },
      http_preset: { icon: 'ph-globe', color: '#0f766e' },
      brain_mission: { icon: 'ph-brain', color: '#6d28d9' },
      clear_brain_mission: { icon: 'ph-check-circle', color: '#6d28d9' },
    },

    get ACTION_OPTIONS() {
      return Object.keys(this.ACTION_LABELS).map((k) => {
        const meta = this.ACTION_ICON[k] || { icon: 'ph-lightning', color: '#047857' };
        return { value: k, label: this.ACTION_LABELS[k] || k, icon: meta.icon, color: meta.color, hint: k === 'send_whatsapp' ? 'Texto + variáveis' : '' };
      });
    },

    get ACTION_OPTIONS_GROUPED() {
      const opts = this.ACTION_OPTIONS;
      return [
        { group: 'WhatsApp & agentes', items: opts.filter((o) => ['send_whatsapp', 'assign_agent', 'invoke_agent', 'pause_ai', 'resume_ai'].includes(o.value)) },
        { group: 'CRM & cérebro', items: opts.filter((o) => ['move_stage', 'add_tag', 'remove_tag', 'set_memory', 'brain_mission', 'clear_brain_mission'].includes(o.value)) },
        { group: 'Integrações', items: opts.filter((o) => ['call_webhook', 'http_preset', 'google_sheets_append'].includes(o.value)) },
      ];
    },
  };
})();

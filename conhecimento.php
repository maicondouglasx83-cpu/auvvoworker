<?php
// conhecimento.php
require_once 'includes/auth.php';
require_once 'backend/db.php';

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT a.id, a.name, a.role, a.status, (SELECT COUNT(*) FROM knowledge_base kb WHERE kb.agent_id = a.id) as file_count FROM agents a WHERE a.user_id = ? ORDER BY a.id ASC");
$stmt->execute([$user_id]);
$agents = $stmt->fetchAll();

$selected_agent_id = intval($_GET['agent_id'] ?? ($agents[0]['id'] ?? 0));
$selected_agent    = null;
$knowledge_files   = [];

if ($selected_agent_id) {
    foreach ($agents as $a) {
        if ($a['id'] == $selected_agent_id) {
            $selected_agent = $a;
            break;
        }
    }
    if ($selected_agent) {
        // Sem `content` — evita carregar MBs de texto só para listar arquivos
        $stmt = $pdo->prepare(
            "SELECT id, agent_id, file_name, file_type, status, created_at,
                    original_name, LENGTH(content) AS content_length,
                    (content IS NOT NULL AND content != '') AS has_content
             FROM knowledge_base WHERE agent_id = ? ORDER BY id DESC"
        );
        $stmt->execute([$selected_agent_id]);
        $knowledge_files = $stmt->fetchAll();
    }
}

$success_msgs = [
    'uploaded' => t('know_ok_uploaded'),
    'text'     => t('know_ok_text'),
    'deleted'  => t('know_ok_deleted'),
];
$error_msgs = [
    'upload_error' => t('know_err_upload'),
    'too_large'    => t('know_err_large'),
    'invalid_type' => t('know_err_type'),
    'move_failed'  => t('know_err_move'),
];
?>
<!DOCTYPE html>
<html lang="<?= lang_html() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('know_title') ?></title>
    <link rel="stylesheet" href="app.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <link rel="icon" type="image/png" href="icone.png">
</head>
<body>

    <div class="app-container">
        <?php include 'includes/sidebar.php'; ?>

        <main class="app-main" style="padding: 24px; display: flex; flex-direction: column;">
            
            <div class="page-header" style="margin-bottom: 24px;">
                <div>
                    <h1 class="page-title"><?= t('know_page_title') ?></h1>
                    <p class="text-muted"><?= t('know_page_sub') ?></p>
                </div>
            </div>

            <?php if (isset($_GET['success']) && isset($success_msgs[$_GET['success']])): ?>
            <div style="background: var(--surface-success); color: var(--text-success); padding: 12px 24px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 8px;">
                <?php echo $success_msgs[$_GET['success']]; ?>
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['error']) && isset($error_msgs[$_GET['error']])): ?>
            <div style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #EF4444; padding: 12px 24px; border-radius: var(--radius-md); margin-bottom: 24px;">
                <?php echo $error_msgs[$_GET['error']]; ?>
            </div>
            <?php endif; ?>

            <?php if (count($agents) == 0): ?>
            <div class="app-card" style="text-align: center; padding: 48px;">
                <i class="ph-bold ph-robot" style="font-size: 3rem; color: var(--text-muted); display: block; margin-bottom: 16px;"></i>
                <h3><?= t('agents_page_title') ?></h3>
                <p class="text-muted" style="margin: 8px 0 24px;"><?= t('dash_no_agent_desc') ?></p>
                <a href="agentes" class="btn btn-primary"><?= t('dash_no_agent_btn') ?></a>
            </div>
            <?php else: ?>

            <div style="display: grid; grid-template-columns: 280px 1fr; gap: 24px; flex: 1; min-height: 0;">
                
                <!-- Lista de Agentes -->
                <div class="app-card" style="padding: 0; overflow-y: auto; background: #FAFAFA;">
                    <div style="padding: 16px; border-bottom: 1px solid var(--border-subtle);">
                        <strong style="font-size: 0.9375rem;"><?= t('know_select_agent') ?></strong>
                    </div>

                    <div style="display: flex; flex-direction: column;">
                        <?php foreach($agents as $agent): 
                            $is_selected = ($agent['id'] == $selected_agent_id);
                        ?>
                            <a href="conhecimento?agent_id=<?php echo $agent['id']; ?>" style="text-decoration: none; color: inherit;">
                                <div style="padding: 16px; border-bottom: 1px solid var(--border-subtle); cursor: pointer; transition: background 0.2s; background: <?php echo $is_selected ? '#FFF' : 'transparent'; ?>; <?php echo $is_selected ? 'border-left: 4px solid var(--accent-teal);' : ''; ?>">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div class="chat-avatar" style="width: 32px; height: 32px; font-size: 1rem; background: rgba(158, 220, 217, 0.2); color: #4DB6AC;"><i class="ph-fill ph-robot"></i></div>
                                        <div>
                                            <strong style="display: block; font-size: 0.875rem;"><?php echo htmlspecialchars($agent['name']); ?> <span style="font-weight:400; color: var(--text-muted);">(<?php echo htmlspecialchars($agent['role']); ?>)</span></strong>
                                            <span class="text-muted" style="font-size: 0.75rem;"><?php echo $agent['file_count']; ?> arquivo(s)</span>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Área de Upload -->
                <div style="display: flex; flex-direction: column; gap: 24px; overflow-y: auto;">
                    
                    <?php if ($selected_agent): ?>

                    <!-- Sub-abas -->
                    <div style="margin-bottom:20px">
                      <div class="tabs-nav" style="margin-bottom:0">
                        <button type="button" class="tab-btn active" onclick="switchKbTab(0)"><?= t('know_upload_title') ?> &amp; <?= t('know_files_title') ?></button>
                        <button type="button" class="tab-btn" onclick="switchKbTab(1)">Prompt Mestre</button>
                      </div>
                    </div>

                    <!-- TAB 0: UPLOAD -->
                    <div id="kb-tab-0">
                    <div class="app-card" style="padding: 32px;">
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px;">
                            <i class="ph-fill ph-robot text-success" style="font-size: 2rem;"></i>
                            <div>
                                <h3 style="font-size: 1.125rem; margin-bottom: 2px;"><?= htmlspecialchars($selected_agent['name']) ?></h3>
                                <p class="text-muted" style="font-size: 0.875rem;"><?= t('know_page_sub') ?></p>
                            </div>
                        </div>

                        <!-- Upload de Arquivo -->
                        <form action="backend/process_knowledge.php" method="POST" enctype="multipart/form-data">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="upload_file">
                            <input type="hidden" name="agent_id" value="<?php echo $selected_agent['id']; ?>">
                            
                            <div class="upload-area" id="drop-zone" onclick="document.getElementById('file-input').click();" style="cursor: pointer;">
                                <i class="ph-bold ph-cloud-arrow-up upload-icon"></i>
                                <div>
                                    <h3 style="font-size: 1.125rem; margin-bottom: 4px;"><?= t('know_upload_drag') ?></h3>
                                    <p class="text-muted" style="font-size: 0.875rem;"><?= t('know_upload_hint') ?></p>
                                    <p id="file-selected-name" style="margin-top: 8px; font-size: 0.875rem; color: var(--accent-teal); display: none;"></p>
                                </div>
                                <input type="file" id="file-input" name="knowledge_file" accept=".pdf,.txt,.csv,.docx" style="display: none;" onchange="showFileName(this)">
                                <button type="button" class="btn btn-outline" style="margin-top: 8px;" onclick="event.stopPropagation(); document.getElementById('file-input').click();"><?= t('know_upload_btn') ?></button>
                            </div>
                            
                            <button type="submit" class="btn btn-primary" style="margin-top: 16px; width: 100%;">
                                <i class="ph-bold ph-upload"></i> <?= t('know_send_btn') ?>
                            </button>
                        </form>

                        <div style="margin-top: 24px; text-align: center;">
                            <span class="text-muted" style="font-size: 0.875rem;">— ou —</span>
                        </div>

                        <!-- Texto Direto -->
                        <form action="backend/process_knowledge.php" method="POST" style="margin-top: 24px;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="save_text">
                            <input type="hidden" name="agent_id" value="<?php echo $selected_agent['id']; ?>">
                            <div class="form-group">
                                <label class="form-label"><?= t('know_text_label') ?></label>
                                <textarea name="text_content" class="form-control" rows="4" placeholder="<?= htmlspecialchars(t('know_text_ph')) ?>"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="ph-bold ph-brain"></i> <?= t('know_text_btn') ?>
                            </button>
                        </form>
                    </div>

                    <!-- Tabela de Arquivos Treinados -->
                    <div>
                        <h3 style="font-size: 1.125rem; margin-bottom: 16px;"><?= t('know_files_title') ?> (<?= htmlspecialchars($selected_agent['name']) ?>)</h3>
                        <div class="app-card" style="padding: 0;">
                            <div class="app-table-wrapper">
                                <table class="app-table">
                                    <thead>
                                        <tr>
                                            <th>Nome / Tipo</th>
                                            <th>Formato</th>
                                            <th>Status</th>
                                            <th>Data</th>
                                            <th>Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($knowledge_files) > 0): ?>
                                            <?php foreach($knowledge_files as $kf): ?>
                                            <?php
                                                $icons  = ['pdf'=>'ph-file-pdf','txt'=>'ph-file-text','csv'=>'ph-file-csv','docx'=>'ph-file-doc','text'=>'ph-text-aa'];
                                                $colors = ['pdf'=>'#EF4444','txt'=>'#6366F1','csv'=>'#10B981','docx'=>'#3B82F6','text'=>'#F59E0B'];
                                                $icon   = $icons[$kf['file_type']] ?? 'ph-file';
                                                $color  = $colors[$kf['file_type']] ?? '#6B7280';
                                                $display_name = ($kf['file_type'] === 'text') ? 'Texto Manual' : $kf['file_name'];
                                            ?>
                                            <tr>
                                                <td><i class="ph-fill <?php echo $icon; ?>" style="color: <?php echo $color; ?>; margin-right: 8px;"></i><?php echo htmlspecialchars($display_name); ?></td>
                                                <td><?php echo strtoupper($kf['file_type']); ?></td>
                                                <td>
                                                    <?php if ($kf['status'] == 'trained'): ?>
                                                        <span class="badge badge-success">Treinado</span>
                                                    <?php elseif ($kf['status'] == 'processing'): ?>
                                                        <span class="badge" style="background:#FEF3C7;color:#92400E;">Processando...</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-danger">Falhou</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('d/m/Y, H:i', strtotime($kf['created_at'])); ?></td>
                                                <td>
                                                    <form method="POST" action="backend/process_knowledge.php" onsubmit="return confirm('<?= addslashes(t('know_delete_confirm')) ?>');" style="display: inline;">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="action" value="delete_knowledge">
                                                        <input type="hidden" name="knowledge_id" value="<?php echo $kf['id']; ?>">
                                                        <input type="hidden" name="agent_id" value="<?php echo $selected_agent['id']; ?>">
                                                        <button type="submit" class="btn btn-icon text-danger"><i class="ph-bold ph-trash"></i></button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" style="text-align: center; padding: 32px; color: var(--text-muted);">
                                                    <i class="ph-bold ph-brain" style="font-size: 2rem; display: block; margin-bottom: 8px; opacity: 0.4;"></i>
                                                    <?= t('know_files_empty') ?>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    </div><!-- /kb-tab-0 -->

                    <!-- TAB 1: PROMPT MESTRE -->
                    <div id="kb-tab-1" style="display:none">
                      <div class="app-card" style="padding:32px">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px">
                          <div>
                            <h3 style="font-size:1.125rem">Prompt Mestre — <?=htmlspecialchars($selected_agent['name'])?></h3>
                            <p class="text-muted" style="font-size:.875rem">Prompt completo enviado à IA a cada mensagem.</p>
                          </div>
                          <div style="display:flex;gap:8px;flex-shrink:0">
                            <button class="btn btn-outline" style="padding:6px 14px;font-size:.8125rem" onclick="copyPrompt(event)"><i class="ph-bold ph-copy"></i> Copiar</button>
                            <button class="btn btn-primary" style="padding:6px 14px;font-size:.8125rem" onclick="loadPrompt()"><i class="ph-bold ph-arrows-clockwise"></i> Regenerar</button>
                          </div>
                        </div>

                        <div id="prompt-loading" style="text-align:center;padding:48px;display:none">
                          <i class="ph-bold ph-circle-notch" style="font-size:2rem;color:var(--text-muted);animation:spin 1s linear infinite"></i>
                          <p class="text-muted" style="margin-top:8px">Gerando prompt mestre...</p>
                        </div>

                        <div id="prompt-result" style="display:none">
                          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                            <span class="text-muted" style="font-size:.8125rem">Tokens: <strong id="token-count">—</strong> / 128k</span>
                            <div id="token-bar-wrap" style="width:200px;height:8px;background:#E5E7EB;border-radius:4px;overflow:hidden">
                              <div id="token-bar" style="height:100%;background:#10B981;width:0%;transition:width .5s"></div>
                            </div>
                          </div>
                          <textarea id="prompt-textarea" class="form-control" rows="20" readonly style="font-family:monospace;font-size:.8rem;line-height:1.6;background:#F9FAFB"></textarea>
                        </div>

                        <div id="prompt-empty" style="text-align:center;padding:48px;color:var(--text-muted)">
                          <i class="ph-bold ph-brain" style="font-size:3rem;opacity:.3;display:block;margin-bottom:12px"></i>
                          <p>Clique em <strong>Regenerar</strong> para gerar o prompt mestre.</p>
                        </div>
                      </div>

                      <div class="app-card" style="padding:0">
                        <div style="padding:16px 24px;border-bottom:1px solid var(--border-subtle)">
                          <strong>Status de Extração</strong>
                          <p class="text-muted" style="font-size:.8125rem">Arquivos com conteúdo extraído são incluídos automaticamente.</p>
                        </div>
                        <div class="app-table-wrapper">
                          <table class="app-table">
                            <thead><tr><th>Arquivo</th><th>Tipo</th><th>Conteúdo</th><th>Ação</th></tr></thead>
                            <tbody>
            <?php foreach($knowledge_files as $kf): 
              $has_content = !empty($kf['has_content']);
              $content_kb  = $has_content ? number_format((int)$kf['content_length'] / 1000, 1) : '0';
              $display = $kf['original_name'] ?? $kf['file_name'];
            ?>
            <tr>
              <td style="font-size:.875rem"><?=htmlspecialchars($display)?></td>
              <td><?=strtoupper($kf['file_type'])?></td>
              <td>
                <?php if($has_content): ?>
                  <span class="badge badge-success">Extraído (<?=$content_kb?>k chars)</span>
                <?php else: ?>
                                  <span class="badge badge-warning">Sem conteúdo</span>
                                <?php endif; ?>
                              </td>
                              <td>
                                <?php if(!$has_content): ?>
                                <button class="btn btn-outline" style="padding:4px 12px;font-size:.75rem" onclick="extractContent(<?=$kf['id']?>, this)"><i class="ph-bold ph-magic-wand"></i> Extrair</button>
                                <?php else: ?>
                                <button class="btn btn-outline" style="padding:4px 12px;font-size:.75rem" disabled><i class="ph-bold ph-check"></i> OK</button>
                                <?php endif; ?>
                              </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($knowledge_files)): ?>
                            <tr><td colspan="4" style="text-align:center;padding:24px;color:var(--text-muted)"><?= t('know_files_empty') ?></td></tr>
                            <?php endif; ?>
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </div><!-- /kb-tab-1 -->

                    <?php else: ?>
                    <div class="app-card" style="text-align: center; padding: 48px;">
                        <p class="text-muted"><?= t('know_select_agent') ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </main>
    </div>

    <script>
        const AGENT_ID = <?=intval($selected_agent_id ?? 0)?>;

        function switchKbTab(i) {
          ['kb-tab-0','kb-tab-1'].forEach((id,idx)=>{
            document.getElementById(id).style.display = idx===i?'block':'none';
          });
          document.querySelectorAll('.tab-btn').forEach((b,idx)=>b.classList.toggle('active',idx===i));
          if (i===1 && !document.getElementById('prompt-textarea').value) loadPrompt();
        }

        function loadPrompt() {
          if (!AGENT_ID) return;
          document.getElementById('prompt-loading').style.display='block';
          document.getElementById('prompt-result').style.display='none';
          document.getElementById('prompt-empty').style.display='none';
          fetch('backend/api.php?action=get_master_prompt&agent_id='+AGENT_ID)
            .then(r=>r.json()).then(d=>{
              document.getElementById('prompt-loading').style.display='none';
              if (d.error) { alert('Erro: '+d.message); document.getElementById('prompt-empty').style.display='block'; return; }
              document.getElementById('prompt-textarea').value = d.prompt;
              document.getElementById('token-count').textContent = d.tokens.toLocaleString();
              const pct = Math.min((d.tokens/128000)*100, 100);
              document.getElementById('token-bar').style.width = pct+'%';
              document.getElementById('token-bar').style.background = pct>80?'#EF4444':pct>50?'#F59E0B':'#10B981';
              document.getElementById('prompt-result').style.display='block';
            }).catch(()=>{
              document.getElementById('prompt-loading').style.display='none';
              document.getElementById('prompt-empty').style.display='block';
            });
        }

        function copyPrompt(e) {
          const ta = document.getElementById('prompt-textarea');
          if (!ta.value) return;
          navigator.clipboard.writeText(ta.value).then(()=>{
            const btn = e.currentTarget;
            btn.innerHTML='<i class="ph-bold ph-check"></i> Copiado!';
            setTimeout(()=>btn.innerHTML='<i class="ph-bold ph-copy"></i> Copiar', 2000);
          });
        }

        function extractContent(kbId, btn) {
          btn.disabled=true; btn.innerHTML='<i class="ph-bold ph-circle-notch"></i> Extraindo...';
          const fd = new FormData();
          fd.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');
          fd.append('action','extract_knowledge');
          fd.append('knowledge_id', kbId);
          fetch('backend/api.php', {method:'POST', body:fd})
            .then(r=>r.json()).then(d=>{
              if (d.error) { btn.innerHTML='❌ Falhou'; btn.disabled=false; alert(d.message); }
              else { btn.closest('tr').querySelector('td:nth-child(3)').innerHTML='<span class="badge badge-success">Extraído ('+Math.round(d.chars/1000)+'k chars)</span>'; btn.innerHTML='<i class="ph-bold ph-check"></i> OK'; }
            });
        }

        function showFileName(input) {
            const nameEl = document.getElementById('file-selected-name');
            if (input.files && input.files[0]) { nameEl.textContent = '📎 ' + input.files[0].name; nameEl.style.display = 'block'; }
        }
        const dropZone = document.getElementById('drop-zone');
        if (dropZone) {
            dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor = 'var(--accent-teal)'; });
            dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = ''; });
            dropZone.addEventListener('drop', e => { e.preventDefault(); dropZone.style.borderColor = ''; const fi = document.getElementById('file-input'); fi.files = e.dataTransfer.files; showFileName(fi); });
        }

        const style = document.createElement('style');
        style.textContent = '@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}';
        document.head.appendChild(style);
    </script>

</body>
</html>

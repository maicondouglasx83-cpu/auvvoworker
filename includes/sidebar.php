<!-- includes/sidebar.php -->
<?php
$user_name_full = $_SESSION['user_name'] ?? 'Usuário';
$name_parts = explode(' ', trim($user_name_full));
$initials = strtoupper(substr($name_parts[0], 0, 1));
if (count($name_parts) > 1) {
    $initials .= strtoupper(substr(end($name_parts), 0, 1));
}
$user_plan = $_SESSION['user_plan'] ?? t('plan_active');

$langs = ['pt_BR' => 'PT-BR', 'es' => 'ES', 'en' => 'EN'];
$cur   = current_lang();
$cur_label = $langs[$cur] ?? 'PT-BR';
?>

<!-- Cabeçalho móvel e overlay para drawer -->
<div class="mobile-navbar">
    <button class="mobile-menu-btn" onclick="toggleMobileSidebar()"><i class="ph-bold ph-list"></i></button>
    <img src="favicon.png" alt="Auvvo Logo" style="height: 32px;">
    <div style="width: 40px;"></div> <!-- Spacer invisível para equilíbrio visual -->
</div>
<div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleMobileSidebar()"></div>

<aside class="app-sidebar">

    <div class="sidebar-logo">
        <img src="favicon.png" alt="Auvvo Logo">
    </div>
    
    <nav class="sidebar-nav">
        <?php
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        $current_route = trim($path, '/');
        $current_route = $current_route === '' ? 'dashboard' : $current_route;
        $current_route = preg_replace('/\.php$/', '', $current_route);
        ?>
        
        <a href="dashboard" class="nav-item <?php echo ($current_route == 'dashboard') ? 'active' : ''; ?>"><i class="ph-bold ph-squares-four"></i> <?= t('nav_dashboard') ?></a>
        
        <a href="agentes" class="nav-item <?php echo ($current_route == 'agentes') ? 'active' : ''; ?>"><i class="ph-bold ph-robot"></i> <?= t('nav_agents') ?></a>
        
        <a href="conversas" class="nav-item <?php echo ($current_route == 'conversas') ? 'active' : ''; ?>"><i class="ph-bold ph-chats"></i> <?= t('nav_conversations') ?></a>
        
        <a href="conhecimento" class="nav-item <?php echo ($current_route == 'conhecimento') ? 'active' : ''; ?>"><i class="ph-bold ph-book-open"></i> Conhecimento</a>
        
        <a href="crm" class="nav-item <?php echo ($current_route == 'crm') ? 'active' : ''; ?>"><i class="ph-bold ph-address-book"></i> CRM</a>
        
        <div class="nav-section-label">Canais &amp; ferramentas</div>
        
        <a href="conexoes" class="nav-item <?php echo ($current_route == 'conexoes') ? 'active' : ''; ?>"><i class="ph-bold ph-whatsapp-logo"></i> Conexões</a>
        
        <a href="automacoes" class="nav-item <?php echo ($current_route == 'automacoes') ? 'active' : ''; ?>"><i class="ph-bold ph-lightning"></i> Automações</a>
        
        <a href="campanhas" class="nav-item <?php echo ($current_route == 'campanhas') ? 'active' : ''; ?>"><i class="ph-bold ph-megaphone"></i> <?= t('nav_campaigns') ?></a>
        
        <a href="webhooks" class="nav-item <?php echo ($current_route == 'webhooks') ? 'active' : ''; ?>"><i class="ph-bold ph-link"></i> Webhooks</a>
        
        <a href="integracoes" class="nav-item <?php echo ($current_route == 'integracoes') ? 'active' : ''; ?>"><i class="ph-bold ph-plugs-connected"></i> Integrações</a>
        
        <a href="configuracoes" class="nav-item <?php echo ($current_route == 'configuracoes') ? 'active' : ''; ?>"><i class="ph-bold ph-gear"></i> <?= t('nav_settings') ?></a>
    </nav>

    <!-- Rodapé: perfil + idioma -->
    <div class="sidebar-footer">

        <!-- Seletor de idioma dropdown -->
        <div class="lang-drop" id="lang-drop">
            <button class="lang-drop-trigger" onclick="toggleLangDrop(event)" title="<?= t('lang_switch_label') ?>">
                <i class="ph-bold ph-globe"></i>
                <span><?= $cur_label ?></span>
                <i class="ph-bold ph-caret-up" style="font-size:.65rem;margin-left:2px"></i>
            </button>
            <div class="lang-drop-menu" id="lang-drop-menu">
                <?php foreach ($langs as $code => $label):
                    $active = ($code === $cur);
                ?>
                <a href="<?= htmlspecialchars(lang_url($code)) ?>"
                   class="lang-drop-item <?= $active ? 'lang-drop-item-active' : '' ?>">
                    <?= $label ?>
                    <?php if ($active): ?><i class="ph-bold ph-check" style="margin-left:auto;font-size:.7rem"></i><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Perfil do usuário -->
        <div class="user-profile">
            <div class="user-avatar"><?php echo htmlspecialchars($initials); ?></div>
            <div class="user-info">
                <strong><?php echo htmlspecialchars($user_name_full); ?></strong>
                <span><?php echo htmlspecialchars($user_plan); ?></span>
            </div>
            <a href="backend/logout.php" title="<?= t('nav_logout') ?>" class="logout-btn">
                <i class="ph-bold ph-sign-out"></i>
            </a>
        </div>
    </div>
</aside>

<script>
(function () {
  const token = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_THROW_ON_ERROR) ?>;
  if (token && !document.querySelector('meta[name="csrf-token"]')) {
    const m = document.createElement('meta');
    m.name = 'csrf-token';
    m.content = token;
    document.head.appendChild(m);
  }
})();
</script>
<script src="assets/auvvo-api.js"></script>

<script>
function toggleMobileSidebar() {
    const sidebar = document.querySelector('.app-sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    sidebar.classList.toggle('active');
    overlay.classList.toggle('active');
}

function toggleLangDrop(e) {
    e.stopPropagation();
    const menu = document.getElementById('lang-drop-menu');
    const drop = document.getElementById('lang-drop');
    const open = menu.classList.toggle('lang-drop-open');
    drop.classList.toggle('lang-drop-active', open);
}
document.addEventListener('click', function() {
    document.getElementById('lang-drop-menu')?.classList.remove('lang-drop-open');
    document.getElementById('lang-drop')?.classList.remove('lang-drop-active');
});
</script>


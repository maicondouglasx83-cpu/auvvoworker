<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/marketing.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auvvo | Automação Simplificada</title>
    <meta name="description"
        content="agente de vendas com IA para WhatsApp. Planos a partir de R$ 87/mês ou R$ 397/ano. Atenda e venda 24/7.">
    <meta property="og:title" content="Auvvo | Automação Simplificada">
    <meta property="og:description"
        content="agente de vendas com IA para WhatsApp. Planos a partir de R$ 87/mês ou R$ 397/ano.">

    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars(mkt_base_url() . '/', ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image" content="<?= htmlspecialchars(mkt_og_image_url(), ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="<?= htmlspecialchars(mkt_og_image_url(), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/png" href="icone.png">

    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <?php mkt_render_tracking_head(); ?>
    <!-- Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <?php mkt_render_tracking_body_open(); ?>
    <!-- Background Video -->
    <div class="gif-background">
        <video autoplay muted loop playsinline poster="favicon.png" preload="metadata">
            <source src="202604302219.mp4" type="video/mp4">
        </video>
    </div>

    <!-- Header/Nav -->
    <div class="navbar-container container">
        <nav class="navbar" aria-label="Principal">
            <div class="logo">
                <a href="<?= htmlspecialchars(mkt_base_url() . '/', ENT_QUOTES, 'UTF-8') ?>" aria-label="Auvvo — início"><img src="favicon.png" width="120" height="auto"
                        alt="Auvvo"></a>
            </div>
            <div class="nav-links" id="primary-nav" role="navigation" aria-label="Menu principal">
                <a href="#casos-de-uso">Casos de Uso</a>
                <a href="#como-funciona">Como Funciona</a>
                <a href="#hub">Ecossistema</a>
                <a href="#prova-social">Resultados</a>
                <a href="#precos">Preços</a>
                <a href="#faq">FAQ</a>
                
                <!-- Botões premium para celular/tablet (escondidos no desktop) -->
                <div class="nav-mobile-buttons">
                    <a href="login" class="btn btn-glass mob-btn">Login</a>
                    <a href="checkout?plan=anual" class="btn btn-primary mob-btn">Começar Agora</a>
                </div>
            </div>

            <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Abrir menu" aria-expanded="false"
                aria-controls="primary-nav">
                <span class="nav-toggle-bar"></span>
                <span class="nav-toggle-bar"></span>
                <span class="nav-toggle-bar"></span>
            </button>
            <div class="nav-actions">
                <a href="login" class="btn btn-glass" style="padding: 10px 24px; font-size: 0.875rem;">Login</a>
                <a href="checkout?plan=anual" class="btn btn-primary"
                    style="padding: 10px 24px; font-size: 0.875rem;">Começar Agora</a>
            </div>
        </nav>
    </div>
    <div class="nav-backdrop" id="nav-backdrop" aria-hidden="true" hidden></div>

    <!-- Hero Section -->
    <section class="hero container">
        <div class="hero-grid">
            <div class="hero-content reveal">
                <div class="section-tag glow-tag"><i class="fa-solid fa-bolt" style="color: #FFD700;"></i> Vendas Automáticas</div>
                <h1 class="hero-title">Contrate o Melhor Vendedor da Sua Empresa por R$ 3/dia.</h1>
                <p class="hero-subtitle">Uma I.A. treinada com a sua operação que atende, qualifica e fecha vendas no WhatsApp 24 horas por dia. Sem salários, sem férias, sem desculpas.</p>
                <div class="hero-actions">
                    <a href="checkout?plan=anual" class="btn btn-primary hero-btn-main">
                        <i class="fa-solid fa-rocket"></i> Ligar o Piloto Automático
                    </a>
                    <a href="#como-funciona" class="btn btn-glass hero-btn-sec">
                        <i class="fa-solid fa-play"></i> Ver na Prática
                    </a>
                </div>
            </div>
            <div class="hero-visual reveal delay-200">
                <!-- Phone Frame (iPhone Mockup) -->
                <div class="chat-showcase">
                    <!-- Live Notification Popup -->
                    <div class="live-notification vision-pro-glass" id="live-notification">
                        <div class="live-notif-dot"></div>
                        <div class="live-notif-icon"><i class="fa-solid fa-arrow-trend-up" id="notif-icon"></i></div>
                        <div class="live-notif-content">
                            <span class="live-notif-title" id="notif-title">NOVO ATENDIMENTO</span>
                            <span class="live-notif-desc" id="notif-desc">Cliente aguardando</span>
                        </div>
                    </div>

                    <img src="icone.png" width="256" height="256" style="width:80%; margin-top: 50px; height:auto; filter: drop-shadow(0 0 40px rgba(158, 220, 217, 0.4));"
                        alt="Auvvo AI" decoding="async" fetchpriority="high">
                </div>
            </div>
        </div>
        <div class="stats-bar reveal delay-300">
            <div class="stat-item">
                <div class="stat-value">24/7</div>
                <div class="stat-label">Vendendo</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">100%</div>
                <div class="stat-label">Automático</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><i class="fa-solid fa-bolt"></i></div>
                <div class="stat-label">Resposta Imediata</div>
            </div>
        </div>
        <p class="hero-stat-note reveal delay-300">*A mais alta tecnologia de processamento natural do mercado.</p>
    </section>

    <!-- Video Section -->
    <section class="section container">
        <div class="section-header reveal">
            <div class="section-tag">A Máquina Trabalhando</div>
            <h2 class="section-title">Veja os Resultados Acontecendo</h2>
        </div>
        <div class="video-showcase reveal delay-200">
            <div class="video-frame">
                <button type="button" class="play-btn" id="video-play-trigger"
                    aria-label="Assistir demonstração em vídeo">
                    <i class="fa-solid fa-play" aria-hidden="true"></i>
                </button>
                <img src="painel-auvvo-demo.png"
                    alt="Captura do painel Auvvo — métricas e conversas" loading="lazy" decoding="async" width="1200" height="675">
            </div>
        </div>
    </section>

    <!-- Prova social -->
    <section id="prova-social" class="section container">
        <div class="section-header reveal">
            <div class="section-tag glow-tag">A Elite do WhatsApp</div>
            <h2 class="section-title">Empresas que já demitiram os problemas de atendimento</h2>
        </div>
        <div class="social-proof-stats reveal delay-100" style="margin-bottom: 30px;">
            <div class="sp-stat">
                <strong>+2.5M</strong>
                <span>Mensagens Processadas pela Auvvo</span>
            </div>
            <div class="sp-stat">
                <strong>0%</strong>
                <span>Leads perdidos por demora no atendimento</span>
            </div>
            <div class="sp-stat">
                <strong>100%</strong>
                <span>Foco da equipe humana no fechamento</span>
            </div>
        </div>
        
        <div class="cases-cta-card reveal delay-200" style="background: var(--surface-glass); backdrop-filter: blur(24px); border: 1px solid var(--border-glass); border-radius: var(--radius-xl); padding: 48px 32px; box-shadow: var(--shadow-soft), var(--inner-light); text-align: center;">
            <h3 class="section-title" style="font-size: 1.8rem; margin-bottom: 12px; color: var(--text-primary);">Você vai assistir seus concorrentes escalarem ou vai se juntar aos pioneiros?</h3>
            <p style="color: var(--text-secondary); line-height: 1.65; margin-bottom: 32px; font-size: 1.1rem; max-width: 600px; margin-left: auto; margin-right: auto; text-align: center;">
                A Auvvo não é apenas uma ferramenta, é a fundação de uma nova era de empresas que lucram 24 horas por dia enquanto a concorrência dorme.
            </p>
            <div style="display: flex; flex-wrap: wrap; gap: 16px; justify-content: center; align-items: center;">
                <a class="btn-plan btn-plan--solid pulse-glow-btn" href="checkout?plan=anual" style="width: auto; min-width: 260px; padding: 16px 32px; font-size: 1.1rem;">
                    <i class="fa-solid fa-rocket" style="margin-right: 8px;"></i> Quero Dominar Meu Nicho
                </a>
                <?php if (mkt_whatsapp_href() !== ''): ?>
                <a class="btn-plan btn-plan--ghost" href="<?= htmlspecialchars(mkt_whatsapp_href(), ENT_QUOTES, 'UTF-8') ?>"
                    target="_blank" rel="noopener noreferrer" style="width: auto; min-width: 260px; padding: 16px 32px; font-size: 1.1rem; border-color: rgba(0,0,0,0.12); color: var(--text-primary);"><i
                        class="fa-brands fa-whatsapp" style="margin-right: 8px; color: #25D366;"></i> Falar com o Time</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Use Cases Grid (Veja na Prática) -->
    <section id="casos-de-uso" class="section container">
        <div class="section-header reveal">
            <div class="section-tag glow-tag">Personalização Absoluta</div>
            <h2 class="section-title">A I.A. Moldada ao Seu Negócio</h2>
            <p class="section-subtitle" style="max-width: 600px; color: var(--text-secondary); margin: 0 auto;">Escolha seu nicho abaixo e veja como a Auvvo conduz a venda na vida real.</p>
        </div>
        
        <div class="interactive-showcase reveal delay-200">
            <div class="niche-tabs">
                <button class="niche-tab active" data-niche="clinica"><i class="fa-solid fa-stethoscope"></i> Clínicas</button>
                <button class="niche-tab" data-niche="ecommerce"><i class="fa-solid fa-cart-shopping"></i> E-commerce</button>
                <button class="niche-tab" data-niche="delivery"><i class="fa-solid fa-burger"></i> Delivery</button>
                <button class="niche-tab" data-niche="infoprodutos"><i class="fa-solid fa-graduation-cap"></i> Infoprodutos</button>
            </div>
            
            <div class="iphone-mockup-wrapper">
                <div class="iphone-mockup">
                    <div class="iphone-notch"></div>
                    <div class="iphone-header">
                        <div class="iphone-contact">
                            <div class="contact-avatar"><img src="favicon.png" width="32" alt="Avatar"></div>
                            <div class="contact-info">
                                <strong>Auvvo AI</strong>
                                <span>online</span>
                            </div>
                        </div>
                        <i class="fa-solid fa-ellipsis-vertical" style="color: #fff; font-size: 1.2rem; padding-right: 10px;"></i>
                    </div>
                    <div class="chat-screen" id="dynamic-chat-screen">
                        <!-- Conteúdo injetado via JS -->
                    </div>
                    <div class="iphone-footer">
                        <i class="fa-regular fa-face-smile"></i>
                        <div class="chat-input-fake">Mensagem</div>
                        <i class="fa-solid fa-microphone"></i>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const chatData = {
                clinica: [
                    { type: 'received', text: 'Olá, queria agendar uma consulta com o Dr. Marcos.' },
                    { type: 'sent', text: 'Olá! Tudo bem? Sou a assistente virtual da clínica. Temos horários com o Dr. Marcos amanhã às 14h ou 16h. Qual fica melhor para você?' },
                    { type: 'received', text: 'Pode ser às 14h.' },
                    { type: 'sent', text: 'Perfeito! Horário reservado para amanhã às 14h. ✅ Pode me confirmar seu CPF para concluir o agendamento?' }
                ],
                ecommerce: [
                    { type: 'received', text: 'Tem dessa blusa G na cor preta?' },
                    { type: 'sent', text: 'Temos sim! Ela é uma das mais vendidas. 😍 Aqui está o link com 10% de desconto que separei para você: [Link]' },
                    { type: 'received', text: 'Maravilha, vou comprar agora.' },
                    { type: 'sent', text: 'Fico feliz! Se precisar de ajuda na finalização do pedido, estou aqui 24 horas. 🛍️' }
                ],
                delivery: [
                    { type: 'received', text: 'Quero pedir uma pizza G de Calabresa.' },
                    { type: 'sent', text: 'Ótima escolha! 🍕 A G de Calabresa sai por R$ 59,90. Vai querer adicionar borda recheada por +R$ 10?' },
                    { type: 'received', text: 'Sim, borda de catupiry.' },
                    { type: 'sent', text: 'Anotado! Total: R$ 69,90. Qual será a forma de pagamento (Pix, Cartão, Dinheiro)?' }
                ],
                infoprodutos: [
                    { type: 'received', text: 'Fiz a compra do curso ontem, mas não recebi o acesso.' },
                    { type: 'sent', text: 'Poxa, vamos resolver isso agora mesmo! 🎓 O acesso geralmente vai para o e-mail cadastrado na Hotmart. Você já olhou na caixa de Spam?' },
                    { type: 'received', text: 'Achei! Estava no spam, obrigado.' },
                    { type: 'sent', text: 'Que ótimo! Bons estudos. Se surgir alguma dúvida sobre as aulas, nosso suporte estará aqui. 🚀' }
                ]
            };

            const chatScreen = document.getElementById('dynamic-chat-screen');
            const tabs = document.querySelectorAll('.niche-tab');

            function typeWriterEffect(container, messages) {
                container.innerHTML = '';
                let delay = 0;
                
                messages.forEach((msg, index) => {
                    const bubble = document.createElement('div');
                    bubble.className = `chat-bubble ${msg.type}`;
                    bubble.style.opacity = '0';
                    bubble.style.transform = 'translateY(10px)';
                    bubble.style.transition = 'opacity 0.3s, transform 0.3s';
                    bubble.textContent = msg.text;
                    container.appendChild(bubble);

                    setTimeout(() => {
                        bubble.style.opacity = '1';
                        bubble.style.transform = 'translateY(0)';
                        container.scrollTop = container.scrollHeight;
                    }, delay);

                    delay += 800; // Tempo entre mensagens
                });
            }

            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    tabs.forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    const niche = tab.getAttribute('data-niche');
                    typeWriterEffect(chatScreen, chatData[niche]);
                });
            });

            // Initial load
            if(chatScreen && tabs.length > 0) {
                typeWriterEffect(chatScreen, chatData['clinica']);
            }
        });
    </script>

    <!-- Você no Controle (Antes vs Depois) -->
    <section class="section container">
        <div class="section-header reveal">
            <div class="section-tag">A Verdade Sobre Vendas</div>
            <h2 class="section-title">Você no Controle do Seu Negócio</h2>
        </div>
        <div class="control-grid reveal">
            <div class="control-card before">
                <div class="control-header"><i class="fa-solid fa-xmark"></i> Antes da Auvvo</div>
                <ul class="control-list">
                    <li><i class="fa-regular fa-circle-xmark"></i> Pagar salários fixos e encargos altos</li>
                    <li><i class="fa-regular fa-circle-xmark"></i> Perder vendas de madrugada e finais de semana</li>
                    <li><i class="fa-regular fa-circle-xmark"></i> Clientes irritados com demora no atendimento</li>
                    <li><i class="fa-regular fa-circle-xmark"></i> Treinamento constante de novos atendentes</li>
                </ul>
            </div>
            <div class="control-card after">
                <div class="control-header"><i class="fa-solid fa-check"></i> Com a Auvvo</div>
                <ul class="control-list">
                    <li><i class="fa-regular fa-circle-check"></i> Vendas rodando 24 horas por dia, 7 dias por semana
                    </li>
                    <li><i class="fa-regular fa-circle-check"></i> Atendimento instantâneo para 1.000 pessoas ao mesmo
                        tempo</li>
                    <li><i class="fa-regular fa-circle-check"></i> Custos operacionais reduzidos a frações de centavos
                    </li>
                    <li><i class="fa-regular fa-circle-check"></i> Padrão de qualidade impecável e persuasivo nas
                        mensagens</li>
                </ul>
            </div>
        </div>
    </section>

    <!-- 4 Passos -->
    <section id="como-funciona" class="section container">
        <div class="section-header reveal">
            <div class="section-tag">Simplicidade Absoluta</div>
            <h2 class="section-title">O Seu agente Pronto em 4 Passos</h2>
        </div>
        <div class="steps-grid reveal">
            <div class="step-card">
                <div class="step-number">1</div>
                <h3>Crie sua Conta</h3>
                <p>Acesse o painel da Auvvo em menos de 1 minuto e assine seu plano.</p>
            </div>
            <div class="step-card">
                <div class="step-number">2</div>
                <h3>Conecte</h3>
                <p>Leia o QR Code com seu celular, exatamente como faz no WhatsApp Web.</p>
            </div>
            <div class="step-card">
                <div class="step-number">3</div>
                <h3>Treine a I.A.</h3>
                <p>Cole o texto com informações da sua empresa, seus produtos e preços.</p>
            </div>
            <div class="step-card">
                <div class="step-number">4</div>
                <h3>Ligue a Máquina</h3>
                <p>Ative o piloto automático e veja o agente atender e vender por você 24/7.</p>
            </div>
        </div>
    </section>


    <!-- Bento Features Grid -->
    <section id="hub" class="section container">
        <div class="section-header reveal">
            <div class="section-tag glow-tag" style="background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.3); color: #10B981; box-shadow: 0 0 20px rgba(16, 185, 129, 0.2);">Poder Extremo</div>
            <h2 class="section-title">O Arsenal Completo de Vendas</h2>
            <p class="section-subtitle">Tudo o que você precisa para esmagar a concorrência no WhatsApp.</p>
        </div>
        <div class="bento-grid reveal">
            <div class="bento-card bento-large tilt-effect">
                <div class="bento-icon"><i class="fa-solid fa-users-rays"></i></div>
                <h3>Atendimentos Infinitos Simultâneos</h3>
                <p>Enquanto seus concorrentes deixam o cliente esfriando 30 minutos na fila, sua Auvvo atende 1.000 pessoas simultaneamente, no mesmo segundo, com a mesma maestria.</p>
            </div>
            <div class="bento-card tilt-effect">
                <div class="bento-icon"><i class="fa-solid fa-microphone-lines"></i></div>
                <h3>Áudios Humanizados</h3>
                <p>Faça upload das suas vozes. A I.A. dispara áudios simulando que estão sendo "gravados na hora", destruindo objeções e disparando a conversão.</p>
            </div>
            <div class="bento-card tilt-effect">
                <div class="bento-icon"><i class="fa-solid fa-people-arrows"></i></div>
                <h3>Transbordo Inteligente</h3>
                <p>A I.A. faz o trabalho duro de qualificar e vender. Sua equipe humana só é chamada pela Auvvo quando o dinheiro já está na mesa.</p>
            </div>
            <div class="bento-card bento-large tilt-effect">
                <div class="bento-icon"><i class="fa-solid fa-calendar-check"></i></div>
                <h3>Agenda Lotada Automática</h3>
                <p>O agente conduz fluxos complexos, entende intenção, quebra objeções e finaliza agendamentos ou direcionamentos de compra sem que você precise mover um dedo. A operação não para.</p>
            </div>
        </div>
    </section>

    <!-- Invoice / Economia -->
    <section id="economia" class="section container">
        <div class="section-header reveal">
            <div class="section-tag">Retorno de Investimento</div>
            <h2 class="section-title">A Matemática do Lucro</h2>
        </div>

        <!-- ROI HTML Component -->
        <div class="roi-diagram reveal delay-100">
            <div class="roi-col left">
                <div class="roi-node">
                    <div class="roi-node-dot"></div>
                    <div class="roi-node-info">
                        <div class="roi-node-title">VPS</div>
                        <div class="roi-node-price">R$ 59/mês</div>
                    </div>
                </div>
                <div class="roi-node">
                    <div class="roi-node-dot"></div>
                    <div class="roi-node-info">
                        <div class="roi-node-title">Fasupanel</div>
                        <div class="roi-node-price">R$ 44/mês</div>
                    </div>
                </div>
                <div class="roi-node">
                    <div class="roi-node-dot"></div>
                    <div class="roi-node-info">
                        <div class="roi-node-title">N8N</div>
                        <div class="roi-node-price">R$ 120/mês</div>
                    </div>
                </div>
                <div class="roi-node">
                    <div class="roi-node-dot"></div>
                    <div class="roi-node-info">
                        <div class="roi-node-title">Supabase</div>
                        <div class="roi-node-price">R$ 140/mês</div>
                    </div>
                </div>
            </div>

            <div class="roi-center-col">
                <div class="roi-center-glow"></div>
                <div class="roi-center-node">
                    <img src="favicon.png" alt="Auvvo Logo">
                </div>
            </div>

            <div class="roi-col right">
                <div class="roi-node">
                    <div class="roi-node-dot"></div>
                    <div class="roi-node-info">
                        <div class="roi-node-title">Redis</div>
                        <div class="roi-node-price">R$ 49/mês</div>
                    </div>
                </div>
                <div class="roi-node">
                    <div class="roi-node-dot"></div>
                    <div class="roi-node-info">
                        <div class="roi-node-title">Vector Store</div>
                        <div class="roi-node-price">R$ 89/mês</div>
                    </div>
                </div>
                <div class="roi-node">
                    <div class="roi-node-dot"></div>
                    <div class="roi-node-info">
                        <div class="roi-node-title">Evolution API</div>
                        <div class="roi-node-price">R$ 79/mês</div>
                    </div>
                </div>
                <div class="roi-node">
                    <div class="roi-node-dot"></div>
                    <div class="roi-node-info">
                        <div class="roi-node-title">Postgres</div>
                        <div class="roi-node-price">R$ 49/mês</div>
                    </div>
                </div>
            </div>
        </div>

        <div id="precos" class="pricing-plans-wrap reveal delay-200">
            <div class="section-header">
                <div class="section-tag">Investimento</div>
                <h2 class="section-title">Planos claros, sem surpresas</h2>
                <p class="section-subtitle pricing-intro">Mesmo produto em todos: escolha mensal, trimestral ou anual.</p>
            </div>
            <div class="pricing-plans-grid">
                <div class="pricing-plan-card">
                    <h3 class="plan-card-title">Mensal</h3>
                    <p class="plan-card-tagline">Flexível, renovação automática</p>
                    <div class="plan-card-price">R$ 97<span>/mês</span></div>
                    <p class="plan-card-note">Cancele antes da próxima cobrança, sem burocracia.</p>
                    <ul class="plan-card-features">
                        <li><i class="fa-solid fa-check"></i> Painel completo, vários agentes e base de conhecimento
                        </li>
                        <li><i class="fa-solid fa-check"></i> WhatsApp (QR), campanhas e transbordo humano</li>
                        <li><i class="fa-solid fa-check"></i> Bom para validar antes de comprometer o plano</li>
                    </ul>
                    <div class="plan-card-cta">
                        <a href="checkout?plan=mensal" class="btn-plan btn-plan--outline">Assinar mensal — R$ 97</a>
                    </div>
                </div>
                <div class="pricing-plan-card">
                    <span class="plan-badge-pill">Popular</span>
                    <h3 class="plan-card-title">Trimestral</h3>
                    <p class="plan-card-tagline">Praticidade e economia</p>
                    <div class="plan-card-price">R$ 197<span>/trimestre</span></div>
                    <p class="plan-savings">≈ R$ 65,66/mês · acesso por 3 meses</p>
                    <p class="plan-card-note">Cobrança a cada três meses.</p>
                    <ul class="plan-card-features">
                        <li><i class="fa-solid fa-check"></i> Tudo do mensal, com desconto proporcional</li>
                        <li><i class="fa-solid fa-check"></i> Tempo ideal para ver a inteligência aprendendo</li>
                        <li><i class="fa-solid fa-check"></i> Sem fidelidade longa</li>
                    </ul>
                    <div class="plan-card-cta">
                        <a href="checkout?plan=trimestral" class="btn-plan btn-plan--outline">Assinar trimestral — R$ 197</a>
                    </div>
                </div>
                <div class="pricing-plan-card featured">
                    <span class="plan-badge-pill">Recomendado</span>
                    <h3 class="plan-card-title">Anual</h3>
                    <p class="plan-card-tagline">Melhor custo por mês</p>
                    <div class="plan-card-price">R$ 597<span>/ano</span></div>
                    <p class="plan-savings">≈ R$ 49,75/mês · economia frente a 12× R$ 97 (R$ 1.164,00 no total)</p>
                    <p class="plan-card-note">Uma cobrança no ano — previsível e sem mensalidade no cartão.</p>
                    <ul class="plan-card-features">
                        <li><i class="fa-solid fa-check"></i> Tudo do mensal, com preço de lançamento no anual</li>
                        <li><i class="fa-solid fa-check"></i> Menos de R$ 1,70/dia para operar o ano inteiro</li>
                        <li><i class="fa-solid fa-check"></i> Ideal para quem vai escalar no WhatsApp</li>
                    </ul>
                    <div class="plan-card-cta">
                        <a href="checkout?plan=anual" class="btn-plan btn-plan--solid">Assinar anual — R$ 597</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="invoice-wrapper reveal">
            <div class="invoice-card epic-duel">
                <div class="invoice-items past-burden">
                    <p class="invoice-col-label"><i class="fa-solid fa-hourglass-half"></i> O Modo Antigo</p>
                    <h3 class="invoice-col-title">O Ralo de Dinheiro</h3>
                    <p class="invoice-col-desc">Custos típicos que afundam o lucro da sua operação quando o atendimento é manual ou usa ferramentas remendadas.</p>
                    <div class="invoice-line">
                        <span class="invoice-label">1 Atendente (Salário + Encargos)</span>
                        <span class="invoice-price">R$ 2.500/mês</span>
                    </div>
                    <div class="invoice-line">
                        <span class="invoice-label">Ferramenta de chat com limitações</span>
                        <span class="invoice-price">R$ 497/mês</span>
                    </div>
                    <div class="invoice-line">
                        <span class="invoice-label">Vendas perdidas de madrugada</span>
                        <span class="invoice-price invoice-price--text danger-text">Incalculável</span>
                    </div>
                </div>
                
                <div class="invoice-total future-auvvo">
                    <div class="glow-bg"></div>
                    <p class="invoice-col-label invoice-col-label--accent"><i class="fa-solid fa-bolt"></i> O Jeito Auvvo</p>
                    <h3 class="invoice-col-title">Máquina Enxuta</h3>
                    <div class="invoice-pricing-lines">
                        <div class="invoice-price-line"><span class="invoice-price-amount">R$ 97</span> <span
                                class="invoice-price-period">/mês</span></div>
                        <div class="invoice-price-alt">ou <strong>R$ 597</strong> <span
                                class="invoice-price-period-inline">/ano</span> <span class="invoice-price-hint">(≈ R$
                                49,75/mês)</span></div>
                    </div>
                    <p class="total-desc">Sem salário. Sem comissão. Operando 24 horas por dia. O mesmo produto poderoso em todos os planos.</p>
                    <a href="checkout?plan=anual" class="btn-plan btn-plan--solid invoice-cta pulse-glow-btn">Ligar Auvvo no Anual</a>
                    <a href="checkout?plan=mensal" class="btn-plan btn-plan--ghost invoice-cta-secondary">Testar o mensal primeiro</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Final Dramatic CTA -->
    <section class="section container">
        <div class="pricing-banner reveal final-cta-banner">
            <h2 class="dramatic-title">Enquanto você lia esta página, nossos clientes realizaram <span class="counter-spin">4.821</span> vendas automáticas. E você?</h2>
            <p class="dramatic-subtitle">
                O tempo está passando. Cada minuto que você demora para assinar, é um lead quente que a sua concorrência atende primeiro.
            </p>
            <div class="pricing-cta-dual" style="margin-top: 40px;">
                <a href="checkout?plan=anual" class="store-btn pulse-glow-btn" style="margin: 0; padding: 20px 40px; font-size: 1.25rem;">
                    <i class="fa-solid fa-lock"></i> Destravar Vendas Infinitas
                </a>
            </div>

            <div class="bonus-list" style="margin-top: 32px; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 32px;">
                <div class="bonus-item"><i class="fa-solid fa-check"></i><strong>Setup Rápido em 10 min</strong></div>
                <div class="bonus-item"><i class="fa-solid fa-check"></i><strong>I.A. Super Inteligente</strong></div>
                <div class="bonus-item"><i class="fa-solid fa-check"></i><strong>Atendimentos Ilimitados</strong></div>
            </div>
            
            <div class="guarantee-box" style="background: rgba(255, 255, 255, 0.65); border: 1px solid var(--border-glass); box-shadow: var(--shadow-soft);">
                <i class="fa-solid fa-shield-halved" style="color: var(--accent-gold);"></i>
                <div class="guarantee-text">
                    <strong style="color: var(--text-primary);">Risco Zero: Garantia de 7 Dias</strong>
                    <span style="color: var(--text-secondary);">Cancele com 1 clique se a Auvvo não explodir suas vendas.</span>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section id="faq" class="section container">
        <div class="section-header reveal">
            <div class="section-tag">Dúvidas</div>
            <h2 class="section-title">Perguntas Frequentes</h2>
        </div>
        <div class="faq-list reveal">
            <div class="faq-item">
                <div class="faq-question"><i class="fa-solid fa-circle-question"></i> Preciso saber programar?</div>
                <div class="faq-answer">Não! A Auvvo foi desenhada para pessoas normais. Você só precisa escrever textos
                    simples, copiar e colar as informações do seu negócio (como o seu FAQ atual) para treinar a sua I.A.
                    instantaneamente.</div>
            </div>
            <div class="faq-item">
                <div class="faq-question"><i class="fa-solid fa-circle-question"></i> Funciona no WhatsApp Pessoal e
                    Business?</div>
                <div class="faq-answer">Sim — a conexão é feita via QR Code, no mesmo modelo do WhatsApp Web, e costuma
                    funcionar em contas pessoais e Business. Isso <strong>não é</strong> a API comercial oficial da Meta;
                    a Meta pode mudar regras ou limitar contas. Leia o aviso nos <a href="termos.php">Termos de Uso</a> e
                    a pergunta abaixo antes de escalar volume.</div>
            </div>
            <div class="faq-item">
                <div class="faq-question"><i class="fa-solid fa-circle-question"></i> Isso é a API oficial do WhatsApp
                    (Meta)? Quais riscos?</div>
                <div class="faq-answer">Não. A Auvvo usa pareamento por QR Code (mesma família técnica do WhatsApp Web),
                    diferente da WhatsApp Business Platform contratada com a Meta. Há risco teórico de restrição ou
                    banimento se a Meta entender que o uso viola as políticas dela — principalmente com automação
                    agressiva ou mensagens não solicitadas. Use com consentimento do cliente, boas práticas de LGPD e
                    supervisão humana nos fluxos críticos. Detalhes em <a href="termos.php">Termos de Uso</a>.</div>
            </div>
            <div class="faq-item">
                <div class="faq-question"><i class="fa-solid fa-circle-question"></i> E se a I.A. não souber a resposta?
                </div>
                <div class="faq-answer">Fique tranquilo. Você pode configurar uma regra de "transbordo": se a I.A. se
                    deparar com uma dúvida fora do seu treinamento, ela pausa automaticamente e avisa um atendente
                    humano da sua equipe para assumir a conversa.</div>
            </div>
            <div class="faq-item">
                <div class="faq-question"><i class="fa-solid fa-circle-question"></i> O agente envia áudios?</div>
                <div class="faq-answer">Sim! Você pode fazer upload dos seus áudios gravados e a I.A. vai enviá-los no
                    momento certo, simulando o status de "gravando áudio..." para passar o máximo de humanização ao
                    cliente.</div>
            </div>
            <div class="faq-item">
                <div class="faq-question"><i class="fa-solid fa-circle-question"></i> Consigo conectar mais de um
                    número?</div>
                <div class="faq-answer">Sim, nosso painel permite gerenciar múltiplas instâncias (números de WhatsApp)
                    simultaneamente. Você pode ter um agente diferente treinado para cada número.</div>
            </div>
            <div class="faq-item">
                <div class="faq-question"><i class="fa-solid fa-circle-question"></i> Como funciona a cobrança das
                    mensagens?</div>
                <div class="faq-answer">Você paga a assinatura fixa da Auvvo para ter acesso à plataforma e conexão com
                    WhatsApp. O custo de "inteligência" (API do ChatGPT) é cobrado direto pela OpenAI, custando apenas
                    frações de centavos por mensagem enviada, garantindo o custo mais baixo do mercado.</div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer-box">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-col">
                    <h3 style="margin-bottom: 16px;">Auvvo</h3>
                    <p
                        style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 24px; line-height: 1.6; padding-right: 20px;">
                        Transformando o atendimento via WhatsApp com Inteligência Artificial avançada. Aumente conversões
                        operando 24/7 no piloto automático.
                    </p>
                    <div class="social-links">
                        <a href="#"><i class="fa-brands fa-instagram"></i></a>
                        <a href="#"><i class="fa-brands fa-linkedin-in"></i></a>
                        <a href="#"><i class="fa-brands fa-youtube"></i></a>
                    </div>
                </div>
                <div class="footer-col">
                    <h4>Produto</h4>
                    <a href="#como-funciona">Como Funciona</a>
                    <a href="#casos-de-uso">Casos de Uso</a>
                    <a href="#prova-social">Resultados</a>
                    <a href="#precos">Preços</a>
                    <a href="#faq">FAQ</a>
                </div>
                <div class="footer-col">
                    <h4>Empresa</h4>
                    <a href="sobre.php">Sobre nós</a>
                    <a href="termos.php">Termos de uso</a>
                    <a href="privacidade.php">Privacidade</a>
                    <a href="mailto:<?= htmlspecialchars(mkt_support_email(), ENT_QUOTES, 'UTF-8') ?>?subject=Programa%20de%20afiliados">Afiliados</a>
                </div>
                <div class="footer-col">
                    <h4>Contato</h4>
                    <a href="mailto:<?= htmlspecialchars(mkt_support_email(), ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid fa-envelope" style="margin-right: 8px;"></i>
                        <?= htmlspecialchars(mkt_support_email(), ENT_QUOTES, 'UTF-8') ?></a>
                    <?php if (mkt_whatsapp_href() !== ''): ?>
                    <a href="<?= htmlspecialchars(mkt_whatsapp_href(), ENT_QUOTES, 'UTF-8') ?>" target="_blank"
                        rel="noopener noreferrer"><i class="fa-brands fa-whatsapp" style="margin-right: 8px;"></i>
                        <?= htmlspecialchars(mkt_whatsapp_footer_label(), ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endif; ?>
                    <a href="login" class="btn btn-primary"
                        style="margin-top: 16px; padding: 12px; font-size: 0.875rem; text-align: center; justify-content: center; width: 100%;">Área
                        do Cliente</a>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2026 Auvvo AI. Todos os direitos reservados.</p>
                <p>Feito com <i class="fa-solid fa-heart" style="color: var(--text-danger);"></i> para times de vendas.</p>
            </div>
        </div>
    </footer>

    <div class="video-modal" id="video-modal" role="dialog" aria-modal="true" aria-labelledby="video-modal-title"
        hidden>
        <button type="button" class="video-modal-backdrop" id="video-modal-backdrop" aria-label="Fechar vídeo"></button>
        <div class="video-modal-dialog">
            <h2 id="video-modal-title" class="visually-hidden">Demonstração Auvvo</h2>
            <button type="button" class="video-modal-close" id="video-modal-close" aria-label="Fechar">&times;</button>
            <video id="video-modal-player" controls playsinline preload="metadata" poster="favicon.png">
                <source src="202604302219.mp4" type="video/mp4">
            </video>
        </div>
    </div>

    <script>
        // Scroll Reveal Animation
        document.addEventListener('DOMContentLoaded', () => {
            const reveals = document.querySelectorAll('.reveal');

            const toggle = document.getElementById('nav-toggle');
            const navBackdrop = document.getElementById('nav-backdrop');
            const primaryNav = document.getElementById('primary-nav');
            function closeNav() {
                document.body.classList.remove('nav-open');
                toggle?.setAttribute('aria-expanded', 'false');
                navBackdrop?.setAttribute('hidden', '');
                navBackdrop?.setAttribute('aria-hidden', 'true');
            }
            function openNav() {
                document.body.classList.add('nav-open');
                toggle?.setAttribute('aria-expanded', 'true');
                navBackdrop?.removeAttribute('hidden');
                navBackdrop?.setAttribute('aria-hidden', 'false');
            }
            toggle?.addEventListener('click', () => {
                if (document.body.classList.contains('nav-open')) closeNav();
                else openNav();
            });
            navBackdrop?.addEventListener('click', closeNav);
            primaryNav?.querySelectorAll('a').forEach((a) => a.addEventListener('click', closeNav));
            window.addEventListener('resize', () => {
                if (window.innerWidth > 1024) closeNav();
            });

            const videoModal = document.getElementById('video-modal');
            const videoPlayTrigger = document.getElementById('video-play-trigger');
            const videoModalPlayer = document.getElementById('video-modal-player');
            const videoModalClose = document.getElementById('video-modal-close');
            const videoModalBackdropBtn = document.getElementById('video-modal-backdrop');
            function closeVideoModal() {
                if (!videoModal) return;
                videoModal.setAttribute('hidden', '');
                if (videoModalPlayer) {
                    videoModalPlayer.pause();
                    videoModalPlayer.currentTime = 0;
                }
                if (!document.body.classList.contains('nav-open')) {
                    document.body.style.overflow = '';
                }
            }
            function openVideoModal() {
                if (!videoModal) return;
                videoModal.removeAttribute('hidden');
                document.body.style.overflow = 'hidden';
                videoModalPlayer?.play().catch(() => { });
            }
            videoPlayTrigger?.addEventListener('click', openVideoModal);
            videoModalClose?.addEventListener('click', closeVideoModal);
            videoModalBackdropBtn?.addEventListener('click', closeVideoModal);

            const revealOnScroll = () => {
                const windowHeight = window.innerHeight;
                reveals.forEach(reveal => {
                    const revealTop = reveal.getBoundingClientRect().top;
                    if (revealTop < windowHeight - 50) {
                        reveal.classList.add('active');
                    }
                });
            };
            window.addEventListener('scroll', revealOnScroll);
            revealOnScroll(); // Trigger initial check

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    closeNav();
                    closeVideoModal();
                }
            });
        });

        // Live Notifications Animation
        const notifications = [
            { title: 'NOVO ATENDIMENTO', desc: 'Cliente aguardando', icon: 'fa-arrow-trend-up' },
            { title: 'MENSAGEM RESPONDIDA', desc: 'Atendimento ativo', icon: 'fa-check-double' },
            { title: '+1 VENDA REALIZADA', desc: 'Assinatura Auvvo', icon: 'fa-sack-dollar' },
            { title: 'REUNIÃO AGENDADA', desc: 'Amanhã, 14h00', icon: 'fa-calendar-check' }
        ];

        let notifIndex = 0;
        const notifEl = document.getElementById('live-notification');
        const notifTitle = document.getElementById('notif-title');
        const notifDesc = document.getElementById('notif-desc');
        const notifIcon = document.getElementById('notif-icon');

        function rotateNotifications() {
            if (!notifEl) return;

            // Fade out
            notifEl.classList.remove('show');

            setTimeout(() => {
                // Update text and icon
                const n = notifications[notifIndex];
                notifTitle.textContent = n.title;
                notifDesc.textContent = n.desc;
                notifIcon.className = `fa-solid ${n.icon}`;

                // Randomize position (left or right side of the phone)
                if (Math.random() > 0.5) {
                    notifEl.style.left = '-60px';
                    notifEl.style.right = 'auto';
                } else {
                    notifEl.style.left = 'auto';
                    notifEl.style.right = '-60px';
                }

                // Fade in
                notifEl.classList.add('show');

                // Next item
                notifIndex = (notifIndex + 1) % notifications.length;
            }, 500); // Wait for fade out animation
        }

        // Start animation loop
        setTimeout(() => {
            rotateNotifications();
            setInterval(rotateNotifications, 4000); // rotate every 4 seconds
        }, 1000);
    </script>
    <?php mkt_render_floating_whatsapp(); ?>
</body>

</html>
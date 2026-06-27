<?php
// checkout.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$plans = [
    'mensal' => [
        'id'          => 'mensal',
        'name'        => 'Plano Mensal',
        'desc'        => 'Renovação automática todo mês. Mesmo acesso completo à plataforma.',
        'price_num'   => 97.00,
        'price_fmt'   => '97,00',
        'period'      => '/mês',
        'equivalent'  => null,
        'strike_label'=> null,
        'strike_fmt'  => null,
        'badge'       => 'Flexível',
    ],
    'trimestral' => [
        'id'          => 'trimestral',
        'name'        => 'Plano Trimestral',
        'desc'        => 'Acesso por 3 meses. Economia e praticidade.',
        'price_num'   => 197.00,
        'price_fmt'   => '197,00',
        'period'      => '/trimestre',
        'equivalent'  => '≈ R$ 65,66 por mês',
        'strike_label'=> 'Trimestral sem desconto',
        'strike_fmt'  => 'R$ 291,00',
        'badge'       => 'Popular',
    ],
    'anual' => [
        'id'          => 'anual',
        'name'        => 'Plano Anual',
        'desc'        => 'Acesso ilimitado por 12 meses. Cobrança única.',
        'price_num'   => 597.00,
        'price_fmt'   => '597,00',
        'period'      => '/ano',
        'equivalent'  => '≈ R$ 49,75 por mês · economia frente ao plano mensal',
        'strike_label'=> '12× mensal',
        'strike_fmt'  => 'R$ 1.164,00',
        'badge'       => 'Melhor custo',
    ],
];


$plan_id = isset($_GET['plan']) && isset($plans[$_GET['plan']]) ? $_GET['plan'] : 'anual';
$p       = $plans[$plan_id];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout | Auvvo — <?= htmlspecialchars($p['name']) ?></title>
    <link rel="stylesheet" href="app.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <link rel="icon" type="image/png" href="icone.png">
    <style>
        .checkout-layout {
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 40px;
            max-width: 1100px;
            margin: 40px auto;
            padding: 24px;
        }
        @media (max-width: 768px) {
            .checkout-layout { grid-template-columns: 1fr; }
        }

        /* Price tag */
        .price-tag {
            display: flex;
            align-items: flex-end;
            gap: 6px;
            margin: 20px 0 4px;
        }
        .price-tag .main-price {
            font-size: 2.8rem;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1;
        }
        .price-tag .period {
            font-size: 1rem;
            color: var(--text-muted);
            margin-bottom: 6px;
        }
        .price-strikethrough {
            font-size: 0.875rem;
            color: var(--text-muted);
            text-decoration: line-through;
            margin-bottom: 2px;
        }
        .price-equivalent {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 4px;
            margin-bottom: 20px;
        }

        /* Benefit list */
        .benefit-list { list-style: none; padding: 0; margin: 0 0 24px; }
        .benefit-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-subtle);
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
        .benefit-list li:last-child { border-bottom: none; }
        .benefit-list li i { color: #10B981; font-size: 1rem; flex-shrink: 0; }

        /* Badge de desconto */
        .discount-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: linear-gradient(135deg, #10B981, #059669);
            color: #fff;
            font-size: 0.8rem;
            font-weight: 700;
            padding: 5px 14px;
            border-radius: 999px;
            margin-bottom: 12px;
            letter-spacing: 0.04em;
        }

        /* Urgency timer */
        .urgency-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            color: #F59E0B;
            margin-top: 14px;
            padding: 10px 14px;
            background: rgba(245, 158, 11, 0.08);
            border: 1px solid rgba(245, 158, 11, 0.25);
            border-radius: var(--radius-sm);
        }
    </style>
</head>
<body style="background: var(--bg-app);">

    <div class="checkout-layout">

        <!-- Formulário de Criação de Conta -->
        <div class="app-card">
            <div style="margin-bottom: 32px;">
                <img src="favicon.png" alt="Auvvo Logo" style="width: 110px; margin-bottom: 20px;">
                <?php if (!empty($_GET['canceled'])): ?>
                <div style="background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3); border-radius: var(--radius-sm); padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <i class="ph-bold ph-warning" style="color: #F59E0B; font-size: 1.1rem; flex-shrink: 0;"></i>
                    <span style="font-size: 0.875rem; color: var(--text-secondary);">Pagamento cancelado. Seus dados estão salvos — clique em continuar quando estiver pronto.</span>
                </div>
                <?php endif; ?>
                <h1 style="font-size: 1.6rem; margin-bottom: 6px;">Crie Sua Conta</h1>
                <p class="text-muted">Preencha os dados abaixo para finalizar sua assinatura.</p>
            </div>

                <form action="backend/process_payment.php" method="POST" id="checkout-form">
                <input type="hidden" name="plan" value="<?= htmlspecialchars($plan_id) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <h3 style="font-size: 1rem; margin-bottom: 16px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-muted);">Seus Dados de Acesso</h3>

                <div class="form-group">
                    <label class="form-label">Nome Completo</label>
                    <input type="text" name="name" class="form-control" placeholder="Seu nome completo" required>
                </div>
                <div class="form-group">
                    <label class="form-label">E-mail (será seu login)</label>
                    <input type="email" name="email" class="form-control" placeholder="seu@email.com" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Criar Senha de Acesso</label>
                    <input type="password" name="password" class="form-control" placeholder="Mínimo 8 caracteres" required minlength="8">
                </div>

                <p class="text-muted" style="margin-top: 20px; font-size: 0.8rem; line-height: 1.6;">
                    Ao continuar, você concorda com nossos <a href="termos.php" style="color: var(--accent-teal);">Termos de Uso</a> e <a href="privacidade.php" style="color: var(--accent-teal);">Política de Privacidade</a>. 
                    Após criar sua conta, você será redirecionado ao ambiente seguro de pagamento.
                </p>

                <button type="submit" id="checkout-btn" class="btn btn-primary btn-block" style="margin-top: 20px; font-size: 1.1rem; padding: 18px;">
                    Garantir Meu Plano Agora &nbsp;<i class="ph-bold ph-arrow-right"></i>
                </button>

                <script>
                (function() {
                  const form = document.getElementById('checkout-form');
                  const btn = document.getElementById('checkout-btn');
                  const defaultHtml = btn.innerHTML;
                  function resetBtn() {
                    btn.disabled = false;
                    btn.innerHTML = defaultHtml;
                  }
                  form.addEventListener('submit', function() {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="ph-bold ph-circle-notch ph-spin"></i> Redirecionando...';
                  });
                  window.addEventListener('pageshow', resetBtn);
                })();
                </script>

                <div style="display: flex; align-items: center; justify-content: center; gap: 18px; margin-top: 20px; opacity: 0.6;">
                    <i class="ph-bold ph-lock" style="font-size: 1.1rem;"></i>
                    <span style="font-size: 0.75rem; color: var(--text-muted);">Dados protegidos com criptografia SSL 256 bits</span>
                </div>
            </form>
        </div>

        <!-- Resumo do Pedido -->
        <div>
            <div class="app-card-glass" style="position: sticky; top: 40px;">

                <span class="discount-badge">
                    <i class="ph-bold ph-tag"></i>
                    <?= htmlspecialchars($p['badge']) ?>
                </span>

                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px;">
                    <a href="checkout?plan=mensal" style="font-size: 0.8rem; padding: 6px 12px; border-radius: 999px; text-decoration: none; font-weight: 600; <?= $plan_id === 'mensal' ? 'background: var(--accent-teal); color: #1A1A1E;' : 'background: var(--surface-glass); color: var(--text-secondary); border: 1px solid var(--border-subtle);' ?>">Mensal</a>
                    <a href="checkout?plan=trimestral" style="font-size: 0.8rem; padding: 6px 12px; border-radius: 999px; text-decoration: none; font-weight: 600; <?= $plan_id === 'trimestral' ? 'background: var(--accent-teal); color: #1A1A1E;' : 'background: var(--surface-glass); color: var(--text-secondary); border: 1px solid var(--border-subtle);' ?>">Trimestral</a>
                    <a href="checkout?plan=anual" style="font-size: 0.8rem; padding: 6px 12px; border-radius: 999px; text-decoration: none; font-weight: 600; <?= $plan_id === 'anual' ? 'background: var(--accent-teal); color: #1A1A1E;' : 'background: var(--surface-glass); color: var(--text-secondary); border: 1px solid var(--border-subtle);' ?>">Anual</a>
                </div>

                <h3 style="font-size: 1rem; margin-bottom: 4px;"><?= htmlspecialchars($p['name']) ?></h3>
                <p class="text-muted" style="font-size: 0.85rem; margin-bottom: 16px;"><?= htmlspecialchars($p['desc']) ?></p>

                <?php if (!empty($p['strike_fmt'])): ?>
                <div class="price-strikethrough"><?= htmlspecialchars($p['strike_label']) ?>: <?= htmlspecialchars($p['strike_fmt']) ?></div>
                <?php endif; ?>
                <div class="price-tag">
                    <span class="main-price">R$ <?= htmlspecialchars($p['price_fmt']) ?></span>
                    <span class="period"><?= htmlspecialchars($p['period']) ?></span>
                </div>
                <?php if (!empty($p['equivalent'])): ?>
                <div class="price-equivalent"><?= htmlspecialchars($p['equivalent']) ?></div>
                <?php else: ?>
                <div class="price-equivalent">Cobrança recorrente mensal no cartão ou método disponível no gateway.</div>
                <?php endif; ?>

                <div style="border-top: 1px solid var(--border-subtle); padding-top: 20px; margin-bottom: 20px;">
                    <ul class="benefit-list">
                        <li><i class="ph-bold ph-check-circle"></i> Atendimentos ilimitados 24/7</li>
                        <li><i class="ph-bold ph-check-circle"></i> Múltiplos agentes de I.A.</li>
                        <li><i class="ph-bold ph-check-circle"></i> Conexão WhatsApp via QR Code</li>
                        <li><i class="ph-bold ph-check-circle"></i> Base de conhecimento exclusiva</li>
                        <li><i class="ph-bold ph-check-circle"></i> Simulação de áudios humanizados</li>
                        <li><i class="ph-bold ph-check-circle"></i> Transbordo humano automático</li>
                        <li><i class="ph-bold ph-check-circle"></i> Disparos em massa (Campanhas)</li>
                        <li><i class="ph-bold ph-check-circle"></i> Suporte prioritário</li>
                    </ul>
                </div>

                <div style="display: flex; justify-content: space-between; font-size: 1.3rem; font-weight: 700; padding: 16px 0; border-top: 1px solid var(--border-subtle); border-bottom: 1px solid var(--border-subtle);">
                    <span>Total</span>
                    <span>R$ <?= htmlspecialchars($p['price_fmt']) ?></span>
                </div>

                <div class="urgency-bar">
                    <i class="ph-bold ph-clock-countdown"></i>
                    <span>Oferta especial de lançamento — preço pode aumentar a qualquer momento.</span>
                </div>

                <div style="text-align: center; margin-top: 20px;">
                    <i class="ph-fill ph-shield-check" style="font-size: 1.8rem; color: #10B981;"></i>
                    <p class="text-muted" style="font-size: 0.75rem; margin-top: 6px;">Garantia de 7 dias — reembolso total, sem perguntas.</p>
                </div>
            </div>
        </div>

    </div>

</body>
</html>

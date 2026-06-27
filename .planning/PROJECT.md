# Auvvo AI

## What This Is

Plataforma SaaS de automação de vendas via WhatsApp com IA. Empresas criam agentes de IA que se conectam ao WhatsApp via QR code (Evolution API), são treinados com conhecimento do negócio, e atendem conversas, qualificam leads e fecham vendas 24/7 automaticamente.

## Core Value

**O agente de IA atende e converte leads no WhatsApp sem intervenção humana**, funcionando como um vendedor autônomo que nunca dorme.

## Context

**Stack:** PHP vanilla, MySQL, Apache/.htaccess, Node.js worker para filas, Evolution API (WhatsApp), OpenRouter (LLM), AbacatePay (pagamentos), SMTP Hostinger, Google OAuth.

**Estado atual:** Sistema funcional em produção mas com bugs, falhas de segurança, e race conditions. Auditoria completa (2026-05-23) identificou 33 issues.

## Constraints

- **Nao mudar stack**: PHP vanilla, MySQL, Evolution API, Node.js worker — tudo permanece
- **Nao reescrever**: Apenas correções pontuais, manter arquitetura atual
- **Nao quebrar**: Toda correção deve manter compatibilidade com produção

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| PHP vanilla sem framework | Simplicidade, zero dependências | Mantido |
| MySQL como fila de jobs | Infraestrutura mais simples | Mantido (corrigir race conditions) |
| Evolution API para WhatsApp | Estável, QR-code, multi-instância | Mantido |
| Polling no worker Node.js | Simples, funciona para escala atual | Mantido (adicionar timeouts) |

---
*Last updated: 2026-05-23 after codebase audit*

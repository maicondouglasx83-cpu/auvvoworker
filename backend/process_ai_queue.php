<?php
/**
 * @deprecated Substituído por auvvo-worker (Node) + backend/internal/process_ai_job.php
 * Mantido apenas para referências antigas na documentação.
 */
header('Content-Type: text/plain; charset=utf-8');
http_response_code(410);
echo "Este consumidor foi descontinuado.\n";
echo "Inicie: cd auvvo-worker && npm install && npm start\n";
echo "Configure WEBHOOK_AI_MODE=queue no .env\n";

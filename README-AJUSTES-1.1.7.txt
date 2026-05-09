Oferta TOP Site - Ajustes 1.1.7

Melhorias aplicadas nesta versão:

1. Proteção contra inflação de impressões
- Endpoint AJAX de impressão agora exige nonce do WordPress.
- O JS usa admin_url('admin-ajax.php') via wp_localize_script, sem URL fixa.
- Impressão só é enviada quando o card fica visível na tela via IntersectionObserver.
- O navegador evita reenvio duplicado na mesma sessão com sessionStorage.
- O servidor agora valida se o anúncio está ativo e com cliques restantes antes de registrar impressão.
- Impressões de administrador e do dono do anúncio não entram na métrica.
- Impressões têm chave única por anúncio, visitante e janela de 12 horas.

2. Cliques únicos por anúncio
- O mesmo visitante não consome cliques novamente no mesmo anúncio, mesmo após 24h.
- A verificação usa fingerprint de IP + navegador, cookie e usuário logado.
- Administrador e dono do anúncio não consomem cliques.
- O decremento de remaining_clicks agora é condicionado a status ativo e saldo restante.
- A gravação do clique usa INSERT IGNORE com tracking_key única para reduzir risco de duplicidade por múltiplos envios simultâneos.

3. Banco de dados
- Adicionadas colunas visitor_hash e tracking_key nas tabelas ots_clicks e ots_impressions.
- Adicionado índice visitor_hash.
- Adicionado índice único tracking_key.
- Versão de tabela atualizada para 1.0.6.
- A rotina de atualização passa a rodar automaticamente no admin quando a versão da tabela estiver desatualizada.

Arquivos alterados:
- oferta-top-site.php
- includes/install.php
- includes/tracking.php
- assets/js/public.js

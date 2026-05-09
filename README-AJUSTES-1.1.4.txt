Oferta TOP Site - Ajustes v1.1.4

Correção aplicada:
- Corrigido botão Pagar agora que redirecionava para /wp-admin.
- Causa: wp_safe_redirect() bloqueia redirecionamento para domínios externos não permitidos e usa o admin_url() como fallback.
- Solução: o plugin agora gera a preferência do Mercado Pago em produção, valida se a URL não é sandbox/teste e redireciona com wp_redirect() para o init_point de produção retornado pela API.

Mantido:
- Geração de novo link no momento do clique em Pagar agora.
- Bloqueio de links sandbox/teste.
- Credenciais de produção configuradas.
- Saque mínimo de R$ 30,00.
- Ações administrativas para marcar saque como Pago/Pendente/Cancelado.
- Pausar/despausar anúncios no Meus Anúncios.

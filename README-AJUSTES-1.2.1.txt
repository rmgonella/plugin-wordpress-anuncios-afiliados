Oferta TOP Site - Ajustes v1.2.1

Hardening operacional e segurança:

- Ações administrativas sensíveis migradas para POST via admin-post.php.
- Aprovar, reprovar, pausar, finalizar, excluir anúncio e alterar saques não usam mais links GET para alterar dados.
- Webhook Mercado Pago passa a aceitar apenas POST.
- Webhook registra aviso quando chega sem cabeçalhos de assinatura do Mercado Pago e continua validando o pagamento pela API oficial.
- Upload de imagens endurecido: aceita somente JPG, PNG e WEBP reais, valida extensão/MIME com wp_check_filetype_and_ext(), bloqueia SVG/HTML/PHP/JS, limita a 3MB, valida binário da imagem e renomeia arquivo com nome seguro.
- Controle de corrida no último clique disponível: se outro clique consumir o saldo em paralelo, o plugin remove o registro/trava não contabilizada e registra evento de bloqueio.
- Campo de Access Token continua mascarado no admin: deixar em branco preserva o valor salvo.
- Versão atualizada para 1.2.1.

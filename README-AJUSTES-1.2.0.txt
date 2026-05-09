Oferta TOP Site - Ajustes v1.2.0

Evolução antifraude e financeira aplicada:

1. Clique único global
- Criada tabela ots_click_locks.
- Depois do primeiro clique válido, o mesmo visitante não consome novos cliques em nenhum outro anúncio.
- Usuário logado, administrador e usuário da conta não contabilizam clique nem geram ganho.
- Cliques bloqueados são registrados em log para auditoria.

2. Afiliados por referência
- Links compartilhados por usuário logado passam a incluir ref=ID_DO_USUARIO.
- Comissão só é creditada ao afiliado do link, nunca ao visitante que clicou.
- Dono do anúncio, administrador e usuários logados não geram comissão pelo clique.

3. Logs de auditoria
- Criada tabela ots_event_logs.
- Nova tela Admin > Oferta TOP Site > Antifraude mostra travas globais e eventos recentes.
- Eventos de clique bloqueado, clique contabilizado, webhook do Mercado Pago e exclusão são registrados.

4. Mercado Pago mais seguro
- Credenciais de produção removidas do código-fonte.
- Campos sensíveis preservam o valor já salvo quando o administrador deixa em branco.
- Webhook valida anúncio, valor pago, moeda BRL, duplicidade de payment_id e external_reference.
- Pagamento aprovado leva o anúncio para validação manual, não ativa diretamente.
- Dados do pagamento real ficam registrados em mp_payment_id, payment_amount, payment_currency e paid_at.

5. Exclusão com auditoria
- Exclusão administrativa passa a usar soft delete: status=deleted, deleted_at e deleted_by.
- Cliques, impressões e histórico financeiro são preservados.
- A imagem física é removida somente se nenhum outro anúncio ativo/não excluído estiver usando a mesma URL.

6. Saques
- Ao marcar saque como pago, registra paid_at e paid_by.

Após instalar/atualizar:
- Desative e ative o plugin se necessário para forçar a atualização das tabelas.
- Acesse Oferta TOP Site > Configurações e confirme as credenciais do Mercado Pago.
- Como as credenciais não ficam mais no código, se o banco estiver sem elas será necessário inserir novamente no painel.

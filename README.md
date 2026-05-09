# Oferta TOP Site

Plugin WordPress para gerenciamento de anúncios pagos, controle de cliques, impressões, pagamentos, programa de afiliados, solicitações de saque e proteção antifraude.

**Versão:** 1.2.1  
**Compatibilidade:** WordPress 6.x ou superior  
**Linguagem:** PHP 7.4+ recomendado  
**Banco de dados:** MySQL/MariaDB via tabelas próprias do WordPress

---

## 1. Visão geral

O **Oferta TOP Site** permite criar uma plataforma de anúncios dentro do WordPress, onde usuários podem cadastrar anúncios, pagar pela campanha, acompanhar cliques/impressões e participar de um programa de afiliados com comissões por clique válido.

A versão **1.2.1** trouxe uma evolução focada em **segurança operacional, antifraude, controle financeiro e hardening do plugin**.

---

## 2. Principais recursos

- Cadastro de anúncios pelo usuário.
- Painel “Meus Anúncios”.
- Status de anúncios: aguardando pagamento, aguardando validação, ativo, pausado, finalizado, reprovado e excluído.
- Pagamento via Mercado Pago.
- Pagamento via Pix manual.
- Botão “Pagar agora”.
- Aprovação manual de anúncios pelo administrador.
- Reprovação com possibilidade de edição e reenvio.
- Pausar e despausar anúncios pelo usuário.
- Exclusão administrativa com preservação de histórico.
- Remoção segura de imagem física quando não estiver em uso por outro anúncio.
- Impressões protegidas por nonce, visibilidade real e controle por visitante.
- Clique único global por visitante em todo o sistema.
- Bloqueio de cliques e ganhos para usuários logados.
- Bloqueio de cliques e ganhos para administradores.
- Programa de afiliados com referência por `ref`.
- Solicitação de saque com valor mínimo configurável.
- Painel administrativo de saques.
- Registro de `paid_at` e `paid_by` em saques pagos.
- Painel antifraude.
- Logs de auditoria.
- Upload de imagem reforçado.
- Webhook Mercado Pago com validações financeiras.
- Ações administrativas sensíveis via POST.

---

## 3. O que mudou na versão 1.2.1

A versão **1.2.1** é uma versão de hardening, focada em aumentar a segurança do plugin.

### 3.1. Ações administrativas via POST

As ações administrativas sensíveis deixaram de usar links GET para alterar dados e passaram a usar `POST` via `admin-post.php`.

Ações protegidas:

- Aprovar anúncio.
- Reprovar anúncio.
- Pausar anúncio.
- Finalizar anúncio.
- Excluir anúncio.
- Marcar saque como pago.
- Voltar saque para pendente.
- Cancelar saque.

Isso reduz risco de execução acidental, crawlers, pré-carregamentos de navegador e ações indevidas via URL.

---

### 3.2. Webhook Mercado Pago somente via POST

A rota de webhook do Mercado Pago agora aceita somente requisições `POST`.

O plugin continua validando o pagamento consultando a API do Mercado Pago e registra eventos suspeitos quando a chamada chega sem cabeçalhos esperados.

---

### 3.3. Upload de imagem mais seguro

O upload de imagens foi reforçado.

Agora o plugin:

- aceita somente JPG, PNG e WEBP;
- bloqueia SVG;
- bloqueia PHP, HTML, JS e arquivos perigosos;
- limita imagem a 3MB;
- valida MIME e extensão real com `wp_check_filetype_and_ext()`;
- valida o binário da imagem;
- renomeia o arquivo com nome seguro.

---

### 3.4. Melhor controle de corrida no último clique

Foi reforçado o controle para evitar inconsistência quando vários visitantes tentam clicar simultaneamente no último clique disponível de um anúncio.

Se o sistema não conseguir consumir saldo do anúncio, o clique não é contabilizado, a trava é removida e o evento é registrado no log antifraude.

---

### 3.5. Access Token mascarado no admin

O campo de Access Token do Mercado Pago fica mascarado no painel administrativo.

O token salvo não deve ser exposto diretamente no HTML do admin.

---

## 4. Regras de clique e antifraude

A regra principal da versão atual é:

> Um visitante só pode gerar **um clique contabilizado em todo o sistema**, independentemente do anúncio.

Ou seja:

- se o visitante clicou no anúncio A, o clique pode ser contabilizado;
- se depois clicar no anúncio B, não contabiliza;
- se clicar no anúncio C, também não contabiliza;
- o redirecionamento pode continuar acontecendo, mas sem consumir saldo e sem gerar comissão.

### Usuários que não contabilizam clique

O sistema não contabiliza clique para:

- usuário logado;
- administrador;
- dono do anúncio;
- visitante que já clicou anteriormente em qualquer anúncio;
- tentativa duplicada por cookie, IP, navegador ou hash de visitante.

### Usuários que não geram ganho

O sistema não gera comissão/ganho para:

- quem está logado;
- administrador;
- dono do anúncio;
- visitante sem referência de afiliado válida;
- afiliado que tenta gerar comissão indevida;
- visitante que já possui clique contabilizado no sistema.

---

## 5. Programa de afiliados

A comissão de afiliado deve ocorrer somente quando o clique vier de um link com referência válida.

Exemplo:

```text
https://seudominio.com.br/a/ots-click/?ad=10&ref=25
```

Onde:

- `ad=10` representa o ID do anúncio;
- `ref=25` representa o ID do afiliado responsável pelo compartilhamento.

O sistema valida se a referência é permitida antes de gerar comissão.

---

## 6. Mercado Pago

O plugin possui integração com Mercado Pago para geração de pagamento de anúncios.

### Configurações necessárias

No WordPress, acesse:

```text
Oferta TOP Site > Configurações
```

Preencha:

- Public Key de produção;
- Access Token de produção;
- URL de retorno, se aplicável;
- configurações de pagamento.

### Importante

As credenciais de produção não devem ficar gravadas diretamente no código-fonte do plugin.

A partir da evolução 1.2.0, o plugin passou a trabalhar com credenciais salvas pelo painel de configurações.

---

## 7. Webhook Mercado Pago

O webhook deve ser configurado no painel do Mercado Pago apontando para a rota REST do plugin.

Exemplo geral:

```text
https://seudominio.com.br/wp-json/ots/v1/mercadopago-webhook
```

O webhook valida:

- ID do pagamento;
- status;
- valor;
- moeda;
- referência externa;
- duplicidade de pagamento;
- anúncio relacionado.

Quando o pagamento é aprovado, o anúncio deve seguir para validação/revisão conforme regra configurada.

---

## 8. Cloudflare Turnstile

O plugin pode usar Cloudflare Turnstile para validar o visitante antes do redirecionamento do anúncio.

### Configuração

No painel do WordPress:

```text
Oferta TOP Site > Configurações
```

Preencha:

- Cloudflare Turnstile Site Key;
- Cloudflare Turnstile Secret Key.

### Observação

A Site Key deve estar criada no Cloudflare para o domínio correto.

Exemplo:

```text
ofertatopsite.com.br
```

Se a chave estiver vinculada a outro domínio, o widget pode não aparecer.

---

## 9. Saques

O sistema permite que afiliados solicitem saque dos ganhos acumulados.

### Regras atuais

- Valor mínimo de saque: R$ 30,00 ou valor configurado no plugin.
- O usuário não pode solicitar valor menor que o mínimo.
- O usuário não pode solicitar valor maior que o saldo disponível.
- O administrador pode marcar o saque como pago.
- Quando marcado como pago, o sistema registra `paid_at` e `paid_by`.

---

## 10. Status dos anúncios

O plugin trabalha com status para controlar o ciclo de vida do anúncio.

### Status comuns

| Status | Significado |
|---|---|
| `pending_payment` | Aguardando pagamento |
| `pending_review` | Aguardando validação manual |
| `active` | Ativo e apto para exibição |
| `paused` | Pausado pelo usuário ou administrador |
| `finished` | Finalizado por esgotamento de cliques |
| `rejected` | Reprovado pelo administrador |
| `deleted` | Excluído administrativamente, com histórico preservado |

---

## 11. Exclusão de anúncios

A exclusão de anúncio é permitida apenas para administrador.

A partir das versões recentes, a exclusão segue uma regra mais segura:

- o anúncio é marcado como `deleted`;
- o histórico é preservado;
- cliques, impressões e registros financeiros não são apagados;
- a imagem física pode ser removida do servidor;
- antes de apagar a imagem, o plugin verifica se outro anúncio ainda usa o mesmo arquivo.

Essa abordagem evita perda de auditoria financeira.

---

## 12. Shortcodes

Os shortcodes devem ser adicionados nas páginas do WordPress para exibir as áreas públicas do plugin.

Exemplos de páginas:

- Meus Anúncios;
- Criar Anúncio;
- Programa de Afiliados;
- Alterar Senha;
- Compartilhe nas Redes Sociais.

> Os nomes exatos dos shortcodes podem variar conforme a implementação do plugin. Consulte o arquivo `includes/shortcodes.php` caso precise confirmar todos os shortcodes disponíveis.

---

## 13. Instalação

1. Acesse o painel do WordPress.
2. Vá em:

```text
Plugins > Adicionar novo > Enviar plugin
```

3. Envie o arquivo `.zip` do plugin.
4. Clique em **Instalar agora**.
5. Clique em **Ativar**.
6. Acesse:

```text
Oferta TOP Site > Configurações
```

7. Configure Mercado Pago, Pix, Turnstile e demais opções.
8. Crie as páginas necessárias e insira os shortcodes.
9. Teste o fluxo completo em ambiente controlado.

---

## 14. Recomendação de atualização

Antes de atualizar em produção:

1. Faça backup completo do WordPress.
2. Faça backup do banco de dados.
3. Faça backup da pasta `wp-content/uploads`.
4. Teste a nova versão em ambiente de homologação.
5. Verifique se as credenciais do Mercado Pago continuam salvas corretamente.
6. Teste criação de anúncio.
7. Teste pagamento.
8. Teste webhook.
9. Teste aprovação de anúncio.
10. Teste clique com visitante não logado.
11. Teste bloqueio de clique para usuário logado.
12. Teste solicitação de saque.

---

## 15. Requisitos técnicos recomendados

- WordPress 6.x ou superior.
- PHP 7.4 ou superior.
- MySQL 5.7+ ou MariaDB equivalente.
- Extensão cURL habilitada.
- Permissão de escrita em `wp-content/uploads`.
- SSL ativo no domínio.
- WP-Cron funcionando corretamente.
- Conta Mercado Pago com credenciais de produção.
- Conta Cloudflare para Turnstile, se a proteção estiver ativa.

---

## 16. Segurança operacional

Para uso em produção, recomenda-se:

- manter WordPress atualizado;
- manter PHP atualizado;
- usar SSL;
- não compartilhar Access Token;
- não versionar credenciais em Git;
- usar backup automático;
- revisar logs antifraude;
- monitorar cliques bloqueados;
- monitorar webhooks do Mercado Pago;
- testar o Turnstile após alterações de domínio;
- limitar acesso ao painel administrativo.

---

## 17. Checklist de teste após instalação

### Anúncios

- [ ] Criar anúncio como usuário comum.
- [ ] Gerar pagamento via Mercado Pago.
- [ ] Confirmar se o link de pagamento abre em produção.
- [ ] Aprovar anúncio no admin.
- [ ] Verificar se aparece na listagem pública.
- [ ] Pausar anúncio.
- [ ] Despausar anúncio.
- [ ] Reprovar anúncio.
- [ ] Editar anúncio reprovado.
- [ ] Excluir anúncio como administrador.

### Cliques

- [ ] Clique de visitante não logado contabiliza uma vez.
- [ ] Segundo clique no mesmo anúncio não contabiliza.
- [ ] Clique em outro anúncio pelo mesmo visitante não contabiliza.
- [ ] Clique de usuário logado não contabiliza.
- [ ] Clique de administrador não contabiliza.
- [ ] Clique do dono do anúncio não contabiliza.

### Afiliados

- [ ] Gerar link com `ref`.
- [ ] Clicar como visitante não logado.
- [ ] Confirmar se a comissão vai para o afiliado correto.
- [ ] Confirmar se visitante logado não gera comissão.

### Saques

- [ ] Solicitar saque abaixo de R$ 30,00 e confirmar bloqueio.
- [ ] Solicitar saque de R$ 30,00 ou mais.
- [ ] Marcar saque como pago no admin.
- [ ] Confirmar registro de pagamento.

### Segurança

- [ ] Testar upload de JPG.
- [ ] Testar upload de PNG.
- [ ] Testar upload de WEBP.
- [ ] Confirmar bloqueio de SVG.
- [ ] Confirmar bloqueio de arquivo PHP disfarçado.
- [ ] Testar webhook do Mercado Pago.
- [ ] Verificar logs antifraude.

---

## 18. Roadmap sugerido

### v1.2.2

- Melhorar validação de assinatura oficial do Mercado Pago.
- Criar painel de diagnóstico do webhook.
- Criar exportação CSV de logs antifraude.
- Criar relatório detalhado para afiliados.

### v1.3.0

- Criar carteira financeira mais robusta.
- Separar saldo disponível, pendente, bloqueado e pago.
- Criar extrato detalhado de ganhos.
- Criar painel de análise antifraude por IP, cookie e User Agent.

### v1.4.0

- Melhorar UX do painel do usuário.
- Criar dashboard com gráficos.
- Adicionar notificações por e-mail para eventos importantes.
- Melhorar responsividade em tablets.

---

## 19. Observações finais

A versão **1.2.1** representa uma evolução importante do plugin, principalmente nos pontos de segurança, antifraude e integridade operacional.

O plugin já está mais preparado para testes controlados e operação inicial, mas em ambientes com alto volume financeiro recomenda-se continuar evoluindo o sistema com logs avançados, contabilidade mais robusta, validação mais forte do Mercado Pago e relatórios detalhados para afiliados e administradores.

---

## 20. Suporte e manutenção

Antes de realizar qualquer alteração estrutural no plugin, recomenda-se:

- criar backup completo;
- testar em ambiente separado;
- validar pagamento em produção;
- verificar webhook;
- revisar logs após atualização;
- manter uma cópia da versão anterior para rollback.


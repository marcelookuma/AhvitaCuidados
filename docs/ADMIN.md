# Painel de administração — `cuidados.ahvita.com/admin`

Painel web da equipe Ahvita para gerir a plataforma: contas, pessoas cuidadas, empresas, convites, log de acessos, auditoria, administradores e saúde do sistema. Página separada do app (`apps/web/admin/index.html`, publicada em `www/admin/`), sem service worker, com política de conteúdo (CSP) que só permite o próprio site e o botão do Google.

## 1. Ligar o painel (uma vez)

1. **Google Cloud** → APIs e serviços → Credenciais → o mesmo ID do cliente OAuth do "Entrar com Google" do app. Em **Origens JavaScript autorizadas** já deve estar `https://cuidados.ahvita.com` (é a mesma origem do painel; nada a acrescentar).
2. **`privado/config/configuracao.php`** no servidor (pelo FileZilla):
   ```php
   'google' => ['client_id' => '…apps.googleusercontent.com'],
   'admin'  => ['donos' => ['seu-email@gmail.com']],
   ```
3. Publique (o banco recebe a `003_admin.sql` sozinho em até 5 min, ou em **Sistema → Rodar tarefas agora**).
4. Abra `https://cuidados.ahvita.com/admin`, entre com o Google. Em **Administradores**, dê acesso ao restante da equipe.

> Use contas Google com **verificação em 2 etapas** ligada: o Google é a única porta do painel.

## 2. Quem pode o quê

| Papel | Pode |
|---|---|
| **Leitura** | ver tudo (números, cadastros, log, auditoria, sistema) |
| **Operador(a)** | + bloquear/desbloquear conta, encerrar sessões, corrigir cadastro (nome, e-mail, celular), desligar o Google de uma conta, revogar convite, encerrar vínculo, baixar o log em CSV, rodar tarefas, ver erros do PHP |
| **Dono(a)** | + excluir conta a pedido do titular, passar a gestão para alguém da Rede de Apoio, gerir administradores |

- Os e-mails de `admin.donos` são sempre donos e **não podem ser removidos pela tela** (a raiz de confiança fica no servidor). Para tirar, edite a configuração.
- Ninguém muda o próprio acesso.
- No primeiro acesso o painel guarda a conta Google (`sub`). Se o mesmo e-mail aparecer com outra conta Google, não entra até um(a) dono(a) clicar em **Liberar outra conta Google**.
- **Toda ação que muda dados pede um motivo** e grava na auditoria: ação, quem (e-mail), quando, IP e o motivo.

## 3. O que o painel mostra e o que não mostra (LGPD)

Mostra cadastro (nome, contato, CPF **mascarado**), vínculos e permissões, consentimentos, sessões abertas, contagens (registros, mensagens, remédios, alertas, mídias) e metadados de acesso.

**Não mostra**: registros de cuidado, conversa, ficha de emergência, plano, remédios, fotos, áudios, data de nascimento (só a idade) nem código de convite inteiro. Isso é minimização (LGPD art. 6º, III): suporte não precisa de dado de saúde para resolver acesso e cadastro. Se um dia precisar (ex.: ordem judicial), o caminho é exportar pelo app com o(a) gestor(a) ou pelo banco, com registro formal — não pelo painel.

## 4. Log de acessos e usos (todos os perfis)

Cada requisição à API passa pelo `Support\LogAcesso`, depois da resposta, sem atrasar nem derrubar o app.

| Vira linha em `log_acessos` | Fica só somado em `uso_diario` |
|---|---|
| entrar, sair, tentativa recusada | leituras de rotina (conversa, painel, lista, fotos…) |
| toda escrita (registrar, mandar mensagem, mudar permissões…) | por **dia**, usuário, perfil, rota e pessoa/empresa acessada |
| todo erro (≥ 400) | sem horário |
| exportação de dados | |
| **tudo** o que a equipe faz no painel, inclusive consultas | |

Cada linha guarda: quem (usuário ou administrador(a)), **perfil no momento** (gestor, cuidador, rede, profissional, empresa — vem da autorização central, sem consulta extra), evento, método, rota em forma de padrão (`/pessoas/{id}/registros`), o `{id}`, pessoa cuidada ou empresa envolvida, status, tempo de resposta, IP, app ou navegador, aparelho.

**Nunca** guarda corpo da requisição, parâmetros de busca, códigos de acesso nem dado de saúde.

- **Guarda**: `logs.dias` (padrão 365; mínimo forçado de 183 dias = 6 meses, Marco Civil art. 15). A limpeza roda com as tarefas periódicas.
- **Quem acessou os dados de uma pessoa**: em *Pessoas cuidadas → detalhe*, os últimos 30 dias (dias e quantidade). Serve para responder ao titular.
- **Investigar**: no log, clique numa linha e filtre "tudo deste usuário / IP / sessão". Os filtros ficam no endereço da página (dá para mandar o link a um colega com acesso).

### ⚖️ Risco trabalhista
O log é ferramenta de **segurança da plataforma**. O painel não calcula horas, tempo conectado, atrasos, frequência nem ranking de cuidador(a), e as leituras ficam sem horário justamente para não virar medição de tempo. **Não repasse relatórios do log a gestores(as) ou empresas para acompanhar cuidador(a) MEI/autônomo(a)**: seria prova de controle e subordinação. Pedidos externos (titular, Justiça) seguem pelo encarregado(a) de dados.

## 5. Segurança

- Sessão do painel separada da do app: cookie `cuidados_admin` httpOnly, `SameSite=Strict`, `Secure`; 8 h no máximo, cai após 30 min parada (`admin.sessao_horas`, `admin.inatividade_min`). Sem Bearer.
- Escrita exige o cabeçalho `X-Cuidados-Admin` e, se o navegador mandar `Origin`, que seja o próprio site.
- Só e-mail **confirmado** pelo Google; 10 recusas em 15 min no mesmo IP bloqueiam novas tentativas por 15 min.
- Conta bloqueada pelo painel perde todas as sessões e não entra (código ou Google) até ser desbloqueada.
- Testes: `php api/tests/admin.php` (68 verificações: separação das sessões, papéis, bloqueio, ausência de dados de saúde, log, auditoria, limite de tentativas).

## 6. Correção incluída

`Support\Migracoes` não registrava em `esquema_versoes` os arquivos depois do `001`, então o `002_google.sql` era reaplicado a cada execução das tarefas e falhava com "coluna duplicada" — e as limpezas periódicas (códigos e sessões vencidas) não chegavam a rodar. Agora cada arquivo é registrado, e "já existe" conta como aplicado, então o banco de produção se acerta sozinho na próxima execução.

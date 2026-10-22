# Publicar na KingHost (plano de revenda)

O app roda na hospedagem **PHP** comum: não precisa de Node.js, Composer nem SSH na hospedagem.
A primeira instalação é feita **uma vez**; depois, cada envio ao GitHub publica sozinho (ver o fim deste guia).

> Os nomes dos menus podem variar um pouco conforme a versão do painel da KingHost. Se não achar algum item, o suporte (chat/WhatsApp, 24 h) resolve rápido: os pedidos estão escritos prontos para copiar.

---

## Etapa 1 — Criar a hospedagem do app (painel da revenda)

**Recomendado:** criar `cuidados.ahvita.com` como **hospedagem própria** dentro da revenda (um cliente/plano só para o app), e não como subpasta do site `ahvita.com`.
- Fica com FTP, banco e e-mail próprios, isolados do site institucional (dado de saúde separado).
- A raiz do FTP fica limpa: `www/` (pública) e, ao lado, `privado/`. É exatamente a estrutura que o app espera.
- Evita o erro 403 da tentativa anterior (arquivos dentro da pasta do site principal).

Passos:
1. Painel da revenda → **Clientes / Planos** → criar uma hospedagem **Linux** para o domínio `cuidados.ahvita.com`.
2. No DNS de `ahvita.com`, o subdomínio `cuidados` precisa apontar para essa hospedagem (se o DNS está na própria KingHost, isso costuma ser automático).
3. Anote os dados de **FTP** que o painel mostra: servidor (ex.: `ftp.cuidados.ahvita.com`), usuário e senha. Eles vão para o GitHub na Etapa 5.

> Se preferir manter como subdomínio do `ahvita.com`, me diga qual é a pasta pública dele (ex.: `/www/cuidados/`). O app funciona, mas preciso ajustar o caminho da pasta `privado` para ela ficar fora da área pública.

## Etapa 2 — PHP e SSL

1. **Versão do PHP:** no painel da hospedagem do app → **PHP** (ou "Linguagens") → escolher **8.1 ou superior** (8.2/8.3 também servem).
2. **Limites de envio** (fotos de até 8 MB): no mesmo lugar, se houver edição de `php.ini`, ajustar:
   ```
   upload_max_filesize = 10M
   post_max_size = 12M
   ```
3. **SSL:** painel → **SSL** / **Certificados** → ativar o **certificado gratuito (Let's Encrypt)** para `cuidados.ahvita.com`. Sem HTTPS o login não funciona (o cookie de sessão é seguro).

Pedido pronto para o suporte, se precisar:
> "Na hospedagem cuidados.ahvita.com, preciso de: PHP 8.1 ou superior; extensões pdo_mysql, curl, fileinfo, mbstring e openssl; mod_rewrite e mod_headers ativos; upload_max_filesize 10M e post_max_size 12M; SSL gratuito ativo. Também preciso confirmar que posso criar uma pasta ao lado de www (fora da área pública) e qual é o caminho completo da raiz da conta (ex.: /home/usuario)."

## Etapa 3 — Banco de dados

1. Painel → **Bancos de dados** → **MySQL** → **Criar banco**.
   - Nome sugerido: `cuidados` (o painel pode acrescentar um prefixo).
   - Crie o usuário com **senha forte** (gerenciador de senhas, 24+ caracteres).
2. Anote os 4 dados que o painel mostra: **servidor (host)**, **nome do banco**, **usuário**, **senha**. O host da KingHost normalmente **não** é `localhost`: use exatamente o que aparece no painel.
3. **Criar as tabelas** (escolha uma forma):
   - **phpMyAdmin** (pelo painel) → selecione o banco → aba **Importar** → arquivo `db/001_schema.sql` (baixe do GitHub: repositório → pasta `db` → arquivo → botão de download) → **Executar**. Devem aparecer 25 tabelas.
   - **ou deixar o cron criar** (Etapa 7): na primeira execução ele aplica o `001_schema.sql` sozinho.
   - O aviso do phpMyAdmin sobre "controluser" pode ser ignorado. Não há gatilhos no esquema, então o erro #1419 da versão anterior não acontece.

## Etapa 4 — E-mail para o código de acesso

1. Painel → **E-mail** → criar a conta `nao-responda@cuidados.ahvita.com` (ou `@ahvita.com`) com senha forte.
2. Anote o **servidor SMTP**, a **porta** (normalmente 587 com TLS) e a senha.

**Sobre SMS/WhatsApp:** para a fase de validação, recomendo começar **só com e-mail** (sem custo e sem transferência internacional de dados). Para cuidadores(as) sem e-mail, ligar a Twilio depois (`codigo.canais` + chaves `twilio`), sabendo que é serviço pago e fora do Brasil (citar na política de privacidade).

## Etapa 5 — Ligar o GitHub à KingHost

Feita uma vez, no repositório **marcelookuma/AhvitaCuidados**. Os segredos ficam guardados criptografados no GitHub: ninguém (nem você) consegue lê-los de volta depois de salvos, só substituí-los.

### 5.1 Separar os dados (vêm da Etapa 1)
- **Servidor FTP**: só o nome, sem `ftp://` e sem porta (ex.: `ftp.cuidados.ahvita.com`).
- **Usuário** e **senha** do FTP.
- **Pasta pública**: abra o FTP (FileZilla ou gerenciador de arquivos do painel) e veja o nome da pasta onde ficam os arquivos do site, normalmente `www`. O valor é `/www/` (com barra no início e no fim).
- **Pasta privada**: `/privado/` (ao lado da `www`; o GitHub cria se não existir).

### 5.2 Cadastrar os segredos
1. Repositório → aba **Settings** (engrenagem, no topo; só aparece para quem é dono).
2. Menu da esquerda → **Secrets and variables** → **Actions**.
3. Aba **Secrets** → botão **New repository secret**. Crie um de cada vez:

| Name (exatamente assim) | Secret |
|---|---|
| `FTP_SERVIDOR` | `ftp.cuidados.ahvita.com` |
| `FTP_USUARIO` | usuário FTP |
| `FTP_SENHA` | senha FTP |

4. Clique em **Add secret** após cada um.

### 5.3 Cadastrar as variáveis
Mesma tela → aba **Variables** → **New repository variable**:

| Name | Value | Obrigatória? |
|---|---|---|
| `KINGHOST_PASTA_WWW` | `/www/` | sim — sem ela nada é publicado |
| `KINGHOST_PASTA_PRIVADO` | `/privado/` | sim |
| `URL_APP` | `https://cuidados.ahvita.com` | recomendada (confere se subiu) |
| `FTP_PROTOCOLO` | `ftp` | só se o FTPS falhar (5.6) |
| `PUBLICAR_AUTOMATICO` | `sim` | **não crie agora** (ver 5.5) |

Crie como **variável do repositório** (não de "environment"): o fluxo só enxerga as do repositório.

### 5.4 Primeira publicação
1. Aba **Actions** → à esquerda, **Testar e publicar** → botão **Run workflow** (à direita) → branch `main` → **Run workflow**.
2. Clique na execução que aparece. São duas etapas:
   - **testes** (≈ 3 min): banco de teste, 91 verificações da API, vocabulário e build.
   - **publicar** (≈ 3–8 min na primeira vez, pois envia todos os arquivos; depois só o que mudou).
3. Tudo verde = arquivos na KingHost. Siga para a Etapa 6 (arquivo de configuração).

O passo "Conferir se está no ar" chama `/api/v1/saude`, que responde mesmo antes do `configuracao.php` existir. Se ele passou, o servidor está servindo a API corretamente.

### 5.5 Como funcionam as próximas publicações
- **Padrão (recomendado na validação):** cada envio para a `main` **só testa**. Para colocar no ar, você clica em **Run workflow** — é a sua aprovação. (A aprovação automática do GitHub, "required reviewers", não está disponível em repositório privado nos planos Free, Pro e Team.)
- **Automático:** crie a variável `PUBLICAR_AUTOMATICO` = `sim`. Aí todo envio que passar nos testes vai direto ao ar. Para voltar ao manual, apague a variável.
- Para desfazer uma publicação ruim: aba **Actions** → uma execução anterior que estava boa → **Re-run all jobs** (republica aquela versão).

### 5.6 Se falhar
Clique no passo vermelho para ver a mensagem:

| Mensagem (ou parecida) | O que fazer |
|---|---|
| `530 Login authentication failed` | usuário ou senha FTP errados: edite `FTP_USUARIO`/`FTP_SENHA` |
| `ENOTFOUND` / `getaddrinfo` | nome do servidor errado em `FTP_SERVIDOR` (sem `ftp://`) |
| `ETIMEDOUT` / `ECONNREFUSED` | servidor errado, ou a KingHost bloqueou o acesso: pergunte ao suporte se o FTP aceita conexões externas (o GitHub conecta de fora do Brasil) |
| erro de TLS / `certificate` / `AUTH TLS` | crie `FTP_PROTOCOLO` = `ftp` (sem criptografia: troque a senha FTP depois de estabilizar e peça FTPS ao suporte) |
| `550` / `No such file or directory` | pasta errada em `KINGHOST_PASTA_WWW`/`KINGHOST_PASTA_PRIVADO` |
| "Conferir se está no ar" com `404` | arquivos foram para a pasta errada (não é a pública do domínio) |
| "Conferir se está no ar" com `500` | PHP abaixo de 8.1 (Etapa 2) |
| job **publicar** aparece como *skipped* | normal num envio comum (5.5), ou falta `KINGHOST_PASTA_WWW` |
| job **testes** vermelho | algo no código: me mande o texto do erro |

## Etapa 6 — Arquivo de configuração (só no servidor, nunca no GitHub)

1. Pelo FTP (FileZilla, ou o gerenciador de arquivos do painel), abra `privado/config/`.
2. Baixe `configuracao.exemplo.php`, salve uma cópia como **`configuracao.php`** e preencha:

```php
<?php
return [
    'ambiente' => 'producao',
    'url_app'  => 'https://cuidados.ahvita.com',
    'fuso'     => 'America/Sao_Paulo',
    'app'   => ['segredo' => 'COLE-AQUI-64-CARACTERES-ALEATORIOS'],
    'db'    => [
        'host'    => 'HOST-DO-PAINEL',
        'porta'   => 3306,
        'nome'    => 'NOME-DO-BANCO',
        'usuario' => 'USUARIO-DO-BANCO',
        'senha'   => 'SENHA-DO-BANCO',
    ],
    'codigo' => ['canais' => ['email']],
    'email'  => [
        'remetente' => 'Ahvita Cuidados <nao-responda@cuidados.ahvita.com>',
        'smtp' => [
            'host'      => 'SMTP-DO-PAINEL',
            'porta'     => 587,
            'seguranca' => 'tls',
            'usuario'   => 'nao-responda@cuidados.ahvita.com',
            'senha'     => 'SENHA-DO-EMAIL',
        ],
    ],
    'termos' => ['versao' => '2026-10'],
];
```
3. Envie o `configuracao.php` para `privado/config/`.

- `app.segredo`: gere 64 caracteres aleatórios no gerenciador de senhas (sem espaços). Não reutilize senha nenhuma.
- **Nunca** use `'ambiente' => 'teste'` com pessoas reais: em teste o código de acesso aparece na tela.
- A publicação automática **não sobrescreve nem apaga** este arquivo.

## Etapa 7 — Cron (tarefas a cada 5 minutos)

Painel → **Agendador de tarefas** (ou "Cron") → nova tarefa:
- Frequência: **a cada 5 minutos**
- Comando:
  ```
  php /home/USUARIO/privado/bin/agendador.php
  ```
  Troque `/home/USUARIO` pelo caminho da raiz da conta (o painel ou o suporte informa). Se o painel pedir o PHP com versão, use o da Etapa 2 (ex.: `php8.2`).

O cron:
- aplica migrações novas do banco (inclusive o `001_schema.sql`, se você não importou na Etapa 3);
- gera o alerta "sem resposta no prazo combinado" para gestor(a) e empresa;
- limpa códigos e sessões vencidos.

Sem cron o app continua funcionando, mas o alerta só aparece quando alguém abre o painel, e as migrações futuras não entram sozinhas.

## Etapa 8 — Conferir

| Teste | Esperado |
|---|---|
| `https://cuidados.ahvita.com/api/v1/saude` | `{"ok":true,"versao":"1.0.0"}` |
| `https://cuidados.ahvita.com/` | tela **Entrar** do app |
| `http://cuidados.ahvita.com/` (sem s) | redireciona para `https://` |
| `https://cuidados.ahvita.com/privado/config/configuracao.php` | erro 403 ou 404 (**nunca** mostrar o conteúdo) |
| Entrar com seu e-mail | o código chega em até 1 minuto (veja o spam) |
| Cadastrar uma pessoa de teste e registrar algo | aparece na conversa e na linha do tempo |
| phpMyAdmin → tabela `auditoria` | linhas novas a cada ação |

Depois do teste, exclua a pessoa de teste em **Cadastro e privacidade**.

## Problemas comuns
| Sintoma | Causa provável |
|---|---|
| 403 "Acesso negado" no app | arquivos na pasta errada, ou faltou o `.htaccess` (arquivo oculto: no FileZilla, "Servidor → Forçar exibir arquivos ocultos") |
| Página em branco ou erro 500 em `/api/v1/saude` | PHP abaixo de 8.1, ou `configuracao.php` com erro de digitação (vírgula, aspas) |
| `{"erro":{"codigo":"banco"…}}` | dados do banco errados no `configuracao.php` (confira o host do painel) |
| Código não chega por e-mail | dados SMTP errados; teste a conta no webmail; olhe o spam |
| Login volta para "Entrar" | site sem HTTPS (cookie seguro) |
| Foto não envia | limites de upload do PHP (Etapa 2) |
| GitHub: falha no passo "Enviar … (FTP)" | dados FTP errados, ou a KingHost recusou FTPS: crie a variável `FTP_PROTOCOLO` = `ftp` |
| "Envio bloqueado por segurança" | proxy/antivírus removendo o cabeçalho `X-Cuidados`; teste em outra rede |

## Demonstração (opcional, nunca com dados reais)
Em um banco e hospedagem separados: `'ambiente' => 'teste'`, `'codigo' => ['canais' => ['log','email'], 'teste_fixo' => '123456']` e `php privado/bin/demo.php`. Entrar com (11) 90000-0001 (gestor), 0002 (cuidadora) ou 0003 (empresa), código 123456.

---

## Publicação automática pelo GitHub (depois da primeira instalação)
O arquivo `.github/workflows/publicar.yml` faz, a cada envio para a branch `main`:
1. **Testa**: sintaxe PHP, banco MariaDB de teste, as 91 verificações da API, a trilha de auditoria, o vocabulário e o build do app.
2. **Publica** quando você clica em Run workflow (ou a cada envio, se `PUBLICAR_AUTOMATICO` = `sim`), só se os testes passarem: envia `www/` e `privado/` por FTP, sem tocar em `privado/config/configuracao.php` nem em `privado/midias/`.
3. **Confere** se `URL_APP/api/v1/saude` responde.

As migrações do banco (`db/002_*.sql` em diante) são aplicadas pelo cron em até 5 minutos depois da publicação.

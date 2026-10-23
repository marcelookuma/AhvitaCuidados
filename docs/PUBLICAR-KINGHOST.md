# Publicar na KingHost (plano de revenda)

O app roda na hospedagem **PHP** comum: não precisa de Node.js, Composer nem SSH na hospedagem.
A primeira instalação é feita **uma vez**; depois, cada envio ao GitHub publica sozinho (ver o fim deste guia).

> Os nomes dos menus podem variar um pouco conforme a versão do painel da KingHost. Se não achar algum item, o suporte (chat/WhatsApp, 24 h) resolve rápido: os pedidos estão escritos prontos para copiar.

---

## Etapa 1 — Onde o app fica na hospedagem

Desde 22/10/2026, `cuidados.ahvita.com` tem **hospedagem própria** na KingHost, separada da de `ahvita.com`.

O FTP da KingHost **abre dentro da `www`**, mas permite subir um nível (pasta `..`) até a raiz da conta e criar pastas ali. Por isso:

```
(raiz da conta)                    ← o GitHub envia a partir daqui ("../" de onde o FTP abre)
├── www/                           ← pasta pública (KINGHOST_PASTA_WWW = /www/); o FTP abre aqui (FTP_ABRE_EM = /www/)
│   ├── index.html, assets/, sw.js, .htaccess…
│   └── api/index.php              (porta de entrada da API)
├── cuidados_privado/              ← FORA da www (KINGHOST_PASTA_PRIVADO = /cuidados_privado/)
│   ├── src/  bin/  db/            (só arquivos .php)
│   ├── config/configuracao.php    (criado à mão, só no servidor)
│   └── midias/                    (criada pela API)
└── .ftp-deploy-sync-state.json    (controle do envio)
```

- Nada da pasta privada pode ser aberto pela internet: ela fica fora da `www`. Mesmo assim, ela só contém arquivos `.php`, com bloqueio 404, como segunda proteção.
- Antes de cada envio, o GitHub confere se a pasta `www` existe na raiz da conta e para com erro se as pastas não baterem.
- Se a KingHost um dia não deixar mais subir para a raiz, o modo de reserva é `KINGHOST_PASTA_WWW` = `/`, `FTP_ABRE_EM` = `/`, `KINGHOST_PASTA_PRIVADO` = `/cuidados_privado/` e `PASTA_PRIVADA_DENTRO_DA_WWW` = `sim`. Use só para testes.

**DNS:** `cuidados` é um subdomínio de `ahvita.com`. Na zona de DNS de **ahvita.com**, o registro `cuidados` (tipo A ou CNAME) precisa apontar para a **nova** hospedagem. O painel da nova hospedagem mostra o IP ou o endereço a usar. A troca pode levar até algumas horas para valer.

## Etapa 2 — PHP e SSL

1. **Versão do PHP:** no painel da hospedagem do app → **PHP** (ou "Linguagens") → escolher **8.1 ou superior** (8.2/8.3 também servem).
2. **Limites de envio** (fotos de até 8 MB): no mesmo lugar, se houver edição de `php.ini`, ajustar:
   ```
   upload_max_filesize = 10M
   post_max_size = 12M
   ```
3. **Teste do PHP:** envie pelo FileZilla um `teste.php` para `/www/` com a linha `<?php echo 'php ok ' . PHP_VERSION;` e abra `https://cuidados.ahvita.com/teste.php`. Precisa aparecer "php ok 8.x". Depois, apague o arquivo.
4. **SSL:** painel → **SSL** / **Certificados** → ativar o **certificado gratuito (Let's Encrypt)** para `cuidados.ahvita.com`. Sem HTTPS o login não funciona (o cookie de sessão é seguro).

Pedido pronto para o suporte, se precisar:
> "Na hospedagem do domínio cuidados.ahvita.com, preciso de: PHP 8.1 ou superior; extensões pdo_mysql, curl, fileinfo, mbstring e openssl; mod_rewrite e mod_headers ativos; upload_max_filesize 10M e post_max_size 12M; SSL gratuito ativo. Também preciso confirmar que posso criar a pasta /cuidados_privado na raiz do FTP (fora de /www), que o PHP do site consegue ler e gravar nela, e que o FTP aceita conexões do GitHub Actions (servidores fora do Brasil)."

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
- **Pasta pública**: `/www/`. **Onde o FTP abre**: `/www/`.
- **Pasta privada**: `/cuidados_privado/` (na raiz do FTP; o GitHub cria se não existir).

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
| `FTP_ABRE_EM` | `/www/` | sim (o FTP da KingHost abre dentro da www) |
| `PASTA_PRIVADA_DENTRO_DA_WWW` | — | **não crie** (só no modo de reserva, ver Etapa 1) |
| `ENVIO_FTP` | `sim` | sim, para o GitHub enviar por FTP (sem ela, só gera o pacote) |
| `KINGHOST_PASTA_PRIVADO` | `/cuidados_privado/` | sim |
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

O passo "Conferir se está no ar" chama `/api/index.php?r=/saude`, que responde mesmo antes do `configuracao.php` existir. Se ele passou, o servidor está servindo a API corretamente.

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
| "Conferir se está no ar" com `500` | PHP abaixo de 8.1 (Etapa 2), ou a mensagem "Pasta privada da API não encontrada": confira `KINGHOST_PASTA_PRIVADO` e se a hospedagem permite ler fora de `/www` (pergunte ao suporte) |
| job **publicar** aparece como *skipped* | normal num envio comum (5.5), ou falta `KINGHOST_PASTA_WWW` |
| job **testes** vermelho | algo no código: me mande o texto do erro |

### 5.7 Envio manual pelo FileZilla (alternativa)

Toda execução de **Testar e publicar** também gera um pacote pronto: abra a execução → fim da página → **Artifacts** → `pacote-kinghost-N` (.zip). Ele espelha a pasta inicial do FTP. Descompacte e arraste **o conteúdo** do pacote (menos `COMO-ENVIAR.txt`) para a pasta inicial do FTP no FileZilla → **Sobrescrever**. Nunca apague no servidor `cuidados_privado/config/configuracao.php` nem `cuidados_privado/midias/`.

### 5.8 Lições da primeira tentativa (subdomínio dentro de ahvita.com)

- **Bloqueio de IP internacional no FTP:** o GitHub conecta de fora do Brasil. Libere no painel na hora de publicar e volte o bloqueio depois. A KingHost também recusa uma segunda conexão logo em seguida, por isso o envio é feito numa conexão só.
- **nginx:** o servidor pode ignorar `.htaccess`. O app não depende dele: a API responde em `/api/index.php?r=/…`, as telas usam `#` (ex.: `/#/entrar`), fotos e áudios são gravados como `.php` com bloqueio e os scripts de `bin/` só rodam pela linha de comando.
- **PHP:** confira que `.php` executa (teste da Etapa 2) antes de publicar.

## Etapa 6 — Arquivo de configuração (só no servidor, nunca no GitHub)

1. Pelo FTP (FileZilla, ou o gerenciador de arquivos do painel), abra `/cuidados_privado/config/`.
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
3. Envie o `configuracao.php` para `/cuidados_privado/config/`.

- `app.segredo`: gere 64 caracteres aleatórios no gerenciador de senhas (sem espaços). Não reutilize senha nenhuma.
- **Nunca** use `'ambiente' => 'teste'` com pessoas reais: em teste o código de acesso aparece na tela.
- A publicação automática **não sobrescreve nem apaga** este arquivo.

## Etapa 6b — Entrar com Google (opcional)

Sem esta etapa o app funciona igual, só sem o botão **Continuar com o Google**.

1. Em <https://console.cloud.google.com> crie (ou escolha) um projeto, por exemplo **Ahvita Cuidados**.
2. **Google Auth Platform → Branding** (tela de consentimento): nome do app, e-mail de suporte, logo, e os links `https://cuidados.ahvita.com/privacidade.html` e `/termos.html`. Em **Público**, escolha **Externo**. Os escopos usados são só `openid`, `email` e `profile`, que não exigem verificação do Google.
3. Enquanto o app estiver em **Teste**, só entram os e-mails cadastrados em **Usuários de teste**. Para o público geral, clique em **Publicar app**.
4. **Clientes → Criar cliente → Aplicativo da Web**. Em **Origens JavaScript autorizadas** coloque exatamente o endereço do app, sem barra no final: `https://cuidados.ahvita.com` (e `http://localhost:5173` se for testar no computador). **URIs de redirecionamento**: deixe em branco (o app usa a janela do Google, sem redirecionar).
5. Copie o **ID do cliente** (termina em `.apps.googleusercontent.com`) e coloque no `configuracao.php`:
   ```php
   'google' => ['client_id' => '1234567890-abc123.apps.googleusercontent.com'],
   ```
   Não há segredo para guardar: esse ID é público. **Não** copie a "chave secreta do cliente" para o servidor — ela não é usada.
6. Abra `https://cuidados.ahvita.com/api/index.php?r=/auth/canais`: deve aparecer `"google":"…apps.googleusercontent.com"`. Na tela **Entrar**, o botão aparece abaixo de "ou".

O servidor baixa as chaves públicas do Google (`www.googleapis.com`) e guarda por algumas horas em `cuidados_privado/cache/`. Se a KingHost bloquear saída para a internet, o botão mostra "Não conseguimos falar com o Google agora" e o código por celular/e-mail continua funcionando.

## Etapa 7 — Tarefas a cada 5 minutos (não precisa de cron)

O app tem tarefas periódicas: atualizar o banco depois de cada publicação, avisar quando um atendimento está sem confirmação e limpar códigos e sessões vencidos.

**Sem cron (padrão, plano de revenda da KingHost):** a própria API roda essas tarefas na primeira requisição depois de 5 minutos. Com o app aberto, ele faz requisições a cada poucos segundos, então na prática roda sozinho. Na **primeira** visita (ex.: abrir `https://cuidados.ahvita.com/api/index.php?r=/saude`), a API cria as 25 tabelas do banco.

Limite: se ninguém abrir o app por horas, os alertas só são calculados na próxima abertura. Os painéis do gestor e da empresa também conferem a cobertura quando são abertos.

**Opcional, para rodar mesmo sem ninguém usando:** um serviço gratuito como o cron-job.org chama a API a cada 5 minutos.
1. Gere uma chave longa: `php -r "echo bin2hex(random_bytes(32));"` (ou qualquer gerador de 64 caracteres aleatórios).
2. Em `configuracao.php`: `'tarefas' => ['automaticas' => true, 'chave' => 'A-CHAVE'],`
3. No cron-job.org: URL `https://cuidados.ahvita.com/api/v1/sistema/tarefas`, método **POST**, a cada 5 min, cabeçalho `X-Chave-Tarefas: A-CHAVE`.

A chamada não leva nem devolve dados de pessoas (só quantos alertas foram gerados). Com a chave errada ou vazia, a API responde 404.

**Se a hospedagem tiver cron:** `php /home/USUARIO/<pasta privada>/bin/agendador.php` a cada 5 minutos.

## Etapa 8 — Conferir

| Teste | Esperado |
|---|---|
| `https://cuidados.ahvita.com/api/index.php?r=/saude` | `{"ok":true,"versao":"1.0.0"}` |
| `https://cuidados.ahvita.com/` | tela **Entrar** do app |
| `http://cuidados.ahvita.com/` (sem s) | redireciona para `https://` |
| `https://ahvita.com/cuidados/` | redireciona para `https://cuidados.ahvita.com/` |
| `https://ahvita.com/cuidados_privado/config/configuracao.php` | erro 404 (a pasta está fora da área pública) |
| Entrar com seu e-mail | o código chega em até 1 minuto (veja o spam) |
| Cadastrar uma pessoa de teste e registrar algo | aparece na conversa e na linha do tempo |
| phpMyAdmin → tabela `auditoria` | linhas novas a cada ação |

Depois do teste, exclua a pessoa de teste em **Cadastro e privacidade**.

## Problemas comuns
| Sintoma | Causa provável |
|---|---|
| 403 "Acesso negado" no app | arquivos na pasta errada, faltou o `.htaccess` (arquivo oculto: no FileZilla, "Servidor → Forçar exibir arquivos ocultos"), ou uma regra do `/www/.htaccess` do site principal bloqueando a subpasta |
| Página em branco ou erro 500 em `/api/index.php?r=/saude` | PHP abaixo de 8.1, ou `configuracao.php` com erro de digitação (vírgula, aspas) |
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
2. **Publica** quando você clica em Run workflow (ou a cada envio, se `PUBLICAR_AUTOMATICO` = `sim`), só se os testes passarem: envia `www/` e `privado/` por FTP, sem tocar em `config/configuracao.php` nem em `midias/` da pasta privada.
3. **Confere** se `URL_APP/api/index.php?r=/saude` responde.

As migrações do banco (`db/002_*.sql` em diante) são aplicadas pelo cron em até 5 minutos depois da publicação.

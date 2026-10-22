# Publicar na KingHost (plano de revenda)

O app roda na hospedagem **PHP** comum: não precisa de Node.js, Composer nem SSH na hospedagem.

## 0. Antes de começar (5 min)
1. No painel da revenda, crie (ou use) o domínio **cuidados.ahvita.com** num plano de hospedagem **Linux** com **PHP 8.1 ou superior**.
2. Ative o **SSL grátis** (Let's Encrypt) do domínio. Sem HTTPS o login não funciona (cookie seguro).
3. Confirme com o suporte, se tiver dúvida: *PHP 8.1+, extensões `pdo_mysql`, `curl`, `fileinfo`, `mbstring`, `openssl`, `mod_rewrite` ligado e permissão para pasta acima de `www`.* São padrão na KingHost.

## 1. Gerar o pacote (no seu computador ou pelo fornecedor)
```bash
npm install
npm run pacote:kinghost      # confere o vocabulário, compila o app e monta pacote-kinghost/
```

## 2. Banco de dados
1. Painel → **Bancos de dados** → **MySQL** → criar banco (ex.: `ahvita_cuidados`) e usuário com senha forte.
2. **phpMyAdmin** → selecione o banco → **Importar** → `privado/db/001_schema.sql`.
   - Os avisos sobre "controluser" do phpMyAdmin podem ser ignorados.
   - Não há gatilhos no esquema: o erro #1419 da versão anterior não acontece mais.

## 3. Arquivos (FTP)
| Do pacote | Para a hospedagem |
|---|---|
| `www/*` (inclusive os ocultos `.htaccess`) | pasta pública do domínio (ex.: `/www/` ou `/cuidados.ahvita.com/`) |
| `privado/` | **ao lado** da pasta pública, nunca dentro dela (ex.: `/privado/`) |

> Se a hospedagem não deixar criar pasta fora da pública, coloque `privado/` dentro e mantenha o `privado/.htaccess` (bloqueia o acesso web). Avise o fornecedor: é uma solução de contorno.

## 4. Configuração
1. Em `privado/config/`, copie `configuracao.exemplo.php` para `configuracao.php`.
2. Preencha: `db` (host, nome, usuário, senha do passo 2), `app.segredo` (64 caracteres aleatórios), `email.smtp` (crie a conta `nao-responda@ahvita.com` no painel) e, se for usar SMS/WhatsApp, `twilio`.
3. **Nunca** deixe `'ambiente' => 'teste'` com pessoas reais: em teste o código de acesso aparece na tela.

## 5. Cron (recomendado)
Painel → **Agendador de tarefas** → a cada 5 minutos:
```
php /home/<seu-usuario>/privado/bin/agendador.php
```
Gera os alertas de "sem resposta no prazo combinado" e limpa códigos e sessões vencidos. Sem cron o app continua correto (os painéis também verificam ao abrir), só que o alerta aparece quando alguém abre o painel.

## 6. Conferir
- `https://cuidados.ahvita.com/api/v1/saude` → `{"ok":true,…}`
- `https://cuidados.ahvita.com/privado/config/configuracao.php` → **precisa** dar erro (403/404).
- Entrar com seu celular ou e-mail e cadastrar uma pessoa cuidada de teste.

## Demonstração (opcional, nunca com dados reais)
Em um banco separado: `'ambiente' => 'teste'`, `'codigo' => ['canais' => ['log','email'], 'teste_fixo' => '123456']` e rode `php privado/bin/demo.php`. Entre com (11) 90000-0001 (gestor), 0002 (cuidadora) ou 0003 (empresa), código 123456.

## Problemas comuns
| Sintoma | Causa provável |
|---|---|
| 403 "Acesso negado" no app | arquivos na pasta errada, ou faltou o `.htaccess` (arquivo oculto) |
| Página em branco em `/api/v1/saude` | PHP abaixo de 8.1; mude a versão no painel |
| "Envio bloqueado por segurança" | proxy/antivírus removendo o cabeçalho `X-Cuidados`; teste em outra rede |
| Login volta para "Entrar" | site sem HTTPS (cookie `Secure`) |
| Foto não envia | limite de upload do PHP abaixo de 8 MB (`upload_max_filesize`, `post_max_size`) |

## Publicação automática pelo GitHub (depois da primeira instalação)
O arquivo `.github/workflows/publicar.yml` faz, a cada envio para a branch `main`:
1. **Testa**: sintaxe PHP, banco MariaDB de teste, as 91 verificações da API, a trilha de auditoria, o vocabulário e o build do app.
2. **Publica** (só se os testes passarem): envia `www/` e `privado/` por FTP, sem tocar em `privado/config/configuracao.php` nem em `privado/midias/`.
3. **Confere** se `URL_APP/api/v1/saude` responde.

As migrações do banco (`db/002_*.sql` em diante) são aplicadas pelo cron `bin/agendador.php` em até 5 minutos depois da publicação.

Configuração, uma vez só, em GitHub → repositório → Settings → Secrets and variables → Actions:
| Tipo | Nome | Exemplo |
|---|---|---|
| Secret | `FTP_SERVIDOR` | `ftp.ahvita.com` |
| Secret | `FTP_USUARIO` | usuário FTP do domínio |
| Secret | `FTP_SENHA` | senha FTP |
| Variable | `KINGHOST_PASTA_WWW` | `/www/` (pasta pública do subdomínio) |
| Variable | `KINGHOST_PASTA_PRIVADO` | `/privado/` |
| Variable | `URL_APP` | `https://cuidados.ahvita.com` |
| Variable | `FTP_PROTOCOLO` | `ftps` (use `ftp` só se a KingHost recusar FTPS) |

Recomendado: em Settings → Environments, criar `producao` com aprovação obrigatória, para nada ir ao ar sem um clique seu.

# Ahvita Cuidados — MVP v2

Coordenação de cuidados para pessoas de qualquer idade. Web responsiva (PWA) com **Cuidador(a) na versão Conversa**, **Gestor(a)** e **Empresa**; API PHP + MySQL/MariaDB para a **KingHost (revenda)**; núcleo compartilhado pronto para o app Android/iOS.

| Documento | Conteúdo |
|---|---|
| `docs/ARQUITETURA.md` | arquitetura, escalabilidade, segurança, estrutura de arquivos |
| `docs/API.md` | os 62 endpoints |
| `docs/BANCO.md` | esquema e diagrama |
| `docs/PUBLICAR-KINGHOST.md` | passo a passo de publicação |
| `docs/DECISOES-E-RISCOS.md` | decisões, ⚖️ riscos trabalhistas e LGPD, fora do MVP |
| `docs/ADMIN.md` | painel `cuidados.ahvita.com/admin`: acesso com Google, papéis, log de acessos |
| `apps/mobile/README.md` | plano do app nativo |

## Rodar localmente
Requisitos: PHP 8.1+ (pdo_mysql, curl, fileinfo, mbstring), MariaDB/MySQL, Node 18+.

```bash
# 1. Banco
mysql -e "CREATE DATABASE cuidados CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
cp api/config/configuracao.exemplo.php api/config/configuracao.php   # ambiente 'teste', teste_fixo '123456'
php api/bin/instalar.php --demo

# 2. API (porta 8099)
cd api && php -S 127.0.0.1:8099 dev-router.php

# 3. App web (outra aba; proxy /api → 8099)
npm install && npm run dev          # http://localhost:5173 (painel: http://localhost:5173/admin/)
```
Demonstração: (11) 90000-0001 gestor(a) Marcos · 0002 cuidadora Fernanda (MEI) · 0003 empresa CuidaBem — código `123456`.

## Comandos
```bash
npm run build              # confere vocabulário + tipos + compila o app
npm run verificar:termos   # falha se aparecer "trabalhando", "jornada", "patrão", "Família"…
php api/tests/fluxo.php http://127.0.0.1:8099   # 108 verificações de ponta a ponta (banco vazio)
php api/tests/admin.php http://127.0.0.1:8099   # 68 verificações do painel /admin (depois do fluxo)
php api/tests/rede.php http://127.0.0.1:8099    # 45 verificações dos pedidos de rede empresa ↔ cuidador(a)
php api/tests/conta.php http://127.0.0.1:8099   # Meus dados (editar o próprio cadastro); SEM_CELULAR_URL testa servidor sem SMS
php api/tests/clientes.php http://127.0.0.1:8099 # 40 verificações do vínculo empresa ↔ pessoa cuidada
php api/tests/convites.php http://127.0.0.1:8099 # 15 verificações de "Convites para você" no início
php api/bin/auditoria.php  # confere a trilha de auditoria encadeada
npm run pacote:kinghost    # gera pacote-kinghost/ (www/ + privado/)
```

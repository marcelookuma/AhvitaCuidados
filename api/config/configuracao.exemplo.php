<?php
/**
 * Copie para configuracao.php (mesma pasta) e preencha.
 * Esta pasta fica FORA da pasta pública (www). Nunca envie configuracao.php ao Git.
 */
return [
    'ambiente' => 'producao',                 // 'teste' mostra o código de acesso na tela: nunca com pessoas reais
    'url_app' => 'https://cuidados.ahvita.com',
    'fuso' => 'America/Sao_Paulo',

    'app' => [
        // Gere com: php -r "echo bin2hex(random_bytes(32));"
        'segredo' => 'TROQUE-POR-64-CARACTERES-ALEATORIOS',
    ],

    // Painel KingHost → Bancos de dados → MySQL
    'db' => [
        'host' => 'mysql.ahvita.com',
        'porta' => 3306,
        'nome' => 'ahvita_cuidados',
        'usuario' => 'ahvita_cuidados',
        'senha' => '',
    ],

    'midias' => [
        'pasta' => null,          // padrão: privado/midias (fora da pasta pública)
        'max_bytes' => 8000000,
    ],

    // Formas de receber o código: 'email', 'sms', 'whatsapp' (estas duas pela Twilio Verify)
    'codigo' => [
        'canais' => ['whatsapp', 'sms', 'email'],
        'teste_fixo' => null,     // só em ambiente 'teste', ex.: '123456'
    ],

    'email' => [
        'remetente' => 'Ahvita Cuidados <nao-responda@ahvita.com>',
        'smtp' => [
            'host' => 'smtp.ahvita.com', // conta de e-mail criada no painel da KingHost
            'porta' => 587,
            'seguranca' => 'tls',        // 'tls' (587) ou 'ssl' (465)
            'usuario' => 'nao-responda@ahvita.com',
            'senha' => '',
            // KingHost: se o certificado do SMTP não for do nome acima, o envio falha no TLS.
            // Use o servidor indicado no painel de e-mail; em último caso, false (ainda criptografa).
            'verificar_certificado' => true,
        ],
        // Durante a instalação, true mostra na tela o motivo de falha no envio. Depois, false.
        'mostrar_erro' => false,
    ],

    'twilio' => [
        'sid' => '',
        'token' => '',
        'verify_sid' => '',
    ],

    // "Entrar com Google": ID do cliente OAuth (tipo "Aplicativo da Web") do Google Cloud.
    // Em "Origens JavaScript autorizadas" cadastre o endereço do app (url_app).
    // Vazio = o botão do Google não aparece. Não há segredo aqui: o ID é público.
    'google' => [
        'client_id' => '',        // ex.: '1234567890-abc123.apps.googleusercontent.com'
    ],

    // Só se o app móvel/web rodar em outro endereço (ex.: desenvolvimento)
    'cors' => [
        'origens' => [],
    ],

    'termos' => ['versao' => '2026-10'],

    // Painel de administração (https://cuidados.ahvita.com/admin) — entra só com Google.
    // 'donos': e-mails que sempre entram como dono(a) e não podem ser removidos pela tela.
    // Depois do primeiro acesso, o(a) dono(a) dá acesso aos demais pelo próprio painel.
    // Use contas Google com verificação em 2 etapas ligada.
    'admin' => [
        'donos' => [],            // ex.: ['voce@gmail.com']
        'sessao_horas' => 8,      // a sessão do painel dura no máximo 8 h…
        'inatividade_min' => 30,  // …e cai depois de 30 min sem uso
    ],

    // Log de acessos (todos os perfis + painel): dias de guarda. Mínimo 183 (Marco Civil, art. 15).
    'logs' => ['dias' => 365],

    // Tarefas a cada 5 min (banco, alertas de cobertura, limpeza).
    // Sem cron na hospedagem, a própria API roda sozinha: deixe 'automaticas' => true.
    // 'chave' (opcional): libera POST /api/v1/sistema/tarefas para um serviço externo
    // (ex.: cron-job.org) com o cabeçalho X-Chave-Tarefas. Gere igual a app.segredo.
    'tarefas' => ['automaticas' => true, 'chave' => ''],
];

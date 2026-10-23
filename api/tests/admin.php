<?php
declare(strict_types=1);

/**
 * Teste de ponta a ponta do painel /admin e do log de acessos.
 * Roda DEPOIS de tests/fluxo.php (usa as contas e pessoas que ele cria).
 * O servidor precisa ter: GOOGLE_CLIENT_ID, GOOGLE_CERTIFICADOS_ARQUIVO e ADMIN_DONOS=dono@ahvita.com
 *   php tests/admin.php http://127.0.0.1:8099 [r]
 */

$base = ($argv[1] ?? 'http://127.0.0.1:8099') . (($argv[2] ?? '') === 'r' ? '/api/index.php?r=' : '/api/v1');
$ok = 0; $falhas = 0;

function req(string $metodo, string $caminho, ?array $corpo = null, array $extra = []): array
{
    global $base;
    $ch = curl_init(str_contains($base, '?') ? $base . preg_replace('/\?/', '&', $caminho, 1) : $base . $caminho);
    $cab = $extra;
    if ($corpo !== null) {
        $cab[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cab, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
    $resp = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $corpoResp = substr($resp, $hs);
    $json = json_decode($corpoResp, true);
    return ['s' => $status, 'j' => is_array($json) ? $json : [], 'bruto' => $corpoResp, 'h' => substr($resp, 0, $hs)];
}

/** Requisição com a sessão do painel (cookie + cabeçalho anti-CSRF). */
function adm(string $metodo, string $caminho, ?string $cookie, ?array $corpo = null, bool $semCabecalho = false): array
{
    $extra = [];
    if ($cookie !== null) $extra[] = 'Cookie: cuidados_admin=' . $cookie;
    if (!$semCabecalho) $extra[] = 'X-Cuidados-Admin: 1';
    return req($metodo, $caminho, $corpo, $extra);
}

function confere(string $nome, bool $cond, mixed $info = null): void
{
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  ✓ $nome\n"; return; }
    $falhas++;
    echo "  ✗ $nome\n";
    if ($info !== null) echo '      ' . substr(json_encode($info, JSON_UNESCAPED_UNICODE), 0, 700) . "\n";
}

function cookieDe(array $r): ?string
{
    return preg_match('/cuidados_admin=([0-9a-f]{64})/', $r['h'], $m) ? $m[1] : null;
}

$gCliente = getenv('GOOGLE_CLIENT_ID') ?: '';
$gArquivo = getenv('GOOGLE_CERTIFICADOS_ARQUIVO') ?: '';
if ($gCliente === '' || $gArquivo === '') {
    echo "Defina GOOGLE_CLIENT_ID e GOOGLE_CERTIFICADOS_ARQUIVO (aqui e no servidor).\n";
    exit(1);
}
$k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => 'teste-admin'], $k, ['digest_alg' => 'sha256']);
openssl_x509_export(openssl_csr_sign($csr, null, $k, 1, ['digest_alg' => 'sha256']), $pem);
file_put_contents($gArquivo, json_encode(['kid-admin' => $pem]));
$b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$jwt = function (array $d) use ($b64, $k, $gCliente): string {
    $d += ['iss' => 'https://accounts.google.com', 'aud' => $gCliente, 'iat' => time(), 'exp' => time() + 3600, 'email_verified' => true];
    $base = $b64(json_encode(['alg' => 'RS256', 'kid' => 'kid-admin', 'typ' => 'JWT'])) . '.' . $b64(json_encode($d));
    openssl_sign($base, $ass, $k, OPENSSL_ALGO_SHA256);
    return $base . '.' . $b64($ass);
};
$pdo = new PDO('mysql:host=127.0.0.1;dbname=cuidados;charset=utf8mb4', 'cuidados', 'cuidados');
$pdo->exec("SET time_zone = '+00:00'");
$dono = ['sub' => '2000000000000000001', 'email' => 'Dono@Ahvita.com', 'name' => 'Dono Teste'];

echo "Preparação (contas próprias deste teste)\n";
function app(string $metodo, string $caminho, ?string $token, ?array $corpo = null): array
{
    return req($metodo, $caminho, $corpo, array_merge(['X-Cuidados: 1'], $token ? ['Authorization: Bearer ' . $token] : []));
}
function entrarApp(string $tel): string
{
    req('POST', '/auth/codigo', ['destino' => $tel]);
    return req('POST', '/auth/entrar', ['destino' => $tel, 'codigo' => '123456'])['j']['token'] ?? '';
}
$tG = entrarApp('11977770201');
app('PATCH', '/eu', $tG, ['nome' => 'Regina Prado', 'apelido' => 'Regina']);
app('POST', '/eu/termos', $tG, []);
$r = app('POST', '/pessoas', $tG, ['nome_completo' => 'Benedito Prado', 'apelido' => 'Seu Benedito', 'nascimento' => '1940-03-02', 'sexo' => 'masculino',
    'arranjo' => 'gestor_rede_cuidador', 'ficha' => ['alergias' => 'Penicilina'], 'consentimentos' => ['dados_saude' => true]]);
$pid = $r['j']['id'] ?? '';
$conv = function (array $d) use ($tG, $pid) { return app('POST', "/pessoas/$pid/convites", $tG, $d)['j']['codigo'] ?? ''; };
$cC = $conv(['papel' => 'cuidador', 'nome' => 'Joana', 'telefone' => '11977770202', 'modalidade' => 'mei']);
$cR = $conv(['papel' => 'rede', 'nome' => 'Paulo', 'telefone' => '11977770203', 'relacao' => 'Filho(a)']);
$conv(['papel' => 'profissional', 'nome' => 'Dra. Lia', 'especialidade' => 'fisioterapia']); // fica pendente
$tC = entrarApp('11977770202');
app('PATCH', '/eu', $tC, ['nome' => 'Joana Reis', 'apelido' => 'Joana']);
app('POST', "/convites/$cC/aceitar", $tC, []);
$tR = entrarApp('11977770203');
app('PATCH', '/eu', $tR, ['nome' => 'Paulo Prado', 'apelido' => 'Paulo']);
app('POST', "/convites/$cR/aceitar", $tR, []);
app('POST', "/pessoas/$pid/registros", $tC, ['id' => strtoupper(substr(bin2hex(random_bytes(13)), 0, 26)), 'tipo' => 'sinais', 'dados' => ['temperatura' => 38.4], 'em' => gmdate('c')]);
$feedC = app('GET', "/pessoas/$pid/feed", $tC);
$feedG = app('GET', "/pessoas/$pid/feed", $tG);
confere('cenário montado (gestor(a), cuidador(a) MEI e Rede de Apoio)', strlen($pid) === 26 && $feedC['s'] === 200 && $feedG['s'] === 200, [$pid, $feedC['s']]);

echo "Painel: entrar com Google\n";
$r = req('GET', '/admin/config');
confere('config pública informa o Google', $r['s'] === 200 && $r['j']['google'] === $gCliente && $r['j']['configurado'] === true, $r['j']);
confere('sem sessão → 401', adm('GET', '/admin/painel', null)['s'] === 401);
$r = req('POST', '/admin/entrar', ['credencial' => $jwt(['sub' => '2000000000000000009', 'email' => 'curioso@exemplo.com'])]);
confere('e-mail fora da lista → 403', $r['s'] === 403, $r['j']);
$r = req('POST', '/admin/entrar', ['credencial' => $jwt(['sub' => '2000000000000000008', 'email' => 'dono@ahvita.com', 'email_verified' => false])]);
confere('e-mail não confirmado → 403', $r['s'] === 403, $r['j']);
$recusas = (int)$pdo->query("SELECT COUNT(*) FROM log_acessos WHERE evento = 'admin.entrada_recusada'")->fetchColumn();
confere('recusas vão para o log de acessos', $recusas === 2, $recusas);

$r = req('POST', '/admin/entrar', ['credencial' => $jwt($dono)]);
$cDono = cookieDe($r);
confere('dono da configuração entra', $r['s'] === 200 && $cDono !== null && $r['j']['admin']['papel'] === 'dono' && $r['j']['admin']['email'] === 'dono@ahvita.com', $r['j']);
confere('cookie do painel: HttpOnly e SameSite=Strict', str_contains($r['h'], 'HttpOnly') && str_contains($r['h'], 'SameSite=Strict'));
$r = adm('GET', '/admin/eu', $cDono);
confere('/admin/eu', $r['s'] === 200 && $r['j']['admin']['google_vinculado'] === true, $r['j']);
$r = req('POST', '/admin/entrar', ['credencial' => $jwt(['sub' => '2000000000000000002'] + $dono)]);
confere('mesmo e-mail com outra conta Google → 403', $r['s'] === 403, $r['j']);

echo "Separação das sessões do app e do painel\n";
$tApp = (function () {
    req('POST', '/auth/codigo', ['destino' => '(11) 97777-0101']);
    return req('POST', '/auth/entrar', ['destino' => '(11) 97777-0101', 'codigo' => '123456'], ['X-Cuidados: 1'])['j']['token'] ?? '';
})();
confere('token do app não abre o painel', req('GET', '/admin/eu', null, ['Authorization: Bearer ' . $tApp])['s'] === 401);
confere('cookie do painel não abre o app', req('GET', '/eu', null, ['Cookie: cuidados_admin=' . $cDono])['s'] === 401);
$r = adm('POST', '/admin/sistema/tarefas', $cDono, [], true);
confere('escrita sem X-Cuidados-Admin → 403', $r['s'] === 403, $r['j']);

echo "Visão geral e sistema\n";
$r = adm('GET', '/admin/painel', $cDono);
confere('painel com números', $r['s'] === 200 && $r['j']['usuarios']['total'] > 0 && $r['j']['pessoas']['total'] >= 0 && count($r['j']['serie_30d']) === 30, $r['j']);
confere('usuários ativos hoje contados', ($r['j']['usuarios']['ativos_hoje'] ?? 0) > 0, $r['j']['usuarios'] ?? null);
confere('perfis ativos (gestor e cuidador)', isset($r['j']['perfis_ativos_30d']['gestor'], $r['j']['perfis_ativos_30d']['cuidador']), $r['j']['perfis_ativos_30d'] ?? null);
$r = adm('GET', '/admin/uso?dias=7', $cDono);
confere('uso por perfil e rota', $r['s'] === 200 && count($r['j']['rotas']) > 0 && count($r['j']['por_dia']) > 0, $r['j']);
$r = adm('GET', '/admin/sistema', $cDono);
confere('sistema: esquema em dia', $r['s'] === 200 && $r['j']['esquema']['pendentes'] === [] && count($r['j']['esquema']['aplicadas']) >= 3, $r['j']['esquema'] ?? $r['j']);
confere('sistema: sem segredos da configuração', !preg_match('/"(senha|segredo|sid|verify_sid|chave)"/i', $r['bruto']));
$r = adm('POST', '/admin/sistema/tarefas', $cDono, []);
confere('rodar tarefas agora', $r['s'] === 200 && isset($r['j']['alertas']), $r['j']);

echo "Usuários\n";
$gestor = $pdo->query("SELECT id, telefone FROM usuarios WHERE telefone = '+5511977770201'")->fetch(PDO::FETCH_ASSOC);
$r = adm('GET', '/admin/usuarios?q=' . urlencode(substr((string)$gestor['telefone'], -8)), $cDono);
confere('busca pelo celular', $r['s'] === 200 && in_array($gestor['id'], array_column($r['j']['itens'], 'id'), true), $r['j']);
$r = adm('GET', '/admin/usuarios?papel=cuidador', $cDono);
confere('filtro por papel', $r['s'] === 200 && $r['j']['total'] > 0 && in_array('cuidador', $r['j']['itens'][0]['papeis'], true), $r['j']['itens'][0] ?? $r['j']);
$r = adm('GET', '/admin/usuarios/' . $gestor['id'], $cDono);
confere('detalhe do usuário com vínculos e sessões', $r['s'] === 200 && count($r['j']['vinculos']) > 0 && isset($r['j']['sessoes'], $r['j']['consentimentos']), $r['j']);
confere('CPF mascarado', !isset($r['j']['usuario']['cpf']) || $r['j']['usuario']['cpf'] === null || str_contains($r['j']['usuario']['cpf'], '*'), $r['j']['usuario']);

// Bloquear: precisa de motivo, derruba as sessões e impede entrar
$cuid = $pdo->query("SELECT id, telefone FROM usuarios WHERE telefone = '+5511977770202'")->fetch(PDO::FETCH_ASSOC);
// O app limita 1 pedido de código a cada 30 s por número: o teste apaga o anterior antes de pedir outro.
$codigoNovo = function () use ($pdo, $cuid): array {
    $pdo->prepare('DELETE FROM otp_codigos WHERE destino = ?')->execute([$cuid['telefone']]);
    req('POST', '/auth/codigo', ['destino' => $cuid['telefone']]);
    return req('POST', '/auth/entrar', ['destino' => $cuid['telefone'], 'codigo' => '123456']);
};
$tCuid = $tC;
confere('cuidador(a) com sessão no app', req('GET', '/eu', null, ['Authorization: Bearer ' . $tCuid])['s'] === 200);
$r = adm('POST', "/admin/usuarios/{$cuid['id']}/bloquear", $cDono, ['motivo' => 'curto']);
confere('bloquear sem motivo suficiente → 422', $r['s'] === 422, $r['j']);
$r = adm('POST', "/admin/usuarios/{$cuid['id']}/bloquear", $cDono, ['motivo' => 'Teste automático de bloqueio por suspeita de acesso indevido']);
confere('bloquear com motivo', $r['s'] === 200, $r['j']);
confere('sessão do app cai ao bloquear', req('GET', '/eu', null, ['Authorization: Bearer ' . $tCuid])['s'] === 401);
$r = $codigoNovo();
confere('conta bloqueada não entra', $r['s'] === 403, $r['j']);
$r = adm('POST', "/admin/usuarios/{$cuid['id']}/desbloquear", $cDono, ['motivo' => 'Teste automático: desbloqueio após conferência']);
confere('desbloquear', $r['s'] === 200, $r['j']);
$r = $codigoNovo();
confere('volta a entrar', $r['s'] === 200, $r['j']);
$tCuid = $r['j']['token'] ?? '';
$r = adm('POST', "/admin/usuarios/{$cuid['id']}/encerrar-sessoes", $cDono, ['motivo' => 'Celular perdido, a pedido do titular']);
confere('encerrar sessões', $r['s'] === 200 && $r['j']['encerradas'] >= 1 && req('GET', '/eu', null, ['Authorization: Bearer ' . $tCuid])['s'] === 401, $r['j']);
$r = adm('PATCH', "/admin/usuarios/{$cuid['id']}", $cDono, ['email' => 'nao-e-email', 'motivo' => 'Correção de cadastro a pedido']);
confere('corrigir cadastro valida e-mail', $r['s'] === 422, $r['j']);
$r = adm('PATCH', "/admin/usuarios/{$cuid['id']}", $cDono, ['email' => 'cuidador.corrigido@exemplo.com', 'motivo' => 'Correção de cadastro a pedido do titular']);
confere('corrigir cadastro', $r['s'] === 200, $r['j']);

echo "Pessoas cuidadas (sem conteúdo de saúde)\n";
$r = adm('GET', '/admin/pessoas', $cDono);
confere('lista de pessoas', $r['s'] === 200 && $r['j']['total'] > 0 && isset($r['j']['itens'][0]['faixa']), $r['j']);
$r = $r0 = adm('GET', "/admin/pessoas/$pid", $cDono);
confere('detalhe com vínculos e contagens', $r['s'] === 200 && count($r['j']['vinculos']) > 0 && isset($r['j']['contagens']['registros_total']), $r['j']);
confere('nada de ficha, plano, registros ou mensagens', !array_key_exists('ficha', $r['j']['pessoa']) && !array_key_exists('plano', $r['j']['pessoa'])
    && !isset($r['j']['registros']) && !isset($r['j']['mensagens']) && !str_contains($r['bruto'], 'nascimento'), array_keys($r['j']));
confere('quem acessou os dados da pessoa (30 dias)', count($r['j']['acessos_30d']) > 0, $r['j']['acessos_30d']);
$vGestor = array_values(array_filter($r['j']['vinculos'], fn($v) => $v['papel'] === 'gestor' && $v['status'] === 'ativo'));
$vCuid = array_values(array_filter($r['j']['vinculos'], fn($v) => $v['papel'] === 'cuidador' && $v['status'] === 'ativo'));
if (count($vGestor) === 1) {
    $r = adm('POST', "/admin/vinculos/{$vGestor[0]['id']}/encerrar", $cDono, ['motivo' => 'Teste: não pode deixar sem gestor']);
    confere('não encerra o(a) único(a) gestor(a)', $r['s'] === 409 && $r['j']['erro']['codigo'] === 'unico_gestor', $r['j']);
}
if ($vCuid) {
    $r = adm('POST', "/admin/vinculos/{$vCuid[0]['id']}/tornar-gestor", $cDono, ['motivo' => 'Teste: cuidador não vira gestor']);
    confere('cuidador(a) não vira gestor(a) pelo painel', $r['s'] === 409 && $r['j']['erro']['codigo'] === 'so_rede', $r['j']);
}
$vRede = array_values(array_filter($r0['j']['vinculos'] ?? [], fn($v) => $v['papel'] === 'rede' && $v['status'] === 'ativo'));
if ($vRede) {
    $r = adm('POST', "/admin/vinculos/{$vRede[0]['id']}/tornar-gestor", $cDono, ['motivo' => 'Gestora sem acesso; filho assume a gestão a pedido']);
    confere('Rede de Apoio passa a gestor(a) (dono, com motivo)', $r['s'] === 200, $r['j']);
    $p = app('GET', "/pessoas/$pid", $tR);
    confere('novo(a) gestor(a) vê o cadastro como gestor(a)', ($p['j']['meu_papel'] ?? '') === 'gestor', $p['j']['meu_papel'] ?? $p['j']);
    $r = adm('POST', "/admin/vinculos/{$vGestor[0]['id']}/encerrar", $cDono, ['motivo' => 'Gestora antiga pediu para sair']);
    confere('com dois gestores, encerra um', $r['s'] === 200, $r['j']);
}
$log = (int)$pdo->query("SELECT COUNT(*) FROM log_acessos WHERE ator = 'admin' AND rota = '/admin/pessoas/{id}' AND pessoa_id = " . $pdo->quote($pid))->fetchColumn();
confere('leitura do painel registrada com a pessoa acessada', $log >= 1, $log);

echo "Empresas e convites\n";
$r = adm('GET', '/admin/empresas', $cDono);
confere('lista de empresas', $r['s'] === 200 && $r['j']['total'] > 0, $r['j']);
$r = adm('GET', '/admin/empresas/' . $r['j']['itens'][0]['id'], $cDono);
confere('detalhe da empresa (sem horas por cuidador)', $r['s'] === 200 && isset($r['j']['cuidadores']) && !str_contains($r['bruto'], 'horas'), $r['j']);
$r = adm('GET', '/admin/convites?status=todos', $cDono);
confere('convites com código mascarado', $r['s'] === 200 && $r['j']['total'] > 0 && str_contains($r['j']['itens'][0]['codigo'], '••••'), $r['j']['itens'][0] ?? $r['j']);
$pend = array_values(array_filter($r['j']['itens'], fn($c) => $c['status'] === 'pendente'));
if ($pend) {
    $r = adm('POST', "/admin/convites/{$pend[0]['id']}/revogar", $cDono, ['motivo' => 'Convite enviado ao número errado']);
    confere('revogar convite', $r['s'] === 200, $r['j']);
}

echo "Administradores e papéis\n";
$r = adm('POST', '/admin/admins', $cDono, ['email' => 'Suporte@Ahvita.com', 'papel' => 'leitura']);
confere('dono adiciona administrador(a) de leitura', $r['s'] === 201 && $r['j']['admin']['email'] === 'suporte@ahvita.com', $r['j']);
$idLeitura = $r['j']['admin']['id'] ?? '';
confere('e-mail repetido → 409', adm('POST', '/admin/admins', $cDono, ['email' => 'suporte@ahvita.com', 'papel' => 'leitura'])['s'] === 409);
$r = req('POST', '/admin/entrar', ['credencial' => $jwt(['sub' => '2000000000000000003', 'email' => 'suporte@ahvita.com'])]);
$cLeitura = cookieDe($r);
confere('leitura entra', $r['s'] === 200 && $r['j']['admin']['papel'] === 'leitura', $r['j']);
confere('leitura vê o painel', adm('GET', '/admin/painel', $cLeitura)['s'] === 200);
$r = adm('POST', "/admin/usuarios/{$cuid['id']}/bloquear", $cLeitura, ['motivo' => 'Tentativa de bloqueio por leitura']);
confere('leitura não bloqueia', $r['s'] === 403, $r['j']);
confere('leitura não exporta o log', adm('GET', '/admin/logs/csv', $cLeitura)['s'] === 403);
confere('leitura não cria administrador(a)', adm('POST', '/admin/admins', $cLeitura, ['email' => 'x@ahvita.com', 'papel' => 'dono'])['s'] === 403);
$r = adm('GET', '/admin/admins', $cDono);
$euDono = array_values(array_filter($r['j']['itens'], fn($a) => $a['email'] === 'dono@ahvita.com'))[0] ?? null;
confere('dono da configuração marcado', $euDono !== null && $euDono['da_configuracao'] === true, $r['j']);
confere('ninguém muda o próprio acesso', adm('PATCH', '/admin/admins/' . $euDono['id'], $cDono, ['ativo' => false])['s'] === 409);
$r = adm('PATCH', "/admin/admins/$idLeitura", $cDono, ['ativo' => false]);
confere('desativar administrador(a) derruba a sessão', $r['s'] === 200 && adm('GET', '/admin/eu', $cLeitura)['s'] === 401, $r['j']);

echo "Log de acessos e auditoria\n";
$r = adm('GET', '/admin/logs?ator=admin', $cDono);
confere('log do painel', $r['s'] === 200 && $r['j']['total'] > 5 && in_array('admin.entrou', array_column($r['j']['itens'], 'evento'), true), $r['j']['total'] ?? $r['j']);
$r = adm('GET', '/admin/logs?usuario=' . $cuid['id'], $cDono);
$eventos = array_column($r['j']['itens'] ?? [], 'evento');
confere('log do(a) cuidador(a): entradas e recusa por bloqueio', in_array('entrou', $eventos, true) && in_array('entrada_recusada', $eventos, true), $eventos);
$r = adm('GET', '/admin/logs?perfil=cuidador', $cDono);
confere('filtro por perfil', $r['s'] === 200 && $r['j']['total'] > 0 && $r['j']['itens'][0]['perfil'] === 'cuidador', $r['j']['itens'][0] ?? $r['j']);
$r = adm('GET', '/admin/logs?status=4xx&de=' . date('Y-m-d', time() - 86400), $cDono);
confere('filtro por status e data', $r['s'] === 200 && $r['j']['total'] > 0 && $r['j']['itens'][0]['status'] >= 400, $r['j']['itens'][0] ?? $r['j']);
$raw = (string)$pdo->query('SELECT GROUP_CONCAT(COALESCE(rota, ""), COALESCE(recurso_id, "")) FROM log_acessos')->fetchColumn();
confere('log sem parâmetros de busca nem dados', !str_contains($raw, '?') && !str_contains($raw, 'temperatura'));
$r = adm('GET', '/admin/logs/csv?ator=admin', $cDono);
confere('exportar CSV', $r['s'] === 200 && str_contains($r['h'], 'text/csv') && str_contains($r['bruto'], 'data_hora_local;ator'), substr($r['bruto'], 0, 200));
$r = adm('GET', '/admin/auditoria?acao=admin.', $cDono);
$acoes = array_column($r['j']['itens'] ?? [], 'acao');
confere('auditoria das ações do painel com motivo', in_array('admin.usuario_bloqueado', $acoes, true)
    && array_values(array_filter($r['j']['itens'], fn($i) => $i['acao'] === 'admin.usuario_bloqueado'))[0]['dados']['motivo'] !== '', $acoes);
$r = adm('POST', '/admin/auditoria/verificar', $cDono, []);
confere('cadeia da auditoria íntegra', $r['s'] === 200 && $r['j']['ok'] === true, $r['j']);

echo "Sair e limite de tentativas\n";
$r = adm('POST', '/admin/sair', $cDono, []);
confere('sair do painel', $r['s'] === 204 && adm('GET', '/admin/eu', $cDono)['s'] === 401);
for ($i = 0; $i < 9; $i++) req('POST', '/admin/entrar', ['credencial' => $jwt(['sub' => '2000000000000000077', 'email' => "intruso$i@exemplo.com"])]);
$r = req('POST', '/admin/entrar', ['credencial' => $jwt($dono)]);
confere('muitas recusas no mesmo IP → 429 (até para quem tem acesso)', $r['s'] === 429, $r['j']);
$pdo->exec("DELETE FROM log_acessos WHERE evento = 'admin.entrada_recusada'"); // libera o IP para outras rodadas

echo "\n$ok ok, $falhas falha(s)\n";
exit($falhas === 0 ? 0 : 1);

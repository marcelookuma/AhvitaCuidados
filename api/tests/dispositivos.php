<?php
declare(strict_types=1);

/**
 * Teste do app Android: aparelhos (POST /dispositivos), CORS da origem do app
 * e avisos no celular (push) com as regras de silêncio do(a) cuidador(a).
 * O envio ao Firebase é simulado (push.arquivo_teste): nada sai para a internet.
 * Cria as próprias contas; pode rodar depois dos outros testes.
 *   php tests/dispositivos.php http://127.0.0.1:8099 [r]
 */

$raiz = ($argv[1] ?? 'http://127.0.0.1:8099');
$base = $raiz . (($argv[2] ?? '') === 'r' ? '/api/index.php?r=' : '/api/v1');
$ok = 0; $falhas = 0;

function req(string $metodo, string $caminho, ?string $token = null, ?array $corpo = null, array $extra = []): array
{
    global $base;
    $ch = curl_init(str_contains($base, '?') ? $base . preg_replace('/\?/', '&', $caminho, 1) : $base . $caminho);
    $cab = array_merge(['X-Cuidados: 1'], $extra);
    if ($token) $cab[] = 'Authorization: Bearer ' . $token;
    if ($corpo !== null) { $cab[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo)); }
    $cabecalhos = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cab, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADERFUNCTION => function ($c, $linha) use (&$cabecalhos) {
            $p = explode(':', $linha, 2);
            if (count($p) === 2) $cabecalhos[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($linha);
        },
    ]);
    $resp = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($resp, true);
    return ['s' => $status, 'j' => is_array($j) ? $j : [], 'h' => $cabecalhos];
}

function confere(string $nome, bool $cond, mixed $info = null): void
{
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  ✓ $nome\n"; return; }
    $falhas++;
    echo "  ✗ $nome\n";
    if ($info !== null) echo '      ' . substr(json_encode($info, JSON_UNESCAPED_UNICODE), 0, 600) . "\n";
}

$pdo = new PDO('mysql:host=127.0.0.1;dbname=cuidados;charset=utf8mb4', 'cuidados', 'cuidados');
function entrar(string $tel, string $nome): string
{
    global $pdo;
    $pdo->prepare('DELETE FROM otp_codigos WHERE destino = ?')->execute(['+55' . $tel]);
    $pdo->exec('UPDATE otp_codigos SET ip = NULL');
    req('POST', '/auth/codigo', null, ['destino' => $tel]);
    $t = req('POST', '/auth/entrar', null, ['destino' => $tel, 'codigo' => '123456'])['j']['token'] ?? '';
    req('PATCH', '/eu', $t, ['nome' => $nome, 'apelido' => explode(' ', $nome)[0]]);
    req('POST', '/eu/termos', $t, []);
    return $t;
}
function ulid(): string
{
    $c = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; $s = '';
    $t = (int)(microtime(true) * 1000);
    for ($i = 9; $i >= 0; $i--) { $s = $c[$t % 32] . $s; $t = intdiv($t, 32); }
    for ($i = 0; $i < 16; $i++) $s .= $c[random_int(0, 31)];
    return $s;
}

echo "CORS da origem do app (https://localhost)\n";
$r = req('OPTIONS', '/eu', null, null, ['Origin: https://localhost', 'Access-Control-Request-Method: GET', 'Access-Control-Request-Headers: authorization']);
confere('app recebe Allow-Origin', ($r['h']['access-control-allow-origin'] ?? '') === 'https://localhost', $r['h']);
confere('sem Allow-Credentials (o cookie da web nunca vale no app)', !isset($r['h']['access-control-allow-credentials']), $r['h']);
confere('Authorization liberado', str_contains($r['h']['access-control-allow-headers'] ?? '', 'Authorization'), $r['h']);
$r = req('OPTIONS', '/eu', null, null, ['Origin: https://site-estranho.com', 'Access-Control-Request-Method: GET']);
confere('outra origem não recebe CORS', !isset($r['h']['access-control-allow-origin']), $r['h']);

echo "Registro do aparelho\n";
$tG = entrar('11944440001', 'Gilda Prado');
$tC = entrar('11944440002', 'Cássia Lopes');
$pid = req('POST', '/pessoas', $tG, ['nome_completo' => 'Oscar Prado', 'apelido' => 'Seu Oscar', 'nascimento' => '1941-05-03', 'sexo' => 'masculino',
    'arranjo' => 'gestor_cuidador', 'consentimentos' => ['dados_saude' => true]])['j']['id'] ?? '';
$cod = req('POST', "/pessoas/$pid/convites", $tG, ['papel' => 'cuidador', 'nome' => 'Cássia', 'telefone' => '11944440002', 'modalidade' => 'mei'])['j']['codigo'] ?? '';
req('POST', "/convites/$cod/aceitar", $tC, []);

$tokC = 'fcm-cuidadora-' . bin2hex(random_bytes(8));
$tokG = 'fcm-gestora-' . bin2hex(random_bytes(8));
confere('sem login não registra', req('POST', '/dispositivos', null, ['token' => $tokC, 'plataforma' => 'android'])['s'] === 401);
confere('token com caractere estranho é recusado', req('POST', '/dispositivos', $tC, ['token' => 'abc <script> 1234567890123', 'plataforma' => 'android'])['s'] === 422);
confere('plataforma desconhecida é recusada', req('POST', '/dispositivos', $tC, ['token' => $tokC, 'plataforma' => 'windows'])['s'] === 422);
confere('cuidadora registra o aparelho', req('POST', '/dispositivos', $tC, ['token' => $tokC, 'plataforma' => 'android'])['s'] === 204);
confere('registrar de novo não duplica', req('POST', '/dispositivos', $tC, ['token' => $tokC, 'plataforma' => 'android'])['s'] === 204
    && (int)$pdo->query("SELECT COUNT(*) FROM dispositivos WHERE token = '$tokC'")->fetchColumn() === 1);
req('POST', '/dispositivos', $tG, ['token' => $tokG, 'plataforma' => 'android']);

// ---------------------------------------------------------------- avisos (no próprio processo)
putenv('CUIDADOS_SEM_LOG=1');
putenv('PUSH_ARQUIVO_TESTE=' . ($arquivo = sys_get_temp_dir() . '/cuidados-push-' . getmypid() . '.jsonl'));
require dirname(__DIR__) . '/src/bootstrap.php';
use Cuidados\Modulos\Avisos;
use Cuidados\Support\Push;
use Cuidados\Support\Tempo;

$fuso = new DateTimeZone('America/Sao_Paulo');
$as = fn(string $quando) => Tempo::fixar((new DateTimeImmutable($quando, $fuso))->setTimezone(new DateTimeZone('UTC')));
$enviados = function () use ($arquivo): array {
    $l = is_file($arquivo) ? array_filter(explode("\n", (string)file_get_contents($arquivo))) : [];
    @unlink($arquivo);
    return array_values(array_map(fn($x) => json_decode($x, true)['message'], $l));
};
$para = fn(array $lista, string $tok) => array_values(array_filter($lista, fn($m) => $m['token'] === $tok));
$limparFila = fn() => $pdo->exec('UPDATE eventos_saida SET processado_em = UTC_TIMESTAMP() WHERE processado_em IS NULL');
$mensagem = fn(string $t, string $texto) => req('POST', "/pessoas/$pid/mensagens", $t, ['id' => ulid(), 'tipo' => 'texto', 'texto' => $texto, 'em' => gmdate('c')]);

echo "Avisos no celular\n";
confere('push simulado está ligado no teste', Push::configurado());
$limparFila(); $enviados();
$as('today 10:00');
$r = $mensagem($tG, 'Seu Oscar tomou o remédio da pressão?');
Push::processar();
$e = $enviados();
$mC = $para($e, $tokC);
confere('mensagem da gestora vira aviso para a cuidadora', count($mC) === 1 && $mC[0]['data']['abrir'] === "/c/p/$pid", [$r['s'], $e]);
confere('quem escreveu não recebe aviso', $para($e, $tokG) === [], $e);
confere('aviso sem nome e sem dado de saúde (aparece na tela bloqueada)',
    count($mC) === 1 && !str_contains(json_encode($mC[0], JSON_UNESCAPED_UNICODE), 'Oscar') && !str_contains(json_encode($mC[0], JSON_UNESCAPED_UNICODE), 'remédio'), $mC);
confere('mensagens da mesma conversa se agrupam', ($mC[0]['android']['notification']['tag'] ?? '') !== '' && ($mC[0]['android']['notification']['visibility'] ?? '') === 'PRIVATE', $mC);

$mensagem($tC, 'Tomou sim');
Push::processar();
$mG = $para($enviados(), $tokG);
confere('resposta da cuidadora vira aviso para a gestora (painel)', count($mG) === 1 && $mG[0]['data']['abrir'] === "/g/$pid/conversa", $mG);

echo "Silêncio das 21h às 7h para a cuidadora\n";
$as('today 23:00');
$mensagem($tG, 'Boa noite');
Push::processar();
$e = $enviados();
confere('mensagem à noite não toca o celular da cuidadora', $para($e, $tokC) === [], $e);
$cid = (string)$pdo->query("SELECT usuario_id FROM dispositivos WHERE token = '$tokC'")->fetchColumn();
Avisos::evento('proposta', $cid, ['atendimento_id' => 'X']);
Push::processar();
confere('pedido de atendimento à noite espera', $para($enviados(), $tokC) === []);
confere('…e continua na fila', (int)$pdo->query("SELECT COUNT(*) FROM eventos_saida WHERE tipo = 'proposta' AND usuario_id = '$cid' AND processado_em IS NULL")->fetchColumn() === 1);
$as('tomorrow 07:30');
Push::processar();
$pC = $para($enviados(), $tokC);
confere('às 7h30 o pedido chega, sem cobrança no texto', count($pC) === 1 && $pC[0]['data']['abrir'] === '/c/agenda'
    && str_contains($pC[0]['notification']['body'], 'decida'), $pC);

$as('today 23:10');
$pdo->prepare('INSERT INTO presencas (id, pessoa_id, cuidador_id, inicio_em, criado_em) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
    ->execute([$presenca = ulid(), $pid, $cid]);
$mensagem($tG, 'Tudo bem aí?');
Push::processar();
confere('em serviço à noite, a cuidadora recebe', count($para($enviados(), $tokC)) === 1);
$pdo->prepare('UPDATE presencas SET fim_em = UTC_TIMESTAMP() WHERE id = ?')->execute([$presenca]);

req('PATCH', '/eu', $tC, ['preferencias' => ['avisos_noite' => true]]);
confere('preferência "Avisos à noite" é guardada', (req('GET', '/eu', $tC)['j']['usuario']['preferencias']['avisos_noite'] ?? null) === true);
$mensagem($tG, 'Mais uma');
Push::processar();
confere('com "Avisos à noite" ligado, recebe', count($para($enviados(), $tokC)) === 1);
req('PATCH', '/eu', $tC, ['preferencias' => ['avisos_noite' => false]]);

echo "Fila e limpeza\n";
$as('today 10:00');
$pdo->prepare("INSERT INTO eventos_saida (tipo, usuario_id, payload, criado_em) VALUES ('proposta', ?, '{}', UTC_TIMESTAMP() - INTERVAL 3 DAY)")->execute([$cid]);
Push::processar();
confere('aviso de dias atrás não é mais enviado', $para($enviados(), $tokC) === []);
req('POST', '/dispositivos', $tC, ['token' => 'invalido-' . bin2hex(random_bytes(8)), 'plataforma' => 'android']);
Avisos::evento('proposta', $cid, ['atendimento_id' => 'Y']);
Push::processar();
$enviados();
confere('token recusado pelo Firebase é apagado', (int)$pdo->query("SELECT COUNT(*) FROM dispositivos WHERE token LIKE 'invalido-%'")->fetchColumn() === 0);

echo "Sair da conta desliga os avisos\n";
confere('"remover" tira o aparelho', req('POST', '/dispositivos/remover', $tG, ['token' => $tokG])['s'] === 204
    && (int)$pdo->query("SELECT COUNT(*) FROM dispositivos WHERE token = '$tokG'")->fetchColumn() === 0);
req('POST', '/auth/sair', $tC, []);
$mensagem($tG, 'Depois de sair');
Push::processar();
confere('depois de sair, a cuidadora não recebe nada', $para($enviados(), $tokC) === []);
confere('aparelho da sessão encerrada é apagado', (int)$pdo->query("SELECT COUNT(*) FROM dispositivos WHERE token = '$tokC'")->fetchColumn() === 0);
$limparFila();
Tempo::fixar(null);

echo "\n$ok ok, $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);

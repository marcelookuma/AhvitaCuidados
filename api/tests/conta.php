<?php
declare(strict_types=1);

/**
 * Teste de ponta a ponta: editar o próprio cadastro (vale para todos os perfis).
 * Cria as próprias contas; pode rodar depois dos outros testes.
 *   php tests/conta.php http://127.0.0.1:8099 [r]
 */

$base = ($argv[1] ?? 'http://127.0.0.1:8099') . (($argv[2] ?? '') === 'r' ? '/api/index.php?r=' : '/api/v1');
$ok = 0; $falhas = 0;

function req(string $metodo, string $caminho, ?string $token = null, ?array $corpo = null): array
{
    global $base;
    $ch = curl_init(str_contains($base, '?') ? $base . preg_replace('/\?/', '&', $caminho, 1) : $base . $caminho);
    $cab = ['X-Cuidados: 1'];
    if ($token) $cab[] = 'Authorization: Bearer ' . $token;
    if ($corpo !== null) { $cab[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo)); }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cab, CURLOPT_RETURNTRANSFER => true]);
    $resp = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($resp, true);
    return ['s' => $status, 'j' => is_array($j) ? $j : []];
}

function confere(string $nome, bool $cond, mixed $info = null): void
{
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  ✓ $nome\n"; return; }
    $falhas++;
    echo "  ✗ $nome\n";
    if ($info !== null) echo '      ' . substr(json_encode($info, JSON_UNESCAPED_UNICODE), 0, 500) . "\n";
}

$pdo = new PDO('mysql:host=127.0.0.1;dbname=cuidados;charset=utf8mb4', 'cuidados', 'cuidados');
/** O app só deixa pedir 1 código a cada 30 s por destino: o teste apaga o anterior. */
function limpar(string $destino): void { global $pdo; $pdo->prepare('DELETE FROM otp_codigos WHERE destino = ?')->execute([$destino]); }
function entrar(string $tel): array
{
    limpar('+55' . $tel);
    req('POST', '/auth/codigo', null, ['destino' => $tel]);
    $r = req('POST', '/auth/entrar', null, ['destino' => $tel, 'codigo' => '123456']);
    return [$r['j']['token'] ?? '', $r['j']['usuario']['id'] ?? ''];
}

echo "Nome, apelido e CPF\n";
[$t, $id] = entrar('11955550001');
[$t2] = entrar('11955550002');
req('PATCH', '/eu', $t2, ['nome' => 'Outra Pessoa', 'cpf' => '390.533.447-05']);
$r = req('PATCH', '/eu', $t, ['nome' => 'Rosa Amaral', 'apelido' => 'Rosinha', 'cpf' => '111.444.777-35']);
confere('salva nome, apelido e CPF', $r['s'] === 200 && $r['j']['nome'] === 'Rosa Amaral' && $r['j']['apelido'] === 'Rosinha' && $r['j']['cpf'] === '11144477735', $r['j']);
$r = req('PATCH', '/eu', $t, ['apelido' => '']);
confere('apaga o apelido', $r['s'] === 200 && $r['j']['apelido'] === null, $r['j']);
confere('nome curto demais → 422', req('PATCH', '/eu', $t, ['nome' => 'R'])['s'] === 422);
confere('CPF inválido → 422', req('PATCH', '/eu', $t, ['cpf' => '111.111.111-11'])['s'] === 422);
$r = req('PATCH', '/eu', $t, ['cpf' => '390.533.447-05']);
confere('CPF de outra conta → 409', $r['s'] === 409 && $r['j']['erro']['codigo'] === 'cpf_em_uso', $r['j']);

echo "Trocar celular (código no número novo)\n";
$r = req('POST', '/eu/contato/codigo', $t, ['destino' => '11955550001']);
confere('mesmo celular → 422', $r['s'] === 422, $r['j']);
$r = req('POST', '/eu/contato/codigo', $t, ['destino' => '11955550002']);
confere('celular de outra conta → 409', $r['s'] === 409 && $r['j']['erro']['codigo'] === 'telefone_em_uso', $r['j']);
limpar('+5511955550009');
$r = req('POST', '/eu/contato/codigo', $t, ['destino' => '(11) 95555-0009']);
confere('manda código para o número novo', $r['s'] === 200 && $r['j']['campo'] === 'telefone', $r['j']);
$r = req('POST', '/eu/contato/confirmar', $t, ['destino' => '11955550009', 'codigo' => '000000']);
confere('código errado não troca', $r['s'] === 422 && req('GET', '/eu', $t)['j']['usuario']['telefone'] === '+5511955550001', $r['j']);
$r = req('POST', '/eu/contato/confirmar', $t, ['destino' => '11955550009', 'codigo' => '123456']);
confere('código certo troca o celular', $r['s'] === 200 && $r['j']['telefone'] === '+5511955550009', $r['j']);
[$tNovo, $idNovo] = entrar('11955550009');
confere('entra pelo celular novo na mesma conta', $idNovo === $id);
confere('sessão atual continua valendo', req('GET', '/eu', $t)['s'] === 200);

echo "E-mail e formas de entrar\n";
limpar('rosa.amaral@exemplo.com');
$r = req('POST', '/eu/contato/codigo', $t, ['destino' => 'Rosa.Amaral@Exemplo.com']);
// Nos testes não há servidor de e-mail: a API avisa e não grava código; simulamos o código entregue.
confere('sem servidor de e-mail, avisa e não troca nada', in_array($r['s'], [200, 424, 502, 503], true) && req('GET', '/eu', $t)['j']['usuario']['email'] === null, $r);
$pdo->prepare("INSERT INTO otp_codigos (id, destino, canal, codigo_hash, tentativas, expira_em, criado_em) VALUES (?, ?, 'email', ?, 0, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), UTC_TIMESTAMP())")
    ->execute(['01TESTEEMAIL0000000000000A', 'rosa.amaral@exemplo.com', hash_hmac('sha256', 'rosa.amaral@exemplo.com|123456', getenv('APP_SEGREDO') ?: 'cuidados')]);
$r = req('POST', '/eu/contato/confirmar', $t, ['destino' => 'rosa.amaral@exemplo.com', 'codigo' => '123456']);
confere('inclui e-mail', $r['s'] === 200 && $r['j']['email'] === 'rosa.amaral@exemplo.com', $r['j']);
confere('e-mail inválido → 422', req('POST', '/eu/contato/codigo', $t, ['destino' => 'nao@email'])['s'] === 422);
$r = req('POST', '/eu/contato/remover', $t, ['campo' => 'google']);
confere('sem Google não há o que desligar → 409', $r['s'] === 409, $r['j']);
$r = req('POST', '/eu/contato/remover', $t, ['campo' => 'telefone']);
confere('tira o celular (sobra o e-mail)', $r['s'] === 200 && $r['j']['telefone'] === null && $r['j']['email'] !== null, $r['j']);
$r = req('POST', '/eu/contato/remover', $t, ['campo' => 'email']);
confere('não tira a última forma de entrar', $r['s'] === 409 && $r['j']['erro']['codigo'] === 'ultimo_acesso', $r['j']);

echo "Auditoria\n";
$dados = (string)$pdo->query("SELECT GROUP_CONCAT(COALESCE(dados,'')) FROM auditoria WHERE usuario_id = " . $pdo->quote($id) . " AND acao LIKE 'conta.contato%'")->fetchColumn();
confere('registra a troca sem gravar o contato', str_contains($dados, 'telefone') && !str_contains($dados, '5555') && !str_contains($dados, '@'), $dados);

echo "\n$ok ok, $falhas falha(s)\n";
exit($falhas === 0 ? 0 : 1);

<?php
declare(strict_types=1);

/**
 * Teste de ponta a ponta: "Convites para você" no início de todos os perfis (GET /eu/convites)
 * e "Não é para mim" (POST /convites/{codigo}/recusar).
 * Cria as próprias contas; pode rodar depois dos outros testes.
 *   php tests/convites.php http://127.0.0.1:8099 [r]
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
    if ($info !== null) echo '      ' . substr(json_encode($info, JSON_UNESCAPED_UNICODE), 0, 600) . "\n";
}

$pdo = new PDO('mysql:host=127.0.0.1;dbname=cuidados;charset=utf8mb4', 'cuidados', 'cuidados');
function entrar(string $tel, string $nome): string
{
    global $pdo;
    $pdo->prepare('DELETE FROM otp_codigos WHERE destino = ?')->execute(['+55' . $tel]);
    $pdo->exec('UPDATE otp_codigos SET ip = NULL'); // as suítes rodam do mesmo IP (limite de 20 códigos/h)
    req('POST', '/auth/codigo', null, ['destino' => $tel]);
    $t = req('POST', '/auth/entrar', null, ['destino' => $tel, 'codigo' => '123456'])['j']['token'] ?? '';
    req('PATCH', '/eu', $t, ['nome' => $nome, 'apelido' => explode(' ', $nome)[0]]);
    req('POST', '/eu/termos', $t, []);
    return $t;
}
$itens = fn(string $t) => req('GET', '/eu/convites', $t)['j']['itens'] ?? null;

echo "Convites por código para o meu celular\n";
$tG = entrar('11933330001', 'Glória Mendes');
$tX = entrar('11933330002', 'Xavier Rocha');   // já tem conta, ainda sem papel
$tZ = entrar('11933330003', 'Zeca Outro');
$pid = req('POST', '/pessoas', $tG, ['nome_completo' => 'Hilda Mendes', 'apelido' => 'Dona Hilda', 'nascimento' => '1939-02-11', 'sexo' => 'feminino',
    'arranjo' => 'gestor_cuidador', 'consentimentos' => ['dados_saude' => true]])['j']['id'] ?? '';
confere('sem convites, a lista vem vazia', $itens($tX) === [], $itens($tX));
$cod = req('POST', "/pessoas/$pid/convites", $tG, ['papel' => 'cuidador', 'nome' => 'Xavier', 'telefone' => '(11) 93333-0002', 'modalidade' => 'mei'])['j']['codigo'] ?? '';
$l = $itens($tX);
confere('convite aparece no início de quem foi convidado', count($l) === 1 && $l[0]['tipo'] === 'convite' && $l[0]['link'] === "/convite/$cod"
    && str_contains($l[0]['titulo'], 'Glória convidou você para cuidar de Dona Hilda') && $l[0]['pode_recusar'] === true, $l);
confere('quem convidou não vê o próprio convite', $itens($tG) === [], $itens($tG));
confere('outra pessoa não vê', $itens($tZ) === []);
confere('outra pessoa não recusa o convite alheio', req('POST', "/convites/$cod/recusar", $tZ, [])['s'] === 404);

$cod2 = req('POST', "/pessoas/$pid/convites", $tG, ['papel' => 'rede', 'nome' => 'Xavier', 'telefone' => '11933330002', 'relacao' => 'Vizinho(a)'])['j']['codigo'] ?? '';
$r = req('POST', "/convites/$cod2/recusar", $tX, []);
confere('"Não é para mim" recusa o convite', $r['s'] === 204, $r['j']);
$l = $itens($tX);
confere('convite recusado some da lista', count($l) === 1 && $l[0]['link'] === "/convite/$cod", $l);
$pend = req('GET', "/pessoas/$pid/vinculos", $tG)['j']['convites_pendentes'] ?? [];
confere('e some dos pendentes de quem convidou', count($pend) === 1 && $pend[0]['codigo'] === $cod, $pend);
confere('convite recusado não pode mais ser aceito', in_array(req('POST', "/convites/$cod2/aceitar", $tX, [])['s'], [404, 409], true));
req('POST', "/convites/$cod/aceitar", $tX, []);
confere('convite aceito some da lista', $itens($tX) === [], $itens($tX));

echo "Celular não confirmado não recebe convite\n";
$tY = entrar('11933330004', 'Yara Lima');
$pdo->exec("UPDATE usuarios SET telefone_confirmado = 0 WHERE telefone = '+5511933330004'");
req('POST', "/pessoas/$pid/convites", $tG, ['papel' => 'rede', 'nome' => 'Yara', 'telefone' => '11933330004', 'relacao' => 'Amigo(a)']);
confere('celular só informado não puxa convite', $itens($tY) === [], $itens($tY));

echo "Pedidos de empresas no início\n";
$tA = entrar('11933330005', 'Alice Empresa');
$e = req('POST', '/empresas', $tA, ['razao_social' => 'Acolher Cuidados Ltda', 'nome_fantasia' => 'Acolher', 'cnpj' => '55.667.788/0001-86'])['j']['id'] ?? '';
req('POST', "/empresas/$e/pedidos-rede", $tA, ['telefone' => '11933330002', 'modalidade' => 'mei']);
$l = $itens($tX);
confere('cuidador(a) vê o pedido da empresa para a rede', count($l) === 1 && $l[0]['tipo'] === 'pedido_rede' && $l[0]['link'] === '/c/empresas' && str_contains($l[0]['titulo'], 'quer você na rede'), $l);
req('POST', "/empresas/$e/pedidos-cliente", $tA, ['contato' => '11933330001', 'permissoes' => ['atendimentos']]);
$l = $itens($tG);
confere('gestor(a) vê o pedido da empresa e vai para "Quem cuida"', count($l) === 1 && $l[0]['tipo'] === 'pedido_cliente' && $l[0]['link'] === "/g/$pid/pessoas", $l);
req('POST', "/pessoas/$pid/pedidos-empresa", $tG, ['empresa_id' => $e, 'permissoes' => ['atendimentos']]); // pedido dos dois lados: liga
$tD = entrar('11933330006', 'Dora Cuidadora');
$pid2 = req('POST', '/pessoas', $tG, ['nome_completo' => 'Inês Mendes', 'apelido' => 'Dona Inês', 'nascimento' => '1945-07-01', 'sexo' => 'feminino',
    'arranjo' => 'gestor_cuidador', 'consentimentos' => ['dados_saude' => true]])['j']['id'] ?? '';
$c3 = req('POST', "/pessoas/$pid2/convites", $tG, ['papel' => 'cuidador', 'nome' => 'Dora', 'telefone' => '11933330006', 'modalidade' => 'mei'])['j']['codigo'] ?? '';
req('POST', "/convites/$c3/aceitar", $tD, []);
req('POST', '/eu/pedidos-rede', $tD, ['empresa_id' => $e, 'modalidade' => 'autonomo']);
req('POST', "/pessoas/$pid2/pedidos-empresa", $tG, ['empresa_id' => $e, 'permissoes' => ['atendimentos']]);
$l = $itens($tA);
$tipos = array_column($l, 'tipo');
confere('equipe da empresa vê os pedidos recebidos (rede e clientes)', in_array('empresa_rede', $tipos, true) && in_array('empresa_cliente', $tipos, true), $l);
$rede = array_values(array_filter($l, fn($i) => $i['tipo'] === 'empresa_rede'))[0] ?? [];
confere('e o link leva ao painel certo', ($rede['link'] ?? '') === "/e/$e/equipe" && str_contains($rede['titulo'], 'pediu para entrar na rede'), $rede);

echo "\n$ok ok, $falhas falha(s)\n";
exit($falhas === 0 ? 0 : 1);

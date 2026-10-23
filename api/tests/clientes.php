<?php
declare(strict_types=1);

/**
 * Teste de ponta a ponta: vínculo empresa ↔ pessoa cuidada, pedido pelos dois lados,
 * com o(a) gestor(a) decidindo o que a empresa vê (linha do tempo incluída) e sem presença para a empresa.
 * Cria as próprias contas; pode rodar depois dos outros testes.
 *   php tests/clientes.php http://127.0.0.1:8099 [r]
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
    return ['s' => $status, 'j' => is_array($j) ? $j : [], 'bruto' => $resp];
}

function confere(string $nome, bool $cond, mixed $info = null): void
{
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  ✓ $nome\n"; return; }
    $falhas++;
    echo "  ✗ $nome\n";
    if ($info !== null) echo '      ' . substr(json_encode($info, JSON_UNESCAPED_UNICODE), 0, 600) . "\n";
}

function ulid(): string
{
    static $n = 0;
    $alf = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $ms = (int)(microtime(true) * 1000) + ($n++);
    $t = '';
    for ($i = 0; $i < 10; $i++) { $t = $alf[$ms % 32] . $t; $ms = intdiv($ms, 32); }
    $r = '';
    for ($i = 0; $i < 16; $i++) $r .= $alf[random_int(0, 31)];
    return $t . $r;
}

$pdo = new PDO('mysql:host=127.0.0.1;dbname=cuidados;charset=utf8mb4', 'cuidados', 'cuidados');
$pdo->exec("SET time_zone = '+00:00'");
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

echo "Preparação\n";
$tG = entrar('11944440001', 'Gilda Teixeira');
$nova = fn(string $nome, string $apelido) => req('POST', '/pessoas', $tG, ['nome_completo' => $nome, 'apelido' => $apelido, 'nascimento' => '1941-04-20',
    'sexo' => 'feminino', 'arranjo' => 'gestor_rede_cuidador', 'ficha' => ['alergias' => 'Sulfa'], 'consentimentos' => ['dados_saude' => true]])['j']['id'] ?? '';
$p1 = $nova('Iracema Teixeira', 'Dona Iracema');
$p2 = $nova('Olga Teixeira', 'Dona Olga');
$p3 = $nova('Zélia Teixeira', 'Dona Zélia');
$conv = fn(string $pid, array $d) => req('POST', "/pessoas/$pid/convites", $tG, $d)['j']['codigo'] ?? '';
$cC = $conv($p1, ['papel' => 'cuidador', 'nome' => 'Lúcia', 'telefone' => '11944440002', 'modalidade' => 'mei']);
$cR = $conv($p1, ['papel' => 'rede', 'nome' => 'Rui', 'telefone' => '11944440003', 'relacao' => 'Sobrinho(a)']);
$tC = entrar('11944440002', 'Lúcia Prado');
req('POST', "/convites/$cC/aceitar", $tC, []);
$tR = entrar('11944440003', 'Rui Teixeira');
req('POST', "/convites/$cR/aceitar", $tR, []);
// A cuidadora presta o serviço e registra: é isso que a empresa poderá (ou não) ver.
$pres = ulid();
req('POST', "/pessoas/$p1/presencas", $tC, ['id' => $pres, 'em' => gmdate('c', time() - 600)]);
req('POST', "/pessoas/$p1/registros", $tC, ['id' => ulid(), 'tipo' => 'agua', 'rotulo' => 'Bebeu água', 'em' => gmdate('c', time() - 300)]);
$tA = entrar('11944440004', 'Ana Coordena');
$tB = entrar('11944440005', 'Beto Outra');
$e1 = req('POST', '/empresas', $tA, ['razao_social' => 'Cuidar Bem Assistência Ltda', 'nome_fantasia' => 'Cuidar Bem', 'cnpj' => '45.612.378/0001-84'])['j']['id'] ?? '';
$e2 = req('POST', '/empresas', $tB, ['razao_social' => 'Vida Plena Serviços Ltda', 'nome_fantasia' => 'Vida Plena', 'cnpj' => '32.165.498/0001-39'])['j']['id'] ?? '';
confere('cenário montado', strlen($p1 . $p2 . $p3 . $e1 . $e2) === 130);

echo "Gestor(a) pede, empresa aceita\n";
$r = req('GET', "/pessoas/$p1/empresas/buscar?q=" . urlencode('cuidar b'), $tG);
confere('gestor(a) acha a empresa pelo nome', ($r['j']['empresas'][0]['id'] ?? '') === $e1, $r['j']);
confere('Rede de Apoio não procura empresa (só quem administra)', req('GET', "/pessoas/$p1/empresas/buscar?q=cuidar", $tR)['s'] === 403);
$r = req('POST', "/pessoas/$p1/pedidos-empresa", $tG, ['empresa_id' => $e1, 'permissoes' => ['ver_registros', 'atendimentos', 'registrar', 'administrar', 'remedios_editar'], 'mensagem' => 'Precisamos de reforço nos fins de semana']);
$ped1 = $r['j']['id'] ?? '';
confere('pedido criado só com o que a empresa pode ter', $r['s'] === 201 && $r['j']['permissoes'] === ['ver_registros', 'atendimentos'], $r['j']);
confere('Rede de Apoio não pede vínculo', req('POST', "/pessoas/$p1/pedidos-empresa", $tR, ['empresa_id' => $e1, 'permissoes' => []])['s'] === 403);
confere('não repete pedido pendente', req('POST', "/pessoas/$p1/pedidos-empresa", $tG, ['empresa_id' => $e1, 'permissoes' => []])['s'] === 409);
$cli = req('GET', "/empresas/$e1/clientes", $tA)['j'];
$rec = $cli['pedidos']['recebidos'][0] ?? [];
confere('empresa vê o pedido com o apelido da pessoa', ($rec['id'] ?? '') === $ped1 && $rec['pessoa']['apelido'] === 'Dona Iracema' && $rec['gestor']['nome'] === 'Gilda', $rec);
confere('antes do aceite: sem ficha nem dado de saúde', !str_contains($cli['bruto'] ?? json_encode($cli), 'Sulfa') && $cli['clientes'] === [], $cli);
confere('empresa ainda não vê a linha do tempo', req('GET', "/pessoas/$p1/registros", $tA)['s'] === 404);
confere('outra empresa não responde', req('POST', "/pedidos-cliente/$ped1/aceitar", $tB, [])['s'] === 404);
confere('gestor(a) não aceita o próprio pedido', req('POST', "/pedidos-cliente/$ped1/aceitar", $tG, [])['s'] === 404);
$r = req('POST', "/pedidos-cliente/$ped1/aceitar", $tA, []);
confere('empresa aceita', $r['s'] === 200 && $r['j']['status'] === 'aceito' && $r['j']['pessoa_id'] === $p1, $r['j']);
$cons = (int)$pdo->query("SELECT COUNT(*) FROM consentimentos WHERE pessoa_id = '$p1' AND tipo = 'compartilhar_empresa' AND revogado_em IS NULL")->fetchColumn();
confere('consentimento do(a) gestor(a) gravado', $cons === 1, $cons);
$feedG = req('GET', "/pessoas/$p1/feed", $tG)['j']['itens'] ?? [];
confere('todos são avisados na conversa', str_contains(json_encode($feedG, JSON_UNESCAPED_UNICODE), 'Cuidar Bem passou a acompanhar o cuidado (vê a linha do tempo, organiza atendimentos)'), array_slice($feedG, -2));

echo "A empresa acompanha a linha do tempo (sem presença)\n";
$r = req('GET', "/pessoas/$p1/registros", $tA);
confere('empresa vê a linha do tempo liberada', $r['s'] === 200 && count($r['j']['registros']) >= 1 && $r['j']['registros'][0]['rotulo'] === 'Bebeu água', $r['j']);
$r = req('GET', "/pessoas/$p1/registros?presencas=1", $tA);
confere('⚖️ empresa não vê presença (início/fim do serviço)', $r['s'] === 200 && $r['j']['presencas'] === [], $r['j']['presencas'] ?? $r['j']);
confere('gestor(a) continua vendo a presença', count(req('GET', "/pessoas/$p1/registros?presencas=1", $tG)['j']['presencas'] ?? []) >= 1);
$painel = req('GET', "/pessoas/$p1/painel", $tA)['j'];
confere('⚖️ painel da empresa sem "em serviço desde"', ($painel['em_servico'] ?? null) === [], $painel['em_servico'] ?? $painel);
req('POST', "/presencas/$pres/finalizar", $tC, ['em' => gmdate('c')]);
req('PATCH', "/pessoas/$p1", $tG, ['empresa_permissoes' => ['ver_registros', 'atendimentos', 'mensagens']]);
$feedE = req('GET', "/pessoas/$p1/feed", $tA)['j'];
$txt = json_encode($feedE['itens'] ?? [], JSON_UNESCAPED_UNICODE);
confere('⚖️ conversa da empresa sem "Serviço iniciado/finalizado às…"', !str_contains($txt, 'Serviço iniciado') && !str_contains($txt, 'Serviço finalizado') && ($feedE['em_servico'] ?? null) === [], $txt);
confere('gestor(a) vê o aviso de serviço finalizado', str_contains(json_encode(req('GET', "/pessoas/$p1/feed", $tG)['j']['itens'] ?? [], JSON_UNESCAPED_UNICODE), 'Serviço finalizado'));
req('PATCH', "/pessoas/$p1", $tG, ['empresa_permissoes' => ['ver_registros', 'atendimentos', 'registrar', 'administrar']]);
$perm = req('GET', "/pessoas/$p1/vinculos", $tG)['j']['empresa']['permissoes'] ?? null;
confere('gestor(a) não consegue dar à empresa registrar nem administrar', $perm === ['ver_registros', 'atendimentos'], $perm);
confere('empresa não registra cuidado', req('POST', "/pessoas/$p1/registros", $tA, ['id' => ulid(), 'tipo' => 'agua', 'rotulo' => 'x', 'em' => gmdate('c')])['s'] === 403);

echo "Gestor(a) libera e tira remédios e ficha: vale na hora para a empresa\n";
confere('sem "Ver remédios", empresa não vê remédios', req('GET', "/pessoas/$p1/remedios", $tA)['s'] === 403);
$pe = req('GET', "/pessoas/$p1", $tA)['j'];
confere('sem ficha liberada, a ficha não vem', !array_key_exists('ficha', $pe) && !in_array('remedios_ver', $pe['permissoes'] ?? [], true), $pe);
req('PATCH', "/pessoas/$p1", $tG, ['empresa_permissoes' => ['ver_registros', 'atendimentos', 'remedios_ver', 'ficha_emergencia']]);
$pe = req('GET', "/pessoas/$p1", $tA)['j'];
confere('empresa recebe as permissões novas em GET /pessoas/{id}', in_array('remedios_ver', $pe['permissoes'] ?? [], true) && in_array('ficha_emergencia', $pe['permissoes'] ?? [], true) && array_key_exists('ficha', $pe), $pe);
confere('empresa passa a ver os remédios', req('GET', "/pessoas/$p1/remedios", $tA)['s'] === 200);
confere('mas não muda remédios', req('POST', "/pessoas/$p1/remedios", $tA, ['nome' => 'X', 'horarios' => ['08:00']])['s'] === 403);
req('PATCH', "/pessoas/$p1", $tG, ['empresa_permissoes' => ['ver_registros', 'atendimentos']]);
confere('gestor(a) tira de novo: empresa deixa de ver', req('GET', "/pessoas/$p1/remedios", $tA)['s'] === 403 && !array_key_exists('ficha', req('GET', "/pessoas/$p1", $tA)['j']));

echo "Empresa pede, gestor(a) aceita e escolhe o que liberar\n";
$r = req('POST', "/empresas/$e2/pedidos-cliente", $tB, ['contato' => '11 9', 'permissoes' => []]);
confere('contato inválido → 422', $r['s'] === 422, $r['j']);
$r = req('POST', "/empresas/$e2/pedidos-cliente", $tB, ['contato' => '11944440003', 'permissoes' => []]);
confere('quem não é gestor(a) → 404 (sugere convite)', $r['s'] === 404 && str_contains($r['j']['erro']['mensagem'] ?? '', 'Convidar'), $r['j']);
$r = req('POST', "/empresas/$e2/pedidos-cliente", $tB, ['contato' => '(11) 94444-0001', 'permissoes' => ['ver_registros', 'plano_ver', 'atendimentos'], 'mensagem' => 'Somos da Vida Plena']);
$ped2 = $r['j']['id'] ?? '';
confere('empresa pede ao(à) gestor(a) pelo celular', $r['s'] === 201 && $r['j']['gestor']['nome'] === 'Gilda', $r['j']);
$meus = req('GET', '/eu/pedidos-empresa', $tG)['j'];
confere('gestor(a) vê o pedido da empresa', ($meus['recebidos'][0]['id'] ?? '') === $ped2 && $meus['recebidos'][0]['empresa']['nome'] === 'Vida Plena', $meus);
confere('aceitar sem escolher a pessoa → 422', req('POST', "/pedidos-cliente/$ped2/aceitar", $tG, [])['s'] === 422);
$r = req('POST', "/pedidos-cliente/$ped2/aceitar", $tG, ['pessoa_id' => $p1, 'permissoes' => ['plano_ver']]);
confere('pessoa já com outra empresa → 409', $r['s'] === 409 && $r['j']['erro']['codigo'] === 'outra_empresa', $r['j']);
confere('Rede de Apoio não aceita pelo(a) gestor(a)', req('POST', "/pedidos-cliente/$ped2/aceitar", $tR, ['pessoa_id' => $p1])['s'] === 404);
$r = req('POST', "/pedidos-cliente/$ped2/aceitar", $tG, ['pessoa_id' => $p2, 'permissoes' => ['plano_ver', 'atendimentos']]);
confere('gestor(a) aceita para outra pessoa, liberando menos do que a empresa pediu', $r['s'] === 200 && $r['j']['permissoes'] === ['plano_ver', 'atendimentos'], $r['j']);
confere('sem "ver a linha do tempo", a empresa não vê os registros', req('GET', "/pessoas/$p2/registros", $tB)['s'] === 403);
$cli2 = req('GET', "/empresas/$e2/clientes", $tB)['j']['clientes'] ?? [];
confere('pessoa aparece nos clientes da empresa', ($cli2[0]['id'] ?? '') === $p2, $cli2);

echo "Pedido dos dois lados = já concordaram\n";
$ped3 = req('POST', "/empresas/$e1/pedidos-cliente", $tA, ['contato' => '11944440001', 'permissoes' => ['atendimentos']])['j']['id'] ?? '';
$r = req('POST', "/pessoas/$p3/pedidos-empresa", $tG, ['empresa_id' => $e1, 'permissoes' => ['atendimentos', 'ver_registros']]);
confere('gestor(a) pede à empresa que já tinha pedido: liga direto', $r['s'] === 200 && $r['j']['status'] === 'aceito' && $r['j']['id'] === $ped3, $r['j']);
$perm3 = req('GET', "/pessoas/$p3/vinculos", $tG)['j']['empresa']['permissoes'] ?? null;
confere('vale o que o(a) gestor(a) liberou', $perm3 === ['atendimentos', 'ver_registros'], $perm3);

echo "Encerrar e vencer\n";
$r = req('POST', "/pessoas/$p1/empresa/desvincular", $tG, []);
confere('gestor(a) encerra a relação', $r['s'] === 204);
confere('empresa perde o acesso na hora', req('GET', "/pessoas/$p1/registros", $tA)['s'] === 404);
$rev = (int)$pdo->query("SELECT COUNT(*) FROM consentimentos WHERE pessoa_id = '$p1' AND tipo = 'compartilhar_empresa' AND revogado_em IS NOT NULL")->fetchColumn();
confere('consentimento revogado', $rev === 1, $rev);
confere('aviso de saída na conversa', str_contains(json_encode(req('GET', "/pessoas/$p1/feed", $tG)['j']['itens'] ?? [], JSON_UNESCAPED_UNICODE), 'deixou de acompanhar'));
$ped4 = req('POST', "/pessoas/$p1/pedidos-empresa", $tG, ['empresa_id' => $e2, 'permissoes' => ['atendimentos']])['j']['id'] ?? '';
$pdo->prepare('UPDATE pedidos_cliente SET expira_em = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 60), $ped4]);
$r = req('POST', "/pedidos-cliente/$ped4/aceitar", $tB, []);
confere('pedido vencido não pode ser aceito', $r['s'] === 409 && str_contains($r['j']['erro']['mensagem'] ?? '', 'venceu'), $r['j']);

echo "Auditoria\n";
$n = (int)$pdo->query("SELECT COUNT(*) FROM auditoria WHERE acao LIKE 'cliente.%'")->fetchColumn();
confere('pedidos e aceites na auditoria', $n === 7, $n);

echo "\n$ok ok, $falhas falha(s)\n";
exit($falhas === 0 ? 0 : 1);

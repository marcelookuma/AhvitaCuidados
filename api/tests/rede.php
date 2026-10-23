<?php
declare(strict_types=1);

/**
 * Teste de ponta a ponta: pedidos para entrar na rede de uma empresa, nos dois sentidos.
 * Cria as próprias contas; pode rodar depois de tests/fluxo.php e tests/admin.php.
 *   php tests/rede.php http://127.0.0.1:8099 [r]
 */

$base = ($argv[1] ?? 'http://127.0.0.1:8099') . (($argv[2] ?? '') === 'r' ? '/api/index.php?r=' : '/api/v1');
$ok = 0; $falhas = 0;

function req(string $metodo, string $caminho, ?string $token = null, ?array $corpo = null): array
{
    global $base;
    $ch = curl_init(str_contains($base, '?') ? $base . preg_replace('/\?/', '&', $caminho, 1) : $base . $caminho);
    $cab = ['X-Cuidados: 1'];
    if ($token) $cab[] = 'Authorization: Bearer ' . $token;
    if ($corpo !== null) {
        $cab[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo));
    }
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

$pdo = new PDO('mysql:host=127.0.0.1;dbname=cuidados;charset=utf8mb4', 'cuidados', 'cuidados');
$pdo->exec("SET time_zone = '+00:00'");

/** Entra (o teste apaga o código anterior: o app só deixa pedir 1 código a cada 30 s por número). */
function entrar(string $tel, string $nome): string
{
    global $pdo;
    $pdo->prepare('DELETE FROM otp_codigos WHERE destino = ?')->execute(['+55' . $tel]);
    req('POST', '/auth/codigo', null, ['destino' => $tel]);
    $t = req('POST', '/auth/entrar', null, ['destino' => $tel, 'codigo' => '123456'])['j']['token'] ?? '';
    req('PATCH', '/eu', $t, ['nome' => $nome, 'apelido' => explode(' ', $nome)[0]]);
    req('POST', '/eu/termos', $t, []);
    return $t;
}

echo "Preparação\n";
$tG = entrar('11966660001', 'Helena Duarte');
$r = req('POST', '/pessoas', $tG, ['nome_completo' => 'Otávio Duarte', 'apelido' => 'Seu Otávio', 'nascimento' => '1938-08-12', 'sexo' => 'masculino',
    'arranjo' => 'gestor_cuidador', 'consentimentos' => ['dados_saude' => true]]);
$pid = $r['j']['id'] ?? '';
$tornarCuidador = function (string $tel, string $nome) use ($tG, $pid): string {
    $c = req('POST', "/pessoas/$pid/convites", $tG, ['papel' => 'cuidador', 'nome' => $nome, 'telefone' => $tel, 'modalidade' => 'mei'])['j']['codigo'] ?? '';
    $t = entrar($tel, $nome);
    req('POST', "/convites/$c/aceitar", $t, []);
    return $t;
};
$tC = $tornarCuidador('11966660002', 'Cida Moraes');      // cuidadora 1
$tD = $tornarCuidador('11966660003', 'Davi Nogueira');    // cuidador 2
$tN = entrar('11966660004', 'Nina Sem Papel');            // conta sem vínculo de cuidador(a)
$tA = entrar('11966660005', 'Ana Gestora Empresa');
$tB = entrar('11966660006', 'Bruno Outra Empresa');
$e1 = req('POST', '/empresas', $tA, ['razao_social' => 'Mãos Dadas Cuidados Ltda', 'nome_fantasia' => 'Mãos Dadas', 'cnpj' => '12.345.678/0001-95'])['j']['id'] ?? '';
$e2 = req('POST', '/empresas', $tB, ['razao_social' => 'Bem Viver Assistência Ltda', 'nome_fantasia' => 'Bem Viver', 'cnpj' => '98.765.432/0001-98'])['j']['id'] ?? '';
$idC = req('GET', '/eu', $tC)['j']['usuario']['id'] ?? '';
$idD = req('GET', '/eu', $tD)['j']['usuario']['id'] ?? '';
confere('cenário: 2 cuidadores(as), 2 empresas', strlen($pid . $e1 . $e2 . $idC . $idD) === 130 && (req('GET', '/eu', $tC)['j']['papeis']['cuidador'] ?? false) === true);

echo "Empresa pede, cuidador(a) aceita\n";
$r = req('POST', "/empresas/$e1/pedidos-rede", $tA, ['telefone' => '11 9', 'modalidade' => 'mei']);
confere('celular inválido → 422', $r['s'] === 422, $r['j']);
$r = req('POST', "/empresas/$e1/pedidos-rede", $tA, ['telefone' => '(11) 96666-0004', 'modalidade' => 'mei']);
confere('conta que não é cuidador(a) → 404 (sugere convite)', $r['s'] === 404 && str_contains($r['j']['erro']['mensagem'] ?? '', 'Convidar'), $r['j']);
$r = req('POST', "/empresas/$e1/pedidos-rede", $tA, ['telefone' => '(11) 96666-0002', 'modalidade' => 'mei', 'mensagem' => 'Atendemos na Zona Sul. Quer fazer parte?']);
$ped1 = $r['j']['id'] ?? '';
confere('empresa pede a uma cuidadora já cadastrada', $r['s'] === 201 && $r['j']['status'] === 'pendente' && $r['j']['cuidador']['nome'] === 'Cida', $r['j']);
$r = req('POST', "/empresas/$e1/pedidos-rede", $tA, ['telefone' => '11966660002', 'modalidade' => 'autonomo']);
confere('não repete pedido pendente', $r['s'] === 409 && $r['j']['erro']['codigo'] === 'pedido_pendente', $r['j']);
$eq = req('GET', "/empresas/$e1/cuidadores", $tA)['j'];
$env = $eq['pedidos']['enviados'][0] ?? [];
confere('painel da empresa mostra o pedido enviado', ($env['id'] ?? '') === $ped1 && $env['cuidador']['telefone_final'] === '0002', $env);
confere('antes do aceite: sem celular completo e fora da rede', !str_contains(json_encode($eq), '11966660002') && $eq['cuidadores'] === [], $eq);
$ini = req('GET', '/eu/inicio', $tC)['j'];
confere('cuidadora vê o aviso na lista de conversas', ($ini['pedidos_rede'] ?? 0) === 1, $ini);
$rede = req('GET', '/eu/rede', $tC)['j'];
confere('cuidadora vê o pedido com o nome da empresa', ($rede['recebidos'][0]['empresa']['nome'] ?? '') === 'Mãos Dadas' && $rede['empresas'] === [], $rede);
confere('outra pessoa não responde pelo(a) cuidador(a)', req('POST', "/pedidos-rede/$ped1/aceitar", $tD, [])['s'] === 404);
confere('a empresa não aceita o próprio pedido', req('POST', "/pedidos-rede/$ped1/aceitar", $tA, [])['s'] === 404);
confere('outra empresa não vê o pedido', req('POST', "/pedidos-rede/$ped1/cancelar", $tB, [])['s'] === 404);
$r = req('POST', "/pedidos-rede/$ped1/aceitar", $tC, []);
confere('cuidadora aceita', $r['s'] === 200 && $r['j']['status'] === 'aceito' && strlen((string)$r['j']['conversa_id']) === 26, $r['j']);
$conv = $r['j']['conversa_id'] ?? '';
$eq = req('GET', "/empresas/$e1/cuidadores", $tA)['j'];
confere('entrou na rede como MEI', ($eq['cuidadores'][0]['id'] ?? '') === $idC && $eq['cuidadores'][0]['modalidade'] === 'mei' && $eq['cuidadores'][0]['status'] === 'ativo', $eq['cuidadores']);
$msgs = req('GET', "/conversas/$conv/mensagens", $tA)['j']['itens'] ?? [];
confere('aviso na conversa direta empresa ↔ cuidadora', str_contains(json_encode($msgs, JSON_UNESCAPED_UNICODE), 'entrou na rede'), $msgs);
confere('responder de novo → 409', req('POST', "/pedidos-rede/$ped1/recusar", $tC, [])['s'] === 409);
$r = req('POST', "/empresas/$e1/pedidos-rede", $tA, ['telefone' => '11966660002', 'modalidade' => 'mei']);
confere('quem já está na rede não recebe outro pedido', $r['s'] === 409 && $r['j']['erro']['codigo'] === 'ja_na_rede', $r['j']);
$rede = req('GET', '/eu/rede', $tC)['j'];
confere('cuidadora vê a empresa em "Minhas empresas"', ($rede['empresas'][0]['nome'] ?? '') === 'Mãos Dadas' && $rede['recebidos'] === [], $rede);

echo "Cuidador(a) pede, empresa aceita\n";
confere('busca com menos de 3 letras não lista nada', req('GET', '/rede/empresas?q=be', $tC)['j']['empresas'] === []);
$r = req('GET', '/rede/empresas?q=' . urlencode('bem viv'), $tC);
confere('procura empresa pelo nome', $r['s'] === 200 && ($r['j']['empresas'][0]['id'] ?? '') === $e2 && $r['j']['empresas'][0]['na_rede'] === false, $r['j']);
$r = req('GET', '/rede/empresas?q=98765432', $tC);
confere('procura empresa pelo CNPJ', ($r['j']['empresas'][0]['cnpj'] ?? '') === '98.765.432/0001-98', $r['j']);
confere('quem não é cuidador(a) não procura empresas', req('GET', '/rede/empresas?q=bem', $tN)['s'] === 403);
$r = req('POST', '/eu/pedidos-rede', $tC, ['empresa_id' => $e2, 'modalidade' => 'clt']);
confere('cuidador(a) não se oferece como CLT (só MEI ou autônomo)', $r['s'] === 422, $r['j']);
$r = req('POST', '/eu/pedidos-rede', $tC, ['empresa_id' => $e2, 'modalidade' => 'autonomo']);
$ped2 = $r['j']['id'] ?? '';
confere('cuidadora pede para entrar na rede', $r['s'] === 201 && $r['j']['status'] === 'pendente' && $r['j']['empresa']['nome'] === 'Bem Viver', $r['j']);
confere('não repete o próprio pedido', req('POST', '/eu/pedidos-rede', $tC, ['empresa_id' => $e2, 'modalidade' => 'mei'])['s'] === 409);
$rec = req('GET', "/empresas/$e2/cuidadores", $tB)['j']['pedidos']['recebidos'] ?? [];
confere('empresa recebe o pedido no painel', ($rec[0]['id'] ?? '') === $ped2 && $rec[0]['modalidade'] === 'autonomo' && $rec[0]['cuidador']['nome'] === 'Cida', $rec);
confere('a cuidadora não aceita o próprio pedido', req('POST', "/pedidos-rede/$ped2/aceitar", $tC, [])['s'] === 404);
confere('membro de outra empresa não responde', req('POST', "/pedidos-rede/$ped2/aceitar", $tA, [])['s'] === 404);
$r = req('POST', "/pedidos-rede/$ped2/recusar", $tB, []);
confere('empresa pode recusar', $r['s'] === 200 && $r['j']['status'] === 'recusado', $r['j']);
$r = req('POST', '/eu/pedidos-rede', $tC, ['empresa_id' => $e2, 'modalidade' => 'autonomo']);
$ped3 = $r['j']['id'] ?? '';
confere('depois da recusa, pode pedir de novo', $r['s'] === 201, $r['j']);
$r = req('POST', "/pedidos-rede/$ped3/cancelar", $tC, []);
confere('quem pediu pode desistir', $r['s'] === 200 && $r['j']['status'] === 'cancelado', $r['j']);
$ped4 = req('POST', '/eu/pedidos-rede', $tC, ['empresa_id' => $e2, 'modalidade' => 'autonomo'])['j']['id'] ?? '';
$r = req('POST', "/pedidos-rede/$ped4/aceitar", $tB, []);
confere('empresa aceita', $r['s'] === 200 && $r['j']['status'] === 'aceito', $r['j']);
$rede = req('GET', '/eu/rede', $tC)['j'];
confere('cuidadora está em duas redes (sem exclusividade)', count($rede['empresas']) === 2, $rede['empresas']);
$eq1 = json_encode(req('GET', "/empresas/$e1/cuidadores", $tA)['j']);
confere('uma empresa não vê a outra', !str_contains($eq1, 'Bem Viver') && !str_contains($eq1, $e2));

echo "Pedido dos dois lados = já concordaram\n";
$ped5 = req('POST', '/eu/pedidos-rede', $tD, ['empresa_id' => $e1, 'modalidade' => 'mei'])['j']['id'] ?? '';
$r = req('POST', "/empresas/$e1/pedidos-rede", $tA, ['telefone' => '11966660003', 'modalidade' => 'autonomo']);
confere('empresa pede quem já tinha pedido: entra direto', $r['s'] === 200 && $r['j']['status'] === 'aceito' && $r['j']['id'] === $ped5, $r['j']);
$d = array_values(array_filter(req('GET', "/empresas/$e1/cuidadores", $tA)['j']['cuidadores'], fn($c) => $c['id'] === $idD))[0] ?? null;
confere('vale a forma que o(a) cuidador(a) declarou', ($d['modalidade'] ?? '') === 'mei', $d);

echo "Cuidador(a) sai da rede quando quiser\n";
$r = req('POST', "/eu/empresas/$e1/sair", $tC, []);
confere('sair da rede', $r['s'] === 204, $r['j']);
$c = array_values(array_filter(req('GET', "/empresas/$e1/cuidadores", $tA)['j']['cuidadores'], fn($x) => $x['id'] === $idC))[0] ?? null;
confere('empresa vê como encerrado', ($c['status'] ?? '') === 'encerrado', $c);
confere('sair de novo → 404', req('POST', "/eu/empresas/$e1/sair", $tC, [])['s'] === 404);
$r = req('POST', "/empresas/$e1/pedidos-rede", $tA, ['telefone' => '11966660002', 'modalidade' => 'mei']);
$ped6 = $r['j']['id'] ?? '';
confere('empresa pode pedir de novo depois', $r['s'] === 201, $r['j']);

echo "Prazo\n";
$pdo->prepare('UPDATE pedidos_rede SET expira_em = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 60), $ped6]);
confere('pedido vencido some da lista da cuidadora', req('GET', '/eu/rede', $tC)['j']['recebidos'] === []);
$r = req('POST', "/pedidos-rede/$ped6/aceitar", $tC, []);
confere('pedido vencido não pode ser aceito', $r['s'] === 409 && str_contains($r['j']['erro']['mensagem'] ?? '', 'venceu'), $r['j']);

echo "Limite de celulares não encontrados\n";
for ($i = 0; $i < 20; $i++) req('POST', "/empresas/$e2/pedidos-rede", $tB, ['telefone' => sprintf('1195555%04d', $i), 'modalidade' => 'mei']);
$r = req('POST', "/empresas/$e2/pedidos-rede", $tB, ['telefone' => '11966660003', 'modalidade' => 'mei']);
confere('muitas buscas sem resultado → 429', $r['s'] === 429, $r['j']);

echo "Auditoria e log\n";
$n = (int)$pdo->query("SELECT COUNT(*) FROM auditoria WHERE acao LIKE 'rede.%'")->fetchColumn();
confere('ações de rede na auditoria', $n >= 10, $n);
$perfil = $pdo->query("SELECT perfil FROM log_acessos WHERE rota = '/pedidos-rede/{id}/aceitar' AND status = 200 ORDER BY id LIMIT 1")->fetchColumn();
confere('log registra o perfil de quem respondeu', $perfil === 'cuidador', $perfil);

echo "\n$ok ok, $falhas falha(s)\n";
exit($falhas === 0 ? 0 : 1);

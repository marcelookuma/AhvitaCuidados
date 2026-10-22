<?php
declare(strict_types=1);

/**
 * Teste de ponta a ponta da API contra um MySQL/MariaDB real.
 *   1) php -S 127.0.0.1:8099 dev-router.php   (com config de teste)
 *   2) php tests/fluxo.php http://127.0.0.1:8099
 * O banco precisa estar vazio (bin/instalar.php sem --demo).
 */

$base = ($argv[1] ?? 'http://127.0.0.1:8099') . '/api/v1';
$ok = 0; $falhas = 0;

function req(string $metodo, string $caminho, ?array $corpo = null, ?string $token = null, ?array $multipart = null): array
{
    global $base;
    $ch = curl_init($base . $caminho);
    $cab = ['X-Cuidados: 1'];
    if ($token) $cab[] = 'Authorization: Bearer ' . $token;
    if ($multipart !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
    } elseif ($corpo !== null) {
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

function entrar(string $tel): string
{
    $r = req('POST', '/auth/codigo', ['destino' => $tel]);
    $c = $r['j']['codigo_teste'] ?? '';
    $e = req('POST', '/auth/entrar', ['destino' => $tel, 'codigo' => $c]);
    return $e['j']['token'] ?? '';
}

/** Próxima data local (America/Sao_Paulo) do dia da semana, com hora. */
function proximo(int $dow, string $hora): string
{
    $tz = new DateTimeZone('America/Sao_Paulo');
    $d = new DateTimeImmutable('tomorrow', $tz);
    while ((int)$d->format('w') !== $dow) $d = $d->modify('+1 day');
    return (new DateTimeImmutable($d->format('Y-m-d') . ' ' . $hora, $tz))->format(DATE_ATOM);
}

echo "Acesso\n";
$r = req('GET', '/saude');
confere('saúde responde', $r['s'] === 200 && $r['j']['ok'] === true, $r);
$r = req('GET', '/eu');
confere('sem sessão → 401', $r['s'] === 401);
$r = req('POST', '/auth/codigo', ['destino' => '11 9']);
confere('celular inválido → 422', $r['s'] === 422 && isset($r['j']['erro']['campos']['destino']), $r['j']);
$r = req('POST', '/auth/codigo', ['destino' => '(11) 98888-0001']);
confere('pede código', $r['s'] === 200 && $r['j']['codigo_teste'] === '123456', $r['j']);
$r = req('POST', '/auth/codigo', ['destino' => '(11) 98888-0001']);
confere('limite de 30 s entre pedidos', $r['s'] === 429, $r['j']);
$r = req('POST', '/auth/entrar', ['destino' => '11988880001', 'codigo' => '000000']);
confere('código errado → 422', $r['s'] === 422);
$r = req('POST', '/auth/entrar', ['destino' => '+55 11 98888-0001', 'codigo' => '123456']);
$tGestor = $r['j']['token'] ?? '';
confere('entra e cria conta', $r['s'] === 200 && strlen($tGestor) === 64 && $r['j']['novo'] === true, $r['j']);
confere('cookie httpOnly', str_contains(strtolower($r['h']), 'httponly'));
$r = req('PATCH', '/eu', ['nome' => 'Marcos Silva', 'cpf' => '111.111.111-11'], $tGestor);
confere('CPF inválido recusado', $r['s'] === 422);
$r = req('PATCH', '/eu', ['nome' => 'Marcos Silva', 'apelido' => 'Marcos', 'cpf' => '529.982.247-25'], $tGestor);
confere('perfil do gestor', $r['s'] === 200 && $r['j']['cpf'] === '52998224725', $r['j']);
req('POST', '/eu/termos', [], $tGestor);

echo "Pessoa cuidada (onboarding do gestor)\n";
$base_p = ['nome_completo' => 'Alzira Souza', 'apelido' => 'Dona Alzira', 'nascimento' => '1943-05-10', 'sexo' => 'feminino', 'arranjo' => 'gestor_cuidador'];
$r = req('POST', '/pessoas', $base_p + ['consentimentos' => []], $tGestor);
confere('sem consentimento de saúde → 422', $r['s'] === 422);
$r = req('POST', '/pessoas', ['nome_completo' => 'Alice Lima', 'apelido' => 'Alice', 'nascimento' => '2018-02-01', 'sexo' => 'feminino', 'arranjo' => 'somente_eu', 'consentimentos' => ['dados_saude' => true]], $tGestor);
confere('menor sem responsável legal → 422', $r['s'] === 422 && str_contains(json_encode($r['j'], JSON_UNESCAPED_UNICODE), 'responsável'), $r['j']);
$r = req('POST', '/pessoas', $base_p + ['ficha' => ['alergias' => 'Dipirona', 'contatos' => [['nome' => 'Marcos', 'telefone' => '11988880001']]], 'consentimentos' => ['dados_saude' => true]], $tGestor);
$pid = $r['j']['id'] ?? '';
confere('cadastra Dona Alzira', $r['s'] === 201 && $r['j']['meu_papel'] === 'gestor' && $r['j']['idade'] >= 82, $r['j']);
confere('ficha de emergência guardada', ($r['j']['ficha']['alergias'] ?? '') === 'Dipirona');
$r = req('PATCH', "/pessoas/$pid", ['plano' => ['texto' => 'Ajuda para andar. Oferecer água.', 'importante' => 'Alergia a dipirona', 'agua_meta_copos' => 8]], $tGestor);
confere('plano de cuidado', $r['s'] === 200 && $r['j']['plano']['importante'] === 'Alergia a dipirona', $r['j']);
$r = req('POST', "/pessoas/$pid/remedios", ['nome' => 'Losartana', 'dose' => '50 mg', 'como_dar' => '1 comprimido depois do café', 'horarios' => ['20:00', '08:00']], $tGestor);
$remedio = $r['j']['id'] ?? '';
confere('remédio com horários ordenados', $r['s'] === 201 && $r['j']['horarios'] === ['08:00', '20:00'], $r['j']);

echo "Convite e cuidador(a) MEI\n";
$r = req('POST', "/pessoas/$pid/convites", ['papel' => 'cuidador', 'nome' => 'Fernanda', 'telefone' => '11988880002', 'modalidade' => 'mei', 'prazo_confirmacao_min' => 60], $tGestor);
$codigo = $r['j']['codigo'] ?? '';
confere('convite com link de WhatsApp', $r['s'] === 201 && strlen($codigo) === 6 && str_starts_with($r['j']['whatsapp'], 'https://wa.me/5511988880002'), $r['j']);
$tCuid = entrar('11988880002');
confere('cuidadora entra', strlen($tCuid) === 64);
req('PATCH', '/eu', ['nome' => 'Fernanda Lima', 'apelido' => 'Fernanda', 'cpf' => '15350946056'], $tCuid);
$r = req('GET', "/pessoas/$pid", null, $tCuid);
confere('sem vínculo não vê a pessoa (404)', $r['s'] === 404);
$r = req('GET', "/convites/$codigo", null, $tCuid);
confere('vê o convite', $r['s'] === 200 && $r['j']['pessoa'] === 'Dona Alzira' && $r['j']['modalidade'] === 'mei', $r['j']);
$r = req('POST', "/convites/$codigo/aceitar", [], $tCuid);
confere('aceita o convite', $r['s'] === 200 && $r['j']['pessoa_id'] === $pid, $r['j']);
$r = req('POST', "/convites/$codigo/aceitar", [], $tCuid);
confere('convite não serve duas vezes', in_array($r['s'], [404, 409], true));
$r = req('GET', '/eu', null, $tCuid);
confere('papel de cuidador(a) reconhecido', $r['j']['papeis']['cuidador'] === true, $r['j']);
$r = req('GET', "/pessoas/$pid", null, $tCuid);
confere('cuidadora não vê CPF da pessoa', $r['s'] === 200 && !array_key_exists('cpf', $r['j']) && isset($r['j']['ficha']), $r['j']);
$r = req('PATCH', "/pessoas/$pid", ['apelido' => 'X'], $tCuid);
confere('cuidadora não altera cadastro (403)', $r['s'] === 403);

echo "Agenda do(a) cuidador(a)\n";
$vinc = req('GET', "/pessoas/$pid/vinculos", null, $tGestor)['j']['vinculos'] ?? [];
$cuidId = '';
foreach ($vinc as $v) if ($v['papel'] === 'cuidador') $cuidId = $v['usuario']['id'];
$r = req('POST', "/pessoas/$pid/atendimentos", ['cuidador_id' => $cuidId, 'inicio' => proximo(6, '07:00'), 'fim' => proximo(6, '19:00')], $tGestor);
confere('MEI sem disponibilidade: proposta bloqueada', $r['s'] === 409 && $r['j']['erro']['codigo'] === 'fora_da_agenda', $r['j']);
$r = req('PUT', '/agenda/disponibilidade', ['janelas' => [
    ['dia_semana' => 6, 'inicio' => '07:00', 'fim' => '19:00'],
    ['dia_semana' => 5, 'inicio' => '19:00', 'fim' => '07:00'], // sexta à noite, vira o sábado
]], $tCuid);
confere('cuidadora marca a própria disponibilidade', $r['s'] === 200 && count($r['j']['disponibilidade']) === 2, $r['j']);
$r = req('PUT', '/agenda/disponibilidade', ['janelas' => []], $tGestor);
confere('agenda do gestor é só dele (não mexe na da cuidadora)', $r['s'] === 200 && req('GET', '/agenda', null, $tCuid)['j']['disponibilidade'] !== []);
$r = req('GET', "/pessoas/$pid/cuidadores/$cuidId/disponivel?inicio=" . urlencode(proximo(6, '06:00')) . '&fim=' . urlencode(proximo(6, '12:00')), null, $tGestor);
confere('fora da janela: aviso antes de enviar', $r['j']['disponivel'] === false, $r['j']);
$r = req('POST', "/pessoas/$pid/atendimentos", ['cuidador_id' => $cuidId, 'inicio' => proximo(5, '22:00'), 'fim' => (new DateTimeImmutable(proximo(5, '22:00')))->modify('+8 hours')->format(DATE_ATOM), 'valor_centavos' => 18000], $tGestor);
confere('atendimento noturno dentro da janela que vira o dia', $r['s'] === 201 && $r['j']['status'] === 'proposto', $r['j']);
$r = req('POST', "/pessoas/$pid/atendimentos", ['cuidador_id' => $cuidId, 'inicio' => proximo(6, '08:00'), 'fim' => proximo(6, '18:00'), 'valor_centavos' => 18000], $tGestor);
$at = $r['j']['id'] ?? '';
confere('proposta de sábado', $r['s'] === 201, $r['j']);
$r = req('GET', "/pessoas/$pid/feed", null, $tCuid);
$props = array_filter($r['j']['pendencias'] ?? [], fn($p) => $p['tipo'] === 'proposta');
confere('proposta aparece na conversa da cuidadora', count($props) === 2, $r['j']['pendencias'] ?? null);
$r = req('POST', "/atendimentos/$at/responder", ['aceitar' => true], $tGestor);
confere('gestor não responde pela cuidadora', $r['s'] === 404);
$r = req('POST', "/atendimentos/$at/responder", ['aceitar' => true], $tCuid);
confere('cuidadora aceita', $r['s'] === 200 && $r['j']['status'] === 'aceito', $r['j']);
$r = req('POST', "/pessoas/$pid/atendimentos", ['cuidador_id' => $cuidId, 'inicio' => proximo(6, '09:00'), 'fim' => proximo(6, '12:00')], $tGestor);
confere('choque de horário bloqueado', $r['s'] === 409 && $r['j']['erro']['codigo'] === 'choque', $r['j']);
$r = req('POST', "/atendimentos/$at/confirmar", ['vou' => false, 'motivo' => 'Consulta médica'], $tCuid);
confere('"Não vou" gera alerta para o gestor', $r['s'] === 200 && $r['j']['confirmacao'] === 'nao_vou', $r['j']);
$painel = req('GET', "/pessoas/$pid/painel", null, $tGestor)['j'];
confere('alerta no painel do gestor', count(array_filter($painel['alertas'] ?? [], fn($a) => $a['tipo'] === 'nao_vou')) === 1, $painel['alertas'] ?? null);

echo "Conversa: serviço, registros e mensagens (versão Conversa)\n";
$pres = ulid();
$r = req('POST', "/pessoas/$pid/presencas", ['id' => $pres, 'em' => gmdate('c')], $tCuid);
confere('Iniciar serviço', $r['s'] === 201 && $r['j']['id'] === $pres, $r['j']);
$r = req('POST', "/pessoas/$pid/presencas", ['id' => ulid()], $tCuid);
confere('não abre dois serviços ao mesmo tempo', $r['s'] === 200 && $r['j']['id'] === $pres);
$r = req('POST', "/pessoas/$pid/presencas", ['id' => ulid()], $tGestor);
confere('gestor não "inicia serviço"', $r['s'] === 403);
$reg = ulid();
$r = req('POST', "/pessoas/$pid/registros", ['id' => $reg, 'tipo' => 'remedio', 'rotulo' => 'Deu o remédio', 'detalhe' => 'Losartana 50 mg', 'remedio_id' => $remedio, 'dados' => ['situacao' => 'dado'], 'em' => gmdate('c')], $tCuid);
confere('registra remédio', $r['s'] === 201 && $r['j']['autor']['nome'] === 'Fernanda', $r['j']);
$r = req('POST', "/pessoas/$pid/registros", ['id' => $reg, 'tipo' => 'remedio', 'rotulo' => 'Deu o remédio', 'detalhe' => 'Losartana 50 mg', 'remedio_id' => $remedio, 'dados' => ['situacao' => 'dado']], $tCuid);
confere('reenvio offline não duplica', $r['s'] === 200 && $r['j']['id'] === $reg);
$r = req('POST', "/pessoas/$pid/registros", ['id' => ulid(), 'tipo' => 'agua', 'rotulo' => 'Água', 'detalhe' => 'Bebeu 2 copos', 'dados' => ['copos' => 2]], $tCuid);
confere('registra água', $r['s'] === 201);
$r = req('POST', "/pessoas/$pid/registros", ['id' => ulid(), 'tipo' => 'sinais', 'rotulo' => 'Temperatura', 'detalhe' => '38,2 °C', 'dados' => ['temperatura' => 38.2]], $tCuid);
confere('febre vira alerta automático', $r['s'] === 201 && $r['j']['alerta'] === true, $r['j']);
$r = req('POST', "/pessoas/$pid/registros", ['id' => ulid(), 'tipo' => 'procedimento', 'rotulo' => 'Curativo'], $tCuid);
confere('cuidadora não registra procedimento de enfermagem', $r['s'] === 403);
$r = req('POST', "/pessoas/$pid/registros", ['id' => ulid(), 'tipo' => 'inventado', 'rotulo' => 'X'], $tCuid);
confere('tipo desconhecido recusado', $r['s'] === 422);
$antigo = (new DateTimeImmutable('-10 days'))->format(DATE_ATOM);
$r = req('POST', "/pessoas/$pid/registros", ['id' => ulid(), 'tipo' => 'nota', 'rotulo' => 'Antigo', 'em' => $antigo], $tCuid);
confere('registro com mais de 7 dias recusado', $r['s'] === 422);

$arq = tempnam(sys_get_temp_dir(), 'img');
file_put_contents($arq, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$r = req('POST', '/midias', null, $tCuid, ['pessoa_id' => $pid, 'arquivo' => new CURLFile($arq, 'image/png', 'comida.png')]);
$midia = $r['j']['id'] ?? '';
confere('envia foto', $r['s'] === 201 && $r['j']['tipo'] === 'foto', $r['j']);
$r = req('POST', "/pessoas/$pid/registros", ['id' => ulid(), 'tipo' => 'comida', 'rotulo' => 'Almoço', 'detalhe' => 'Comeu metade', 'midia_id' => $midia], $tCuid);
confere('registro de comida com foto', $r['s'] === 201 && $r['j']['midia_id'] === $midia);
$r = req('GET', "/midias/$midia", null, $tGestor);
confere('gestor vê a foto (com permissão)', $r['s'] === 200 && str_contains($r['h'], 'image/png'));
$txt = tempnam(sys_get_temp_dir(), 'txt');
file_put_contents($txt, '<?php echo 1;');
$r = req('POST', '/midias', null, $tCuid, ['pessoa_id' => $pid, 'arquivo' => new CURLFile($txt, 'image/png', 'falso.png')]);
confere('arquivo disfarçado de foto recusado', $r['s'] === 422);

$msg = ulid();
$r = req('POST', "/pessoas/$pid/mensagens", ['id' => $msg, 'tipo' => 'texto', 'texto' => 'Bom dia! Ela acordou bem.'], $tCuid);
confere('cuidadora manda mensagem', $r['s'] === 201);
$r = req('GET', "/pessoas/$pid/feed", null, $tGestor);
$tipos = array_count_values(array_map(fn($i) => $i['tipo_item'], $r['j']['itens'] ?? []));
confere('gestor vê registros e mensagens na mesma conversa', ($tipos['registro'] ?? 0) >= 4 && ($tipos['mensagem'] ?? 0) >= 3, $tipos);
$cursor = $r['j']['cursor'];
req('POST', "/pessoas/$pid/visto", [], $tGestor);
$r = req('GET', "/pessoas/$pid/feed?desde=" . urlencode($cursor), null, $tCuid);
confere('✓✓: cuidadora sabe que o gestor viu', $r['j']['visto_por_outros_ate'] !== null, $r['j']);
sleep(3);
$cursor = req('GET', "/pessoas/$pid/feed", null, $tCuid)['j']['cursor'];
sleep(3);
req('POST', "/pessoas/$pid/mensagens", ['id' => ulid(), 'tipo' => 'texto', 'texto' => 'Ela almoçou.'], $tGestor);
$r = req('GET', "/pessoas/$pid/feed?desde=" . urlencode($cursor), null, $tCuid);
confere('busca incremental (desde) traz só o novo', count($r['j']['itens']) === 1 && $r['j']['itens'][0]['texto'] === 'Ela almoçou.', $r['j']['itens']);
$r = req('POST', "/registros/$reg/anular", ['motivo' => 'Registrei errado'], $tCuid);
confere('desfazer registro sem apagar histórico', $r['s'] === 204);
$lt = req('GET', "/pessoas/$pid/registros?tipo=remedio", null, $tGestor)['j']['registros'] ?? [];
confere('linha do tempo com filtro mostra o anulado', count($lt) === 1 && $lt[0]['anulado'] === true, $lt);
$r = req('POST', "/presencas/$pres/finalizar", ['recado' => 'Ela dormiu às 14h.'], $tCuid);
confere('Finalizar com resumo automático', $r['s'] === 200 && ($r['j']['resumo']['registros']['agua'] ?? 0) === 1, $r['j']);
$ini = req('GET', '/eu/inicio', null, $tCuid)['j'];
confere('lista de conversas da cuidadora', count($ini['conversas']) === 1 && $ini['conversas'][0]['nome'] === 'Dona Alzira', $ini);
$painel = req('GET', "/pessoas/$pid/painel", null, $tGestor)['j'];
confere('indicadores do dia (água, remédios)', $painel['hoje']['agua_copos'] === 2 && $painel['hoje']['remedios_previstos'] === 2, $painel['hoje']);

echo "Permissões por funcionalidade\n";
$vid = '';
foreach (req('GET', "/pessoas/$pid/vinculos", null, $tGestor)['j']['vinculos'] as $v) if ($v['papel'] === 'cuidador') $vid = $v['id'];
$r = req('PATCH', "/vinculos/$vid", ['permissoes' => ['registrar', 'mensagens', 'administrar']], $tGestor);
confere('"administrar" nunca vai para cuidador(a)', $r['s'] === 200 && !in_array('administrar', $r['j']['permissoes'], true), $r['j']);
$r = req('GET', "/midias/$midia", null, $tCuid);
confere('foto própria continua visível', $r['s'] === 200);
$r = req('GET', "/pessoas/$pid/registros", null, $tCuid);
confere('sem "ver_registros" não vê a linha do tempo', $r['s'] === 403);

echo "Empresa\n";
$tEmp = entrar('11988880003');
req('PATCH', '/eu', ['nome' => 'Paula Rocha'], $tEmp);
$r = req('POST', '/empresas', ['razao_social' => 'CuidaBem Home Care Ltda', 'nome_fantasia' => 'CuidaBem', 'cnpj' => '11.222.333/0001-80'], $tEmp);
confere('CNPJ inválido recusado', $r['s'] === 422);
$r = req('POST', '/empresas', ['razao_social' => 'CuidaBem Home Care Ltda', 'nome_fantasia' => 'CuidaBem', 'cnpj' => '11.222.333/0001-81'], $tEmp);
$eid = $r['j']['id'] ?? '';
confere('cria empresa', $r['s'] === 201 && $r['j']['meu_papel'] === 'admin', $r['j']);
$r = req('POST', "/empresas/$eid/convites", ['tipo' => 'cuidador', 'nome' => 'Rosa', 'modalidade' => 'mei'], $tEmp);
$cRosa = $r['j']['codigo'] ?? '';
$tRosa = entrar('11988880004');
req('PATCH', '/eu', ['nome' => 'Rosa Alves'], $tRosa);
$r = req('POST', "/convites/$cRosa/aceitar", [], $tRosa);
confere('cuidadora entra na rede da empresa', $r['s'] === 200 && $r['j']['empresa_id'] === $eid, $r['j']);
$r = req('POST', "/empresas/$eid/convites", ['tipo' => 'cliente', 'nome' => 'Marcos'], $tEmp);
$cCli = $r['j']['codigo'] ?? '';
$r = req('POST', "/convites/$cCli/aceitar", ['pessoa_id' => $pid], $tCuid);
confere('só o gestor liga a pessoa à empresa', $r['s'] === 403 || $r['s'] === 404, $r['j']);
$r = req('POST', "/convites/$cCli/aceitar", ['pessoa_id' => $pid, 'permissoes' => ['atendimentos']], $tGestor);
confere('gestor liga Dona Alzira à empresa', $r['s'] === 200, $r['j']);
$r = req('GET', "/pessoas/$pid/feed", null, $tEmp);
confere('empresa NÃO vê registros de saúde sem permissão', $r['s'] === 403);
$cli = req('GET', "/empresas/$eid/clientes", null, $tEmp)['j']['clientes'] ?? [];
confere('cliente na lista da empresa, sem ficha de saúde', count($cli) === 1 && !isset($cli[0]['ficha']), $cli);
$r = req('POST', "/empresas/$eid/propostas", ['pessoa_id' => $pid, 'inicio' => proximo(0, '08:00'), 'fim' => proximo(0, '17:00'), 'valor_centavos' => 19000], $tEmp);
confere('ninguém disponível → aviso claro', $r['s'] === 409 && $r['j']['erro']['codigo'] === 'ninguem_disponivel', $r['j']);
req('PUT', '/agenda/disponibilidade', ['janelas' => [['dia_semana' => 0, 'inicio' => '07:00', 'fim' => '19:00']]], $tRosa);
$tAna = entrar('11988880005');
$r = req('POST', "/empresas/$eid/convites", ['tipo' => 'cuidador', 'nome' => 'Ana', 'modalidade' => 'autonomo'], $tEmp);
req('POST', '/convites/' . $r['j']['codigo'] . '/aceitar', [], $tAna);
req('PUT', '/agenda/disponibilidade', ['janelas' => [['dia_semana' => 0, 'inicio' => '06:00', 'fim' => '20:00']]], $tAna);
$r = req('POST', "/empresas/$eid/propostas", ['pessoa_id' => $pid, 'inicio' => proximo(0, '08:00'), 'fim' => proximo(0, '17:00'), 'valor_centavos' => 19000], $tEmp);
$prop = $r['j']['atendimento']['id'] ?? '';
confere('proposta vai para quem tem disponibilidade', $r['s'] === 201 && $r['j']['enviada_para'] === 2, $r['j']);
$ag = req('GET', '/agenda', null, $tRosa)['j'];
confere('proposta aparece na agenda da Rosa', count($ag['propostas_abertas']) === 1 && $ag['propostas_abertas'][0]['empresa'] === 'CuidaBem', $ag);
$r = req('POST', "/atendimentos/$prop/responder", ['aceitar' => true], $tRosa);
confere('Rosa aceita primeiro', $r['s'] === 200 && $r['j']['cuidador']['nome'] === 'Rosa', $r['j']);
$r = req('POST', "/atendimentos/$prop/responder", ['aceitar' => true], $tAna);
confere('Ana chega depois: "outra pessoa aceitou antes"', $r['s'] === 409, $r['j']);
$r = req('GET', "/pessoas/$pid", null, $tRosa);
confere('Rosa passa a ver Dona Alzira como cuidadora', $r['s'] === 200 && $r['j']['sou_cuidador'] === true, $r['j']);
$conv = req('GET', "/empresas/$eid/conversas", null, $tEmp)['j']['conversas'] ?? [];
$cRosaId = '';
foreach ($conv as $c) if ($c['com']['nome'] === 'Rosa') $cRosaId = $c['conversa_id'];
$r = req('POST', "/conversas/$cRosaId/mensagens", ['id' => ulid(), 'tipo' => 'texto', 'texto' => 'Obrigada, Rosa!'], $tEmp);
confere('empresa conversa com a cuidadora', $r['s'] === 201);
$r = req('GET', "/conversas/$cRosaId/mensagens", null, $tAna);
confere('outra cuidadora não lê a conversa', $r['s'] === 404);
$pe = req('GET', "/empresas/$eid/painel", null, $tEmp)['j'];
confere('painel da empresa', $pe['equipe'] === 2 && $pe['clientes'] === 1, $pe);

echo "Cobertura (sem resposta no prazo)\n";
// Simula: atendimento aceito que começa daqui a 30 min, prazo de 60 min → já venceu.
$pdo = new PDO('mysql:host=127.0.0.1;dbname=cuidados;charset=utf8mb4', 'cuidados', 'cuidados');
$pdo->exec("UPDATE atendimentos SET inicio = UTC_TIMESTAMP() + INTERVAL 30 MINUTE, fim = UTC_TIMESTAMP() + INTERVAL 8 HOUR WHERE id = '$prop'");
$pe = req('GET', "/empresas/$eid/painel", null, $tEmp)['j'];
$sem = array_filter($pe['alertas'], fn($a) => $a['tipo'] === 'sem_confirmacao');
confere('alerta neutro para a empresa', count($sem) === 1 && !str_contains(mb_strtolower(json_encode($sem, JSON_UNESCAPED_UNICODE)), 'falta'), $pe['alertas']);
$r = req('POST', "/atendimentos/$prop/confirmar", ['vou' => true], $tRosa);
$pe = req('GET', "/empresas/$eid/painel", null, $tEmp)['j'];
confere('"Vou" resolve o alerta', count(array_filter($pe['alertas'], fn($a) => $a['tipo'] === 'sem_confirmacao')) === 0);

echo "LGPD\n";
$r = req('GET', "/pessoas/$pid/exportar", null, $tCuid);
confere('cuidadora não exporta dados da pessoa', $r['s'] === 403);
$r = req('GET', "/pessoas/$pid/exportar", null, $tGestor);
confere('gestor exporta tudo (portabilidade)', $r['s'] === 200 && count($r['j']['registros']) >= 5, array_keys($r['j']));
$r = req('POST', '/eu/excluir', ['confirmar' => 'EXCLUIR'], $tGestor);
confere('único gestor não some sem passar a gestão', $r['s'] === 409);
$r = req('POST', "/pessoas/$pid/excluir", ['confirmar' => 'outra'], $tGestor);
confere('exclusão exige confirmar o nome', $r['s'] === 422);
$r = req('POST', "/pessoas/$pid/excluir", ['confirmar' => 'dona alzira'], $tGestor);
confere('exclui dados da pessoa', $r['s'] === 204);
$r = req('GET', "/pessoas/$pid", null, $tGestor);
confere('pessoa excluída some para todos', $r['s'] === 404);
$restam = (int)$pdo->query("SELECT COUNT(*) FROM registros WHERE pessoa_id = '$pid'")->fetchColumn();
confere('registros de saúde apagados', $restam === 0);
confere('foto apagada do disco', req('GET', "/midias/$midia", null, $tCuid)['s'] === 404);

echo "Sessão\n";
$r = req('POST', '/auth/sair', [], $tCuid);
confere('sair', $r['s'] === 204);
confere('token revogado', req('GET', '/eu', null, $tCuid)['s'] === 401);

echo "\n$ok ok, $falhas falha(s)\n";
exit($falhas === 0 ? 0 : 1);

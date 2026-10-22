<?php
declare(strict_types=1);

/**
 * Dados FICTÍCIOS para demonstração (nunca em produção com pessoas reais).
 *   php bin/demo.php
 * Entrar com o código fixo de teste (configuração 'ambiente' => 'teste', 'teste_fixo' => '123456'):
 *   Gestor(a)     Marcos     (11) 90000-0001
 *   Cuidador(a)   Fernanda   (11) 90000-0002  (MEI)
 *   Empresa       Paula      (11) 90000-0003  (CuidaBem Home Care)
 */
if (!class_exists(\Cuidados\Support\Bd::class)) {
    require dirname(__DIR__) . '/src/bootstrap.php';
}

use Cuidados\Modulos\Conversas;
use Cuidados\Modulos\Permissoes;
use Cuidados\Support\Bd;
use Cuidados\Support\Tempo;
use Cuidados\Support\Ulid;

if ((int)Bd::valor("SELECT COUNT(*) FROM usuarios WHERE telefone = '+5511900000001'") > 0) {
    echo "Demonstração já carregada.\n";
    return;
}

$agora = Tempo::agora();
$sql = fn(DateTimeImmutable $d) => $d->format('Y-m-d H:i:s');
$fuso = Tempo::fuso();
$hoje = fn(string $hora) => (new DateTimeImmutable($agora->setTimezone($fuso)->format('Y-m-d') . " $hora", $fuso))->setTimezone(new DateTimeZone('UTC'));

$usuario = function (string $nome, string $apelido, string $tel, string $cpf) use ($agora, $sql): string {
    $id = Ulid::novo();
    Bd::inserir('usuarios', ['id' => $id, 'nome' => $nome, 'apelido' => $apelido, 'telefone' => $tel, 'cpf' => $cpf,
        'preferencias' => ['fonte' => 1, 'voz' => false, 'modo' => 'conversa'], 'criado_em' => $sql($agora), 'atualizado_em' => $sql($agora)]);
    Bd::inserir('consentimentos', ['id' => Ulid::novo(), 'usuario_id' => $id, 'tipo' => 'termos', 'versao' => '2026-10', 'aceito_em' => $sql($agora)]);
    return $id;
};

$marcos = $usuario('Marcos Souza', 'Marcos', '+5511900000001', '52998224725');
$fernanda = $usuario('Fernanda Lima', 'Fernanda', '+5511900000002', '15350946056');
$paula = $usuario('Paula Rocha', 'Paula', '+5511900000003', '39053344705');
$renata = $usuario('Renata Lima', 'Renata', '+5511900000004', '11144477735');

$pessoa = function (string $nome, string $apelido, string $nasc, string $sexo, string $arranjo, array $ficha, array $plano, string $gestor) use ($agora, $sql): string {
    $id = Ulid::novo();
    Bd::inserir('pessoas_cuidadas', ['id' => $id, 'nome_completo' => $nome, 'apelido' => $apelido, 'nascimento' => $nasc, 'sexo' => $sexo,
        'arranjo' => $arranjo, 'ficha' => $ficha, 'plano' => $plano, 'criado_por' => $gestor, 'criado_em' => $sql($agora), 'atualizado_em' => $sql($agora)]);
    Bd::inserir('vinculos', ['id' => Ulid::novo(), 'pessoa_id' => $id, 'usuario_id' => $gestor, 'papel' => 'gestor', 'relacao' => 'Tutor(a)',
        'permissoes' => Permissoes::padrao('gestor'), 'criado_em' => $sql($agora)]);
    Bd::inserir('consentimentos', ['id' => Ulid::novo(), 'usuario_id' => $gestor, 'pessoa_id' => $id, 'tipo' => 'dados_saude', 'versao' => '2026-10', 'aceito_em' => $sql($agora)]);
    return $id;
};

$alzira = $pessoa('Alzira Souza', 'Dona Alzira', '1943-05-10', 'feminino', 'gestor_cuidador',
    ['alergias' => 'Dipirona', 'condicoes' => 'Hipertensão, diabetes tipo 2', 'convenio' => 'Saúde Bem · Plano Ouro', 'endereco' => 'Rua das Flores, 120 — Vila Mariana, São Paulo',
     'observacoes' => 'Usa andador.', 'contatos' => [['nome' => 'Marcos (filho)', 'telefone' => '+5511900000001']]],
    ['texto' => "Precisa de ajuda para levantar e andar (usa andador).\nOferecer água ao longo do dia.\nGosta de ouvir rádio depois do almoço.", 'importante' => 'Alergia a dipirona. Nunca dar dipirona.',
     'cuidados' => [['titulo' => 'Pele', 'como' => 'Hidratar as pernas depois do banho.'], ['titulo' => 'Andar', 'como' => 'Caminhar até a sala duas vezes por dia, com o andador.']], 'agua_meta_copos' => 8],
    $marcos);
$alice = $pessoa('Alice Lima', 'Alice', '2018-03-02', 'feminino', 'gestor_cuidador',
    ['alergias' => '', 'condicoes' => '', 'convenio' => '', 'endereco' => '', 'observacoes' => '', 'contatos' => [['nome' => 'Renata (mãe)', 'telefone' => '+5511900000004']]],
    ['texto' => 'Lanche às 10h. Pode brincar no quintal.', 'importante' => '', 'cuidados' => [], 'agua_meta_copos' => 6], $renata);
Bd::inserir('consentimentos', ['id' => Ulid::novo(), 'usuario_id' => $renata, 'pessoa_id' => $alice, 'tipo' => 'responsavel_legal', 'versao' => '2026-10', 'aceito_em' => $sql($agora)]);

foreach ([[$alzira, 'mei'], [$alice, 'mei']] as [$p, $mod]) {
    Bd::inserir('vinculos', ['id' => Ulid::novo(), 'pessoa_id' => $p, 'usuario_id' => $fernanda, 'papel' => 'cuidador', 'modalidade' => $mod,
        'permissoes' => Permissoes::padrao('cuidador'), 'prazo_confirmacao_min' => 60, 'criado_em' => $sql($agora)]);
}

$remedio = function (string $p, string $nome, string $dose, string $como, array $h) use ($marcos, $agora, $sql): string {
    $id = Ulid::novo();
    Bd::inserir('remedios', ['id' => $id, 'pessoa_id' => $p, 'nome' => $nome, 'dose' => $dose, 'como_dar' => $como, 'horarios' => $h,
        'criado_por' => $marcos, 'criado_em' => $sql($agora), 'atualizado_em' => $sql($agora)]);
    return $id;
};
$losartana = $remedio($alzira, 'Losartana', '50 mg', '1 comprimido branco, depois do café', ['08:00', '20:00']);
$metformina = $remedio($alzira, 'Metformina', '850 mg', '1 comprimido, depois da refeição', ['08:00', '15:00', '20:00']);

for ($d = 1; $d <= 6; $d++) {
    Bd::inserir('disponibilidades', ['id' => Ulid::novo(), 'usuario_id' => $fernanda, 'dia_semana' => $d, 'inicio' => '07:00:00', 'fim' => '19:00:00', 'criado_em' => $sql($agora)]);
}

$atend = function (string $p, string $cuid, string $solic, DateTimeImmutable $i, DateTimeImmutable $f, string $status, ?int $valor = null) use ($agora, $sql): string {
    $id = Ulid::novo();
    Bd::inserir('atendimentos', ['id' => $id, 'pessoa_id' => $p, 'cuidador_id' => $cuid, 'solicitante_id' => $solic, 'origem' => 'gestor',
        'inicio' => $sql($i), 'fim' => $sql($f), 'valor_centavos' => $valor, 'status' => $status, 'prazo_confirmacao_min' => 60,
        'respondido_em' => $status === 'aceito' ? $sql($agora) : null, 'confirmacao' => null, 'criado_em' => $sql($agora)]);
    return $id;
};
$ini = $hoje('07:00'); $fim = $hoje('19:00');
$atHoje = $atend($alzira, $fernanda, $marcos, $ini, $fim, 'aceito');
Bd::exec("UPDATE atendimentos SET confirmacao = 'vou', confirmacao_em = :a WHERE id = :id", ['a' => $sql($ini->modify('-2 hours')), 'id' => $atHoje]);
$atend($alzira, $fernanda, $marcos, $ini->modify('+1 day'), $fim->modify('+1 day'), 'aceito');
$sab = $ini; while ((int)$sab->setTimezone($fuso)->format('w') !== 6) $sab = $sab->modify('+1 day');
if ($sab <= $agora) $sab = $sab->modify('+7 days');
$atend($alice, $fernanda, $renata, $sab->modify('+1 hour'), $sab->modify('+7 hours'), 'proposto', 18000);

// Conversa de hoje com Dona Alzira
$cid = Conversas::daPessoa($alzira);
$inicioServico = min($hoje('07:32'), $agora->modify('-10 minutes'));
$presenca = Ulid::novo();
Bd::inserir('presencas', ['id' => $presenca, 'pessoa_id' => $alzira, 'cuidador_id' => $fernanda, 'atendimento_id' => $atHoje, 'inicio_em' => $sql($inicioServico), 'criado_em' => $sql($inicioServico)]);
$msg = function (?string $autor, string $tipo, string $texto, DateTimeImmutable $em) use ($cid, $sql) {
    Bd::inserir('mensagens', ['id' => Ulid::novo((int)($em->format('U') * 1000)), 'conversa_id' => $cid, 'autor_id' => $autor, 'tipo' => $tipo, 'texto' => $texto, 'criado_em' => $sql($em), 'recebido_em' => $sql($em)]);
};
$reg = function (string $tipo, string $rotulo, string $detalhe, array $dados, DateTimeImmutable $em, ?string $rem = null, bool $alerta = false) use ($alzira, $fernanda, $presenca, $sql) {
    $id = Ulid::novo((int)($em->format('U') * 1000));
    Bd::inserir('registros', ['id' => $id, 'pessoa_id' => $alzira, 'autor_id' => $fernanda, 'presenca_id' => $presenca, 'tipo' => $tipo, 'rotulo' => $rotulo,
        'detalhe' => $detalhe, 'dados' => $dados ?: null, 'alerta' => (int)$alerta, 'remedio_id' => $rem, 'ocorrido_em' => $sql($em), 'recebido_em' => $sql($em)]);
    return $id;
};
$t = fn(string $h) => min($hoje($h), $agora->modify('-1 minute'));
$msg(null, 'sistema', 'Conversa criada. Tudo o que for registrado aqui fica guardado com dia e hora.', $inicioServico->modify('-1 day'));
$msg(null, 'sistema', '▶️ Serviço iniciado às ' . $inicioServico->setTimezone($fuso)->format('H:i') . '.', $inicioServico);
$reg('remedio', 'Deu o remédio', 'Losartana 50 mg', ['situacao' => 'dado'], $t('08:00'), $losartana);
$reg('remedio', 'Deu o remédio', 'Metformina 850 mg', ['situacao' => 'dado'], $t('08:02'), $metformina);
$reg('comida', 'Café da manhã', 'Comeu tudo', [], $t('08:12'));
$msg($marcos, 'texto', 'Bom dia, Fernanda! Como ela amanheceu hoje?', $t('08:15'));
$msg($fernanda, 'texto', 'Bom dia! Acordou tranquila, já tomou o café e os remédios da manhã.', $t('08:20'));
$reg('agua', 'Água', 'Bebeu 2 copos', ['copos' => 2], $t('09:00'));
$reg('humor', 'Como ela está', 'Bem', ['nivel' => 4], $t('09:20'));
$reg('comida', 'Almoço', 'Comeu metade', [], $t('12:45'));
$alertaId = $reg('algo', 'Aconteceu algo', 'Dor forte no joelho', [], $t('14:50'), null, true);
Bd::inserir('alertas', ['id' => Ulid::novo(), 'pessoa_id' => $alzira, 'tipo' => 'registro', 'referencia_id' => $alertaId, 'texto' => 'Fernanda registrou: Aconteceu algo — Dor forte no joelho', 'criado_em' => $sql($t('14:50'))]);
$msg($marcos, 'texto', 'Obrigado por avisar. Se a dor continuar, liga pro Dr. Paulo.', $t('14:53'));
Conversas::marcarVisto($cid, $marcos, $sql($t('14:53')));
Conversas::marcarVisto($cid, $fernanda, $sql($t('14:50')));

// Empresa
$emp = Ulid::novo();
Bd::inserir('empresas', ['id' => $emp, 'razao_social' => 'CuidaBem Home Care Ltda', 'nome_fantasia' => 'CuidaBem', 'cnpj' => '11222333000181',
    'criado_por' => $paula, 'criado_em' => $sql($agora), 'atualizado_em' => $sql($agora)]);
Bd::inserir('empresa_membros', ['id' => Ulid::novo(), 'empresa_id' => $emp, 'usuario_id' => $paula, 'papel' => 'admin', 'criado_em' => $sql($agora)]);
Bd::inserir('empresa_cuidadores', ['id' => Ulid::novo(), 'empresa_id' => $emp, 'usuario_id' => $fernanda, 'modalidade' => 'mei', 'criado_em' => $sql($agora)]);
Bd::atualizar('pessoas_cuidadas', ['empresa_id' => $emp, 'empresa_permissoes' => ['atendimentos', 'plano_ver']], ['id' => $alzira]);
$cd = Conversas::direta($emp, $fernanda);
Bd::inserir('mensagens', ['id' => Ulid::novo(), 'conversa_id' => $cd, 'autor_id' => $paula, 'tipo' => 'texto',
    'texto' => 'Oi, Fernanda! Se tiver disponibilidade no domingo, temos uma proposta. Você decide.', 'criado_em' => $sql($agora->modify('-3 hours')), 'recebido_em' => $sql($agora->modify('-3 hours'))]);

echo "✓ Demonstração carregada (Marcos, Fernanda, Paula — código 123456 em ambiente de teste).\n";

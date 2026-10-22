<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Support\Bd;
use Cuidados\Support\Tempo;

/** Saída da API: só o necessário para a tela (minimização de dados). */
final class Formata
{
    /** @var array<string,array<string,mixed>> */
    private static array $cacheUsuarios = [];

    /** @param array<string,mixed> $u @return array<string,mixed> */
    public static function eu(array $u): array
    {
        return [
            'id' => $u['id'],
            'nome' => $u['nome'],
            'apelido' => $u['apelido'],
            'cpf' => $u['cpf'],
            'telefone' => $u['telefone'],
            'email' => $u['email'],
            'preferencias' => json_decode((string)($u['preferencias'] ?? '{}'), true) ?: (object)[],
        ];
    }

    /** @return array{id:string,nome:string}|null */
    public static function pessoaPublica(?string $usuarioId): ?array
    {
        if ($usuarioId === null) return null;
        if (!isset(self::$cacheUsuarios[$usuarioId])) {
            $u = Bd::um('SELECT id, nome, apelido FROM usuarios WHERE id = :id', ['id' => $usuarioId]);
            self::$cacheUsuarios[$usuarioId] = $u === null
                ? ['id' => $usuarioId, 'nome' => 'Usuário']
                : ['id' => $u['id'], 'nome' => (string)($u['apelido'] ?: ($u['nome'] ? explode(' ', (string)$u['nome'])[0] : 'Usuário'))];
        }
        return self::$cacheUsuarios[$usuarioId];
    }

    /** @param array<string,mixed> $ctx @return array<string,mixed> */
    public static function pessoa(array $ctx): array
    {
        $p = $ctx['pessoa'];
        $saida = [
            'id' => $p['id'],
            'apelido' => $p['apelido'],
            'nome_completo' => $p['nome_completo'],
            'nascimento' => $p['nascimento'],
            'idade' => Tempo::idade($p['nascimento']),
            'sexo' => $p['sexo'],
            'genero' => $p['genero'],
            'arranjo' => $p['arranjo'],
            'empresa_id' => $p['empresa_id'],
            'meu_papel' => $ctx['papel'],
            'sou_cuidador' => $ctx['cuidador'],
            'permissoes' => $ctx['permissoes'],
        ];
        if (Acesso::pode($ctx, 'administrar')) {
            $saida['cpf'] = $p['cpf'];
            $saida['empresa_permissoes'] = json_decode((string)($p['empresa_permissoes'] ?? 'null'), true);
        }
        if (Acesso::pode($ctx, 'ficha_emergencia') || Acesso::pode($ctx, 'administrar')) {
            $saida['ficha'] = json_decode((string)($p['ficha'] ?? '{}'), true) ?: (object)[];
        }
        if (Acesso::pode($ctx, 'plano_ver') || Acesso::pode($ctx, 'plano_editar')) {
            $saida['plano'] = json_decode((string)($p['plano'] ?? '{}'), true) ?: (object)[];
        }
        if ($ctx['vinculo'] !== null) {
            $saida['meu_vinculo'] = ['id' => $ctx['vinculo']['id'], 'modalidade' => $ctx['vinculo']['modalidade'], 'prazo_confirmacao_min' => (int)$ctx['vinculo']['prazo_confirmacao_min']];
        }
        return $saida;
    }

    /** @param array<string,mixed> $v @return array<string,mixed> */
    public static function vinculo(array $v): array
    {
        $u = Bd::um('SELECT id, nome, apelido, telefone FROM usuarios WHERE id = :id', ['id' => $v['usuario_id']]);
        return [
            'id' => $v['id'],
            'usuario' => ['id' => $v['usuario_id'], 'nome' => $u['nome'] ?? null, 'apelido' => $u['apelido'] ?? null, 'telefone' => $u['telefone'] ?? null],
            'papel' => $v['papel'],
            'relacao' => $v['relacao'],
            'modalidade' => $v['modalidade'],
            'empresa_id' => $v['empresa_id'],
            'especialidade' => $v['especialidade'],
            'atua_como_cuidador' => (bool)$v['atua_como_cuidador'],
            'permissoes' => json_decode((string)$v['permissoes'], true) ?: [],
            'prazo_confirmacao_min' => (int)$v['prazo_confirmacao_min'],
            'status' => $v['status'],
            'desde' => Tempo::sqlParaIso($v['criado_em']),
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function registro(array $r): array
    {
        return [
            'tipo_item' => 'registro',
            'id' => $r['id'],
            'tipo' => $r['tipo'],
            'rotulo' => $r['rotulo'],
            'detalhe' => $r['detalhe'],
            'dados' => $r['dados'] !== null ? json_decode((string)$r['dados'], true) : null,
            'alerta' => (bool)$r['alerta'],
            'remedio_id' => $r['remedio_id'],
            'midia_id' => $r['midia_id'],
            'autor' => self::pessoaPublica($r['autor_id']),
            'em' => Tempo::sqlParaIso($r['ocorrido_em']),
            'recebido_em' => Tempo::sqlParaIso($r['recebido_em']),
            'anulado' => $r['anulado_por'] !== null,
            'anula_id' => $r['anula_id'],
        ];
    }

    /** @param array<string,mixed> $m @return array<string,mixed> */
    public static function mensagem(array $m): array
    {
        return [
            'tipo_item' => 'mensagem',
            'id' => $m['id'],
            'tipo' => $m['tipo'],
            'texto' => $m['texto'],
            'midia_id' => $m['midia_id'],
            'autor' => self::pessoaPublica($m['autor_id']),
            'em' => Tempo::sqlParaIso($m['criado_em']),
            'recebido_em' => Tempo::sqlParaIso($m['recebido_em']),
        ];
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    public static function atendimento(array $a, bool $comValor = true): array
    {
        $p = Bd::um('SELECT apelido FROM pessoas_cuidadas WHERE id = :id', ['id' => $a['pessoa_id']]);
        $saida = [
            'id' => $a['id'],
            'pessoa' => ['id' => $a['pessoa_id'], 'apelido' => $p['apelido'] ?? ''],
            'cuidador' => self::pessoaPublica($a['cuidador_id']),
            'solicitante' => self::pessoaPublica($a['solicitante_id']),
            'empresa_id' => $a['empresa_id'],
            'origem' => $a['origem'],
            'inicio' => Tempo::sqlParaIso($a['inicio']),
            'fim' => Tempo::sqlParaIso($a['fim']),
            'observacao' => $a['observacao'],
            'status' => $a['status'],
            'motivo_recusa' => $a['motivo_recusa'],
            'prazo_confirmacao_min' => (int)$a['prazo_confirmacao_min'],
            'confirmar_ate' => Tempo::sqlParaIso(Tempo::utc($a['inicio'])->modify('-' . (int)$a['prazo_confirmacao_min'] . ' minutes')->format('Y-m-d H:i:s')),
            'confirmacao' => $a['confirmacao'],
            'confirmacao_em' => Tempo::sqlParaIso($a['confirmacao_em']),
            'confirmacao_motivo' => $a['confirmacao_motivo'],
            'sem_resposta_no_prazo' => $a['alerta_cobertura_em'] !== null && $a['confirmacao'] === null,
        ];
        if ($comValor) {
            $saida['valor_centavos'] = $a['valor_centavos'] !== null ? (int)$a['valor_centavos'] : null;
        }
        return $saida;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function remedio(array $r): array
    {
        return [
            'id' => $r['id'],
            'nome' => $r['nome'],
            'dose' => $r['dose'],
            'como_dar' => $r['como_dar'],
            'horarios' => json_decode((string)$r['horarios'], true) ?: [],
            'ativo' => (bool)$r['ativo'],
            'inicio' => $r['inicio'],
            'fim' => $r['fim'],
        ];
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    public static function alerta(array $a): array
    {
        $p = Bd::um('SELECT apelido FROM pessoas_cuidadas WHERE id = :id', ['id' => $a['pessoa_id']]);
        return [
            'id' => $a['id'],
            'pessoa' => ['id' => $a['pessoa_id'], 'apelido' => $p['apelido'] ?? ''],
            'tipo' => $a['tipo'],
            'referencia_id' => $a['referencia_id'],
            'texto' => $a['texto'],
            'em' => Tempo::sqlParaIso($a['criado_em']),
            'resolvido' => $a['resolvido_em'] !== null,
        ];
    }
}

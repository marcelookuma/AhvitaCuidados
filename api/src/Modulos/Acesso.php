<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Support\Bd;
use Cuidados\Support\ErroHttp;

/**
 * Autorização central: TODA leitura ou escrita de dados de uma pessoa
 * cuidada passa por aqui. Quem não tem vínculo recebe 404 (não revela
 * que a pessoa existe).
 */
final class Acesso
{
    /**
     * @param array<string,mixed> $usuario
     * @return array{pessoa:array<string,mixed>,papel:string,vinculo:?array<string,mixed>,permissoes:list<string>,cuidador:bool,empresa_id:?string}
     */
    public static function pessoa(array $usuario, string $pessoaId): array
    {
        $p = Bd::um('SELECT * FROM pessoas_cuidadas WHERE id = :id AND excluido_em IS NULL', ['id' => $pessoaId]);
        if ($p === null) throw ErroHttp::naoEncontrado('Pessoa não encontrada.');

        $v = Bd::um(
            "SELECT * FROM vinculos WHERE pessoa_id = :p AND usuario_id = :u AND status = 'ativo' ORDER BY criado_em DESC LIMIT 1",
            ['p' => $pessoaId, 'u' => $usuario['id']]
        );
        if ($v !== null) {
            $perm = json_decode((string)$v['permissoes'], true) ?: [];
            $cuidador = $v['papel'] === 'cuidador' || (int)$v['atua_como_cuidador'] === 1;
            return ['pessoa' => $p, 'papel' => $v['papel'], 'vinculo' => $v, 'permissoes' => $perm, 'cuidador' => $cuidador, 'empresa_id' => $v['empresa_id']];
        }

        if ($p['empresa_id'] !== null && self::membroEmpresa($usuario['id'], $p['empresa_id']) !== null) {
            $perm = json_decode((string)($p['empresa_permissoes'] ?? '[]'), true) ?: Permissoes::padrao('empresa');
            return ['pessoa' => $p, 'papel' => 'empresa', 'vinculo' => null, 'permissoes' => Permissoes::limpar($perm, 'empresa'), 'cuidador' => false, 'empresa_id' => $p['empresa_id']];
        }
        throw ErroHttp::naoEncontrado('Pessoa não encontrada.');
    }

    /** @param array{permissoes:list<string>} $ctx */
    public static function exigir(array $ctx, string $func): void
    {
        if (!in_array($func, $ctx['permissoes'], true)) {
            throw ErroHttp::proibido(self::mensagem($func));
        }
    }

    /** @param array{permissoes:list<string>} $ctx */
    public static function pode(array $ctx, string $func): bool
    {
        return in_array($func, $ctx['permissoes'], true);
    }

    /** @return array<string,mixed>|null */
    public static function membroEmpresa(string $usuarioId, string $empresaId): ?array
    {
        return Bd::um(
            'SELECT * FROM empresa_membros WHERE empresa_id = :e AND usuario_id = :u AND ativo = 1',
            ['e' => $empresaId, 'u' => $usuarioId]
        );
    }

    /** @param array<string,mixed> $usuario @return array<string,mixed> */
    public static function empresa(array $usuario, string $empresaId, bool $admin = false): array
    {
        $m = self::membroEmpresa($usuario['id'], $empresaId);
        if ($m === null) throw ErroHttp::naoEncontrado('Empresa não encontrada.');
        if ($admin && $m['papel'] !== 'admin') throw ErroHttp::proibido('Só a administração da empresa pode fazer isso.');
        $e = Bd::um('SELECT * FROM empresas WHERE id = :id', ['id' => $empresaId]);
        if ($e === null) throw ErroHttp::naoEncontrado('Empresa não encontrada.');
        $e['meu_papel'] = $m['papel'];
        return $e;
    }

    private static function mensagem(string $func): string
    {
        return match ($func) {
            'registrar' => 'Você não tem permissão para registrar cuidados desta pessoa.',
            'mensagens' => 'Você não participa da conversa desta pessoa.',
            'administrar' => 'Só o(a) gestor(a) pode mudar isso.',
            'atendimentos' => 'Você não tem permissão para organizar atendimentos.',
            default => 'Você não tem permissão para isso. Fale com o(a) gestor(a).',
        };
    }
}

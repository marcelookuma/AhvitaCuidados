<?php
declare(strict_types=1);

namespace Cuidados\Http;

use Cuidados\Support\ErroHttp;

/**
 * Roteador mínimo: "GET /pessoas/{id}/feed" => [Classe::class, 'metodo'].
 * {id} aceita ULID ou código de convite.
 */
final class Roteador
{
    /** @var list<array{0:string,1:string,2:callable|array{0:class-string,1:string},3:bool,4:string}> */
    private array $rotas = [];

    /** @param callable|array{0:class-string,1:string} $acao */
    public function add(string $metodo, string $padrao, callable|array $acao, bool $publica = false): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[0-9A-Za-z]{1,40})', rtrim($padrao, '/') ?: '/') . '$#';
        $this->rotas[] = [$metodo, $regex, $acao, $publica, $padrao];
    }

    /**
     * @param ?string $padraoAchado recebe o padrão da rota (ex.: /pessoas/{id}/feed), mesmo se o método não bater
     * @return array{0:callable|array{0:class-string,1:string},1:array<string,string>,2:bool}
     */
    public function resolver(string $metodo, string $caminho, ?string &$padraoAchado = null): array
    {
        $caminho = rtrim($caminho, '/') ?: '/';
        $existeCaminho = false;
        foreach ($this->rotas as [$m, $regex, $acao, $publica, $padrao]) {
            if (preg_match($regex, $caminho, $c)) {
                $existeCaminho = true;
                $padraoAchado ??= $padrao;
                if ($m === $metodo) {
                    $padraoAchado = $padrao;
                    return [$acao, array_filter($c, 'is_string', ARRAY_FILTER_USE_KEY), $publica];
                }
            }
        }
        if ($existeCaminho) {
            throw new ErroHttp(405, 'metodo', 'Método não permitido.');
        }
        throw ErroHttp::naoEncontrado('Endereço não encontrado.');
    }
}

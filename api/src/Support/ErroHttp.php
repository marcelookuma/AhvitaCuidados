<?php
declare(strict_types=1);

namespace Cuidados\Support;

/** Erro com status HTTP e mensagem curta, em linguagem simples, pronta para a tela. */
final class ErroHttp extends \RuntimeException
{
    /** @param array<string,string> $campos */
    public function __construct(
        public readonly int $status,
        public readonly string $codigo,
        string $mensagem,
        public readonly array $campos = []
    ) {
        parent::__construct($mensagem);
    }

    /** @param array<string,string> $campos */
    public static function validacao(array $campos, string $msg = 'Confira os dados.'): self
    {
        return new self(422, 'validacao', $msg, $campos);
    }

    public static function naoAutenticado(): self
    {
        return new self(401, 'nao_autenticado', 'Entre de novo para continuar.');
    }

    public static function proibido(string $msg = 'Você não tem permissão para isso.'): self
    {
        return new self(403, 'proibido', $msg);
    }

    public static function naoEncontrado(string $msg = 'Não encontrado.'): self
    {
        return new self(404, 'nao_encontrado', $msg);
    }

    public static function conflito(string $codigo, string $msg): self
    {
        return new self(409, $codigo, $msg);
    }

    public static function muitas(string $msg = 'Muitas tentativas. Espere alguns minutos.'): self
    {
        return new self(429, 'muitas_tentativas', $msg);
    }
}

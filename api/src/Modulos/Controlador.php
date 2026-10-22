<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Http\Requisicao;
use Cuidados\Support\ErroHttp;

abstract class Controlador
{
    /** @param array<string,mixed>|null $usuario */
    public function __construct(protected Requisicao $req, protected ?array $usuario, protected ?string $sessaoId = null) {}

    /** @return array<string,mixed> */
    protected function u(): array
    {
        if ($this->usuario === null) throw ErroHttp::naoAutenticado();
        return $this->usuario;
    }

    protected function uid(): string
    {
        return (string)$this->u()['id'];
    }

    /** @return array<string,mixed> */
    protected function corpo(): array
    {
        return $this->req->json();
    }
}

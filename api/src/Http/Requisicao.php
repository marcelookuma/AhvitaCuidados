<?php
declare(strict_types=1);

namespace Cuidados\Http;

use Cuidados\Support\ErroHttp;

final class Requisicao
{
    /** @var array<string,string> */
    public array $params = [];
    /** @var array<string,mixed>|null */
    private ?array $corpo = null;

    /**
     * @param array<string,mixed> $query
     * @param array<string,string> $cabecalhos
     */
    public function __construct(
        public readonly string $metodo,
        public readonly string $caminho,
        public readonly array $query,
        public readonly array $cabecalhos,
        private readonly string $bruto,
        public readonly ?string $ip,
        /** @var array<string,mixed> */
        public readonly array $arquivos = [],
        /** @var array<string,mixed> */
        public readonly array $formulario = []
    ) {}

    public static function doServidor(string $base): self
    {
        $uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $uri = '/' . trim($uri, '/');
        $cab = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $cab[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) $cab['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
        // Alguns Apache compartilhados não repassam Authorization: o .htaccess cria REDIRECT_HTTP_AUTHORIZATION.
        if (!isset($cab['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $cab['authorization'] = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($metodo === 'POST' && isset($cab['x-http-method-override'])) {
            $metodo = strtoupper($cab['x-http-method-override']);
        }
        return new self(
            $metodo,
            $uri,
            $_GET,
            $cab,
            (string)file_get_contents('php://input'),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_FILES,
            $_POST
        );
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->corpo === null) {
            if ($this->bruto === '') {
                $this->corpo = $this->formulario;
            } else {
                if (strlen($this->bruto) > 1_000_000) {
                    throw new ErroHttp(413, 'muito_grande', 'Envio muito grande.');
                }
                $d = json_decode($this->bruto, true);
                if (!is_array($d)) {
                    throw new ErroHttp(400, 'json_invalido', 'Formato de envio inválido.');
                }
                $this->corpo = $d;
            }
        }
        return $this->corpo;
    }

    public function cabecalho(string $nome): ?string
    {
        return $this->cabecalhos[strtolower($nome)] ?? null;
    }

    public function param(string $nome): string
    {
        return $this->params[$nome] ?? '';
    }

    public function q(string $nome, ?string $padrao = null): ?string
    {
        $v = $this->query[$nome] ?? $padrao;
        return is_string($v) ? $v : $padrao;
    }
}

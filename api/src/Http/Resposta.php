<?php
declare(strict_types=1);

namespace Cuidados\Http;

final class Resposta
{
    /** @var array<string,string> */
    public array $cabecalhos = [];
    /** @var list<string> */
    public array $cookies = [];
    /** Corpo pronto (ex.: CSV) em vez de JSON. */
    public ?string $texto = null;

    public function __construct(
        public int $status = 200,
        public mixed $corpo = null,
        public ?string $arquivo = null,
        public ?string $tipoArquivo = null
    ) {}

    public static function json(mixed $dados, int $status = 200): self
    {
        return new self($status, $dados);
    }

    public static function vazio(): self
    {
        return new self(204);
    }

    public static function arquivo(string $caminho, string $mime): self
    {
        $r = new self(200, null, $caminho, $mime);
        $r->cabecalhos['Cache-Control'] = 'private, max-age=3600';
        return $r;
    }

    /** Download de texto (ex.: CSV do log de acessos). */
    public static function download(string $conteudo, string $mime, string $nome): self
    {
        $r = new self(200);
        $r->texto = $conteudo;
        $r->cabecalhos['Content-Type'] = $mime;
        $r->cabecalhos['Content-Disposition'] = 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $nome) . '"';
        return $r;
    }

    public function enviar(): void
    {
        http_response_code($this->status);
        foreach ($this->cabecalhos as $k => $v) {
            header("$k: $v");
        }
        foreach ($this->cookies as $c) {
            header('Set-Cookie: ' . $c, false);
        }
        if ($this->arquivo !== null) {
            header('Content-Type: ' . $this->tipoArquivo);
            $pular = \Cuidados\Support\ArquivoProtegido::deslocamento($this->arquivo);
            header('Content-Length: ' . (string)(filesize($this->arquivo) - $pular));
            header('Content-Disposition: inline');
            $h = fopen($this->arquivo, 'rb');
            if ($h !== false) {
                fseek($h, $pular);
                fpassthru($h);
                fclose($h);
            }
            return;
        }
        if ($this->status === 204) {
            return;
        }
        if ($this->texto !== null) {
            echo $this->texto;
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}

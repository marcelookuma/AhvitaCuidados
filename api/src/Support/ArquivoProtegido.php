<?php
declare(strict_types=1);

namespace Cuidados\Support;

/**
 * Fotos e áudios gravados como "<id>.<ext>.php", começando por um trecho PHP que
 * encerra com 404. Se a pasta de mídias ficar acessível pela web (ex.: nginx, que
 * ignora .htaccess), quem abrir o endereço recebe 404 em vez do arquivo.
 * A API lê o conteúdo pulando esse trecho.
 */
final class ArquivoProtegido
{
    public const PREFIXO = "<?php http_response_code(404); exit; ?>\n";

    /** Move o upload para $destino (que deve terminar em .php) com o prefixo. */
    public static function guardar(string $origem, string $destino): bool
    {
        $in = @fopen($origem, 'rb');
        $out = @fopen($destino, 'wb');
        if ($in === false || $out === false) return false;
        fwrite($out, self::PREFIXO);
        $ok = stream_copy_to_stream($in, $out) !== false;
        fclose($in);
        fclose($out);
        @unlink($origem);
        return $ok;
    }

    /** Bytes do início a pular ao ler (0 para arquivos antigos, sem prefixo). */
    public static function deslocamento(string $arquivo): int
    {
        $h = @fopen($arquivo, 'rb');
        if ($h === false) return 0;
        $ini = (string)fread($h, strlen(self::PREFIXO));
        fclose($h);
        return $ini === self::PREFIXO ? strlen(self::PREFIXO) : 0;
    }
}

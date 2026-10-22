<?php
declare(strict_types=1);

namespace Cuidados\Auth;

use Cuidados\Support\Config;
use Cuidados\Support\ErroHttp;

/**
 * Envio de e-mail. Com email.smtp.host configurado usa SMTP autenticado
 * (recomendado na KingHost: conta nao-responda@seudominio); senão, mail().
 */
final class Email
{
    public static function enviar(string $para, string $assunto, string $texto): void
    {
        $host = (string)Config::get('email.smtp.host', '');
        $de = (string)Config::get('email.remetente');
        if ($host === '') {
            $cab = "From: $de\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
            if (!@mail($para, '=?UTF-8?B?' . base64_encode($assunto) . '?=', $texto, $cab)) {
                throw new ErroHttp(503, 'email_fora', 'Não conseguimos enviar o e-mail agora.');
            }
            return;
        }
        self::smtp($host, (int)Config::get('email.smtp.porta', 587), (string)Config::get('email.smtp.seguranca', 'tls'),
            (string)Config::get('email.smtp.usuario', ''), (string)Config::get('email.smtp.senha', ''), $de, $para, $assunto, $texto);
    }

    private static function smtp(string $host, int $porta, string $seg, string $usuario, string $senha, string $de, string $para, string $assunto, string $texto): void
    {
        $alvo = ($seg === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $porta;
        $s = @stream_socket_client($alvo, $errno, $errstr, 15);
        if (!$s) throw new ErroHttp(503, 'email_fora', 'Não conseguimos enviar o e-mail agora.');
        stream_set_timeout($s, 15);
        $ler = function () use ($s): string {
            $r = '';
            while (($l = fgets($s, 515)) !== false) {
                $r .= $l;
                if (isset($l[3]) && $l[3] === ' ') break;
            }
            return $r;
        };
        $cmd = function (string $c, string $esperado) use ($s, $ler): void {
            fwrite($s, $c . "\r\n");
            $r = $ler();
            if (!str_starts_with($r, $esperado)) {
                throw new ErroHttp(503, 'email_fora', 'Não conseguimos enviar o e-mail agora.');
            }
        };
        $ler();
        $cmd('EHLO ' . (gethostname() ?: 'localhost'), '250');
        if ($seg === 'tls') {
            $cmd('STARTTLS', '220');
            stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd('EHLO ' . (gethostname() ?: 'localhost'), '250');
        }
        if ($usuario !== '') {
            $cmd('AUTH LOGIN', '334');
            $cmd(base64_encode($usuario), '334');
            $cmd(base64_encode($senha), '235');
        }
        preg_match('/<([^>]+)>/', $de, $m);
        $cmd('MAIL FROM:<' . ($m[1] ?? $de) . '>', '250');
        $cmd('RCPT TO:<' . $para . '>', '250');
        $cmd('DATA', '354');
        $msg = "From: $de\r\nTo: <$para>\r\nSubject: =?UTF-8?B?" . base64_encode($assunto) . "?=\r\nMIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($texto)) . "\r\n.";
        $cmd($msg, '250');
        fwrite($s, "QUIT\r\n");
        fclose($s);
    }
}

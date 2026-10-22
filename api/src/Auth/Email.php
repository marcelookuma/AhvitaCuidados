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
                throw self::falha('mail() recusou o envio (configure email.smtp)');
            }
            return;
        }
        self::smtp($host, (int)Config::get('email.smtp.porta', 587), (string)Config::get('email.smtp.seguranca', 'tls'),
            (string)Config::get('email.smtp.usuario', ''), (string)Config::get('email.smtp.senha', ''), $de, $para, $assunto, $texto);
    }

    private static function smtp(string $host, int $porta, string $seg, string $usuario, string $senha, string $de, string $para, string $assunto, string $texto): void
    {
        $alvo = ($seg === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $porta;
        $verificar = filter_var(Config::get('email.smtp.verificar_certificado', true), FILTER_VALIDATE_BOOL);
        $ssl = ['peer_name' => $host, 'verify_peer' => $verificar, 'verify_peer_name' => $verificar, 'allow_self_signed' => !$verificar];
        $ctx = stream_context_create(['ssl' => $ssl]);
        $s = @stream_socket_client($alvo, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) throw self::falha("não conectou em $alvo: $errstr ($errno)");
        stream_set_timeout($s, 15);
        $ler = function () use ($s): string {
            $r = '';
            while (($l = fgets($s, 515)) !== false) {
                $r .= $l;
                if (isset($l[3]) && $l[3] === ' ') break;
            }
            return $r;
        };
        $cmd = function (string $c, string $esperado, string $etapa) use ($s, $ler): void {
            fwrite($s, $c . "\r\n");
            $r = $ler();
            if (!str_starts_with($r, $esperado)) {
                throw self::falha("$etapa: servidor respondeu " . trim(mb_substr($r, 0, 200)));
            }
        };
        $ler();
        $cmd('EHLO ' . (gethostname() ?: 'localhost'), '250', 'EHLO');
        if ($seg === 'tls') {
            $cmd('STARTTLS', '220', 'STARTTLS');
            if (@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                $e = error_get_last()['message'] ?? '';
                throw self::falha("TLS com $host falhou ($e). Se o certificado do servidor for de outro nome, use o host que aparece no certificado ou email.smtp.verificar_certificado = false");
            }
            $cmd('EHLO ' . (gethostname() ?: 'localhost'), '250', 'EHLO após TLS');
        }
        if ($usuario !== '') {
            $cmd('AUTH LOGIN', '334', 'AUTH');
            $cmd(base64_encode($usuario), '334', 'usuário SMTP');
            $cmd(base64_encode($senha), '235', 'senha SMTP (usuário ou senha errados?)');
        }
        preg_match('/<([^>]+)>/', $de, $m);
        $cmd('MAIL FROM:<' . ($m[1] ?? $de) . '>', '250', 'remetente (MAIL FROM; o remetente precisa ser a própria conta SMTP)');
        $cmd('RCPT TO:<' . $para . '>', '250', 'destinatário');
        $cmd('DATA', '354', 'DATA');
        $msg = "From: $de\r\nTo: <$para>\r\nSubject: =?UTF-8?B?" . base64_encode($assunto) . "?=\r\nMIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($texto)) . "\r\n.";
        $cmd($msg, '250', 'envio da mensagem');
        fwrite($s, "QUIT\r\n");
        fclose($s);
    }

    /** Erro para o app (424: a KingHost troca o corpo das respostas 5xx) e motivo no log. */
    private static function falha(string $motivo): ErroHttp
    {
        error_log('[cuidados] e-mail: ' . $motivo);
        return new ErroHttp(424, 'email_fora', 'Não conseguimos enviar o e-mail agora. Tente de novo em alguns minutos.');
    }
}

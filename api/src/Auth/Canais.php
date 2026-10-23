<?php
declare(strict_types=1);

namespace Cuidados\Auth;

use Cuidados\Support\Config;
use Cuidados\Support\ErroHttp;

/**
 * Canais para enviar o código de acesso.
 *  - email    : SMTP autenticado (conta de e-mail da KingHost) ou mail() do PHP.
 *  - sms/whatsapp: Twilio Verify (a Twilio gera e confere o código e já tem
 *               o modelo de mensagem aprovado pelo WhatsApp).
 *  - log      : só ambiente de teste; grava o código no log do servidor.
 * Para trocar de fornecedor (Zenvia, Infobip…), implemente aqui.
 */
final class Canais
{
    /** @return list<string> */
    public static function disponiveis(): array
    {
        $pedidos = (array)Config::get('codigo.canais', ['email']);
        $ok = [];
        foreach ($pedidos as $c) {
            $c = trim((string)$c);
            $configurado = match ($c) {
                'email' => true,
                'sms', 'whatsapp' => Config::get('twilio.sid') !== '' && Config::get('twilio.verify_sid') !== '',
                'log' => Config::emTeste(),
                default => false,
            };
            if ($configurado) $ok[] = $c;
        }
        return $ok;
    }

    /** Dá para mandar código por celular (SMS, WhatsApp ou, em teste, o log)? */
    public static function celular(): bool
    {
        return array_intersect(['sms', 'whatsapp', 'log'], self::disponiveis()) !== [];
    }

    public static function verificacaoExterna(string $canal): bool
    {
        return in_array($canal, ['sms', 'whatsapp'], true);
    }

    public static function enviarCodigo(string $canal, string $destino, string $codigo): void
    {
        if ($canal === 'log') {
            error_log("[cuidados] código para $destino: $codigo");
            return;
        }
        if ($canal === 'email') {
            $assunto = 'Seu código: ' . $codigo;
            $texto = "Seu código para entrar no Ahvita Cuidados é: $codigo\n\nEle vale por 10 minutos.\nSe não foi você, ignore este e-mail.";
            Email::enviar($destino, $assunto, $texto);
            return;
        }
        throw new ErroHttp(500, 'canal', 'Canal não suportado.');
    }

    public static function iniciarVerificacao(string $canal, string $destino): void
    {
        self::twilio('Verifications', ['To' => $destino, 'Channel' => $canal === 'whatsapp' ? 'whatsapp' : 'sms', 'Locale' => 'pt-BR']);
    }

    public static function conferirVerificacao(string $canal, string $destino, string $codigo): bool
    {
        $r = self::twilio('VerificationCheck', ['To' => $destino, 'Code' => $codigo]);
        return ($r['status'] ?? '') === 'approved';
    }

    /** @param array<string,string> $campos @return array<string,mixed> */
    private static function twilio(string $recurso, array $campos): array
    {
        $url = sprintf('https://verify.twilio.com/v2/Services/%s/%s', rawurlencode((string)Config::get('twilio.verify_sid')), $recurso);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($campos),
            CURLOPT_USERPWD => Config::get('twilio.sid') . ':' . Config::get('twilio.token'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $corpo = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($corpo === false || $status >= 500) {
            throw new ErroHttp(424, 'canal_fora', 'Não conseguimos enviar agora. Tente pelo e-mail.');
        }
        $d = json_decode((string)$corpo, true);
        if ($status >= 400 && $recurso === 'Verifications') {
            throw new ErroHttp(422, 'canal_recusou', 'Não conseguimos enviar para esse número.');
        }
        return is_array($d) ? $d : [];
    }
}

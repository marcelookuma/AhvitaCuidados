<?php
declare(strict_types=1);

namespace Cuidados\Support;

/**
 * Avisos no celular (push) pelo Firebase Cloud Messaging (API HTTP v1).
 * Lê a fila de saída (eventos_saida) e entrega para os aparelhos registrados
 * em /dispositivos cujas sessões ainda estão ativas.
 *
 * Regras (LGPD e risco trabalhista):
 *  - O texto do aviso NUNCA leva dado de saúde nem nome: aparece na tela bloqueada.
 *  - Cuidador(a): por padrão, nada de aviso das 21h às 7h, a menos que esteja
 *    "em serviço" ou tenha ligado "Avisos à noite" em Eu. Pedidos de atendimento
 *    esperam até as 7h; mensagens não geram aviso nesse horário (aparecem ao abrir).
 *    A agenda é dele(a): o app não cobra resposta fora do horário que ele(a) escolheu.
 *  - Sem a credencial do Firebase configurada, nada é enviado (a fila segue igual).
 *
 * Configuração: push.fcm_credenciais = o JSON da conta de serviço (Firebase →
 * Configurações do projeto → Contas de serviço) colado no configuracao.php, ou o
 * caminho de um arquivo .php com bloqueio no início. Nunca um .json solto: na
 * KingHost (nginx) a pasta privada pode ficar dentro da www e o .htaccess não vale.
 */
final class Push
{
    private const ESCOPO = 'https://www.googleapis.com/auth/firebase.messaging';
    private const MAX_TENTATIVAS = 5;
    private const SILENCIO_INICIO = 21; // horário local (Brasília)
    private const SILENCIO_FIM = 7;

    /** @var array<string,mixed>|null */
    private static ?array $credencial = null;

    public static function configurado(): bool
    {
        return self::credencial() !== null || self::simulando();
    }

    /** Ambiente de teste: grava os avisos num arquivo em vez de chamar o Firebase. */
    private static function simulando(): bool
    {
        return Config::emTeste() && (string)Config::get('push.arquivo_teste', '') !== '';
    }

    /** @return array<string,mixed>|null */
    private static function credencial(): ?array
    {
        if (self::$credencial !== null) return self::$credencial;
        $valor = trim((string)Config::get('push.fcm_credenciais', ''));
        if ($valor === '') return null;
        if (str_starts_with($valor, '{')) {
            $texto = $valor; // JSON colado dentro do configuracao.php (recomendado: nunca vira arquivo baixável)
        } elseif (is_readable($valor)) {
            $texto = (string)file_get_contents($valor);
            // Arquivo .php com bloqueio no início (como os .sql.php): o servidor nunca entrega o conteúdo.
            if (str_starts_with($texto, '<?php')) $texto = substr($texto, (int)strpos($texto, '?>') + 2);
        } else {
            return null;
        }
        $j = json_decode(trim($texto), true);
        if (!is_array($j) || empty($j['project_id']) || empty($j['client_email']) || empty($j['private_key'])) {
            error_log('[cuidados] push: credencial do Firebase incompleta');
            return null;
        }
        return self::$credencial = $j;
    }

    /**
     * Processa a fila de saída. @return array{enviados:int, aparelhos:int}
     */
    public static function processar(int $limite = 100): array
    {
        $r = ['enviados' => 0, 'aparelhos' => 0];
        if (!self::configurado()) return $r;
        $eventos = Bd::todos(
            'SELECT * FROM eventos_saida WHERE processado_em IS NULL ORDER BY id LIMIT ' . max(1, $limite)
        );
        $velho = Tempo::agora()->modify('-1 day')->format('Y-m-d H:i:s');
        foreach ($eventos as $e) {
            // Aviso de mais de um dia (ex.: push ligado depois) não vai mais: perdeu o sentido.
            if ((string)$e['criado_em'] < $velho) {
                Bd::exec('UPDATE eventos_saida SET processado_em = :a WHERE id = :id', ['a' => Tempo::agoraSql(), 'id' => $e['id']]);
                continue;
            }
            $payload = json_decode((string)$e['payload'], true) ?: [];
            try {
                $destinos = self::destinos((string)$e['tipo'], $e['usuario_id'] !== null ? (string)$e['usuario_id'] : null, $payload);
                $adiar = false;
                foreach ($destinos as $d) {
                    if (self::emSilencio($d['usuario_id'], $d['cuidador'])) {
                        // Pedido de atendimento espera até as 7h; mensagem não gera aviso agora.
                        if ($d['adiavel']) $adiar = true;
                        continue;
                    }
                    $n = self::enviarParaUsuario($d['usuario_id'], self::mensagem((string)$e['tipo'], $d['abrir'], $d['agrupar']));
                    if ($n > 0) { $r['enviados']++; $r['aparelhos'] += $n; }
                }
                if (!$adiar) {
                    Bd::exec('UPDATE eventos_saida SET processado_em = :a WHERE id = :id', ['a' => Tempo::agoraSql(), 'id' => $e['id']]);
                }
            } catch (\Throwable $x) {
                error_log('[cuidados] push: evento ' . $e['id'] . ': ' . $x->getMessage());
                Bd::exec(
                    'UPDATE eventos_saida SET tentativas = tentativas + 1,
                            processado_em = IF(tentativas + 1 >= :max, :a, NULL) WHERE id = :id',
                    ['max' => self::MAX_TENTATIVAS, 'a' => Tempo::agoraSql(), 'id' => $e['id']]
                );
            }
        }
        // Aparelhos de sessões encerradas não recebem mais nada.
        Bd::exec(
            'DELETE d FROM dispositivos d LEFT JOIN sessoes s ON s.id = d.sessao_id
              WHERE s.id IS NULL OR s.revogada_em IS NOT NULL OR s.expira_em < :a',
            ['a' => Tempo::agoraSql()]
        );
        return $r;
    }

    /**
     * Quem recebe o aviso, para onde o toque leva e se pode esperar o fim do silêncio.
     * @param array<string,mixed> $p
     * @return list<array{usuario_id:string, abrir:string, cuidador:bool, adiavel:bool, agrupar:string}>
     */
    private static function destinos(string $tipo, ?string $usuarioId, array $p): array
    {
        switch ($tipo) {
            case 'proposta':
                return $usuarioId === null ? [] : [['usuario_id' => $usuarioId, 'abrir' => '/c/agenda', 'cuidador' => true, 'adiavel' => true, 'agrupar' => 'agenda']];
            case 'pedido_rede':
                return $usuarioId === null ? [] : [['usuario_id' => $usuarioId, 'abrir' => '/c/empresas', 'cuidador' => true, 'adiavel' => true, 'agrupar' => 'empresas']];
            case 'pedido_cliente':
                return $usuarioId === null ? [] : [['usuario_id' => $usuarioId, 'abrir' => '/', 'cuidador' => false, 'adiavel' => false, 'agrupar' => 'pedidos']];
            case 'alerta':
                $pid = (string)($p['pessoa_id'] ?? '');
                return $usuarioId === null ? [] : [['usuario_id' => $usuarioId, 'abrir' => $pid !== '' ? "/g/$pid" : '/', 'cuidador' => false, 'adiavel' => false, 'agrupar' => 'alerta-' . $pid]];
            case 'mensagem':
                return self::destinosMensagem($p);
        }
        return [];
    }

    /**
     * @param array<string,mixed> $p
     * @return list<array{usuario_id:string, abrir:string, cuidador:bool, adiavel:bool, agrupar:string}>
     */
    private static function destinosMensagem(array $p): array
    {
        $c = Bd::um('SELECT * FROM conversas WHERE id = :id', ['id' => (string)($p['conversa_id'] ?? '')]);
        if ($c === null) return [];
        $autor = (string)($p['autor_id'] ?? '');
        $lista = [];
        if ($c['tipo'] === 'direta') {
            if ((string)$c['usuario_id'] !== $autor) {
                $lista[] = ['usuario_id' => (string)$c['usuario_id'], 'abrir' => '/c/d/' . $c['id'], 'cuidador' => true, 'adiavel' => false, 'agrupar' => 'conversa-' . $c['id']];
            }
            foreach (Bd::todos('SELECT usuario_id FROM empresa_membros WHERE empresa_id = :e AND ativo = 1', ['e' => $c['empresa_id']]) as $m) {
                if ((string)$m['usuario_id'] === $autor) continue;
                $lista[] = ['usuario_id' => (string)$m['usuario_id'], 'abrir' => '/e/' . $c['empresa_id'] . '/conversas?c=' . $c['id'], 'cuidador' => false, 'adiavel' => false, 'agrupar' => 'conversa-' . $c['id']];
            }
            return $lista;
        }
        // Conversa da pessoa cuidada: só quem tem a permissão "mensagens" no vínculo ativo.
        $vinculos = Bd::todos(
            "SELECT usuario_id, papel, atua_como_cuidador FROM vinculos
              WHERE pessoa_id = :p AND status = 'ativo' AND JSON_CONTAINS(permissoes, '\"mensagens\"')",
            ['p' => $c['pessoa_id']]
        );
        foreach ($vinculos as $v) {
            if ((string)$v['usuario_id'] === $autor) continue;
            $cuidador = $v['papel'] === 'cuidador' || (int)$v['atua_como_cuidador'] === 1;
            $lista[] = [
                'usuario_id' => (string)$v['usuario_id'],
                'abrir' => $cuidador ? '/c/p/' . $c['pessoa_id'] : '/g/' . $c['pessoa_id'] . '/conversa',
                'cuidador' => $cuidador, 'adiavel' => false, 'agrupar' => 'conversa-' . $c['id'],
            ];
        }
        return $lista;
    }

    /** Textos curtos e neutros: sem nome, sem dado de saúde, sem cobrança. */
    private static function mensagem(string $tipo, string $abrir, string $agrupar): array
    {
        $corpo = match ($tipo) {
            'mensagem' => 'Nova mensagem na conversa.',
            'proposta' => 'Você recebeu um pedido de atendimento. Veja e decida se aceita.',
            'pedido_rede' => 'Uma empresa quer incluir você na rede dela. Veja o pedido.',
            'pedido_cliente' => 'Você tem um pedido de acompanhamento para ver.',
            'alerta' => 'Há um aviso sobre o cuidado. Toque para ver.',
            default => 'Você tem um aviso novo.',
        };
        return ['titulo' => 'Ahvita Cuidados', 'corpo' => $corpo, 'abrir' => $abrir, 'tipo' => $tipo, 'agrupar' => $agrupar];
    }

    /** Das 21h às 7h, cuidador(a) só recebe aviso se estiver em serviço ou se escolheu receber. */
    private static function emSilencio(string $usuarioId, bool $cuidador): bool
    {
        if (!$cuidador) return false;
        $hora = (int)Tempo::local(Tempo::agora())->format('G');
        if ($hora >= self::SILENCIO_FIM && $hora < self::SILENCIO_INICIO) return false;
        $pref = json_decode((string)(Bd::valor('SELECT preferencias FROM usuarios WHERE id = :u', ['u' => $usuarioId]) ?? ''), true);
        if (is_array($pref) && !empty($pref['avisos_noite'])) return false;
        $emServico = (int)Bd::valor('SELECT COUNT(*) FROM presencas WHERE cuidador_id = :u AND fim_em IS NULL', ['u' => $usuarioId]) > 0;
        return !$emServico;
    }

    /** @param array{titulo:string,corpo:string,abrir:string,tipo:string,agrupar:string} $m */
    private static function enviarParaUsuario(string $usuarioId, array $m): int
    {
        $aparelhos = Bd::todos(
            'SELECT d.id, d.token FROM dispositivos d JOIN sessoes s ON s.id = d.sessao_id
              WHERE d.usuario_id = :u AND s.revogada_em IS NULL AND s.expira_em > :a',
            ['u' => $usuarioId, 'a' => Tempo::agoraSql()]
        );
        $n = 0;
        foreach ($aparelhos as $ap) {
            $res = self::enviar((string)$ap['token'], $m);
            if ($res === 'ok') {
                $n++;
                Bd::exec('UPDATE dispositivos SET ultimo_envio_em = :a WHERE id = :id', ['a' => Tempo::agoraSql(), 'id' => $ap['id']]);
            } elseif ($res === 'invalido') {
                Bd::exec('DELETE FROM dispositivos WHERE id = :id', ['id' => $ap['id']]);
            } else {
                throw new \RuntimeException('falha temporária no Firebase');
            }
        }
        return $n;
    }

    /**
     * @param array{titulo:string,corpo:string,abrir:string,tipo:string,agrupar:string} $m
     * @return 'ok'|'invalido'|'tentar_de_novo'
     */
    private static function enviar(string $token, array $m): string
    {
        $msg = ['message' => [
            'token' => $token,
            'notification' => ['title' => $m['titulo'], 'body' => $m['corpo']],
            'data' => ['abrir' => $m['abrir'], 'tipo' => $m['tipo']],
            'android' => [
                'collapse_key' => $m['agrupar'],
                // Mensagens da mesma conversa substituem o aviso anterior (não empilham).
                'notification' => ['tag' => $m['agrupar'], 'visibility' => 'PRIVATE'],
            ],
        ]];

        if (self::simulando()) {
            file_put_contents((string)Config::get('push.arquivo_teste'), json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
            return str_starts_with($token, 'invalido') ? 'invalido' : 'ok';
        }

        $cred = self::credencial();
        if ($cred === null) return 'tentar_de_novo';
        $acesso = self::tokenAcesso($cred);
        if ($acesso === null) return 'tentar_de_novo';
        [$status, $corpo] = self::post(
            'https://fcm.googleapis.com/v1/projects/' . rawurlencode((string)$cred['project_id']) . '/messages:send',
            json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['Content-Type: application/json', 'Authorization: Bearer ' . $acesso]
        );
        if ($status === 200) return 'ok';
        $erro = (string)(json_decode($corpo, true)['error']['details'][0]['errorCode'] ?? '');
        if ($status === 404 || $erro === 'UNREGISTERED' || ($status === 400 && $erro === 'INVALID_ARGUMENT')) return 'invalido';
        if ($status === 401) self::$acesso = null;
        error_log("[cuidados] push: FCM respondeu $status $erro");
        return 'tentar_de_novo';
    }

    /** @var array{token:string,expira:int}|null */
    private static ?array $acesso = null;

    /**
     * Token de acesso do Google (OAuth 2, conta de serviço). Fica só na memória
     * da execução: nada de segredo gravado em disco (a pasta privada pode estar sob nginx).
     */
    private static function tokenAcesso(array $cred): ?string
    {
        if (self::$acesso !== null && self::$acesso['expira'] > time() + 60) return self::$acesso['token'];
        $agora = time();
        $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $uri = (string)($cred['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        $cab = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $dados = $b64(json_encode(['iss' => $cred['client_email'], 'scope' => self::ESCOPO, 'aud' => $uri, 'iat' => $agora, 'exp' => $agora + 3600]));
        $assinatura = '';
        if (!openssl_sign("$cab.$dados", $assinatura, (string)$cred['private_key'], OPENSSL_ALGO_SHA256)) {
            error_log('[cuidados] push: não deu para assinar com a chave do Firebase');
            return null;
        }
        [$status, $corpo] = self::post($uri, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => "$cab.$dados." . $b64($assinatura),
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        $j = json_decode($corpo, true);
        if ($status !== 200 || empty($j['access_token'])) {
            error_log("[cuidados] push: token do Google recusado ($status)");
            return null;
        }
        self::$acesso = ['token' => (string)$j['access_token'], 'expira' => $agora + (int)($j['expires_in'] ?? 3600) - 300];
        return self::$acesso['token'];
    }

    /** @param list<string> $cabecalhos @return array{0:int,1:string} */
    private static function post(string $url, string $corpo, array $cabecalhos): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $corpo,
            CURLOPT_HTTPHEADER => $cabecalhos,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, is_string($resp) ? $resp : ''];
    }
}

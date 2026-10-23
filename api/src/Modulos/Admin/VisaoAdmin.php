<?php
declare(strict_types=1);

namespace Cuidados\Modulos\Admin;

use Cuidados\Auth\Canais;
use Cuidados\Auth\Google;
use Cuidados\Http\Resposta;
use Cuidados\Support\Auditoria;
use Cuidados\Support\Bd;
use Cuidados\Support\Config;
use Cuidados\Support\Migracoes;
use Cuidados\Support\Tarefas;
use Cuidados\Support\Tempo;

/** Visão geral, uso por perfil e saúde do sistema. Só números: nenhum conteúdo de saúde. */
final class VisaoAdmin extends AdminBase
{
    /** Deslocamento do fuso local para agrupar por dia no SQL (CONVERT_TZ com número não precisa das tabelas de fuso). */
    public static function deslocamento(): string
    {
        return Tempo::local(Tempo::agora())->format('P');
    }

    /** GET /admin/painel */
    public function painel(): Resposta
    {
        $this->adm();
        $ag = Tempo::agora();
        $h7 = $ag->modify('-7 days')->format('Y-m-d H:i:s');
        $h24 = $ag->modify('-24 hours')->format('Y-m-d H:i:s');
        $hoje = Tempo::local($ag)->format('Y-m-d');
        $d7 = Tempo::local($ag)->modify('-6 days')->format('Y-m-d');
        $d30 = Tempo::local($ag)->modify('-29 days')->format('Y-m-d');
        $tz = self::deslocamento();

        $usuarios = Bd::um(
            'SELECT COUNT(*) AS total,
                    SUM(criado_em > :h7) AS novos_7d,
                    SUM(bloqueado_em IS NOT NULL) AS bloqueados,
                    SUM(google_sub IS NOT NULL) AS com_google
               FROM usuarios WHERE excluido_em IS NULL',
            ['h7' => $h7]
        );
        $pessoas = Bd::todos('SELECT arranjo, nascimento, criado_em FROM pessoas_cuidadas WHERE excluido_em IS NULL');
        $porArranjo = [];
        $porFaixa = ['crianca' => 0, 'adolescente' => 0, 'adulto' => 0, 'idoso' => 0];
        $pessoasNovas = 0;
        foreach ($pessoas as $p) {
            $porArranjo[$p['arranjo']] = ($porArranjo[$p['arranjo']] ?? 0) + 1;
            $f = self::faixa(self::idade($p['nascimento']));
            $porFaixa[$f] = ($porFaixa[$f] ?? 0) + 1;
            if ($p['criado_em'] > $h7) $pessoasNovas++;
        }
        $vinculos = [];
        foreach (Bd::todos("SELECT papel, COUNT(*) AS n FROM vinculos WHERE status = 'ativo' GROUP BY papel") as $l) $vinculos[$l['papel']] = (int)$l['n'];
        $modalidades = [];
        foreach (Bd::todos("SELECT modalidade, COUNT(*) AS n FROM empresa_cuidadores WHERE status = 'ativo' GROUP BY modalidade") as $l) $modalidades[$l['modalidade']] = (int)$l['n'];

        // Ativos = quem usou o app (leitura somada por dia ou ação registrada no log).
        $ativos = fn(string $desde) => (int)Bd::valor(
            "SELECT COUNT(DISTINCT x.u) FROM (
                SELECT usuario_id AS u FROM uso_diario WHERE dia >= :d
                UNION SELECT usuario_id FROM log_acessos WHERE ator = 'usuario' AND criado_em >= :dt
             ) x JOIN usuarios us ON us.id = x.u AND us.excluido_em IS NULL",
            ['d' => $desde, 'dt' => (new \DateTimeImmutable($desde, Tempo::fuso()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]
        );

        $serie = [];
        for ($i = 29; $i >= 0; $i--) {
            $dia = Tempo::local($ag)->modify("-$i days")->format('Y-m-d');
            $serie[$dia] = ['dia' => $dia, 'ativos' => 0, 'entradas' => 0, 'registros' => 0, 'novas_contas' => 0];
        }
        $dt30 = (new \DateTimeImmutable($d30, Tempo::fuso()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $preencher = function (string $campo, string $sql) use (&$serie, $tz, $d30, $dt30) {
            // Só os parâmetros que a consulta usa (com prepare nativo, parâmetro sobrando é erro).
            $p = array_filter(['tz' => $tz, 'd' => $d30, 'dt' => $dt30], fn($k) => preg_match('/:' . $k . '\b/', $sql) === 1, ARRAY_FILTER_USE_KEY);
            foreach (Bd::todos($sql, $p) as $l) {
                if (isset($serie[$l['dia']])) $serie[$l['dia']][$campo] = (int)$l['n'];
            }
        };
        $preencher('ativos', "SELECT dia, COUNT(DISTINCT u) AS n FROM (
                SELECT dia, usuario_id AS u FROM uso_diario WHERE dia >= :d
                UNION SELECT DATE(CONVERT_TZ(criado_em, '+00:00', :tz)), usuario_id FROM log_acessos WHERE ator = 'usuario' AND criado_em >= :dt
            ) x GROUP BY dia");
        $preencher('entradas', "SELECT DATE(CONVERT_TZ(criado_em, '+00:00', :tz)) AS dia, COUNT(*) AS n FROM log_acessos
            WHERE evento IN ('entrou','conta_criada') AND criado_em >= :dt GROUP BY dia");
        $preencher('registros', "SELECT DATE(CONVERT_TZ(recebido_em, '+00:00', :tz)) AS dia, COUNT(*) AS n FROM registros
            WHERE recebido_em >= :dt GROUP BY dia");
        $preencher('novas_contas', "SELECT DATE(CONVERT_TZ(criado_em, '+00:00', :tz)) AS dia, COUNT(*) AS n FROM usuarios
            WHERE criado_em >= :dt GROUP BY dia");

        $perfis = [];
        foreach (Bd::todos(
            "SELECT perfil, COUNT(DISTINCT u) AS n FROM (
                SELECT NULLIF(perfil, '') AS perfil, usuario_id AS u FROM uso_diario WHERE dia >= :d
                UNION SELECT perfil, usuario_id FROM log_acessos WHERE ator = 'usuario' AND criado_em >= :dt
             ) x WHERE perfil IS NOT NULL GROUP BY perfil",
            ['d' => $d30, 'dt' => $dt30]
        ) as $l) $perfis[$l['perfil']] = (int)$l['n'];

        return Resposta::json([
            'usuarios' => [
                'total' => (int)$usuarios['total'], 'novos_7d' => (int)$usuarios['novos_7d'],
                'bloqueados' => (int)$usuarios['bloqueados'], 'com_google' => (int)$usuarios['com_google'],
                'ativos_hoje' => $ativos($hoje), 'ativos_7d' => $ativos($d7), 'ativos_30d' => $ativos($d30),
            ],
            'pessoas' => ['total' => count($pessoas), 'novas_7d' => $pessoasNovas, 'por_arranjo' => (object)$porArranjo, 'por_faixa' => $porFaixa],
            'vinculos' => (object)$vinculos,
            'empresas' => [
                'total' => (int)Bd::valor('SELECT COUNT(*) FROM empresas'),
                'cuidadores_por_modalidade' => (object)$modalidades,
            ],
            'convites_pendentes' => (int)Bd::valor('SELECT COUNT(*) FROM convites WHERE aceito_em IS NULL AND revogado_em IS NULL AND expira_em > :a', ['a' => Tempo::agoraSql()]),
            'atividade_7d' => [
                'registros' => (int)Bd::valor('SELECT COUNT(*) FROM registros WHERE recebido_em > :h', ['h' => $h7]),
                'mensagens' => (int)Bd::valor('SELECT COUNT(*) FROM mensagens WHERE recebido_em > :h AND autor_id IS NOT NULL', ['h' => $h7]),
                'alertas_abertos' => (int)Bd::valor('SELECT COUNT(*) FROM alertas WHERE resolvido_em IS NULL'),
            ],
            'seguranca_24h' => [
                'entradas' => (int)Bd::valor("SELECT COUNT(*) FROM log_acessos WHERE evento IN ('entrou','conta_criada') AND criado_em > :h", ['h' => $h24]),
                'entradas_recusadas' => (int)Bd::valor("SELECT COUNT(*) FROM log_acessos WHERE evento = 'entrada_recusada' AND criado_em > :h", ['h' => $h24]),
                'painel_recusadas' => (int)Bd::valor("SELECT COUNT(*) FROM log_acessos WHERE evento = 'admin.entrada_recusada' AND criado_em > :h", ['h' => $h24]),
                'erros_servidor' => (int)Bd::valor('SELECT COUNT(*) FROM log_acessos WHERE status >= 500 AND criado_em > :h', ['h' => $h24]),
                'negados' => (int)Bd::valor('SELECT COUNT(*) FROM log_acessos WHERE status IN (401, 403) AND criado_em > :h', ['h' => $h24]),
            ],
            'perfis_ativos_30d' => (object)$perfis,
            'serie_30d' => array_values($serie),
        ]);
    }

    /** GET /admin/uso?dias=30 — uso por perfil e por rota (sem horários). */
    public function uso(): Resposta
    {
        $this->adm();
        $dias = max(1, min(366, (int)($this->req->q('dias') ?? 30)));
        $desde = Tempo::local(Tempo::agora())->modify('-' . ($dias - 1) . ' days')->format('Y-m-d');
        $dt = (new \DateTimeImmutable($desde, Tempo::fuso()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $tz = self::deslocamento();

        $porDia = Bd::todos(
            "SELECT dia, perfil, COUNT(DISTINCT u) AS usuarios FROM (
                SELECT dia, perfil, usuario_id AS u FROM uso_diario WHERE dia >= :d
                UNION SELECT DATE(CONVERT_TZ(criado_em, '+00:00', :tz)), COALESCE(perfil, ''), usuario_id
                  FROM log_acessos WHERE ator = 'usuario' AND criado_em >= :dt
             ) x GROUP BY dia, perfil ORDER BY dia",
            ['d' => $desde, 'dt' => $dt, 'tz' => $tz]
        );
        $rotas = [];
        foreach (Bd::todos('SELECT rota, perfil, SUM(requisicoes) AS n FROM uso_diario WHERE dia >= :d GROUP BY rota, perfil', ['d' => $desde]) as $l) {
            $k = $l['rota'] . '|' . $l['perfil'];
            $rotas[$k] = ['rota' => $l['rota'], 'metodo' => 'GET', 'perfil' => $l['perfil'] ?: null, 'requisicoes' => (int)$l['n']];
        }
        foreach (Bd::todos("SELECT metodo, rota, COALESCE(perfil, '') AS perfil, COUNT(*) AS n FROM log_acessos
                             WHERE ator = 'usuario' AND criado_em >= :dt GROUP BY metodo, rota, perfil", ['dt' => $dt]) as $l) {
            $k = $l['metodo'] . ' ' . $l['rota'] . '|' . $l['perfil'];
            $rotas[$k] = ['rota' => $l['rota'], 'metodo' => $l['metodo'], 'perfil' => $l['perfil'] ?: null, 'requisicoes' => (int)$l['n'] + ($rotas[$k]['requisicoes'] ?? 0)];
        }
        usort($rotas, fn($a, $b) => $b['requisicoes'] <=> $a['requisicoes']);

        return Resposta::json([
            'desde' => $desde,
            'por_dia' => array_map(fn($l) => ['dia' => $l['dia'], 'perfil' => $l['perfil'] ?: null, 'usuarios' => (int)$l['usuarios']], $porDia),
            'rotas' => array_slice($rotas, 0, 40),
        ]);
    }

    /** GET /admin/sistema */
    public function sistema(): Resposta
    {
        $adm = $this->adm();
        $ag = Tempo::agoraSql();
        $h24 = Tempo::agora()->modify('-24 hours')->format('Y-m-d H:i:s');

        $aplicadas = array_column(Bd::todos('SELECT versao, aplicado_em FROM esquema_versoes ORDER BY versao'), 'aplicado_em', 'versao');
        $arquivos = array_map(fn($f) => substr(basename($f), 0, 3),
            array_merge(glob(Migracoes::pasta() . '/0*.sql') ?: [], glob(Migracoes::pasta() . '/0*.sql.php') ?: []));
        sort($arquivos);
        $tabelas = Bd::todos(
            'SELECT table_name AS nome, table_rows AS linhas, (data_length + index_length) AS bytes
               FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length + index_length) DESC'
        );
        $ultima = Tarefas::ultimaExecucao();

        $dados = [
            'versao_api' => '1.1.0',
            'php' => PHP_VERSION,
            'banco' => (string)Bd::valor('SELECT VERSION()'),
            'ambiente' => Config::emTeste() ? 'teste' : 'producao',
            'url_app' => Config::get('url_app'),
            'canais_codigo' => Canais::disponiveis(),
            'google_ligado' => Google::ligado(),
            'termos_versao' => Config::get('termos.versao'),
            'guarda_log_dias' => max(183, (int)Config::get('logs.dias', 365)),
            'esquema' => [
                'aplicadas' => array_map(fn($v, $a) => ['versao' => $v, 'aplicado_em' => self::iso($a)], array_keys($aplicadas), $aplicadas),
                'pendentes' => array_values(array_diff(array_unique($arquivos), array_keys($aplicadas))),
            ],
            'tarefas' => [
                'automaticas' => filter_var(Config::get('tarefas.automaticas', true), FILTER_VALIDATE_BOOL),
                'ultima_execucao' => $ultima ? gmdate('Y-m-d\TH:i:s\Z', $ultima) : null,
                'chamada_externa' => strlen((string)Config::get('tarefas.chave', '')) >= 32,
            ],
            'gravacao' => [
                'midias' => is_writable((string)Config::get('midias.pasta')),
                'logs' => is_dir(CUIDADOS_RAIZ . '/logs') && is_writable(CUIDADOS_RAIZ . '/logs'),
            ],
            'midias' => Bd::um('SELECT COUNT(*) AS arquivos, COALESCE(SUM(bytes), 0) AS bytes FROM midias WHERE excluido_em IS NULL'),
            'fila_saida_pendente' => (int)Bd::valor('SELECT COUNT(*) FROM eventos_saida WHERE processado_em IS NULL'),
            'codigos_24h' => Bd::um('SELECT COUNT(*) AS pedidos, SUM(usado_em IS NOT NULL) AS usados, SUM(tentativas >= 5) AS esgotados FROM otp_codigos WHERE criado_em > :h', ['h' => $h24]),
            'sessoes_ativas' => [
                'app' => (int)Bd::valor('SELECT COUNT(*) FROM sessoes WHERE revogada_em IS NULL AND expira_em > :a', ['a' => $ag]),
                'painel' => (int)Bd::valor('SELECT COUNT(*) FROM admin_sessoes WHERE revogada_em IS NULL AND expira_em > :a', ['a' => $ag]),
            ],
            'tabelas' => array_map(fn($t) => ['nome' => $t['nome'], 'linhas' => (int)$t['linhas'], 'bytes' => (int)$t['bytes']], $tabelas),
        ];
        // Últimas linhas do log de erros do PHP (só mensagens técnicas): operador ou dono.
        if (\Cuidados\Auth\AdminSessao::nivel((string)$adm['papel']) >= 2) {
            $dados['erros_php'] = self::ultimasLinhas(CUIDADOS_RAIZ . '/logs/erros.log.php', 40)
                ?: self::ultimasLinhas(rtrim((string)Config::get('midias.pasta'), '/') . '/erros.log.php', 40);
        }
        return Resposta::json($dados);
    }

    /** POST /admin/sistema/tarefas — roda agora migrações, alertas de cobertura e limpezas. */
    public function tarefas(): Resposta
    {
        $this->adm('operador');
        $r = Tarefas::executar();
        $this->auditar('tarefas_executadas', 'sistema', null, null, ['migracoes' => $r['migracoes'], 'alertas' => $r['alertas']]);
        return Resposta::json($r);
    }

    /** POST /admin/auditoria/verificar — confere a cadeia de hashes da auditoria. */
    public function verificarAuditoria(): Resposta
    {
        $this->adm();
        return Resposta::json(Auditoria::verificar());
    }

    /** @return list<string> */
    private static function ultimasLinhas(string $arquivo, int $n): array
    {
        if (!is_file($arquivo) || !is_readable($arquivo)) return [];
        $tam = (int)filesize($arquivo);
        $h = fopen($arquivo, 'rb');
        if ($h === false) return [];
        fseek($h, max(0, $tam - 20000));
        $texto = (string)stream_get_contents($h);
        fclose($h);
        $linhas = array_values(array_filter(explode("\n", $texto), fn($l) => trim($l) !== '' && !str_starts_with($l, '<?php')));
        return array_map(fn($l) => mb_substr($l, 0, 400), array_slice($linhas, -$n));
    }
}

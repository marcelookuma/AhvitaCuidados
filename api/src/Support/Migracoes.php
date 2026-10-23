<?php
declare(strict_types=1);

namespace Cuidados\Support;

/**
 * Aplica os arquivos db/0*.sql que ainda não estão em esquema_versoes.
 * Usado por bin/instalar.php e pelo cron (bin/agendador.php): depois de cada
 * publicação, o banco se atualiza sozinho em até 5 minutos.
 *
 * Cada arquivo aplicado é registrado em esquema_versoes (antes só o 001 se
 * registrava sozinho, e o 002 era reaplicado a cada execução das tarefas).
 * "Já existe" (coluna, índice ou tabela) conta como aplicado: assim um banco
 * que já recebeu o arquivo, mas não o registrou, se acerta sozinho.
 */
final class Migracoes
{
    /** 1050 tabela já existe · 1060 coluna já existe · 1061 índice já existe · 1826 FK já existe */
    private const JA_EXISTE = [1050, 1060, 1061, 1826];

    /** @return list<string> versões aplicadas agora */
    public static function aplicar(string $pasta): array
    {
        // 0NN_nome.sql (repositório) ou 0NN_nome.sql.php (servidor: começa com um bloqueio 404)
        $arquivos = array_merge(glob(rtrim($pasta, '/') . '/0*.sql') ?: [], glob(rtrim($pasta, '/') . '/0*.sql.php') ?: []);
        sort($arquivos);
        $ja = [];
        try {
            $ja = array_column(Bd::todos('SELECT versao FROM esquema_versoes'), 'versao');
        } catch (\PDOException) {
            // banco vazio
        }
        $novas = [];
        foreach ($arquivos as $arq) {
            $versao = substr(basename($arq), 0, 3);
            if (in_array($versao, $ja, true)) continue;
            $ja[] = $versao;
            $sql = (string)file_get_contents($arq);
            if (str_starts_with($sql, '<?php')) $sql = substr($sql, (int)strpos($sql, "?>\n") + 3);
            foreach (preg_split('/;\s*\n/', $sql) ?: [] as $cmd) {
                $cmd = trim(preg_replace('/^\s*--.*$/m', '', $cmd) ?? '');
                if ($cmd === '') continue;
                try {
                    Bd::pdo()->exec($cmd);
                } catch (\PDOException $e) {
                    $codigo = (int)($e->errorInfo[1] ?? 0);
                    if (!in_array($codigo, self::JA_EXISTE, true)) throw $e;
                }
            }
            Bd::exec('INSERT IGNORE INTO esquema_versoes (versao, aplicado_em) VALUES (:v, :a)', ['v' => $versao, 'a' => Tempo::agoraSql()]);
            $novas[] = $versao;
        }
        return $novas;
    }

    public static function pasta(): string
    {
        foreach ([getenv('CUIDADOS_SQL') ?: '', CUIDADOS_RAIZ . '/db', dirname(CUIDADOS_RAIZ) . '/db'] as $p) {
            if ($p !== '' && is_dir($p)) return $p;
        }
        return CUIDADOS_RAIZ . '/db';
    }
}

<?php
declare(strict_types=1);

namespace Cuidados\Support;

/**
 * Aplica os arquivos db/0*.sql que ainda não estão em esquema_versoes.
 * Usado por bin/instalar.php e pelo cron (bin/agendador.php): depois de cada
 * publicação, o banco se atualiza sozinho em até 5 minutos.
 */
final class Migracoes
{
    /** @return list<string> versões aplicadas agora */
    public static function aplicar(string $pasta): array
    {
        $arquivos = glob(rtrim($pasta, '/') . '/0*.sql') ?: [];
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
            foreach (preg_split('/;\s*\n/', (string)file_get_contents($arq)) ?: [] as $cmd) {
                $cmd = trim(preg_replace('/^\s*--.*$/m', '', $cmd) ?? '');
                if ($cmd !== '') Bd::pdo()->exec($cmd);
            }
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

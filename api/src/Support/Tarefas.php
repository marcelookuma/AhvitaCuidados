<?php
declare(strict_types=1);

namespace Cuidados\Support;

use Cuidados\Modulos\Avisos;

/**
 * Tarefas periódicas: migrações do banco, alertas de cobertura e limpeza.
 *
 * Três formas de rodar (a hospedagem pode não ter cron):
 *  1. Automática: a própria API roda a cada 5 min, na primeira requisição depois
 *     desse intervalo (o app aberto já faz requisições a cada poucos segundos).
 *  2. Cron, se existir: php <privado>/bin/agendador.php
 *  3. Chamada externa (ex.: cron-job.org): POST /api/v1/sistema/tarefas
 *     com o cabeçalho X-Chave-Tarefas = tarefas.chave da configuração.
 */
final class Tarefas
{
    private const INTERVALO = 300;
    /** Avisos no celular saem com mais pressa que as demais tarefas. */
    private const INTERVALO_PUSH = 60;

    /** @return array{migracoes: list<string>, alertas: int, avisos_celular: int} */
    public static function executar(): array
    {
        $migr = Migracoes::aplicar(Migracoes::pasta());
        $n = Avisos::verificarCobertura();
        // Outbox: avisos no celular (push) do app Android/iOS; WhatsApp entra aqui depois.
        $push = Push::processar();
        Bd::exec('UPDATE eventos_saida SET processado_em = :a WHERE processado_em IS NULL AND criado_em < :lim',
            ['a' => Tempo::agoraSql(), 'lim' => Tempo::agora()->modify('-7 days')->format('Y-m-d H:i:s')]);
        Bd::exec('DELETE FROM eventos_saida WHERE processado_em IS NOT NULL AND processado_em < :lim',
            ['lim' => Tempo::agora()->modify('-30 days')->format('Y-m-d H:i:s')]);
        Bd::exec('DELETE FROM otp_codigos WHERE criado_em < :lim', ['lim' => Tempo::agora()->modify('-2 days')->format('Y-m-d H:i:s')]);
        Bd::exec('DELETE FROM sessoes WHERE expira_em < :lim', ['lim' => Tempo::agora()->modify('-30 days')->format('Y-m-d H:i:s')]);
        LogAcesso::limpar();
        self::marcar();
        return ['migracoes' => $migr, 'alertas' => $n, 'avisos_celular' => $push['enviados']];
    }

    /** Roda se já passou o intervalo. Nunca derruba a requisição. */
    public static function seVencidas(): void
    {
        if (!Config::configurado()) return; // ainda sem configuracao.php
        if (!filter_var(Config::get('tarefas.automaticas', true), FILTER_VALIDATE_BOOL)) return;
        self::pushSeVencido();
        $marca = self::arquivoMarca();
        $ultima = is_file($marca) ? (int)@filemtime($marca) : 0;
        if ($ultima > time() - self::INTERVALO) return;
        $trava = @fopen($marca . '.trava', 'c');
        if ($trava === false) return;
        try {
            if (!flock($trava, LOCK_EX | LOCK_NB)) return; // outra requisição já está rodando
            clearstatcache(true, $marca);
            if (is_file($marca) && (int)filemtime($marca) > time() - self::INTERVALO) return;
            self::marcar(); // marca antes: se falhar, só tenta de novo no próximo intervalo
            self::executar();
        } catch (\Throwable $e) {
            error_log('[cuidados] tarefas: ' . $e->getMessage());
        } finally {
            flock($trava, LOCK_UN);
            fclose($trava);
        }
    }

    /** Push a cada minuto (só quando o Firebase estiver configurado). Nunca derruba a requisição. */
    private static function pushSeVencido(): void
    {
        if (!Push::configurado()) return;
        $marca = self::arquivoPrivado('.push');
        $ultima = is_file($marca) ? (int)@filemtime($marca) : 0;
        if ($ultima > time() - self::INTERVALO_PUSH) return;
        $trava = @fopen($marca . '.trava', 'c');
        if ($trava === false) return;
        try {
            if (!flock($trava, LOCK_EX | LOCK_NB)) return;
            @touch($marca);
            Push::processar();
        } catch (\Throwable $e) {
            error_log('[cuidados] push: ' . $e->getMessage());
        } finally {
            flock($trava, LOCK_UN);
            fclose($trava);
        }
    }

    /** Quando as tarefas rodaram pela última vez (para o painel /admin). */
    public static function ultimaExecucao(): ?int
    {
        $m = self::arquivoMarca();
        return is_file($m) ? (int)filemtime($m) : null;
    }

    private static function marcar(): void
    {
        @touch(self::arquivoMarca());
    }

    private static function arquivoMarca(): string
    {
        return self::arquivoPrivado('.tarefas');
    }

    /** Arquivo de controle na pasta privada de mídias (fora da pasta pública). */
    public static function arquivoPrivado(string $nome): string
    {
        $pasta = rtrim((string)Config::get('midias.pasta'), '/');
        if (!is_dir($pasta)) @mkdir($pasta, 0770, true);
        if (is_dir($pasta) && is_writable($pasta)) return $pasta . '/' . $nome;
        // Sem escrita na pasta de mídias: usa a pasta temporária (sem isso, rodaria a cada requisição)
        return rtrim(sys_get_temp_dir(), '/') . '/cuidados' . $nome . '-' . substr(hash('sha256', CUIDADOS_RAIZ), 0, 12);
    }
}

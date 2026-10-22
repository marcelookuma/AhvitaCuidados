<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Http\Resposta;
use Cuidados\Support\Bd;
use Cuidados\Support\Config;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\Tempo;
use Cuidados\Support\Ulid;

/**
 * Fotos e áudios. Ficam numa pasta FORA da pasta pública e só saem por
 * aqui, depois de checar o acesso. Nunca há link público para uma foto.
 */
final class MidiasControlador extends Controlador
{
    private const TIPOS = [
        'image/jpeg' => ['foto', 'jpg'], 'image/png' => ['foto', 'png'], 'image/webp' => ['foto', 'webp'],
        'image/heic' => ['foto', 'heic'], 'image/heif' => ['foto', 'heic'],
        'audio/webm' => ['audio', 'webm'], 'audio/ogg' => ['audio', 'ogg'], 'audio/mp4' => ['audio', 'm4a'],
        'audio/mpeg' => ['audio', 'mp3'], 'audio/aac' => ['audio', 'aac'], 'audio/x-m4a' => ['audio', 'm4a'],
        'video/webm' => ['audio', 'webm'], // Chrome às vezes marca áudio gravado como video/webm
    ];

    /** POST /midias (multipart: arquivo, pessoa_id | conversa_id) */
    public function enviar(): Resposta
    {
        $f = $this->req->arquivos['arquivo'] ?? null;
        if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name']) && !Config::emTeste()) {
            throw ErroHttp::validacao(['arquivo' => 'Não recebemos o arquivo. Tente de novo']);
        }
        $max = (int)Config::get('midias.max_bytes');
        if ((int)$f['size'] > $max) throw ErroHttp::validacao(['arquivo' => 'Arquivo muito grande']);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string)$f['tmp_name']) ?: '';
        if (!isset(self::TIPOS[$mime])) throw ErroHttp::validacao(['arquivo' => 'Envie uma foto ou um áudio']);
        [$tipo, $ext] = self::TIPOS[$mime];

        $pessoaId = $this->req->formulario['pessoa_id'] ?? null;
        $conversaId = $this->req->formulario['conversa_id'] ?? null;
        if (is_string($pessoaId) && $pessoaId !== '') {
            $ctx = Acesso::pessoa($this->u(), $pessoaId);
            if (!Acesso::pode($ctx, 'registrar') && !Acesso::pode($ctx, 'mensagens')) throw ErroHttp::proibido();
            $conversaId = Conversas::daPessoa($pessoaId);
        } elseif (is_string($conversaId) && $conversaId !== '') {
            $c = Bd::um("SELECT * FROM conversas WHERE id = :id AND tipo = 'direta'", ['id' => $conversaId]);
            if ($c === null || ($c['usuario_id'] !== $this->uid() && Acesso::membroEmpresa($this->uid(), (string)$c['empresa_id']) === null)) {
                throw ErroHttp::naoEncontrado();
            }
            $pessoaId = null;
        } else {
            throw ErroHttp::validacao(['pessoa_id' => 'Informe a pessoa ou a conversa']);
        }

        $id = Ulid::novo();
        $rel = Tempo::agora()->format('Y/m') . "/$id.$ext.php"; // .php + prefixo: nunca servido como arquivo
        $destino = rtrim((string)Config::get('midias.pasta'), '/') . '/' . $rel;
        if (!is_dir(dirname($destino)) && !mkdir(dirname($destino), 0750, true) && !is_dir(dirname($destino))) {
            throw new ErroHttp(500, 'midias', 'Não conseguimos guardar o arquivo.');
        }
        $tmp = (string)$f['tmp_name'];
        if (!is_uploaded_file($tmp) && !is_file($tmp)) throw new ErroHttp(500, 'midias', 'Não conseguimos guardar o arquivo.');
        $sha = (string)hash_file('sha256', $tmp);
        $bytes = (int)filesize($tmp);
        if (!\Cuidados\Support\ArquivoProtegido::guardar($tmp, $destino)) throw new ErroHttp(500, 'midias', 'Não conseguimos guardar o arquivo.');
        chmod($destino, 0640);
        Bd::inserir('midias', [
            'id' => $id, 'pessoa_id' => $pessoaId, 'conversa_id' => $conversaId, 'autor_id' => $this->uid(),
            'tipo' => $tipo, 'mime' => $mime === 'video/webm' ? 'audio/webm' : $mime, 'bytes' => $bytes,
            'caminho' => $rel, 'sha256' => $sha, 'criado_em' => Tempo::agoraSql(),
        ]);
        return Resposta::json(['id' => $id, 'tipo' => $tipo], 201);
    }

    /** GET /midias/{id} */
    public function ver(): Resposta
    {
        $m = Bd::um('SELECT * FROM midias WHERE id = :id AND excluido_em IS NULL', ['id' => $this->req->param('id')]);
        if ($m === null) throw ErroHttp::naoEncontrado();
        if ($m['pessoa_id'] !== null) {
            $ctx = Acesso::pessoa($this->u(), $m['pessoa_id']);
            $precisa = $m['tipo'] === 'foto' ? 'fotos' : 'mensagens';
            if ($m['autor_id'] !== $this->uid() && !Acesso::pode($ctx, $precisa) && !Acesso::pode($ctx, 'ver_registros')) {
                throw ErroHttp::proibido();
            }
            if ($m['tipo'] === 'foto' && $m['autor_id'] !== $this->uid() && !Acesso::pode($ctx, 'fotos')) throw ErroHttp::proibido('Você não tem acesso às fotos.');
        } else {
            $c = Bd::um('SELECT * FROM conversas WHERE id = :id', ['id' => $m['conversa_id']]);
            if ($c === null || ($c['usuario_id'] !== $this->uid() && Acesso::membroEmpresa($this->uid(), (string)$c['empresa_id']) === null)) {
                throw ErroHttp::naoEncontrado();
            }
        }
        $arquivo = rtrim((string)Config::get('midias.pasta'), '/') . '/' . $m['caminho'];
        if (!is_file($arquivo)) throw ErroHttp::naoEncontrado();
        return Resposta::arquivo($arquivo, $m['mime']);
    }
}

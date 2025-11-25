<?php
namespace OCA\FolderProtection;

use OCP\Files\NotPermittedException;
use OC\Files\Storage\Wrapper\Wrapper;
use Psr\Log\LoggerInterface;


class StorageWrapper extends Wrapper {

    /**
     * Objeto responsável por verificar se um caminho/pasta está protegido.
     * A ideia: a proteção não é guardada aqui — existe outro serviço
     * (protectionChecker) que sabe quais as pastas protegidas.
     */
    private $protectionChecker;

    /**
     * Construtor: recebe parâmetros do sistema Nextcloud.
     * Espera que em $parameters exista a chave 'protectionChecker'.
     */
    public function __construct($parameters) {
        parent::__construct($parameters);
        $this->protectionChecker = $parameters['protectionChecker'];
    }


    /**
     * Método mágico para tratar chamadas desconhecidas.
     * Aqui está apenas a registar (log) e encaminhar para o storage real.
     * Explicação leiga: se o código chamar um método que não está escrito
     * explicitamente neste wrapper, este __call captura a chamada,
     * escreve um registo e depois delega a ação ao storage original.
     */
    public function __call($method, $args) {
        error_log("FolderProtection: UNKNOWN method called: $method with args: " . json_encode($args));
        return call_user_func_array([$this->storage, $method], $args);
    }

    /**
     * is_dir: verifica se o caminho é uma pasta.
     * Mantemos o comportamento original, mas registamos o pedido.
     */
    public function is_dir($path): bool {
        error_log("FolderProtection: is_dir called for: $path");
        return $this->storage->is_dir($path);
    }

    /**
     * isDeletable: pergunta se é possível apagar um caminho.
     * Se o path estiver protegido, devolve false (não apagável).
     * Explicação leiga: evita que a UI pense que é possível apagar
     * algo que nós queremos proteger.
     */
    public function isDeletable($path): bool {
        error_log("FolderProtection: isDeletable called for: $path");
        if ($this->protectionChecker->isProtected($path)) {
            error_log("FolderProtection: BLOCKING delete on $path via isDeletable");
            return false;
        }
        return $this->storage->isDeletable($path);
    }

    /**
     * isUpdatable: pergunta se um ficheiro/pasta pode ser alterado.
     * Se o path estiver protegido, devolve false (não atualizável).
     */
    public function isUpdatable($path): bool {
        error_log("FolderProtection: isUpdatable called for: $path");
        if ($this->protectionChecker->isProtected($path)) {
            error_log("FolderProtection: BLOCKING update on $path via isUpdatable");
            return false;
        }
        return $this->storage->isUpdatable($path);
    }

    /**
     * copy: bloqueia cópias que envolvam pastas protegidas.
     * Casos cobertos (explicação simples):
     * - Não se pode copiar "de" uma pasta protegida.
     * - Não se pode copiar "para dentro" de uma pasta protegida.
     * - Não se pode criar uma pasta cujo nome (basename) seja igual ao
     *   de uma pasta protegida (evita colisões de nomes).
     *
     * Se tudo estiver ok, delega para o storage original.
     */
    public function copy($source, $target): bool {
        error_log("FolderProtection StorageWrapper: copy() called");
        error_log("  Source: $source");
        error_log("  Target: $target");

        // Se o SOURCE estiver protegido: bloqueia
        if ($this->protectionChecker->isProtected($source)) {
            error_log("FolderProtection: BLOCKING copy - SOURCE is protected: $source");
            throw new LockedException(
                'This folder is protected and cannot be copied.',
                false
            );
        }

        // Se o TARGET ou algum ancestor do target estiver protegido: bloqueia
        if ($this->protectionChecker->isProtectedOrParentProtected($target)) {
            error_log("FolderProtection: BLOCKING copy - TARGET is protected: $target");
            throw new LockedException(
                'Cannot copy into protected folders.',
                false
            );
        }

        // Se o nome do target (basename) conflitar com uma proteção existente: bloqueia
        $targetBasename = basename($target);
        if ($this->protectionChecker->isAnyProtectedWithBasename($targetBasename)) {
            error_log("FolderProtection: BLOCKING copy - TARGET basename matches protected: $targetBasename");
            throw new LockedException(
                'Cannot create folders with protected names.',
                false
            );
        }

        error_log("FolderProtection: Copy ALLOWED - proceeding with operation");
        return $this->storage->copy($source, $target);
    }

    /**
     * rename: bloqueia a renomeação / movimento se a origem estiver protegida.
     * Explicação leiga: mover/renomear uma pasta protegida poderia removê-la da
     * área protegida ou permitir operações indesejadas; portanto bloqueamos.
     */
    public function rename(string $source, string $target): bool {
        error_log("FolderProtection: rename called - source: $source, target: $target");
        if ($this->protectionChecker->isProtected($source)) {
            \OC::$server->get(LoggerInterface::class)->warning("FolderProtection: blocked rename/move of protected folder: $source");
            throw new NotPermittedException("Moving protected folders is not allowed");
        }

        return $this->storage->rename($source, $target);
    }

    /**
     * unlink: bloqueia exclusão de ficheiros/pastas protegidas.
     */
    public function unlink(string $path): bool {
        error_log("FolderProtection: unlink called for: $path");
        if ($this->protectionChecker->isProtected($path)) {
            \OC::$server->get(LoggerInterface::class)->warning("FolderProtection: blocked unlink of protected path: $path");
            throw new NotPermittedException("Deleting protected folders is not allowed");
        }

        return $this->storage->unlink($path);
    }

    /**
     * copyFromStorage: trata cópias entre storages diferentes (ex: GroupFolders).
     * Importante: quando se copia entre storages, o comportamento pode variar;
     * este método garante que também são aplicadas as mesmas regras de proteção.
     */
    public function copyFromStorage(\OCP\Files\Storage\IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool {
        error_log("FolderProtection: copyFromStorage called");
        error_log("  Source storage class: " . get_class($sourceStorage));
        error_log("  Source path: '$sourceInternalPath'");
        error_log("  Target path: '$targetInternalPath'");

        // Se o caminho de origem está preenchido e protegido: bloqueia
        if (!empty($sourceInternalPath) && $this->protectionChecker->isProtected($sourceInternalPath)) {
            error_log("FolderProtection: BLOCKING - source path protected: $sourceInternalPath");
            throw new LockedException(
                'This folder is protected and cannot be copied.',
                false
            );
        }

        // Caso especial: SourceStorage pode corresponder a uma GroupFolder.
        // Muitos storages de GroupFolders expõem um método getFolderId(). Se existir,
        // construímos o caminho interno e verificamos se a GroupFolder está protegida.
        if (method_exists($sourceStorage, 'getFolderId')) {
            $folderId = $sourceStorage->getFolderId();
            $groupFolderPath = "/__groupfolders/$folderId";
            error_log("  Detected GroupFolder ID: $folderId, checking path: $groupFolderPath");

            if ($this->protectionChecker->isProtected($groupFolderPath)) {
                error_log("FolderProtection: BLOCKING - GroupFolder $folderId is protected!");
                throw new LockedException(
                    'This group folder is protected and cannot be copied.',
                    false
                );
            }
        }

        // Verificar target: não podemos copiar para dentro de pastas protegidas
        if ($this->protectionChecker->isProtectedOrParentProtected($targetInternalPath)) {
            error_log("FolderProtection: BLOCKING - target protected: $targetInternalPath");
            throw new LockedException(
                'Cannot copy into protected folders.',
                false
            );
        }

        // Verificar nome base do target para evitar criar pastas cujo nome
        // é igual ao de uma pasta protegida
        $targetBasename = basename($targetInternalPath);
        if ($this->protectionChecker->isAnyProtectedWithBasename($targetBasename)) {
            error_log("FolderProtection: BLOCKING - basename protected: $targetBasename");
            throw new LockedException(
                'Cannot create folders with protected names.',
                false
            );
        }

        error_log("FolderProtection: copyFromStorage ALLOWED");
        return parent::copyFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
    }

    /**
     * moveFromStorage: bloqueia movimentos entre storages quando a origem
     * estiver protegida. Similar a copyFromStorage mas para operações de "mover".
     */
    public function moveFromStorage(\OCP\Files\Storage\IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool {
        error_log("FolderProtection: moveFromStorage called - source: $sourceInternalPath, target: $targetInternalPath");

        if ($this->protectionChecker->isProtected($sourceInternalPath)) {
            error_log("FolderProtection: BLOCKING moveFromStorage of $sourceInternalPath");
            throw new LockedException(
                'This folder is protected and cannot be moved.',
                false
            );
        }
        return parent::moveFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
    }

    /**
     * rmdir: bloqueia remoção de diretórios protegidos.
     */
    public function rmdir(string $path): bool {
        error_log("FolderProtection: rmdir called for: $path");
        if ($this->protectionChecker->isProtected($path)) {
            \OC::$server->get(LoggerInterface::class)->warning("FolderProtection: blocked rmdir of protected folder: $path");
            throw new NotPermittedException("Deleting protected folders is not allowed");
        }

        return $this->storage->rmdir($path);
    }

    /**
     * getPermissions: quando um caminho está protegido, removemos
     * permissões de escrita/alteração para que a UI e outros componentes
     * tratem o item como somente-leitura.
     */
    public function getPermissions($path): int {
        if ($this->protectionChecker->isProtected($path)) {
            error_log("FolderProtection: REMOVING write permissions from $path");
            // Devolve apenas permissão de leitura e partilha, sem escrita
            return \OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_SHARE;
        }
        return $this->storage->getPermissions($path);
    }

    /**
     * file_exists: wrapper simples que também regista a chamada.
     */
    public function file_exists($path): bool {
        error_log("FolderProtection: file_exists called for: $path");
        return $this->storage->file_exists($path);
    }


    /**
     * mkdir: cria diretório, mas bloqueia se o destino ou qualquer pai
     * estiver protegido, ou se o nome conflitar com uma pasta protegida.
     */
    public function mkdir(string $path): bool {
        error_log("FolderProtection: mkdir called for: $path");

        // Bloqueia se o destino ou qualquer ancestor estiver protegido
        if ($this->protectionChecker->isProtected($path) || $this->protectionChecker->isProtectedOrParentProtected($path)) {
            error_log("FolderProtection: BLOCKING mkdir for protected path: $path");
            throw new \OCP\Files\ForbiddenException(
                'Cannot create directory: target is protected or inside a protected folder.',
                false
            );
        }

        // Bloqueia se o basename do novo item conflitar com uma pasta protegida
        if ($this->protectionChecker->isAnyProtectedWithBasename(basename($path))) {
            error_log("FolderProtection: BLOCKING mkdir for $path because protected basename: " . basename($path));
            throw new \OCP\Files\ForbiddenException(
                'Cannot create directory with this name because a protected folder exists.',
                false
            );
        }

        return $this->storage->mkdir($path);
    }

}

<?php
namespace OCA\FolderProtection\AppInfo;

/**
 * Services
 *
 * Pequeno helper para registar serviços no container da aplicação.
 * Este ficheiro regista o serviço `ProtectionChecker` quando o framework
 * chama `Services::register($container)` — é uma alternativa/adição ao
 * método `register` presente em `Application.php` e pode ser usado por
 * código de bootstrap mais simples ou por testes.
 *
 * Serviço registado:
 * - `ProtectionChecker::class` — instanciado com `IDBConnection` e `ICacheFactory`.
 *
 * Observações:
 * - Evita duplicar lógica: se já estás a registar o serviço em `Application::register`,
 *   não é estritamente necessário chamar este helper; está presente por compatibilidade.
 */

use OCA\FolderProtection\ProtectionChecker;
use OCP\AppFramework\App;
use OCP\IContainer;

class Services {
    // Função de conveniência para registar serviços no container.
    public static function register(IContainer $container): void {
        // Regista ProtectionChecker no container — o mesmo construtor usado noutros pontos
        // da app (ver `AppInfo/Application.php`). Mantemos a mesma assinatura para consistência.
        $container->registerService(ProtectionChecker::class, function($c) {
            return new ProtectionChecker(
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCP\ICacheFactory::class)
            );
        });
    }
}

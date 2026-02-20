# Changelog de Desenvolvimento - Folder Protection

## Fase 4: Melhoria de UX e Tratamento de Erros (423 Locked)
- **Objetivo**: Substituir erros genéricos (403/404) por `423 Locked` e fornecer headers informativos para clientes de sincronização (especialmente Windows).
- **Alterações**:
  - **`lib/DAV/ProtectionPlugin.php`**:
    - Substituídas exceções `Forbidden` e `NotFound` por `Locked` (423).
    - Adicionado método `setHeaders()` para injetar:
      - `X-NC-Folder-Protected: true`
      - `X-NC-Protection-Action: <action>`
      - `X-NC-Protection-Reason: <reason>`
    - Lógica de bloqueio atualizada em `beforeMethod`, `beforeBind`, `beforeUnbind`, `beforeMove`, `beforeCopy`, `propPatch` e `beforeLock`.
  - **`lib/StorageWrapper.php`**:
    - Padronizado o uso de `NotPermittedException` para todas as operações de bloqueio no storage.

## Fase 5: Sistema de Notificações
- **Objetivo**: Alertar o utilizador via sistema de notificações do Nextcloud quando uma operação é bloqueada.
- **Alterações**:
  - **`lib/Notification/Notifier.php`**: Nova classe criada para formatar e traduzir as notificações (`folder_protected`).
  - **`lib/AppInfo/Application.php`**: Registo do serviço de notificações e do Notifier.
  - **`lib/ProtectionChecker.php`**:
    - Adicionado método `shouldNotify($path, $action)` com **Rate Limiting** (TTL 30 min) para evitar spam de notificações quando clientes tentam repetir operações.
    - Adicionado método `clearCache()` para limpeza geral.
  - **`lib/DAV/ProtectionPlugin.php`**: Integrada chamada a `sendProtectionNotification()` antes de lançar exceções.
  - **`lib/StorageWrapper.php`**: Integrada chamada a `sendProtectionNotification()` nas operações de filesystem (`rmdir`, `unlink`, `rename`, etc.).
  - **Correções**: `getInternalPath` e `buildPathsToCheck` ajustados para normalização correta de caminhos. Atribuição de `$reason` mais robusta. Tratamento de `TypeError` em `sendProtectionNotification` (catch `\Throwable`).
  - **`lib/Command/ClearNotifications.php`**: Novo comando OCC `folder-protection:clear-notifications` para limpar a cache de rate-limit manualmente.

## Fase 6: Interface Web (UI)
- **Objetivo**: Identificar visualmente pastas protegidas na interface web e (futuramente) esconder ações proibidas.
- **Alterações**:
  - **`js/folder-protection-ui.js`**:
    - Implementado `MutationObserver` para detetar renderização da lista de ficheiros.
    - Adiciona atributo `data-folder-protected="true"` às linhas de pastas protegidas.
    - Injeta CSS dinâmico para adicionar um ícone de cadeado (🔒) e badge "Protected folder".
  - **`lib/AppInfo/Application.php`**: Atualizado `boot()` para carregar o script `folder-protection-ui` globalmente.

## Comandos OCC Adicionados/Atualizados
- `folder-protection:protect`: Adiciona proteção.
- `folder-protection:unprotect`: Remove proteção.
- `folder-protection:list`: Lista proteções ativas.
- `folder-protection:check`: Verifica estado de uma pasta.
- `folder-protection:clear-notifications`: Limpa cache de notificações.

## Testes
- Criado script `tests/manual_curl_test.sh` para validação manual via cURL dos status codes (423) e headers (`X-NC-*`).
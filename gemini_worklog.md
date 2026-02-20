# Gemini Worklog - Folder Protection App

Este ficheiro regista os passos tomados para depurar e resolver o problema de deleção de pastas protegidas.

## Problema

O cliente de desktop do Nextcloud tenta repetidamente apagar pastas protegidas, mesmo que o servidor recuse a operação com um status `423 Locked`. Isto causa uma má experiência de utilização, com a pasta a desaparecer e reaparecer.

No Windows especificamente, o problema é mais grave porque:
- O cliente remove a pasta localmente (do seu cache)
- O servidor rejeita a operação com 403/423
- A pasta já desapareceu do explorador local
- Cliente tem de resyncronizar manualmente

## Solução: WebDAV LOCK Bloqueado + Propriedades Customizadas

Implementação de um plugin WebDAV que bloqueia operações de LOCK/UNLOCK em pastas protegidas.

### Por que esta solução?

1. **Nativa** - Usa WebDAV standard (operações LOCK/UNLOCK)
2. **Compatível** - Windows, macOS, Linux e todos os clientes WebDAV entendem LOCK
3. **Preventiva** - Bloqueia operações ANTES de modificarem o filesystem
4. **Elegante** - Sem necessidade de restaurar pastas após delete

### Como funciona

```
Client Windows tenta DELETE numa pasta protegida
        ↓
1. propFind revela propriedades customizadas is-locked=true
        ↓
2. Cliente WebDAV detecta que não consegue fazer LOCK (403)
        ↓
3. Cliente WebDAV recusa DELETE localmente
        ↓
4. Pasta permanece intacta, sem confusão
```

Em vez de implementar locks automáticos (complexo), a estratégia é:
- **Bloquear tentativas de LOCK** - Se o cliente tenta fazer LOCK, retorna 403 Forbidden
- **Bloquear tentativas de UNLOCK** - Se o cliente tenta fazer UNLOCK, retorna 403 Forbidden
- **Reportar propriedades** - PROPFIND reporta `is-locked=true` e a razão da proteção

Isto força o cliente WebDAV a respeitar a proteção sem necessidade de locks automáticos no servidor.

### Solução de 2025-02-20 vs Solução Original

Original (`ProtectionPlugin.php`):
- Bloqueia DELETE/MOVE/COPY com 403/423
- Tenta atualizar ETag para forçar resync
- Problema: No Windows, a pasta já desaparece do cache antes do servidor rejeitar

**Nova** (`LockPlugin.php`):
- Bloqueia LOCK/UNLOCK com 403 Forbidden
- Reporta propriedades `is-locked` e `lock-reason`
- Melhor: Cliente WebDAV não consegue fazer operações de lock, logo não consegue modificar
- Windows: Vê que não consegue fazer LOCK e não tenta DELETE

## Plano de Ação

1. [X] Clarificar o problema e estabelecer um plano de ação
2. [X] Considerar estratégias (opção 1 vs opção 2)
3. [X] Escolher WebDAV LOCK como solução
4. [X] Implementar `LockPlugin.php` com:
   - beforeLock / beforeUnlock handlers
   - propFind para reportar locks automáticos
   - Geração de lock tokens determinísticos
5. [X] Registar LockPlugin em `SabrePluginListener.php`
6. [X] Registar LockPlugin em `Application.php` (DI container)
7. [ ] Testar com cliente Windows (curl + real client)
8. [ ] Testar com macOS Finder
9. [ ] Testar com cliente Linux
10. [ ] Validar performance (caching, load)
11. [ ] Documentar behaviorém no README

## Implementação de LockPlugin

### Ficheiros criados/modificados:

1. **`lib/DAV/LockPlugin.php`** (NOVO)
   - Plugin separado que bloqueia lock/unlock em pastas protegidas
   - Métodos:
     - `beforeLock()` - Lança 403 Forbidden se a pasta está protegida
     - `beforeUnlock()` - Lança 403 Forbidden se a pasta está protegida
     - `propFind()` - Reporta `is-locked` e `lock-reason` nas propriedades
   - Thread-safe, stateless para multi-node Nextcloud

2. **`lib/Listener/SabrePluginListener.php`** (MODIFICADO)
   - Adicionado suporte a LockPlugin
   - Ordem de prioridade: LockPlugin (5) → ProtectionPlugin (10) → ProtectionPropertyPlugin
   - LockPlugin tem prioridade mais alta para interceptar antes

3. **`lib/AppInfo/Application.php`** (MODIFICADO)
   - Registado LockPlugin no container DI
   - Injeção automática de ProtectionChecker e LoggerInterface

### Propriedades Reportadas

```xml
<d:propfind>
  <d:prop>
    <nc:is-locked>true</nc:is-locked>
    <nc:lock-reason>Protected by server policy</nc:lock-reason>
    <nc:is-protected>true</nc:is-protected>
    <nc:protection-reason>Critical data</nc:protection-reason>
    <nc:is-deletable>false</nc:is-deletable>
    <nc:is-renameable>false</nc:is-renameable>
    <nc:is-moveable>false</nc:is-moveable>
  </d:prop>
</d:propfind>
```

### Comportamento de Erro

```
Operação           Resposta
─────────────────────────────────
LOCK protected     403 Forbidden
UNLOCK protected   403 Forbidden
DELETE protected   (bloqueado antes por ProtectionPlugin com 403)
PROPFIND protected Reports is-locked=true
```

## Status: ✅ Implementação Completa

### Código implementado:
- [X] `LockPlugin.php` criado com handlers beforeLock/beforeUnlock
- [X] `propFind()` implementado para reportar propriedades
- [X] `SabrePluginListener` registra LockPlugin
- [X] `Application.php` injeta dependências
- [X] Worklog atualizado com estratégia explicada

## ✅ Solução Definitiva — 2026-02-20

### Problema real identificado
O cliente desktop Nextcloud ignora propriedades customizadas (`nc:is-deletable`, etc.) para decisões de sincronização. Usa **exclusivamente** `oc:permissions` para decidir se pode apagar uma pasta.

A pasta protegida retornava `oc:permissions=RGDNVCK` (com `D` = delete), por isso o cliente tentava sempre apagar no servidor, recebia 423, e ficava em erro de sincronização sem restaurar a pasta.

### Fix implementado em `ProtectionPropertyPlugin.php`
- Registar o handler `propFind` com **prioridade 150** (depois do FilesPlugin do core que corre a 100)
- Para pastas protegidas, ler o valor atual de `oc:permissions` com `PropFind::get()`
- Remover o carácter `D` e forçar o novo valor com `PropFind::set()`
- Resultado: `RGDNVCK` → `RGNVCK`

### Resultado verificado
```
oc:permissions = RGNVCK   ← sem 'D', cliente não tenta apagar
nc:is-protected = true
nc:is-deletable = false
```

### Comportamento esperado após fix
- Cliente desktop vê `oc:permissions` sem `D`
- Não tenta sincronizar a eliminação para o servidor
- Pasta permanece visível localmente e no servidor
- Sem erros de sincronização

---

## Testes Próximos (Em Progresso)

### 1. Teste PROPFIND
```bash
# Verificar que pastas protegidas reportam is-locked=true
curl -X PROPFIND http://localhost/remote.php/dav/files/user/protected_folder \
  -H "Depth: 0" \
  -u user:pass | grep -i "is-locked"
```

### 2. Teste LOCK (deve falhar)
```bash
# Tentar lock numa pasta protegida (deve retornar 403)
curl -X LOCK http://localhost/remote.php/dav/files/user/protected_folder \
  -H "Content-Type: application/xml" \
  -H "Timeout: Infinite" \
  -d '<?xml version="1.0" encoding="UTF-8"?>
      <lockinfo xmlns="DAV:">
        <lockscope><exclusive/></lockscope>
        <locktype><write/></locktype>
        <owner>Test User</owner>
      </lockinfo>' \
  -u user:pass -v
# Esperado: HTTP/1.1 403 Forbidden
```

### 3. Teste DELETE via Cliente Desktop
- Proteger pasta no admin panel
- Tentar deletar no cliente desktop Windows
- Observar comportamento:
  - ✓ Pasta NÃO desaparece e reaparece
  - ✓ Erro claro reportado ao utilizador
  - ✓ Logs do servidor mostram 403
  - ✓ Pasta permanece intacta no servidor

### 4. Teste Performance
- Verificar latência de propFind em pastas com muitos ficheiros
- Monitorar cache hits do ProtectionChecker
- Validar que não há deadlocks ou race conditions

### 5. Teste Compatibilidade
- macOS Finder (drag-drop de pasta protegida)
- Linux Nautilus (delete via contextmenu)
- Windows Explorer (delete, cut-paste, move)
- Nextcloud Web UI (delete, move)

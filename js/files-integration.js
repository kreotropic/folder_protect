(function(OC, OCA) {
    'use strict';

    const FolderProtectionUI = {
        protectedFolders: new Set(),
        initialized: false,

        async loadProtectedFolders() {
            try {
                const response = await fetch(OC.generateUrl('/apps/folder_protection/api/status'));
                const data = await response.json();
                
                if (data.success && data.protections) {
                    this.protectedFolders = new Set(Object.keys(data.protections));
                    console.log('FolderProtection: Loaded', this.protectedFolders.size, 'protected folders');
                    console.log('FolderProtection: Protected paths:', Array.from(this.protectedFolders));
                }
            } catch (error) {
                console.error('FolderProtection: Failed to load', error);
            }
        },

        addProtectionIndicators() {
            const fileTable = document.querySelector('tbody.files-list__tbody');
            
            if (!fileTable) {
                console.warn('FolderProtection: File table not found');
                return;
            }

            const fileRows = fileTable.querySelectorAll('tr.files-list__row[data-cy-files-list-row-name]');
            console.log('FolderProtection: Found', fileRows.length, 'items');
            
            let marked = 0;
            fileRows.forEach(row => {
                const filename = row.getAttribute('data-cy-files-list-row-name');
                
                if (!filename) return;

                // Construir path (assumir root por agora)
                let fullPath = '/files/' + filename;

                console.log('FolderProtection: Checking:', fullPath);

                if (this.protectedFolders.has(fullPath)) {
                    this.markAsProtected(row, filename);
                    marked++;
                }
            });

            console.log('FolderProtection: Marked', marked, 'protected folders');
        },

        markAsProtected(row, filename) {
            if (row.querySelector('.folder-protection-icon')) {
                return;
            }

            row.classList.add('folder-protected');

            // Adicionar à CÉLULA do nome (container maior)
            const nameCell = row.querySelector('td.files-list__row-name');
            const iconSpan = row.querySelector('span.files-list__row-icon');
            
            if (nameCell && iconSpan) {
                console.log('FolderProtection: Adding badge overlay:', filename);
                
                // Obter posição do ícone em relação à célula
                const cellRect = nameCell.getBoundingClientRect();
                const iconRect = iconSpan.getBoundingClientRect();
                
                // Calcular offset relativo
                const leftOffset = iconRect.left - cellRect.left;
                const topOffset = iconRect.top - cellRect.top;
                
                // Garantir célula é relative
                nameCell.style.position = 'relative';
                
                const wrapper = document.createElement('div');
                wrapper.className = 'folder-protection-wrapper';
                wrapper.style.cssText = `
                    position:absolute;
                    top:${topOffset + 26}px;
                    left:${leftOffset + 24}px;
                    width:15px;
                    height:15px;
                    pointer-events:none;
                    z-index:9999;
                `;
                
                const badge = document.createElement('span');
                badge.className = 'folder-protection-icon';
                badge.title = 'This folder is protected and cannot be moved, copied or deleted';
                badge.innerHTML = `<span class="material-design-icon lock-icon" style="width:16px;height:16px">
                        <svg fill="#f39c12" width="16" height="16" viewBox="0 0 24 24">
                            <path d="M12,17A2,2 0 0,0 14,15C14,13.89 13.1,13 12,13A2,2 0 0,0 10,15A2,2 0 0,0 12,17M18,8A2,2 0 0,1 20,10V20A2,2 0 0,1 18,22H6A2,2 0 0,1 4,20V10C4,8.89 4.9,8 6,8H7V6A5,5 0 0,1 12,1A5,5 0 0,1 17,6V8H18M12,3A3,3 0 0,0 9,6V8H15V6A3,3 0 0,0 12,3Z" />
                        </svg>
                    </span>`;
                badge.style.cssText = 'position:absolute;top:0;left:0;pointer-events:all;font-size:16px;line-height:1;cursor:help';
                
                wrapper.appendChild(badge);
                nameCell.appendChild(wrapper);
                
                console.log('FolderProtection: ✅ Badge overlaid on:', filename);
            } else {
                console.warn('FolderProtection: ⚠️ Name cell or icon span not found for:', filename);
            }
        },

        observeFileTable() {
            console.log('FolderProtection: Setting up event listeners');

            // Tentar hooks nativos do Nextcloud Files
            if (window.OCA && window.OCA.Files && window.OCA.Files.App) {
                // Hook no file list update (se existir)
                const originalReload = window.OCA.Files.App.fileList?.reload;
                if (originalReload) {
                    window.OCA.Files.App.fileList.reload = function() {
                        console.log('FolderProtection: FileList reload intercepted');
                        const result = originalReload.apply(this, arguments);
                        
                        // Aplicar badges após reload
                        setTimeout(() => {
                            FolderProtectionUI.addProtectionIndicators();
                        }, 500);
                        
                        return result;
                    };
                    console.log('FolderProtection: Hooked into fileList.reload');
                }
            }

            // Fallback: MutationObserver específico
            const appContent = document.querySelector('#app-content-vue');
            if (appContent) {
                let debounce;
                const observer = new MutationObserver(() => {
                    clearTimeout(debounce);
                    debounce = setTimeout(() => {
                        const hasRows = document.querySelectorAll('tbody.files-list__tbody tr[data-cy-files-list-row-name]').length;
                        if (hasRows > 0) {
                            console.log('FolderProtection: DOM changed, reapplying');
                            this.addProtectionIndicators();
                        }
                    }, 500);
                });

                observer.observe(appContent, {
                    childList: true,
                    subtree: true,
                    attributes: false
                });
                
                console.log('FolderProtection: MutationObserver active on #app-content-vue');
            }

            // Listener leve para navegação (só URL)
            let lastPath = this.getCurrentPath();
            const checkNavigation = () => {
                const currentPath = this.getCurrentPath();
                if (currentPath !== lastPath) {
                    console.log('FolderProtection: Path changed', lastPath, '→', currentPath);
                    lastPath = currentPath;
                    setTimeout(() => {
                        this.addProtectionIndicators();
                    }, 800);
                }
            };

            // Polling leve - só verificar path (não DOM)
            setInterval(checkNavigation, 2000); // A cada 2 segundos (menos agressivo)
        },

        async initialize() {
            if (this.initialized) {
                return;
            }

            console.log('FolderProtection: Initializing...');
            this.initialized = true;

            await this.loadProtectedFolders();
            
            this.addProtectionIndicators();
            this.observeFileTable();

            console.log('FolderProtection: ✅ Initialization complete!');
        }
    };

    const waitForFileList = () => {
        return new Promise((resolve) => {
            const check = () => {
                const tbody = document.querySelector('tbody.files-list__tbody');
                const hasRows = tbody && tbody.querySelectorAll('tr').length > 0;
                
                if (hasRows) {
                    console.log('FolderProtection: File list ready!');
                    resolve(true);
                    return true;
                }
                return false;
            };

            if (check()) return;

            console.log('FolderProtection: Waiting for file list...');

            const observer = new MutationObserver(() => {
                if (check()) {
                    observer.disconnect();
                }
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            setTimeout(() => {
                observer.disconnect();
                resolve(check());
            }, 15000);
        });
    };

    (async () => {
        console.log('FolderProtection: Script loaded');
        
        await waitForFileList();
        
        setTimeout(() => {
            FolderProtectionUI.initialize();
        }, 500);
    })();

(async () => {
        console.log('FolderProtection: Script loaded');
        
        await waitForFileList();
        
        setTimeout(() => {
            FolderProtectionUI.initialize();
        }, 500);
    })();

    // ✅ ADICIONAR ISTO AQUI (antes de fechar a IIFE)
    window.FolderProtectionUI = FolderProtectionUI;

})(window.OC, window.OCA);
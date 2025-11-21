(function(OC, OCA) {
    'use strict';

    const FolderProtectionUI = {
        protectedFolders: new Set(),
        initialized: false,
        currentPath: '/',

        async loadProtectedFolders() {
            try {
                const response = await fetch(OC.generateUrl('/apps/folder_protection/api/status'));
                const data = await response.json();
                
                if (data.success && data.protections) {
                    this.protectedFolders = new Set(Object.keys(data.protections));
                    console.log('FolderProtection: Loaded', this.protectedFolders.size, 'protected folders');
                    console.log('FolderProtection: Paths:', Array.from(this.protectedFolders));
                }
            } catch (error) {
                console.error('FolderProtection: Failed to load', error);
            }
        },

        getCurrentPath() {
            // Tentar obter path atual da URL
            const hash = window.location.hash;
            const match = hash.match(/dir=([^&]*)/);
            if (match) {
                return decodeURIComponent(match[1]);
            }
            return '/';
        },

        addProtectionIndicators() {
            const fileTable = document.querySelector('tbody.files-list__tbody');
            
            if (!fileTable) {
                console.warn('FolderProtection: File table not found');
                return;
            }

            // Atualizar path atual
            this.currentPath = this.getCurrentPath();
            console.log('FolderProtection: Current path:', this.currentPath);

            const fileRows = fileTable.querySelectorAll('tr.files-list__row[data-cy-files-list-row-name]');
            console.log('FolderProtection: Found', fileRows.length, 'items');
            
            let marked = 0;
            fileRows.forEach(row => {
                const filename = row.getAttribute('data-cy-files-list-row-name');
                
                if (!filename) return;

                // Construir path CORRETO baseado no diretório atual
                let fullPath = '/files';
                if (this.currentPath !== '/') {
                    fullPath += this.currentPath;
                }
                if (!fullPath.endsWith('/')) {
                    fullPath += '/';
                }
                fullPath += filename;

                console.log('FolderProtection: Checking:', fullPath);

                if (this.protectedFolders.has(fullPath)) {
                    this.markAsProtected(row, filename);
                    marked++;
                }
            });

            console.log('FolderProtection: Marked', marked, 'protected folders');
        },

        markAsProtected(row, filename) {
            // Verificar se já tem badge
            if (row.querySelector('.folder-protection-icon')) {
                return;
            }

            row.classList.add('folder-protected');

            const nameCell = row.querySelector('td.files-list__row-name');
            
            if (nameCell) {
                // Garantir célula é relative
                if (getComputedStyle(nameCell).position === 'static') {
                    nameCell.style.position = 'relative';
                }
                
                const wrapper = document.createElement('div');
                wrapper.className = 'folder-protection-wrapper';
                wrapper.style.cssText = 'position:absolute;bottom:2px;right:2px;top:auto;left:auto;width:20px;height:20px;pointer-events:none;z-index:9999';
                
                const badge = document.createElement('span');
                badge.className = 'folder-protection-icon';
                badge.title = 'This folder is protected and cannot be moved, copied or deleted';
                badge.textContent = '🔒';
                badge.style.cssText = 'position:absolute;bottom:0;right:0;top:auto;left:auto;pointer-events:all';
                
                wrapper.appendChild(badge);
                nameCell.appendChild(wrapper);
                
                console.log('FolderProtection: ✅ Badge added to:', filename);
            }
        },

        observeFileTable() {
            const tbody = document.querySelector('tbody.files-list__tbody');
            
            if (!tbody) {
                console.warn('FolderProtection: tbody not found');
                return;
            }

            console.log('FolderProtection: Observing for changes');

            let debounceTimer;
            const observer = new MutationObserver(() => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    console.log('FolderProtection: File list changed, updating...');
                    // Limpar badges antigos antes de re-aplicar
                    document.querySelectorAll('.folder-protection-icon, .folder-protection-wrapper').forEach(el => el.remove());
                    document.querySelectorAll('.folder-protected').forEach(row => row.classList.remove('folder-protected'));
                    this.addProtectionIndicators();
                }, 300);
            });

            observer.observe(tbody, {
                childList: true,
                subtree: false
            });

            // Observar mudanças de URL (navegação)
            window.addEventListener('hashchange', () => {
                console.log('FolderProtection: Navigation detected');
                setTimeout(() => {
                    this.addProtectionIndicators();
                }, 500);
            });
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

})(window.OC, window.OCA);
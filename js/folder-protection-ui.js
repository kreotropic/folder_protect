console.log('🔵 folder-protection-ui.js LOADED');

/**
 * Folder Protection UI Module for Nextcloud 31
 * Uses CSS pseudo-elements for badges (Vue-compatible)
 */

(function() {
    'use strict';

    const FolderProtectionUI = {
        config: {
            apiEndpoint: '/apps/folder_protection/api/status',
            protectedAttr: 'data-folder-protected',
            checkInterval: 100,
            maxCheckAttempts: 50
        },

        state: {
            protectedFolders: new Set(),
            initialized: false,
            observer: null
        },

        async init() {
            console.log('[FolderProtection] Initializing');
            
            await this.loadProtectedFolders();
            this.injectStyles();
            
            this.waitForFilesApp().then(() => {
                this.setupEventListeners();
                this.markProtectedFolders(); // Aplicação inicial
                this.state.initialized = true;
                console.log('[FolderProtection] ✅ Initialized');
            });
        },

        async loadProtectedFolders() {
            try {
                const response = await fetch(OC.generateUrl(this.config.apiEndpoint));
                const data = await response.json();
                
                if (data.success && data.protections) {
                    this.state.protectedFolders = new Set(Object.keys(data.protections));
                    console.log('[FolderProtection] Loaded', this.state.protectedFolders.size, 'folders');
                }
            } catch (error) {
                console.error('[FolderProtection] Load failed:', error);
            }
        },

        injectStyles() {
            if (document.getElementById('folder-protection-styles')) return;

            const styles = `
                /* CSS variables para fácil ajuste e possível theming */
                :root {
                    --protection-badge-color: rgba(0, 0, 0, 0.9);
                    --protection-badge-text-color: #fff;
                    --protection-badge-zindex: 1000;
                    --protection-badge-shadow: 0 1px 3px rgba(0,0,0,0.5);
                    --protection-badge-size: 14px;
                }

                /* Agrupar regras que usam o mesmo seletor de linha protegida */
                .files-list__row[${this.config.protectedAttr}="true"] {
                    /* alvo: o ícone e o nome recebem estilos via pseudo-elementos abaixo */
                }

                .files-list__row[${this.config.protectedAttr}="true"] .files-list__row-icon {
                    position: relative !important;
                }

                .files-list__row[${this.config.protectedAttr}="true"] .files-list__row-icon::after {
                    content: '🔒';
                    position: absolute;
                    bottom: 15px;
                    right: -9px;
                    font-size: var(--protection-badge-size);
                    line-height: 16px;
                    z-index: calc(var(--protection-badge-zindex) - 990); /* small local stacking */
                    filter: drop-shadow(var(--protection-badge-shadow));
                    pointer-events: none;
                }

                /* Badge de texto (visível ao hover) */
                .files-list__row[${this.config.protectedAttr}="true"] .files-list__row-name::after {
                    content: 'Protected folder';
                    position: absolute;
                    top: -20px;
                    left: 50%;
                    transform: translateX(-50%);
                    background: var(--protection-badge-color);
                    color: var(--protection-badge-text-color);
                    padding: 6px 10px;
                    border-radius: 4px;
                    font-size: 12px;
                    white-space: nowrap;
                    opacity: 0;
                    pointer-events: none;
                    transition: opacity 0.2s;
                    z-index: var(--protection-badge-zindex);
                }

                .files-list__row[${this.config.protectedAttr}="true"] .files-list__row-name:hover::after {
                    opacity: 1;
                }

                .files-list__row[${this.config.protectedAttr}="true"] .files-list__row-icon-overlay {
                    z-index: calc(var(--protection-badge-zindex) + 1);
                }

                @keyframes badgeAppear {
                    from { opacity: 0; transform: scale(0); }
                    to { opacity: 1; transform: scale(1); }
                }

                .files-list__row[${this.config.protectedAttr}="true"]:not([data-animated]) .files-list__row-icon::after {
                    animation: badgeAppear 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
                }
            `;

            const styleEl = document.createElement('style');
            styleEl.id = 'folder-protection-styles';
            styleEl.textContent = styles;
            document.head.appendChild(styleEl);
        },

        waitForFilesApp() {
            return new Promise((resolve) => {
                let attempts = 0;
                const check = setInterval(() => {
                    const app = document.querySelector('.files-list__tbody');
                    
                    if (app || ++attempts > this.config.maxCheckAttempts) {
                        clearInterval(check);
                        resolve();
                    }
                }, this.config.checkInterval);
            });
        },

        setupEventListeners() {
            console.log('[FolderProtection] Setting up observer');
            
            const container = document.querySelector('#app-content-vue');
            if (!container) return;

            let debounce;
            let rafId;
            let pendingProcess = false; // Flag para evitar múltiplos processamentos simultâneos
            
            // Debounce otimizado: agrupa mutações consecutivas
            const scheduleProcess = () => {
                if (pendingProcess) return; // Já há um processamento agendado
                
                pendingProcess = true;
                
                // Cancelar timers anteriores (mais eficiente)
                if (debounce) clearTimeout(debounce);
                if (rafId) cancelAnimationFrame(rafId);
                
                // Usar apenas RAF (mais eficiente que setTimeout + RAF)
                // RAF é sincronizado com o refresh do browser (~60fps)
                rafId = requestAnimationFrame(() => {
                    debounce = setTimeout(() => {
                        console.log('[FolderProtection] ⚡ Processing rows');
                        this.markProtectedFolders();
                        pendingProcess = false; // Permitir próximo processamento
                    }, 16); // 16ms ≈ 1 frame em 60fps
                });
            };
            
            this.state.observer = new MutationObserver(() => {
                const tbody = document.querySelector('tbody.files-list__tbody');
                const hasRows = tbody?.querySelectorAll('tr.files-list__row').length > 0;
                
                if (hasRows) {
                    scheduleProcess();
                }
            });

            this.state.observer.observe(container, {
                childList: true,
                subtree: false
            });
            
            console.log('[FolderProtection] ✅ Observer active');
        },


        processRow(row) {
            const filename = row.getAttribute('data-cy-files-list-row-name');
            if (!filename) return;

            // Limpar atributos antigos
            row.removeAttribute(this.config.protectedAttr);
            row.removeAttribute('data-animated');

            // Construir path completo
            const currentDir = this.getCurrentDirectory();
            const fullPath = this.buildFullPath(currentDir, filename);
            
            // Verificar se está protegido (isFolderProtected decide se aplica)
            const isProtected = this.isFolderProtected(fullPath);

            if (isProtected) {
                row.setAttribute(this.config.protectedAttr, 'true');
                row.setAttribute('data-animated', 'true');
                console.log('[FolderProtection] ✅ Protected:', filename, '|', fullPath);
            }
        },

        markProtectedFolders() {
            const rows = document.querySelectorAll('.files-list__row:not([data-protected-checked])');
            
            if (rows.length === 0) {
                console.log('[FolderProtection] No new rows to process');
                return;
            }
            
            console.log(`[FolderProtection] Processing ${rows.length} new rows`);
            
            // Processar apenas novas rows (com microtasks em lotes para não bloquear UI)
            let processed = 0;
            const batchSize = 50; // Processar 50 rows por lote
            
            const processBatch = () => {
                const end = Math.min(processed + batchSize, rows.length);
                
                for (let i = processed; i < end; i++) {
                    this.processRow(rows[i]);
                    rows[i].setAttribute('data-protected-checked', 'true'); // Marcar como processada
                }
                
                processed = end;
                
                // Se houver mais rows, agendar próximo lote
                if (processed < rows.length) {
                    setTimeout(processBatch, 0); // Yield ao browser
                }
            };
            
            processBatch();
        },

        getCurrentDirectory() {
            const hash = window.location.hash;
            const match = hash.match(/dir=([^&]*)/);
            return match ? decodeURIComponent(match[1]) : '/';
        },

        /**
         * Constrói o path completo com prefixo /files
         * 
         * Explicação:
         * - window.location.hash traz dir sem /files (ex: /Docs)
         * - Backend armazena com /files (ex: /files/Docs)
         * - Precisamos normalizar para fazer match com a BD
         */
        buildFullPath(currentDir, filename) {
            // Remove prefixo /files se estiver presente
            currentDir = currentDir.replace(/^\/files/, '');
            
            // Constrói o caminho
            let fullPath = currentDir === '/' ? `/${filename}` : `${currentDir}/${filename}`;
            
            // Adiciona /files e normaliza slashes múltiplos
            fullPath = `/files${fullPath}`.replace(/\/+/g, '/');
            
            return fullPath;
        },

        isFolderProtected(fullPath) {
            if (this.state.protectedFolders.has(fullPath)) return true;

            const variations = [
                fullPath.replace(/^\/files/, ''),
                fullPath.replace(/\/$/, ''),
                `${fullPath}/`
            ];

            return variations.some(p => this.state.protectedFolders.has(p));
        },

        async refresh() {
            await this.loadProtectedFolders();
            this.markProtectedFolders();
        },

        destroy() {
            if (this.state.observer) this.state.observer.disconnect();
            document.getElementById('folder-protection-styles')?.remove();
            
            // Limpar atributos de tracking
            document.querySelectorAll('[data-protected-checked]').forEach(el => {
                el.removeAttribute('data-protected-checked');
            });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => FolderProtectionUI.init());
    } else {
        setTimeout(() => FolderProtectionUI.init(), 100);
    }

    window.FolderProtectionUI = FolderProtectionUI;

})();
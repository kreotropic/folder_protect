// Nota: logs do módulo são condicionados por `config.debug` (padrão: false).

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
            // Nova opção: usar uma classe CSS para marcar linhas protegidas.
            // Preferível por performance/selectors; mantemos `protectedAttr` apenas por compatibilidade.
            protectedClass: 'fp-protected',
            // Classe usada para marcar rows já processadas (evita re-processamento)
            processedClass: 'fp-processed',
            checkInterval: 100,
            maxCheckAttempts: 50,
            debug: false // definir para true para ativar logs
        },

        state: {
            protectedFolders: new Set(),
            initialized: false,
            observer: null
        },

        // Helpers de logging: usam `config.debug` para evitar spam no console
        log(...args) {
            if (this.config.debug) console.log(...args);
        },

        error(...args) {
            if (this.config.debug) console.error(...args);
        },

        async init() {
            this.log('[FolderProtection] Initializing');
            
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
                    // Pré-processar versões normalizadas para lookups rápidos
                    this.preprocessProtectedFolders();
                    this.log('[FolderProtection] Loaded', this.state.protectedFolders.size, 'folders');
                }
            } catch (error) {
                this.error('[FolderProtection] Load failed:', error);
            }
        },

        // Normaliza caminhos recebidos/consultados para uma forma canónica
        normalizePath(p) {
            if (!p) return p;
            // colapsa múltiplas barras
            p = p.replace(/\/+/g, '/');
            // garante leading slash
            if (!p.startsWith('/')) p = '/' + p;
            // remove trailing slash (exceto root)
            if (p.length > 1 && p.endsWith('/')) p = p.slice(0, -1);
            return p;
        },

        // Constrói um Set com paths normalizados para lookups rápidos
        preprocessProtectedFolders() {
            const normalized = new Set();
            for (const p of this.state.protectedFolders) {
                if (!p) continue;
                const np = this.normalizePath(p);
                normalized.add(np);
            }
            this.state.normalizedProtected = normalized;
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

                /* Agrupar regras que usam o mesmo seletor de linha protegida (classe) */
                .files-list__row.${this.config.protectedClass} {
                    /* alvo: o ícone e o nome recebem estilos via pseudo-elementos abaixo */
                }

                .files-list__row.${this.config.protectedClass} .files-list__row-icon {
                    position: relative !important;
                }

                .files-list__row.${this.config.protectedClass} .files-list__row-icon::after {
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
                .files-list__row.${this.config.protectedClass} .files-list__row-name::after {
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

                .files-list__row.${this.config.protectedClass} .files-list__row-name:hover::after {
                    opacity: 1;
                }

                .files-list__row.${this.config.protectedClass} .files-list__row-icon-overlay {
                    z-index: calc(var(--protection-badge-zindex) + 1);
                }

                @keyframes badgeAppear {
                    from { opacity: 0; transform: scale(0); }
                    to { opacity: 1; transform: scale(1); }
                }

                .files-list__row.${this.config.protectedClass}:not([data-animated]) .files-list__row-icon::after {
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
            this.log('[FolderProtection] Setting up observer');
            
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
                        this.log('[FolderProtection] ⚡ Processing rows');
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
            
            this.log('[FolderProtection] ✅ Observer active');
        },


        processRow(row) {
            const filename = row.getAttribute('data-cy-files-list-row-name');
            if (!filename) return;

            // Limpar marcações antigas
            row.classList.remove(this.config.protectedClass);
            row.removeAttribute('data-animated');

            // Construir path completo
            const currentDir = this.getCurrentDirectory();
            const fullPath = this.buildFullPath(currentDir, filename);
            
            // Verificar se está protegido (isFolderProtected decide se aplica)
            const isProtected = this.isFolderProtected(fullPath);

            if (isProtected) {
                row.classList.add(this.config.protectedClass);
                row.setAttribute('data-animated', 'true');
                this.log('[FolderProtection] ✅ Protected:', filename, '|', fullPath);
            }
        },

        markProtectedFolders() {
            const rows = document.querySelectorAll(`.files-list__row:not(.${this.config.processedClass})`);
            
            if (rows.length === 0) {
                this.log('[FolderProtection] No new rows to process');
                return;
            }

            this.log(`[FolderProtection] Processing ${rows.length} new rows`);
            
            // Processar apenas novas rows (com microtasks em lotes para não bloquear UI)
            let processed = 0;
            // Calcular batch size dinamicamente: processa tudo em listas pequenas,
            // usa uma fracção (≈10%) em listas maiores, com limites para estabilidade.
            const batchSize = rows.length <= 100 ? rows.length : Math.max(20, Math.min(200, Math.floor(rows.length / 10)));
            this.log(`[FolderProtection] batchSize selected: ${batchSize}`);
            
            const processBatch = () => {
                const end = Math.min(processed + batchSize, rows.length);
                
                for (let i = processed; i < end; i++) {
                    this.processRow(rows[i]);
                    rows[i].classList.add(this.config.processedClass); // Marcar como processada
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
            // Usa o Set pré-processado para checks rápidos. Também valida pais.
            if (!fullPath) return false;

            const np = this.normalizePath(fullPath);
            // Verifica exato
            if (this.state.normalizedProtected?.has(np)) return true;

            // Verifica pais: sobe na hierarquia até root
            let curr = np;
            while (curr && curr !== '/') {
                // remove último segmento
                const parent = curr.replace(/\/[^\/]*$/, '') || '/';
                if (this.state.normalizedProtected?.has(parent)) return true;
                if (parent === curr) break;
                curr = parent;
            }

            return false;
        },

        async refresh() {
            await this.loadProtectedFolders();
            this.markProtectedFolders();
        },

        destroy() {
            if (this.state.observer) this.state.observer.disconnect();
            document.getElementById('folder-protection-styles')?.remove();
            
            // Limpar atributos de tracking
            document.querySelectorAll(`.${this.config.processedClass}`).forEach(el => {
                el.classList.remove(this.config.processedClass);
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
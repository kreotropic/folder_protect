/**
 * Folder Protection UI Module for Nextcloud 31
 * Handles visual badges for protected folders in the Files app
 */

(function() {
    'use strict';

    const FolderProtectionUI = {
        // Configuration
        config: {
            apiEndpoint: '/apps/folder_protection/api/status',
            badgeClass: 'folder-protection-badge',
            protectedAttr: 'data-folder-protected',
            checkInterval: 100,
            maxCheckAttempts: 50
        },

        // State
        state: {
            protectedFolders: new Set(),
            currentDir: '',
            initialized: false,
            observer: null
        },

        /**
         * Initialize the module
         */
        async init() {
            console.log('[FolderProtection] Initializing UI module');
            
            // Load protected folders from API
            await this.loadProtectedFolders();
            
            // Setup CSS (badges rendered via CSS, not DOM manipulation)
            this.injectStyles();
            
            // Wait for Files app to be ready
            this.waitForFilesApp().then(() => {
                this.setupEventListeners();
                this.setupMutationObserver();
                this.markProtectedFolders();
                this.state.initialized = true;
                console.log('[FolderProtection] UI module initialized');
            });
        },

        /**
         * Load list of protected folders from API
         */
        async loadProtectedFolders() {
            try {
                const response = await fetch(OC.generateUrl(this.config.apiEndpoint));
                const data = await response.json();
                
                if (data.success && Array.isArray(data.protected_folders)) {
                    this.state.protectedFolders = new Set(data.protected_folders);
                    console.log('[FolderProtection] Loaded protected folders:', this.state.protectedFolders.size);
                }
            } catch (error) {
                console.error('[FolderProtection] Failed to load protected folders:', error);
            }
        },

        /**
         * Inject CSS styles for badges
         * Using CSS pseudo-elements avoids DOM manipulation
         */
        injectStyles() {
            if (document.getElementById('folder-protection-styles')) return;

            const styles = `
                /* Badge container positioning */
                .files-list__row[${this.config.protectedAttr}="true"] .files-list__row-icon {
                    position: relative !important;
                }

                /* Badge using pseudo-element - survives Vue updates */
                .files-list__row[${this.config.protectedAttr}="true"] .files-list__row-icon::after {
                    content: '';
                    position: absolute;
                    bottom: -2px;
                    right: -2px;
                    width: 16px;
                    height: 16px;
                    background: #e91e63;
                    border-radius: 50%;
                    z-index: 10;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    pointer-events: none;
                    
                    /* Lock icon using CSS */
                    background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white"><path d="M12 2C9.243 2 7 4.243 7 7v3H6c-1.103 0-2 .897-2 2v8c0 1.103.897 2 2 2h12c1.103 0 2-.897 2-2v-8c0-1.103-.897-2-2-2h-1V7c0-2.757-2.243-5-5-5zM9 7c0-1.654 1.346-3 3-3s3 1.346 3 3v3H9V7zm9 13H6v-8h12v8z"/></svg>');
                    background-size: 12px 12px;
                    background-repeat: no-repeat;
                    background-position: center;
                }

                /* Tooltip on hover */
                .files-list__row[${this.config.protectedAttr}="true"] .files-list__row-icon::before {
                    content: 'Protected folder';
                    position: absolute;
                    bottom: -30px;
                    right: -10px;
                    background: rgba(0, 0, 0, 0.8);
                    color: white;
                    padding: 4px 8px;
                    border-radius: 3px;
                    font-size: 11px;
                    white-space: nowrap;
                    opacity: 0;
                    pointer-events: none;
                    transition: opacity 0.2s;
                    z-index: 100;
                }

                .files-list__row[${this.config.protectedAttr}="true"] .files-list__row-icon:hover::before {
                    opacity: 1;
                }

                /* Ensure badge doesn't interfere with other icons */
                .files-list__row[${this.config.protectedAttr}="true"] .files-list__row-icon-overlay {
                    position: relative;
                    z-index: 11;
                }

                /* Animation for new badges */
                @keyframes badgePulse {
                    0% { transform: scale(0); opacity: 0; }
                    50% { transform: scale(1.1); }
                    100% { transform: scale(1); opacity: 1; }
                }

                .files-list__row[${this.config.protectedAttr}="true"]:not([data-badge-animated]) .files-list__row-icon::after {
                    animation: badgePulse 0.3s ease-out;
                }
            `;

            const styleElement = document.createElement('style');
            styleElement.id = 'folder-protection-styles';
            styleElement.textContent = styles;
            document.head.appendChild(styleElement);
        },

        /**
         * Wait for Files app to be ready
         */
        waitForFilesApp() {
            return new Promise((resolve) => {
                let attempts = 0;
                const checkInterval = setInterval(() => {
                    attempts++;
                    
                    // Check if Files app is loaded
                    const filesApp = document.querySelector('#app-content-vue') || 
                                   document.querySelector('.files-list__tbody');
                    
                    if (filesApp || attempts > this.config.maxCheckAttempts) {
                        clearInterval(checkInterval);
                        resolve();
                    }
                }, this.config.checkInterval);
            });
        },

        /**
         * Setup event listeners for navigation and file list updates
         */
        setupEventListeners() {
            // Listen for URL hash changes (navigation)
            window.addEventListener('hashchange', () => {
                console.log('[FolderProtection] Navigation detected');
                this.handleNavigation();
            });

            // Listen for popstate (browser back/forward)
            window.addEventListener('popstate', () => {
                console.log('[FolderProtection] Popstate detected');
                this.handleNavigation();
            });

            // Try to hook into Nextcloud's file list events if available
            if (window.OCA && OCA.Files && OCA.Files.App) {
                // Listen for fileList updates if available
                document.addEventListener('fileListUpdated', () => {
                    this.handleFileListUpdate();
                });

                // Also try legacy events
                document.addEventListener('changeDirectory', () => {
                    this.handleNavigation();
                });
            }

            // Listen for Vue router navigation if accessible
            this.setupVueNavigationHook();
        },

        /**
         * Setup Vue navigation hook if possible
         */
        setupVueNavigationHook() {
            // Try to access Vue Router
            const checkVueRouter = setInterval(() => {
                try {
                    // Check for Vue instance
                    const vueApp = document.querySelector('#content-vue')?.__vue_app__ ||
                                  document.querySelector('#app')?.__vue_app__ ||
                                  window.OCA?.Files?.App?.vm;

                    if (vueApp) {
                        clearInterval(checkVueRouter);
                        
                        // Try to add navigation guard
                        if (vueApp.config?.globalProperties?.$router) {
                            const router = vueApp.config.globalProperties.$router;
                            router.afterEach(() => {
                                console.log('[FolderProtection] Vue navigation detected');
                                setTimeout(() => this.markProtectedFolders(), 100);
                            });
                        }
                    }
                } catch (e) {
                    // Vue not ready yet
                }
            }, 500);

            // Stop trying after 10 seconds
            setTimeout(() => clearInterval(checkVueRouter), 10000);
        },

        /**
         * Setup MutationObserver to watch for new file rows
         */
        setupMutationObserver() {
            // Disconnect existing observer
            if (this.state.observer) {
                this.state.observer.disconnect();
            }

            const targetNode = document.querySelector('.files-list__tbody') || 
                              document.querySelector('#app-content-vue');

            if (!targetNode) return;

            // Debounce function to avoid excessive processing
            let debounceTimer;
            const debouncedMark = () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    this.markProtectedFolders();
                }, 50);
            };

            // Create observer
            this.state.observer = new MutationObserver((mutations) => {
                // Check if relevant changes occurred
                const hasRelevantChanges = mutations.some(mutation => {
                    // Check for added nodes
                    if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                        return Array.from(mutation.addedNodes).some(node => 
                            node.nodeType === 1 && (
                                node.classList?.contains('files-list__row') ||
                                node.querySelector?.('.files-list__row')
                            )
                        );
                    }
                    // Check for attribute changes on rows
                    if (mutation.type === 'attributes' && 
                        mutation.target.classList?.contains('files-list__row')) {
                        return mutation.attributeName === 'data-cy-files-list-row-name';
                    }
                    return false;
                });

                if (hasRelevantChanges) {
                    debouncedMark();
                }
            });

            // Start observing
            this.state.observer.observe(targetNode, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['data-cy-files-list-row-name']
            });
        },

        /**
         * Handle navigation events
         */
        handleNavigation() {
            const newDir = this.getCurrentDirectory();
            if (newDir !== this.state.currentDir) {
                this.state.currentDir = newDir;
                // Small delay to let Vue render
                setTimeout(() => {
                    this.markProtectedFolders();
                }, 200);
            }
        },

        /**
         * Handle file list updates
         */
        handleFileListUpdate() {
            console.log('[FolderProtection] File list updated');
            this.markProtectedFolders();
        },

        /**
         * Get current directory from URL
         */
        getCurrentDirectory() {
            const hash = window.location.hash;
            const match = hash.match(/dir=([^&]*)/);
            return match ? decodeURIComponent(match[1]) : '/';
        },

        /**
         * Mark protected folders with data attribute
         * This is the core function that applies protection status
         */
        markProtectedFolders() {
            const rows = document.querySelectorAll('.files-list__row');
            let marked = 0;

            rows.forEach(row => {
                const filename = row.getAttribute('data-cy-files-list-row-name');
                if (!filename) return;

                // Check if it's a folder (has folder icon or specific class)
                const isFolder = row.querySelector('.folder-icon') !== null ||
                               row.querySelector('[class*="folder"]') !== null ||
                               row.classList.contains('files-list__row--type-folder');

                if (!isFolder) {
                    // Remove protection attribute from non-folders
                    row.removeAttribute(this.config.protectedAttr);
                    return;
                }

                // Build the full path
                const currentDir = this.getCurrentDirectory();
                const fullPath = this.buildFullPath(currentDir, filename);

                // Check if folder is protected
                const isProtected = this.isFolderProtected(fullPath);

                if (isProtected) {
                    if (row.getAttribute(this.config.protectedAttr) !== 'true') {
                        row.setAttribute(this.config.protectedAttr, 'true');
                        marked++;
                        console.log('[FolderProtection] Marked folder as protected:', filename);
                    }
                } else {
                    row.removeAttribute(this.config.protectedAttr);
                }

                // Mark as animated to prevent re-animation
                if (isProtected && !row.hasAttribute('data-badge-animated')) {
                    row.setAttribute('data-badge-animated', 'true');
                }
            });

            if (marked > 0) {
                console.log(`[FolderProtection] Marked ${marked} folders as protected`);
            }
        },

        /**
         * Build full path for a folder
         */
        buildFullPath(currentDir, filename) {
            // Remove leading /files if present in current dir
            currentDir = currentDir.replace(/^\/files/, '');
            
            // Build path
            let fullPath = currentDir === '/' ? `/${filename}` : `${currentDir}/${filename}`;
            
            // Add /files prefix for comparison with API data
            fullPath = `/files${fullPath}`.replace(/\/+/g, '/');
            
            return fullPath;
        },

        /**
         * Check if a folder is protected
         */
        isFolderProtected(fullPath) {
            // Direct match
            if (this.state.protectedFolders.has(fullPath)) {
                return true;
            }

            // Check without /files prefix
            const pathWithoutFiles = fullPath.replace(/^\/files/, '');
            if (this.state.protectedFolders.has(pathWithoutFiles)) {
                return true;
            }

            // Check with different variations
            const variations = [
                fullPath,
                pathWithoutFiles,
                `/files${pathWithoutFiles}`,
                fullPath.replace(/\/$/, ''), // Remove trailing slash
                `${fullPath}/` // Add trailing slash
            ];

            return variations.some(path => this.state.protectedFolders.has(path));
        },

        /**
         * Refresh protection status (can be called manually)
         */
        async refresh() {
            console.log('[FolderProtection] Refreshing protection status');
            await this.loadProtectedFolders();
            this.markProtectedFolders();
        },

        /**
         * Cleanup function
         */
        destroy() {
            if (this.state.observer) {
                this.state.observer.disconnect();
            }
            window.removeEventListener('hashchange', this.handleNavigation);
            window.removeEventListener('popstate', this.handleNavigation);
            const styles = document.getElementById('folder-protection-styles');
            if (styles) {
                styles.remove();
            }
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => FolderProtectionUI.init());
    } else {
        // DOM already loaded
        setTimeout(() => FolderProtectionUI.init(), 100);
    }

    // Expose to global scope for debugging
    window.FolderProtectionUI = FolderProtectionUI;

})();
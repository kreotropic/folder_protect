// (function(OC, OCA) {
//     'use strict';

//     const FolderProtectionUI = {
//         protectedFolders: new Set(),
//         initialized: false,

//         async loadProtectedFolders() {
//             try {
//                 const response = await fetch(OC.generateUrl('/apps/folder_protection/api/status'));
//                 const data = await response.json();
                
//                 if (data.success && data.protections) {
//                     this.protectedFolders = new Set(Object.keys(data.protections));
//                     console.log('FolderProtection: Loaded', this.protectedFolders.size, 'protected folders');
//                 }
//             } catch (error) {
//                 console.error('FolderProtection: Failed to load', error);
//             }
//         },

//         getCurrentPath() {
//             const hash = window.location.hash;
//             const match = hash.match(/dir=([^&]*)/);
//             if (match) {
//                 return decodeURIComponent(match[1]);
//             }
//             return '/';
//         },

//         isProtectedPath(filename) {
//             const currentPath = this.getCurrentPath();
            
//             let fullPath = '/files';
//             if (currentPath !== '/') {
//                 fullPath += currentPath;
//             }
//             if (!fullPath.endsWith('/')) {
//                 fullPath += '/';
//             }
//             fullPath += filename;

//             return this.protectedFolders.has(fullPath);
//         },

//         applyToAllRows() {
//             // Limpar badges antigos primeiro
//             document.querySelectorAll('.folder-protection-badge').forEach(b => b.remove());
            
//             const rows = document.querySelectorAll('tbody.files-list__tbody tr.files-list__row[data-cy-files-list-row-name]');
            
//             let count = 0;
//             rows.forEach(row => {
//                 const filename = row.getAttribute('data-cy-files-list-row-name');
//                 if (!filename) return;

//                 const folderIcon = row.querySelector('span.files-list__row-icon .folder-icon');
//                 if (!folderIcon) return;

//                 if (this.isProtectedPath(filename)) {
//                     this.addBadgeToRow(row);
//                     count++;
//                 }
//             });
            
//             if (count > 0) {
//                 console.log('FolderProtection: Applied', count, 'badges');
//             }
//         },

//         addBadgeToRow(row) {
//             // Não adicionar se já tem
//             if (row.querySelector('.folder-protection-badge')) {
//                 return;
//             }

//             // Adicionar DEPOIS do span do ícone (não dentro!)
//             const iconSpan = row.querySelector('span.files-list__row-icon');
//             if (!iconSpan) return;

//             // Criar badge pequeno e posicionado
//             const badge = document.createElement('span');
//             badge.className = 'folder-protection-badge';
//             badge.title = 'This folder is protected and cannot be moved, copied or deleted';
//             badge.style.cssText = `
//                 position: absolute;
//                 bottom: -2px;
//                 right: -2px;
//                 width: 16px;
//                 height: 16px;
//                 display: inline-flex;
//                 align-items: center;
//                 justify-content: center;
//                 pointer-events: all;
//                 cursor: help;
//                 z-index: 100;
//             `;
//             badge.innerHTML = `
//                 <svg fill="#f39c12" width="16" height="16" viewBox="0 0 24 24" style="filter:drop-shadow(0 1px 2px rgba(0,0,0,0.6))">
//                     <path d="M12,17A2,2 0 0,0 14,15C14,13.89 13.1,13 12,13A2,2 0 0,0 10,15A2,2 0 0,0 12,17M18,8A2,2 0 0,1 20,10V20A2,2 0 0,1 18,22H6A2,2 0 0,1 4,20V10C4,8.89 4.9,8 6,8H7V6A5,5 0 0,1 12,1A5,5 0 0,1 17,6V8H18M12,3A3,3 0 0,0 9,6V8H15V6A3,3 0 0,0 12,3Z" />
//                 </svg>
//             `;

//             // Garantir iconSpan é relative para o badge se posicionar
//             const computedStyle = window.getComputedStyle(iconSpan);
//             if (computedStyle.position === 'static') {
//                 iconSpan.style.position = 'relative';
//             }
//             iconSpan.style.display = 'inline-block';

//             // Adicionar como filho do iconSpan
//             iconSpan.appendChild(badge);
//         },

//         setupObserver() {
//             const tbody = document.querySelector('tbody.files-list__tbody');
//             if (!tbody) return;

//             // Observer para novas rows
//             const observer = new MutationObserver((mutations) => {
//                 mutations.forEach(mutation => {
//                     mutation.addedNodes.forEach(node => {
//                         if (node.nodeType === 1 && node.tagName === 'TR') {
//                             const filename = node.getAttribute('data-cy-files-list-row-name');
//                             if (filename) {
//                                 const folderIcon = node.querySelector('.folder-icon');
//                                 if (folderIcon && this.isProtectedPath(filename)) {
//                                     this.addBadgeToRow(node);
//                                 }
//                             }
//                         }
//                     });
//                 });
//             });

//             observer.observe(tbody, {
//                 childList: true,
//                 subtree: false
//             });

//             console.log('FolderProtection: Observer active');
//         },

//         setupNavigationListener() {
//             let lastPath = this.getCurrentPath();
//             let lastHash = window.location.hash;
            
//             setInterval(() => {
//                 const currentPath = this.getCurrentPath();
//                 const currentHash = window.location.hash;
                
//                 // Se mudou path OU hash completo
//                 if (currentPath !== lastPath || currentHash !== lastHash) {
//                     console.log('FolderProtection: Navigation detected');
//                     lastPath = currentPath;
//                     lastHash = currentHash;
                    
//                     // Aguardar rows carregarem
//                     setTimeout(() => {
//                         this.applyToAllRows();
//                     }, 600);
//                 }
//             }, 1000);
//         },

//         async initialize() {
//             if (this.initialized) return;

//             console.log('FolderProtection: Initializing...');
//             this.initialized = true;

//             await this.loadProtectedFolders();
            
//             // Aplicar inicial
//             this.applyToAllRows();
            
//             // Observer para novas rows
//             this.setupObserver();
            
//             // Listener para navegação
//             this.setupNavigationListener();

//             console.log('FolderProtection: ✅ Complete!');
//         }
//     };

//     const waitForFileList = () => {
//         return new Promise((resolve) => {
//             const check = () => {
//                 const tbody = document.querySelector('tbody.files-list__tbody');
//                 const hasRows = tbody && tbody.querySelectorAll('tr').length > 0;
//                 if (hasRows) {
//                     resolve(true);
//                     return true;
//                 }
//                 return false;
//             };

//             if (check()) return;

//             const observer = new MutationObserver(() => {
//                 if (check()) observer.disconnect();
//             });

//             observer.observe(document.body, { childList: true, subtree: true });
//             setTimeout(() => { observer.disconnect(); resolve(check()); }, 15000);
//         });
//     };

//     (async () => {
//         await waitForFileList();
//         setTimeout(() => FolderProtectionUI.initialize(), 300);
//     })();

//     window.FolderProtectionUI = FolderProtectionUI;

// })(window.OC, window.OCA);
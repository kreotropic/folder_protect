<template>
    <div class="folder-protection-admin">
        <!-- Botão para abrir o modal de adição -->
        <div class="add-protection-section">
            <button @click="openAddModal" class="button primary">
                <span class="icon icon-add"></span>
                {{ t('folder_protection', 'Add Protection') }}
            </button>
        </div>

        <!-- Indicador de carregamento -->
        <div v-if="loading" class="loading-container">
            <span class="icon-loading"></span>
            {{ t('folder_protection', 'Loading protected folders...') }}
        </div>

        <!-- Lista de pastas protegidas -->
        <div v-else-if="folders.length > 0" class="protected-folders-list">
            <h3>{{ t('folder_protection', 'Protected Folders') }}</h3>
            <div v-for="folder in folders" :key="folder.id" class="folder-item">
                <div class="folder-icon">📁</div>
                <div class="folder-details">
                    <div class="folder-path">
                        <template v-if="folder.mountPoint">
                            <strong class="folder-mount">{{ folder.mountPoint }}</strong>
                            <div class="folder-internal">{{ folder.path }}</div>
                        </template>
                        <template v-else>
                            {{ folder.path }}
                        </template>
                    </div>
                    <div class="folder-meta">
                        <span v-if="folder.created_by">
                            {{ t('folder_protection', 'Created by') }}: {{ folder.created_by }}
                        </span>
                        <span v-if="folder.created_at">
                            | {{ formatDate(folder.created_at) }}
                        </span>
                    </div>
                    <div v-if="folder.reason" class="folder-reason">
                        {{ folder.reason }}
                    </div>
                </div>
                <div class="folder-actions">
                    <button @click="removeProtection(folder)"
                            class="button icon-delete"
                            :title="t('folder_protection', 'Remove protection')">
                    </button>
                </div>
            </div>
        </div>

        <!-- Estado vazio -->
        <div v-else class="empty-content">
            <div class="icon-folder"></div>
            <h3>{{ t('folder_protection', 'No protected folders') }}</h3>
            <p>{{ t('folder_protection', 'Add folders to protect them from being moved, copied, or deleted.') }}</p>
        </div>

        <!-- Modal de adicionar proteção -->
        <div v-if="showAddModal" class="modal-overlay" @click.self="closeModal">
            <div class="modal-content" @click.stop>
                <h3>{{ t('folder_protection', 'Add Folder Protection') }}</h3>

                <!-- Tabs (só visível se groupfolders disponível) -->
                <div v-if="groupFoldersAvailable" class="tabs">
                    <button
                        class="tab-btn"
                        :class="{ active: activeTab === 'groupfolders' }"
                        @click="activeTab = 'groupfolders'">
                        {{ t('folder_protection', 'Group Folders') }}
                    </button>
                    <button
                        class="tab-btn"
                        :class="{ active: activeTab === 'custom' }"
                        @click="activeTab = 'custom'">
                        {{ t('folder_protection', 'Custom Path') }}
                    </button>
                </div>

                <!-- Tab: Group Folders -->
                <div v-if="activeTab === 'groupfolders' && groupFoldersAvailable">
                    <div v-if="loadingGroupFolders" class="loading-container">
                        <span class="icon-loading"></span>
                        {{ t('folder_protection', 'Loading group folders...') }}
                    </div>
                    <div v-else-if="groupFolders.length === 0" class="empty-content" style="padding: 30px 0">
                        <p>{{ t('folder_protection', 'No group folders found.') }}</p>
                    </div>
                    <div v-else class="group-folders-list">
                        <div v-for="gf in groupFolders" :key="gf.id" class="gf-item">
                            <div class="gf-info">
                                <div class="gf-name">📂 {{ gf.mountPoint }}</div>
                                <div class="gf-path">{{ gf.path }}</div>
                                <div v-if="gf.protected && gf.reason" class="gf-reason">{{ gf.reason }}</div>
                            </div>
                            <div class="gf-actions">
                                <span v-if="gf.protected && !gf.partialProtection" class="badge-protected">
                                    🔒 {{ t('folder_protection', 'Protected') }}
                                </span>
                                <span v-if="gf.partialProtection" class="badge-partial"
                                      :title="t('folder_protection', 'Protected via custom path ({path}). This only blocks folder name creation but does not fully protect the group folder from deletion or move. Remove and re-add via Group Folders tab.', { path: gf.protectionPath || '/files/' + gf.mountPoint })">
                                    ⚠️ {{ t('folder_protection', 'Partial') }}
                                </span>
                                <button
                                    v-if="gf.protected"
                                    class="button"
                                    @click="unprotectGroupFolder(gf)">
                                    {{ t('folder_protection', 'Remove') }}
                                </button>
                                <button
                                    v-else
                                    class="button primary"
                                    @click="openReasonDialog(gf)">
                                    {{ t('folder_protection', 'Protect') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Dialog de motivo (inline) -->
                    <div v-if="showReasonDialog" class="reason-dialog-overlay" @click.self="cancelReasonDialog">
                        <div class="reason-dialog">
                            <h4>{{ t('folder_protection', 'Protect "{name}"?', { name: pendingGroupFolder && pendingGroupFolder.mountPoint }) }}</h4>
                            <div class="form-group">
                                <label>{{ t('folder_protection', 'Reason (optional)') }}</label>
                                <textarea
                                    v-model="pendingReason"
                                    :placeholder="t('folder_protection', 'Why is this folder protected?')"
                                    rows="3"
                                    autofocus
                                ></textarea>
                            </div>
                            <div class="form-actions">
                                <button class="button" @click="cancelReasonDialog">
                                    {{ t('folder_protection', 'Cancel') }}
                                </button>
                                <button class="button primary" :disabled="submitting" @click="confirmProtectGroupFolder">
                                    {{ submitting ? t('folder_protection', 'Adding...') : t('folder_protection', 'Confirm') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Custom Path -->
                <div v-if="activeTab === 'custom' || !groupFoldersAvailable">
                    <form @submit.prevent="addProtection">
                        <div class="form-group">
                            <label for="folder-path">
                                {{ t('folder_protection', 'Folder Path') }}
                            </label>
                            <div class="path-input-wrapper">
                                <span class="path-prefix">/files/</span>
                                <input
                                    id="folder-path"
                                    v-model="newFolder.folderName"
                                    type="text"
                                    :placeholder="t('folder_protection', 'folder_name')"
                                    required
                                />
                            </div>
                            <p class="form-hint">
                                {{ t('folder_protection', 'Full path will be: /files/{name}', { name: newFolder.folderName || 'folder_name' }) }}
                            </p>
                            <p v-if="matchingGroupFolder" class="form-warning">
                                ⚠️ {{ t('folder_protection', '"{name}" is a Group Folder. Use the Group Folders tab for full protection — this custom path only blocks name creation but does not protect the group folder from deletion or move.', { name: newFolder.folderName }) }}
                            </p>
                        </div>
                        <div class="form-group">
                            <label for="folder-reason">
                                {{ t('folder_protection', 'Reason (optional)') }}
                            </label>
                            <textarea
                                id="folder-reason"
                                v-model="newFolder.reason"
                                :placeholder="t('folder_protection', 'Why is this folder protected?')"
                                rows="3"
                            ></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="button" @click="closeModal" class="button">
                                {{ t('folder_protection', 'Cancel') }}
                            </button>
                            <button type="submit" class="button primary" :disabled="submitting || !!matchingGroupFolder">
                                {{ submitting ? t('folder_protection', 'Adding...') : t('folder_protection', 'Add Protection') }}
                            </button>
                        </div>
                    </form>
                    <div v-if="error" class="error-message">{{ error }}</div>
                </div>

                <!-- Botão fechar quando em tab groupfolders -->
                <div v-if="activeTab === 'groupfolders' && groupFoldersAvailable && !showReasonDialog" class="form-actions" style="margin-top: 16px;">
                    <button class="button" @click="closeModal">
                        {{ t('folder_protection', 'Close') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
    name: 'AdminApp',

    data() {
        return {
            folders: [],
            loading: true,
            showAddModal: false,
            submitting: false,
            error: null,
            newFolder: { folderName: '', reason: '' },

            // Group folders
            groupFolders: [],
            groupFoldersAvailable: false,
            loadingGroupFolders: false,
            activeTab: 'groupfolders',

            // Reason dialog
            pendingGroupFolder: null,
            pendingReason: '',
            showReasonDialog: false,
        }
    },

    mounted() {
        this.loadFolders()
    },

    computed: {
        matchingGroupFolder() {
            if (!this.groupFoldersAvailable || !this.newFolder.folderName) return null
            const name = this.newFolder.folderName.trim().replace(/^\/+/, '')
            return this.groupFolders.find(gf => gf.mountPoint === name) || null
        },
    },

    methods: {
        async loadFolders() {
            this.loading = true
            try {
                const response = await axios.get(generateUrl('/apps/folder_protection/api/list'))
                if (response.data.success) {
                    this.folders = response.data.folders
                }
            } catch (error) {
                console.error('Error loading folders:', error)
                showError(this.t('folder_protection', 'Failed to load protected folders'))
            } finally {
                this.loading = false
            }
        },

        async openAddModal() {
            this.showAddModal = true
            this.error = null
            this.newFolder = { folderName: '', reason: '' }
            this.activeTab = 'groupfolders'  // Resetar para o tab por defeito ao reabrir
            await this.loadGroupFolders()
        },

        closeModal() {
            this.showAddModal = false
            this.showReasonDialog = false
            this.pendingGroupFolder = null
            this.pendingReason = ''
            this.error = null
        },

        async loadGroupFolders() {
            this.loadingGroupFolders = true
            try {
                const response = await axios.get(generateUrl('/apps/folder_protection/api/groupfolders'))
                this.groupFoldersAvailable = response.data.available
                this.groupFolders = response.data.folders || []
                // Se não disponível, vai directo para custom
                if (!this.groupFoldersAvailable) {
                    this.activeTab = 'custom'
                }
            } catch (error) {
                console.error('Error loading group folders:', error)
                this.groupFoldersAvailable = false
                this.activeTab = 'custom'
            } finally {
                this.loadingGroupFolders = false
            }
        },

        openReasonDialog(groupFolder) {
            this.pendingGroupFolder = groupFolder
            this.pendingReason = ''
            this.showReasonDialog = true
        },

        cancelReasonDialog() {
            this.showReasonDialog = false
            this.pendingGroupFolder = null
            this.pendingReason = ''
        },

        async confirmProtectGroupFolder() {
            if (!this.pendingGroupFolder) return
            this.submitting = true
            try {
                const response = await axios.post(
                    generateUrl('/apps/folder_protection/api/protect'),
                    {
                        path: this.pendingGroupFolder.path,
                        reason: this.pendingReason || null,
                        userId: OC.getCurrentUser().uid,
                    }
                )
                if (response.data.success) {
                    showSuccess(this.t('folder_protection', 'Folder protected successfully'))
                    this.cancelReasonDialog()
                    // Actualiza ambas as listas
                    await Promise.all([this.loadGroupFolders(), this.loadFolders()])
                } else {
                    showError(response.data.message)
                }
            } catch (error) {
                console.error('Error protecting group folder:', error)
                showError(error.response?.data?.message || this.t('folder_protection', 'Failed to protect folder'))
            } finally {
                this.submitting = false
            }
        },

        async unprotectGroupFolder(groupFolder) {
            if (!confirm(this.t('folder_protection', 'Remove protection from "{path}"?', { path: groupFolder.mountPoint }))) {
                return
            }
            try {
                const response = await axios.post(
                    generateUrl('/apps/folder_protection/api/unprotect'),
                    { id: groupFolder.protectionId }
                )
                if (response.data.success) {
                    showSuccess(this.t('folder_protection', 'Protection removed successfully'))
                    await Promise.all([this.loadGroupFolders(), this.loadFolders()])
                } else {
                    showError(response.data.message)
                }
            } catch (error) {
                console.error('Error removing protection:', error)
                showError(this.t('folder_protection', 'Failed to remove protection'))
            }
        },

        async addProtection() {
            this.submitting = true
            this.error = null
            const fullPath = '/files/' + this.newFolder.folderName.replace(/^\/+/, '')
            try {
                const response = await axios.post(
                    generateUrl('/apps/folder_protection/api/protect'),
                    {
                        path: fullPath,
                        reason: this.newFolder.reason,
                        userId: OC.getCurrentUser().uid,
                    }
                )
                if (response.data.success) {
                    showSuccess(this.t('folder_protection', 'Folder protected successfully'))
                    this.closeModal()
                    await this.loadFolders()
                } else {
                    this.error = response.data.message
                }
            } catch (error) {
                console.error('Error adding protection:', error)
                this.error = error.response?.data?.message || this.t('folder_protection', 'Failed to protect folder')
            } finally {
                this.submitting = false
            }
        },

        async removeProtection(folder) {
            if (!confirm(this.t('folder_protection', 'Remove protection from "{path}"?', { path: folder.path }))) {
                return
            }
            try {
                const response = await axios.post(
                    generateUrl('/apps/folder_protection/api/unprotect'),
                    { id: folder.id }
                )
                if (response.data.success) {
                    showSuccess(this.t('folder_protection', 'Protection removed successfully'))
                    await this.loadFolders()
                } else {
                    showError(response.data.message)
                }
            } catch (error) {
                console.error('Error removing protection:', error)
                showError(this.t('folder_protection', 'Failed to remove protection'))
            }
        },

        formatDate(timestamp) {
            return new Date(timestamp * 1000).toLocaleString()
        },

        t(app, text, vars = {}) {
            return OC.L10N.translate(app, text, vars)
        },
    },
}
</script>

<style scoped>
.folder-protection-admin {
    max-width: 900px;
}

.add-protection-section {
    margin-bottom: 20px;
}

.loading-container {
    text-align: center;
    padding: 40px;
    color: var(--color-text-maxcontrast);
}

.protected-folders-list h3 {
    margin-bottom: 15px;
}

.folder-item {
    display: flex;
    align-items: center;
    padding: 15px;
    margin-bottom: 10px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius-large);
}

.folder-icon {
    font-size: 24px;
    margin-right: 15px;
}

.folder-details {
    flex: 1;
}

.folder-path {
    font-weight: bold;
    margin-bottom: 5px;
}

.folder-mount {
    display: block;
    font-weight: var(--font-weight-bold);
}
.folder-internal {
    font-size: 0.9em;
    color: var(--color-text-lighter);
    word-break: break-all;
}

.folder-meta {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

.folder-reason {
    margin-top: 5px;
    font-style: italic;
    color: var(--color-text-lighter);
}

.folder-actions button {
    opacity: 0.7;
}

.folder-actions button:hover {
    opacity: 1;
}

.empty-content {
    text-align: center;
    padding: 60px 20px;
    color: var(--color-text-maxcontrast);
}

.empty-content .icon-folder {
    font-size: 64px;
    opacity: 0.3;
}

/* Modal */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
}

.modal-content {
    background: var(--color-main-background);
    padding: 30px;
    border-radius: var(--border-radius-large);
    max-width: 560px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
}

/* Tabs */
.tabs {
    display: flex;
    border-bottom: 2px solid var(--color-border);
    margin-bottom: 20px;
}

.tab-btn {
    background: none;
    border: none;
    padding: 8px 18px;
    cursor: pointer;
    font-size: 14px;
    color: var(--color-text-maxcontrast);
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
}

.tab-btn.active {
    color: var(--color-main-text);
    border-bottom-color: var(--color-primary);
    font-weight: bold;
}

/* Group folders list */
.group-folders-list {
    max-height: 360px;
    overflow-y: auto;
}

.gf-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 10px;
    border-bottom: 1px solid var(--color-border);
}

.gf-item:last-child {
    border-bottom: none;
}

.gf-info {
    flex: 1;
}

.gf-name {
    font-weight: bold;
    font-size: 15px;
}

.gf-path {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    font-family: monospace;
}

.gf-reason {
    font-size: 12px;
    font-style: italic;
    color: var(--color-text-lighter);
    margin-top: 2px;
}

.gf-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
    margin-left: 12px;
}

.badge-protected {
    font-size: 12px;
    color: var(--color-success, #46ba61);
    white-space: nowrap;
}

/* Reason dialog (inline overlay dentro do modal) */
.reason-dialog-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--border-radius-large);
    z-index: 10;
}

.reason-dialog {
    background: var(--color-main-background);
    padding: 24px;
    border-radius: var(--border-radius-large);
    width: 90%;
    max-width: 400px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.25);
}

.reason-dialog h4 {
    margin: 0 0 16px;
}

/* Form */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}

.form-group input,
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    box-sizing: border-box;
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
}

.error-message {
    margin-top: 15px;
    padding: 10px;
    background: var(--color-error);
    color: white;
    border-radius: var(--border-radius);
}

.path-input-wrapper {
    display: flex;
    align-items: center;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    overflow: hidden;
}

.path-prefix {
    padding: 10px 8px 10px 10px;
    background: var(--color-background-hover);
    color: var(--color-text-maxcontrast);
    font-family: monospace;
    font-size: 14px;
    white-space: nowrap;
    border-right: 1px solid var(--color-border);
    user-select: none;
}

.path-input-wrapper input {
    border: none;
    border-radius: 0;
    flex: 1;
}

.form-hint {
    margin-top: 4px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    font-family: monospace;
}

.form-warning {
    margin-top: 6px;
    padding: 8px 10px;
    font-size: 12px;
    background: var(--color-warning, #eca700);
    color: var(--color-main-background);
    border-radius: var(--border-radius);
    line-height: 1.4;
}

.badge-partial {
    font-size: 12px;
    color: var(--color-warning, #eca700);
    white-space: nowrap;
    cursor: help;
}
</style>

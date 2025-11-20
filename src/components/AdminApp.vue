<template>
    <!-- Contentor principal da aplicação -->
    <div class="folder-protection-admin">
        <!-- Secção com botão para adicionar uma nova pasta protegida -->
        <div class="add-protection-section">
            <!-- Botão que abre o modal para adicionar proteção -->
            <!-- @click="showAddModal = true" - quando clicado, mostra o formulário de adição -->
            <!-- class="button primary" - aplica estilo de botão principal (destaque) -->
            <button @click="showAddModal = true" class="button primary">
                <span class="icon icon-add"></span>
                <!-- Texto do botão (traduzido automaticamente) -->
                {{ t('folder_protection', 'Add Protection') }}
            </button>
        </div>

        <!-- Indicador de carregamento (apresentado enquanto se está a buscar dados) -->
        <!-- v-if="loading" - mostra apenas se a variável "loading" for verdadeira -->
        <div v-if="loading" class="loading-container">
            <span class="icon-loading"></span>
            {{ t('folder_protection', 'Loading protected folders...') }}
        </div>

        <!-- Lista de Pastas Protegidas -->
        <!-- v-else-if="folders.length > 0" - mostra apenas se houver pastas na lista -->
        <div v-else-if="folders.length > 0" class="protected-folders-list">
            <h3>{{ t('folder_protection', 'Protected Folders') }}</h3>
            
            <!-- Ciclo que repete cada pasta na lista -->
            <!-- v-for="folder in folders" - passa por cada pasta da variável "folders" -->
            <!-- :key="folder.id" - identificador único para cada item (otimização) -->
            <div v-for="folder in folders" :key="folder.id" class="folder-item">
                <!-- Ícone da pasta -->
                <div class="folder-icon">📁</div>
                
                <!-- Informações da pasta -->
                <div class="folder-details">
                    <!-- Caminho completo da pasta -->
                    <div class="folder-path">{{ folder.path }}</div>
                    
                    <!-- Metadados: quem criou e quando -->
                    <div class="folder-meta">
                        <!-- Mostra o utilizador que criou a proteção -->
                        <span v-if="folder.created_by">
                            {{ t('folder_protection', 'Created by') }}: {{ folder.created_by }}
                        </span>
                        <!-- Mostra a data de criação (separado por barra) -->
                        <span v-if="folder.created_at">
                            | {{ formatDate(folder.created_at) }}
                        </span>
                    </div>
                    
                    <!-- Motivo da proteção (se houver) -->
                    <div v-if="folder.reason" class="folder-reason">
                        {{ folder.reason }}
                    </div>
                </div>
                
                <!-- Botão para remover a proteção -->
                <div class="folder-actions">
                    <!-- @click="removeProtection(folder)" - executa a função que remove a proteção -->
                    <!-- :title="..." - texto que aparece ao passar o rato sobre o botão -->
                    <button @click="removeProtection(folder)" 
                            class="button icon-delete" 
                            :title="t('folder_protection', 'Remove protection')">
                    </button>
                </div>
            </div>
        </div>

        <!-- Mensagem quando não existem pastas protegidas -->
        <!-- v-else - mostra se as condições anteriores forem falsas (sem carregamento e sem pastas) -->
        <div v-else class="empty-content">
            <div class="icon-folder"></div>
            <h3>{{ t('folder_protection', 'No protected folders') }}</h3>
            <p>{{ t('folder_protection', 'Add folders to protect them from being moved, copied, or deleted.') }}</p>
        </div>

        <!-- Modal (janela flutuante) para adicionar proteção a uma nova pasta -->
        <!-- v-if="showAddModal" - mostra apenas quando o utilizador clica no botão "Adicionar" -->
        <!-- @click="showAddModal = false" no overlay - fecha o modal ao clicar fora do formulário -->
        <div v-if="showAddModal" class="modal-overlay" @click="showAddModal = false">
            <!-- Conteúdo do modal -->
            <!-- @click.stop - impede que o clique feche o modal (fica aberto) -->
            <div class="modal-content" @click.stop>
                <h3>{{ t('folder_protection', 'Add Folder Protection') }}</h3>
                
                <!-- Formulário para adicionar proteção -->
                <!-- @submit.prevent="addProtection" - executa a função ao submeter (sem recarregar a página) -->
                <form @submit.prevent="addProtection">
                    <!-- Campo para inserir o caminho da pasta -->
                    <div class="form-group">
                        <label for="folder-path">
                            {{ t('folder_protection', 'Folder Path') }}
                        </label>
                        <!-- v-model="newFolder.path" - liga automaticamente o valor ao objeto de dados -->
                        <input 
                            id="folder-path"
                            v-model="newFolder.path" 
                            type="text" 
                            :placeholder="t('folder_protection', '/files/folder_name')"
                            required
                        />
                    </div>

                    <!-- Campo para inserir o motivo (opcional) -->
                    <div class="form-group">
                        <label for="folder-reason">
                            {{ t('folder_protection', 'Reason (optional)') }}
                        </label>
                        <!-- textarea - campo de texto grande para escrever múltiplas linhas -->
                        <!-- rows="3" - altura de 3 linhas -->
                        <textarea 
                            id="folder-reason"
                            v-model="newFolder.reason" 
                            :placeholder="t('folder_protection', 'Why is this folder protected?')"
                            rows="3"
                        ></textarea>
                    </div>

                    <!-- Botões do formulário -->
                    <div class="form-actions">
                        <!-- Botão Cancelar -->
                        <button type="button" @click="showAddModal = false" class="button">
                            {{ t('folder_protection', 'Cancel') }}
                        </button>
                        <!-- Botão Submeter -->
                        <!-- :disabled="submitting" - desativa o botão enquanto se está a enviar -->
                        <button type="submit" class="button primary" :disabled="submitting">
                            <!-- Muda o texto enquanto está a processar -->
                            {{ submitting ? t('folder_protection', 'Adding...') : t('folder_protection', 'Add Protection') }}
                        </button>
                    </div>
                </form>

                <!-- Mensagem de erro (se houver) -->
                <!-- v-if="error" - mostra apenas se existir uma mensagem de erro -->
                <div v-if="error" class="error-message">
                    {{ error }}
                </div>
            </div>
        </div>
    </div>
</template>

<script>
// Importa a biblioteca axios para fazer pedidos HTTP
import axios from '@nextcloud/axios'
// Importa a função para gerar URLs correctas da aplicação
import { generateUrl } from '@nextcloud/router'
// Importa funções para mostrar mensagens de sucesso e erro
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
    // Nome do componente (identificador interno)
    name: 'AdminApp',
    
    // data() retorna o estado reativo da aplicação
    // Tudo o que está aqui pode ser alterado dinamicamente
    data() {
        return {
            folders: [],  // Lista de pastas protegidas
            loading: true,  // Indica se está a carregar dados
            showAddModal: false,  // Controla se o modal está visível
            submitting: false,  // Indica se está a enviar um formulário
            error: null,  // Guarda mensagem de erro do formulário
            newFolder: {  // Objeto para guardar dados do novo formulário
                path: '',  // Caminho da pasta
                reason: ''  // Motivo da proteção
            }
        }
    },

    // mounted() - executado automaticamente quando o componente é carregado
    mounted() {
        this.loadFolders()  // Carrega a lista de pastas protegidas
    },

    // methods - funções que a aplicação pode executar
    methods: {
        // Função para carregar a lista de pastas protegidas do servidor
        async loadFolders() {
            this.loading = true  // Mostra indicador de carregamento
            try {
                // Faz um pedido GET ao servidor para obter a lista de pastas
                const response = await axios.get(generateUrl('/apps/folder_protection/api/list'))
                
                // Se a resposta foi bem-sucedida, guarda as pastas
                if (response.data.success) {
                    this.folders = response.data.folders
                }
            } catch (error) {
                // Se ocorreu um erro, regista-o na consola e mostra uma mensagem
                console.error('Error loading folders:', error)
                showError(this.t('folder_protection', 'Failed to load protected folders'))
            } finally {
                // Esconde o indicador de carregamento (com sucesso ou erro)
                this.loading = false
            }
        },

        // Função para adicionar proteção a uma pasta
        async addProtection() {
            this.submitting = true  // Desativa o botão do formulário
            this.error = null  // Limpa mensagens de erro anteriores

            try {
                // Envia os dados do formulário para o servidor
                const response = await axios.post(
                    generateUrl('/apps/folder_protection/api/protect'),
                    {
                        path: this.newFolder.path,  // Caminho da pasta
                        reason: this.newFolder.reason,  // Motivo
                        userId: OC.getCurrentUser().uid  // ID do utilizador atual
                    }
                )

                // Se foi protegida com sucesso
                if (response.data.success) {
                    showSuccess(this.t('folder_protection', 'Folder protected successfully'))
                    this.showAddModal = false  // Fecha o modal
                    this.newFolder = { path: '', reason: '' }  // Limpa o formulário
                    await this.loadFolders()  // Recarrega a lista
                } else {
                    // Se houve erro, mostra a mensagem do servidor
                    this.error = response.data.message
                }
            } catch (error) {
                // Se ocorreu um erro na comunicação
                console.error('Error adding protection:', error)
                this.error = error.response?.data?.message || this.t('folder_protection', 'Failed to protect folder')
            } finally {
                // Reativa o botão do formulário
                this.submitting = false
            }
        },

        // Função para remover proteção de uma pasta
        async removeProtection(folder) {
            // Pede confirmação ao utilizador antes de remover
            if (!confirm(this.t('folder_protection', 'Remove protection from "{path}"?', { path: folder.path }))) {
                return  // Cancela se o utilizador clicar em "Não"
            }

            try {
                // Envia o ID da pasta para remover a proteção
                const response = await axios.post(
                    generateUrl('/apps/folder_protection/api/unprotect'),
                    { id: folder.id }  // ID único da pasta protegida
                )

                // Se foi removida com sucesso
                if (response.data.success) {
                    showSuccess(this.t('folder_protection', 'Protection removed successfully'))
                    await this.loadFolders()  // Recarrega a lista
                } else {
                    // Se houve erro, mostra a mensagem
                    showError(response.data.message)
                }
            } catch (error) {
                // Se ocorreu um erro na comunicação
                console.error('Error removing protection:', error)
                showError(this.t('folder_protection', 'Failed to remove protection'))
            }
        },

        // Função para formatar um timestamp (número) em data legível
        formatDate(timestamp) {
            // Multiplica por 1000 porque JavaScript usa milissegundos
            // toLocaleString() formata de acordo com o idioma do sistema
            return new Date(timestamp * 1000).toLocaleString()
        },

        // Função para traduzir texto (abreviado em "t")
        // Permite que a interface apareça em vários idiomas
        t(app, text, vars = {}) {
            return OC.L10N.translate(app, text, vars)
        }
    }
}
</script>

<style scoped>
/* Contentor principal - define a largura máxima */
.folder-protection-admin {
    max-width: 900px;
}

/* Secção do botão adicionar */
.add-protection-section {
    margin-bottom: 20px;  /* Espaço abaixo do botão */
}

/* Contentor de carregamento */
.loading-container {
    text-align: center;  /* Centra o texto */
    padding: 40px;  /* Espaço interno */
    color: var(--color-text-maxcontrast);  /* Cor de texto mate */
}

/* Título da lista de pastas */
.protected-folders-list h3 {
    margin-bottom: 15px;  /* Espaço abaixo do título */
}

/* Estilo de cada pasta na lista */
.folder-item {
    display: flex;  /* Coloca elementos lado a lado */
    align-items: center;  /* Alinha verticalmente no centro */
    padding: 15px;  /* Espaço interno */
    margin-bottom: 10px;  /* Espaço entre pastas */
    background: var(--color-background-hover);  /* Cor de fundo (tema claro/escuro) */
    border-radius: var(--border-radius-large);  /* Cantos arredondados */
}

/* Ícone da pasta */
.folder-icon {
    font-size: 24px;  /* Tamanho grande */
    margin-right: 15px;  /* Espaço à direita */
}

/* Detalhes da pasta (caminho, data, motivo) */
.folder-details {
    flex: 1;  /* Ocupa todo o espaço disponível */
}

/* Caminho da pasta (em destaque) */
.folder-path {
    font-weight: bold;  /* Texto em negrito */
    margin-bottom: 5px;  /* Espaço abaixo */
}

/* Metadados (quem criou e quando) */
.folder-meta {
    font-size: 12px;  /* Texto pequeno */
    color: var(--color-text-maxcontrast);  /* Cor mate */
}

/* Motivo da proteção (em itálico) */
.folder-reason {
    margin-top: 5px;  /* Espaço acima */
    font-style: italic;  /* Texto inclinado */
    color: var(--color-text-lighter);  /* Cor mais clara */
}

/* Botão de remover (menos transparente por defeito) */
.folder-actions button {
    opacity: 0.7;  /* 70% de opacidade */
}

/* Botão de remover (mais opaco ao passar o rato) */
.folder-actions button:hover {
    opacity: 1;  /* 100% de opacidade */
}

/* Conteúdo vazio (quando não há pastas protegidas) */
.empty-content {
    text-align: center;  /* Centra tudo */
    padding: 60px 20px;  /* Espaço interno grande */
    color: var(--color-text-maxcontrast);  /* Cor mate */
}

/* Ícone de pasta vazia */
.empty-content .icon-folder {
    font-size: 64px;  /* Ícone muito grande */
    opacity: 0.3;  /* 30% de opacidade (desfocado) */
}

/* === Estilos do Modal (janela flutuante) === */

/* Fundo escuro do modal (overlay) */
.modal-overlay {
    position: fixed;  /* Fixo na tela */
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);  /* Preto semi-transparente */
    display: flex;  /* Centra horizontalmente */
    align-items: center;  /* Centra verticalmente */
    justify-content: center;
    z-index: 10000;  /* Fica por cima de tudo */
}

/* Conteúdo do modal (caixa branca) */
.modal-content {
    background: var(--color-main-background);  /* Fundo (tema claro/escuro) */
    padding: 30px;  /* Espaço interno */
    border-radius: var(--border-radius-large);  /* Cantos arredondados */
    max-width: 500px;  /* Largura máxima */
    width: 90%;  /* Responsivo (90% em ecrãs pequenos) */
    max-height: 90vh;  /* Altura máxima */
    overflow-y: auto;  /* Scrollbar vertical se precisar */
}

/* Grupo de formulário (label + input) */
.form-group {
    margin-bottom: 20px;  /* Espaço abaixo */
}

/* Rótulo do formulário */
.form-group label {
    display: block;  /* Ocupa uma linha inteira */
    margin-bottom: 5px;  /* Espaço abaixo do rótulo */
    font-weight: bold;  /* Texto em negrito */
}

/* Campos de input e textarea */
.form-group input,
.form-group textarea {
    width: 100%;  /* Ocupa a largura completa */
    padding: 10px;  /* Espaço interno */
    border: 1px solid var(--color-border);  /* Borda fina */
    border-radius: var(--border-radius);  /* Cantos levemente arredondados */
}

/* Botões do formulário */
.form-actions {
    display: flex;  /* Coloca botões lado a lado */
    gap: 10px;  /* Espaço entre botões */
    justify-content: flex-end;  /* Alinha à direita */
    margin-top: 20px;  /* Espaço acima */
}

/* Mensagem de erro */
.error-message {
    margin-top: 15px;  /* Espaço acima */
    padding: 10px;  /* Espaço interno */
    background: var(--color-error);  /* Fundo vermelho */
    color: white;  /* Texto branco */
    border-radius: var(--border-radius);  /* Cantos arredondados */
}
</style>

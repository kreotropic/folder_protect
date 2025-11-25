import Vue from 'vue'
import AdminApp from './components/AdminApp.vue'

console.log('Folder Protection: Script loaded!')
console.log('Vue:', Vue)

// Esperar o DOM carregar
document.addEventListener('DOMContentLoaded', function() {
    console.log('Folder Protection: DOM loaded')
    console.log('Target element:', document.getElementById('folder-protection-app'))
    
    // Montar a aplicação Vue
    const app = new Vue({
        el: '#folder-protection-app',
        render: h => h(AdminApp)
    })
    
    console.log('Folder Protection: Vue app mounted', app)
})

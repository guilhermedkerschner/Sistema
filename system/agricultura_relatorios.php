<?php
session_start();
require_once 'includes/template_base.php';

// Definir conteúdo da página
function renderPageContent() {
?>
    <div class="desenvolvimento-container">
        <div class="desenvolvimento-icon">
            <i class="fas fa-tools"></i>
        </div>

        <h2 class="desenvolvimento-title">Nova Página</h2>
        
        <p class="desenvolvimento-subtitle">
            Esta é uma nova página usando o template modular
        </p>

        <div class="desenvolvimento-message">
            <h3>
                <i class="fas fa-info-circle"></i>
                Página Modular
            </h3>
            <p>Agora você pode criar páginas rapidamente usando o template base!</p>
        </div>

        <a href="dashboard.php" class="back-button">
            <i class="fas fa-arrow-left"></i>
            Voltar ao Dashboard
        </a>
    </div>
<?php
}

// Renderizar a página
renderPageTemplate(
    'Nova Página', 
    'renderPageContent',
    ['assets/css/custom-page.css'], // CSS customizado (opcional)
    ['assets/js/custom-page.js']    // JS customizado (opcional)
);
?>
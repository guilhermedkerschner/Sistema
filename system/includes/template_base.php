<?php
/**
 * Template Base
 * Sistema da Prefeitura
 */

function renderPageTemplate($pageTitle, $contentCallback, $customCSS = [], $customJS = []) {
    // Verificar se usuário está logado
    if (!isset($_SESSION['usersystem_logado'])) {
        header("Location: ../acessdeniedrestrict.php"); 
        exit;
    }

    // Incluir dependências se não foram incluídas
    if (!class_exists('MenuManager')) {
        require_once "../lib/config.php";
        require_once "./core/MenuManager.php";
    }

    // Buscar dados do usuário (lógica igual ao seu código original)
    global $conn, $usuario_dados, $menuManager, $themeColors, $is_admin;
    
    // ... (seu código de busca do usuário aqui) ...
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../../img/logo_eai.ico" type="imagem/x-icon">
    <title><?php echo $pageTitle; ?> - Sistema da Prefeitura</title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS Base -->
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/header.css">
    <link rel="stylesheet" href="assets/css/menu.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    
    <!-- CSS Customizado -->
    <?php foreach ($customCSS as $css): ?>
        <link rel="stylesheet" href="<?php echo $css; ?>">
    <?php endforeach; ?>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <?php 
        // Executar callback do conteúdo
        if (is_callable($contentCallback)) {
            call_user_func($contentCallback);
        }
        ?>
    </div>

    <!-- JavaScript Base -->
    <script src="assets/js/main.js"></script>
    
    <!-- JavaScript Customizado -->
    <?php foreach ($customJS as $js): ?>
        <script src="<?php echo $js; ?>"></script>
    <?php endforeach; ?>
</body>
</html>
<?php
}
?>
<?php
// Inicia a sessão
session_start();

// Verificar se o usuário está logado
if (!isset($_SESSION['usersystem_logado'])) {
    header("Location: ../acessdeniedrestrict.php"); 
    exit;
}

// Incluir dependências
require_once "../lib/config.php";
require_once "./core/MenuManager.php";

// Buscar informações completas do usuário logado
$usuario_id = $_SESSION['usersystem_id'];
$usuario_dados = [];

try {
    $stmt = $conn->prepare("
        SELECT 
            usuario_id,
            usuario_nome, 
            usuario_departamento, 
            usuario_nivel_id,
            usuario_email,
            usuario_telefone,
            usuario_status,
            usuario_data_criacao,
            usuario_ultimo_acesso
        FROM tb_usuarios_sistema 
        WHERE usuario_id = :id
    ");
    $stmt->bindParam(':id', $usuario_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $usuario_dados = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Atualizar dados da sessão
        $_SESSION['usersystem_nome'] = $usuario_dados['usuario_nome'];
        $_SESSION['usersystem_departamento'] = $usuario_dados['usuario_departamento'];
        $_SESSION['usersystem_nivel'] = $usuario_dados['usuario_nivel_id'];
        
        // Atualizar último acesso
        $stmt_update = $conn->prepare("UPDATE tb_usuarios_sistema SET usuario_ultimo_acesso = NOW() WHERE usuario_id = :id");
        $stmt_update->bindParam(':id', $usuario_id);
        $stmt_update->execute();
    } else {
        // Usuário não encontrado, fazer logout
        session_destroy();
        header("Location: ../acessdeniedrestrict.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar dados do usuário: " . $e->getMessage());
    $usuario_dados = [
        'usuario_nome' => $_SESSION['usersystem_nome'] ?? 'Usuário',
        'usuario_departamento' => $_SESSION['usersystem_departamento'] ?? '',
        'usuario_nivel_id' => $_SESSION['usersystem_nivel'] ?? 4,
        'usuario_email' => '',
        'usuario_telefone' => ''
    ];
}

// Inicializar o MenuManager com dados da sessão
$userSession = [
    'usuario_id' => $usuario_dados['usuario_id'],
    'usuario_nome' => $usuario_dados['usuario_nome'],
    'usuario_departamento' => $usuario_dados['usuario_departamento'],
    'usuario_nivel_id' => $usuario_dados['usuario_nivel_id'],
    'usuario_email' => $usuario_dados['usuario_email']
];

$menuManager = new MenuManager($userSession);

// Obter configurações do tema
$themeColors = $menuManager->getThemeColors();
$availableModules = $menuManager->getAvailableModules();
$is_admin = ($usuario_dados['usuario_nivel_id'] == 1);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../../img/logo_eai.ico" type="imagem/x-icon">
    <title>Página em Desenvolvimento - Sistema da Prefeitura</title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/header.css">
    <link rel="stylesheet" href="assets/css/menu.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="desenvolvimento-container">
            <!-- Ícone animado -->
            <div class="desenvolvimento-icon">
                <i class="fas fa-tools"></i>
            </div>

            <!-- Título principal -->
            <h2 class="desenvolvimento-title">Página em Desenvolvimento</h2>
            
            <!-- Subtítulo -->
            <p class="desenvolvimento-subtitle">
                Esta funcionalidade está sendo construída pela nossa equipe de desenvolvimento
            </p>

            <!-- Mensagem informativa -->
            <div class="desenvolvimento-message">
                <h3>
                    <i class="fas fa-info-circle"></i>
                    O que está acontecendo?
                </h3>
                <p>Em breve, esta página estará totalmente funcional com todas as ferramentas necessárias.</p>
                <p>Agradecemos sua paciência enquanto aprimoramos o sistema!</p>
            </div>

            <!-- Barra de progresso -->
            <div class="progress-container">
                <div class="progress-label">
                    <span>Progresso do Desenvolvimento</span>
                    <span>25%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
            </div>

            <!-- Botão para voltar -->
            <a href="dashboard.php" class="back-button">
                <i class="fas fa-arrow-left"></i>
                Voltar ao Dashboard
            </a>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="assets/js/main.js"></script>
</body>
</html>
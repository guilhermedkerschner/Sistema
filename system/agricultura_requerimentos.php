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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        :root {
            --primary-color: #4169E1;
            --secondary-color: #4169E1;
            --text-color: #333;
            --text-secondary: #6b7280;
            --text-muted: #9ca3af;
            --light-color: #ecf0f1;
            --bg-primary: #f8f9fa;
            --bg-secondary: #ffffff;
            --bg-tertiary: #f3f4f6;
            --border-color: #e5e7eb;
            --sidebar-width: 280px;
            --header-height: 70px;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #17a2b8;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --radius: 8px;
            --transition: all 0.2s ease;
        }

        body {
            display: flex;
            min-height: 100vh;
            background-color: var(--bg-primary);
            color: var(--text-color);
            line-height: 1.6;
        }

        /* Header Completo Moderno */
        .header {
            height: var(--header-height);
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 200;
            display: flex;
            align-items: center;
            padding: 0 2rem;
            box-shadow: var(--shadow);
            justify-content: space-between;
        }

        /* Logo na esquerda */
        .header-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 200px;
        }

        .logo-placeholder {
            width: 40px;
            height: 40px;
            background: var(--secondary-color);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.125rem;
        }

        .system-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-color);
        }

        /* Barra de pesquisa no centro */
        .header-search {
            flex: 1;
            max-width: 600px;
            margin: 0 2rem;
        }

        .search-container {
            position: relative;
            width: 100%;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: 1px solid var(--border-color);
            border-radius: 50px;
            background: var(--bg-tertiary);
            font-size: 0.875rem;
            color: var(--text-color);
            outline: none;
            transition: var(--transition);
        }

        .search-input:focus {
            border-color: var(--secondary-color);
            background: var(--bg-secondary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        /* User Menu na direita */
        .user-menu {
            display: flex;
            align-items: center;
            position: relative;
        }

        .user-trigger {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            transition: var(--transition);
            border: none;
            background: transparent;
        }

        .user-trigger:hover {
            background: var(--bg-tertiary);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--secondary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            position: relative;
            overflow: hidden;
        }

        .user-info {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            text-align: left;
        }

        .user-name {
            font-weight: 700;
            color: var(--text-color);
            font-size: 0.875rem;
            line-height: 1.2;
        }

        .user-email {
            font-size: 0.75rem;
            color: var(--text-secondary);
            line-height: 1.2;
        }

        .dropdown-arrow {
            color: var(--text-muted);
            font-size: 0.75rem;
            margin-left: 0.5rem;
            transition: var(--transition);
        }

        .user-menu.open .dropdown-arrow {
            transform: rotate(180deg);
        }

        /* Dropdown Menu */
        .user-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            min-width: 220px;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: var(--transition);
            margin-top: 0.5rem;
        }

        .user-menu.open .user-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-header {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-tertiary);
        }

        .dropdown-user-name {
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.25rem;
        }

        .dropdown-user-email {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .dropdown-menu {
            padding: 0.5rem 0;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            color: var(--text-color);
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .dropdown-item:hover {
            background: var(--bg-tertiary);
        }

        .dropdown-item.danger {
            color: #dc2626;
        }

        .dropdown-item.danger:hover {
            background: rgba(220, 38, 38, 0.1);
        }

        .dropdown-divider {
            height: 1px;
            background: var(--border-color);
            margin: 0.5rem 0;
        }

        /* Sidebar styles */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--bg-secondary);
            border-right: 1px solid var(--border-color);
            position: fixed;
            height: 100vh;
            left: 0;
            top: var(--header-height);
            z-index: 100;
            transition: all 0.3s;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }

        .sidebar::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 2px;
        }

        .sidebar.collapsed {
            width: 70px;
        }

        .sidebar.collapsed .menu-text,
        .sidebar.collapsed .sidebar-header h3 {
            display: none;
        }

        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            background-color: var(--bg-secondary);
        }

        .sidebar-header h3 {
            font-size: 1.1rem;
            color: var(--text-color);
            line-height: 1.2;
        }

        .toggle-btn {
            background: none;
            border: none;
            color: var(--text-color);
            font-size: 20px;
            cursor: pointer;
            padding: 5px;
            border-radius: 4px;
            transition: var(--transition);
        }

        .toggle-btn:hover {
            background-color: var(--bg-tertiary);
        }

        /* Menu Styles */
        .menu {
            list-style: none;
            padding: 1rem 0;
        }

        .menu-item {
            margin: 0.25rem 1rem;
        }

        .menu-link {
            display: flex;
            align-items: center;
            padding: 0.875rem 1rem;
            color: var(--text-color);
            text-decoration: none;
            border-radius: var(--radius);
            transition: var(--transition);
            font-weight: 500;
            font-size: 0.875rem;
        }

        .menu-link:hover,
        .menu-link.active {
            background: var(--secondary-color);
            color: white;
        }

        .menu-link-content {
            display: flex;
            align-items: center;
            flex: 1;
        }

        .menu-icon {
            margin-right: 0.75rem;
            font-size: 1rem;
            width: 20px;
            text-align: center;
        }

        .menu-text {
            font-weight: 500;
        }

        .arrow {
            margin-left: auto;
            transition: var(--transition);
            font-size: 0.75rem;
        }

        .menu-item.open .arrow {
            transform: rotate(90deg);
        }

        .submenu {
            list-style: none;
            background: var(--bg-tertiary);
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            margin-top: 0.25rem;
            border-radius: var(--radius);
        }

        .menu-item.open .submenu {
            max-height: 500px;
        }

        .submenu-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem 0.75rem 3rem;
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.8125rem;
        }

        .submenu-link:hover,
        .submenu-link.active {
            color: var(--secondary-color);
            background: rgba(52, 152, 219, 0.1);
        }

        .menu-category {
            padding: 1rem 1rem 0.5rem;
            color: var(--text-muted);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 0.5rem;
        }

        .menu-separator {
            height: 1px;
            background-color: var(--border-color);
            margin: 10px 0;
        }

        /* Main content styles */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            padding: 2rem;
            background: var(--bg-primary);
            min-height: calc(100vh - var(--header-height));
            transition: margin-left 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .main-content.expanded {
            margin-left: 70px;
        }

        /* Mobile Toggle */
        .mobile-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--text-color);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: var(--radius);
        }

        .mobile-toggle:hover {
            background: var(--bg-tertiary);
        }

        /* Página em Desenvolvimento */
        .desenvolvimento-container {
            text-align: center;
            max-width: 600px;
            width: 100%;
            padding: 2rem;
        }

        .desenvolvimento-icon {
            font-size: 4rem;
            color: var(--secondary-color);
            margin-bottom: 2rem;
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        .desenvolvimento-title {
            font-size: 3rem;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 1rem;
            line-height: 1.2;
        }

        .desenvolvimento-subtitle {
            font-size: 1.25rem;
            color: var(--text-secondary);
            margin-bottom: 2rem;
            line-height: 1.4;
        }

        .desenvolvimento-message {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }

        .desenvolvimento-message h3 {
            color: var(--secondary-color);
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .desenvolvimento-message p {
            color: var(--text-secondary);
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 0.5rem;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--secondary-color);
            color: white;
            text-decoration: none;
            border-radius: var(--radius);
            font-weight: 500;
            transition: var(--transition);
            border: none;
            cursor: pointer;
        }

        .back-button:hover {
            background: #2980b9;
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        /* Progress Bar */
        .progress-container {
            margin: 2rem 0;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--bg-tertiary);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--secondary-color), #2980b9);
            border-radius: 4px;
            width: 25%;
            animation: progress 2s ease-in-out;
        }

        @keyframes progress {
            0% { width: 0%; }
            100% { width: 25%; }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .header-search {
                max-width: 400px;
                margin: 0 1rem;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 300;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .mobile-toggle {
                display: flex;
            }

            .header {
                padding: 0 1rem;
            }

            .header-logo {
                min-width: auto;
            }

            .system-title {
                display: none;
            }

            .header-search {
                margin: 0 0.5rem;
                max-width: none;
            }

            .user-info {
                display: none;
            }

            .desenvolvimento-icon {
                font-size: 0.5rem;
            }

            .desenvolvimento-title {
                font-size: 1rem;
            }

            .desenvolvimento-subtitle {
                font-size: 1rem;
            }

            .desenvolvimento-message {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header Completo -->
    <div class="header">
        <!-- Logo do Sistema (esquerda) -->
        <div class="header-logo">
            <button class="mobile-toggle" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <div class="logo-placeholder">
                <i class="fas fa-city"></i>
            </div>
            <div class="system-title">SGM - Eai Cidadão!</div>
        </div>

        <!-- Barra de Pesquisa (centro) -->
        <div class="header-search">
            <div class="search-container">
                <i class="fas fa-search search-icon"></i>
                <input type="text" class="search-input" placeholder="Pesquisar módulos, usuários, documentos..." id="globalSearch">
            </div>
        </div>

        <!-- Menu do Usuário (direita) -->
        <div class="user-menu" id="userMenu">
            <button class="user-trigger" onclick="toggleUserMenu()">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($usuario_dados['usuario_nome'], 0, 2)); ?>
                    <?php if ($is_admin): ?>
                        <div class="notification-badge">!</div>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($usuario_dados['usuario_nome']); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($usuario_dados['usuario_email']); ?></div>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
            </button>

            <div class="user-dropdown">
                <div class="dropdown-header">
                    <div class="dropdown-user-name"><?php echo htmlspecialchars($usuario_dados['usuario_nome']); ?></div>
                    <div class="dropdown-user-email"><?php echo htmlspecialchars($usuario_dados['usuario_email']); ?></div>
                </div>
                <div class="dropdown-menu">
                    <a href="perfil.php" class="dropdown-item">
                        <i class="fas fa-user"></i>
                        Meu Perfil
                    </a>
                    <a href="configuracoes.php" class="dropdown-item">
                        <i class="fas fa-cog"></i>
                        Configurações
                    </a>
                    <?php if ($is_admin): ?>
                    <div class="dropdown-divider"></div>
                    <a href="lista_usuarios.php" class="dropdown-item">
                        <i class="fas fa-users-cog"></i>
                        Gerenciar Usuários
                    </a>
                    <a href="sistema_config.php" class="dropdown-item">
                        <i class="fas fa-tools"></i>
                        Configurações do Sistema
                    </a>
                    <?php endif; ?>
                    <div class="dropdown-divider"></div>
                    <a href="ajuda.php" class="dropdown-item">
                        <i class="fas fa-question-circle"></i>
                        Ajuda e Suporte
                    </a>
                    <a href="../controller/logout_system.php" class="dropdown-item danger">
                        <i class="fas fa-sign-out-alt"></i>
                        Sair
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3><?php echo $themeColors['title']; ?></h3>
            <button class="toggle-btn" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        
        <?php echo $menuManager->generateSidebar(basename(__FILE__, '.php')); ?>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="desenvolvimento-container">
            <!-- Ícone animado -->
            <div class="desenvolvimento-icon">
                <i class="fas fa-tools"></i>
            </div>

            <!-- Título principal -->
            <h2 class="desenvolvimento-title">Página em Desenvolvimento</h1>
            
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

    <script>
        // Variáveis globais
        let sidebarCollapsed = false;
        let userMenuOpen = false;

        // Inicialização
        document.addEventListener('DOMContentLoaded', function() {
            initializePage();
            initializeSearch();
        });

        function initializePage() {
            // Event listeners
            document.addEventListener('click', handleDocumentClick);
            window.addEventListener('resize', handleWindowResize);
            
            // Submenu toggle
            const menuItems = document.querySelectorAll('.menu-item');
            menuItems.forEach(function(item) {
                const menuLink = item.querySelector('.menu-link');
                if (menuLink && menuLink.querySelector('.arrow')) {
                    menuLink.addEventListener('click', function(e) {
                        e.preventDefault();
                        item.classList.toggle('open');
                        menuItems.forEach(function(otherItem) {
                            if (otherItem !== item && otherItem.classList.contains('open')) {
                                otherItem.classList.remove('open');
                            }
                        });
                    });
                }
            });

            // Handle window resize
            handleWindowResize();
        }

        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            
            if (window.innerWidth <= 768) {
                // No mobile, comportamento normal (mostrar/esconder)
                sidebar.classList.toggle('show');
            } else {
                // No desktop, colapsar para caixinha
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                sidebarCollapsed = !sidebarCollapsed;
            }
        }

        function toggleUserMenu() {
            const userMenu = document.getElementById('userMenu');
            userMenu.classList.toggle('open');
            userMenuOpen = !userMenuOpen;
        }

        function initializeSearch() {
            const searchInput = document.getElementById('globalSearch');
            
            searchInput.addEventListener('input', function(e) {
                const query = e.target.value.toLowerCase();
                
                if (query.length > 2) {
                    // Aqui você pode implementar a lógica de pesquisa
                    console.log('Pesquisando por:', query);
                }
            });
        }

        // Event handlers
        function handleDocumentClick(e) {
            // Fechar user menu ao clicar fora
            const userMenu = document.getElementById('userMenu');
            if (!userMenu.contains(e.target) && userMenuOpen) {
                userMenu.classList.remove('open');
                userMenuOpen = false;
            }
            
            // Fechar sidebar no mobile ao clicar fora
            if (window.innerWidth <= 768) {
                const sidebar = document.querySelector('.sidebar');
                const isClickInsideSidebar = sidebar.contains(e.target);
                const isToggleBtn = e.target.closest('.mobile-toggle');
                
                if (!isClickInsideSidebar && !isToggleBtn && sidebar.classList.contains('show')) {
                   sidebar.classList.remove('show');
               }
           }
       }

       function handleWindowResize() {
           if (window.innerWidth > 768) {
               const sidebar = document.querySelector('.sidebar');
               sidebar.classList.remove('show');
           }
       }

       // Atalhos de teclado
       document.addEventListener('keydown', function(e) {
           // Ctrl+K para focar na pesquisa
           if (e.ctrlKey && e.key === 'k') {
               e.preventDefault();
               document.getElementById('globalSearch').focus();
           }
           
           // ESC para fechar modais e menus
           if (e.key === 'Escape') {
               // Fechar user menu
               const userMenu = document.getElementById('userMenu');
               if (userMenuOpen) {
                   userMenu.classList.remove('open');
                   userMenuOpen = false;
               }
               
               // Fechar sidebar no mobile
               if (window.innerWidth <= 768) {
                   const sidebar = document.querySelector('.sidebar');
                   if (sidebar.classList.contains('show')) {
                       sidebar.classList.remove('show');
                   }
               }
           }
       });

   </script>
</body>
</html>
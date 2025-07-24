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

// Determinar se é administrador
$is_admin = ($usuario_dados['usuario_nivel_id'] == 1);

// Buscar estatísticas do sistema (apenas para admins)
$estatisticas = [];
if ($is_admin) {
    try {
        // Total de usuários ativos
        $stmt = $conn->query("SELECT COUNT(*) as total FROM tb_usuarios_sistema WHERE usuario_status = 'ativo'");
        $estatisticas['usuarios_ativos'] = $stmt->fetch()['total'];
        
        // Total de departamentos com usuários
        $stmt = $conn->query("SELECT COUNT(DISTINCT usuario_departamento) as total FROM tb_usuarios_sistema WHERE usuario_departamento IS NOT NULL AND usuario_departamento != ''");
        $estatisticas['departamentos_ativos'] = $stmt->fetch()['total'];
        
        // Usuários online (últimos 30 minutos)
        $stmt = $conn->query("SELECT COUNT(*) as total FROM tb_usuarios_sistema WHERE usuario_ultimo_acesso >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
        $estatisticas['usuarios_online'] = $stmt->fetch()['total'];
        
        // Total de cadastros habitacionais (se existir a tabela)
        try {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM tb_cad_social");
            $estatisticas['cadastros_habitacionais'] = $stmt->fetch()['total'];
        } catch (PDOException $e) {
            $estatisticas['cadastros_habitacionais'] = 0;
        }
        
    } catch (PDOException $e) {
        error_log("Erro ao buscar estatísticas: " . $e->getMessage());
        $estatisticas = [
            'usuarios_ativos' => 0,
            'departamentos_ativos' => 0,
            'usuarios_online' => 0,
            'cadastros_habitacionais' => 0
        ];
    }
}

// Buscar atividades recentes do usuário
$atividades_recentes = [];
try {
    // Verificar se existe tabela de logs
    $stmt = $conn->query("SHOW TABLES LIKE 'tb_log_atividades'");
    if ($stmt->rowCount() > 0) {
        $stmt = $conn->prepare("
            SELECT acao, detalhes, data_atividade 
            FROM tb_log_atividades 
            WHERE usuario_id = :usuario_id 
            ORDER BY data_atividade DESC 
            LIMIT 5
        ");
        $stmt->bindParam(':usuario_id', $usuario_id);
        $stmt->execute();
        $atividades_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Tabela de logs não existe ainda
    $atividades_recentes = [];
}

// Preparar módulos para exibição em cards
$modulos_cards = [];
foreach ($availableModules as $key => $module) {
    if ($module['info']['category'] !== 'system' && $module['info']['category'] !== 'user') {
        $modulos_cards[$key] = $module;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistema da Prefeitura</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        :root {
            --primary-color: #2c3e50;
            --secondary-color: <?php echo $themeColors['primary']; ?>;
            --text-color: #333;
            --light-color: #ecf0f1;
            --sidebar-width: 250px;
            --header-height: 60px;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #17a2b8;
        }

        body {
            display: flex;
            min-height: 100vh;
            background-color: #f5f7fa;
        }

        /* Sidebar styles */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--primary-color);
            color: white;
            position: fixed;
            height: 100%;
            left: 0;
            top: 0;
            z-index: 100;
            transition: all 0.3s;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            height: var(--header-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            background-color: var(--primary-color);
        }

        .sidebar-header h3 {
            font-size: 1.1rem;
            color: white;
            line-height: 1.2;
        }

        .sidebar.collapsed {
            width: 70px;
        }

        .sidebar.collapsed .menu-text,
        .sidebar.collapsed .sidebar-header h3 {
            display: none;
        }

        .toggle-btn {
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
        }

        .menu {
            list-style: none;
            padding: 10px 0;
        }

        .menu-item {
            position: relative;
        }

        .menu-link {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            transition: all 0.3s;
        }

        .menu-link:hover, 
        .menu-link.active {
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--secondary-color);
        }

        .menu-icon {
            margin-right: 10px;
            font-size: 18px;
            width: 25px;
            text-align: center;
        }

        .arrow {
            margin-left: auto;
            transition: transform 0.3s;
        }

        .menu-item.open .arrow {
            transform: rotate(90deg);
        }

        .submenu {
            list-style: none;
            background-color: rgba(0, 0, 0, 0.1);
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .menu-item.open .submenu {
            max-height: 1000px;
        }

        .submenu-link {
            display: block;
            padding: 10px 10px 10px 55px;
            color: white;
            text-decoration: none;
            transition: all 0.3s;
        }

        .submenu-link:hover,
        .submenu-link.active {
            background-color: rgba(255, 255, 255, 0.05);
            color: var(--secondary-color);
        }

        .menu-separator {
            height: 1px;
            background-color: rgba(255, 255, 255, 0.1);
            margin: 10px 0;
        }

        .menu-category {
            padding: 10px 20px 5px;
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Main content styles */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: margin-left 0.3s;
        }

        .main-content.expanded {
            margin-left: 70px;
        }

        .header {
            height: var(--header-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            background-color: white;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            border-radius: 8px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-details {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 3px;
        }

        .user-name {
            font-weight: bold;
            color: var(--text-color);
            white-space: nowrap;
        }

        .user-role {
            display: flex;
            align-items: center;
            justify-content: flex-end;
        }

        .admin-badge, .department-badge {
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .admin-badge {
            background-color: #e74c3c;
        }

        .admin-badge i {
            font-size: 0.7rem;
        }

        .department-badge {
            background-color: var(--secondary-color);
        }

        .page-title {
            margin-bottom: 20px;
            font-size: 1.8rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
        }

        .page-title i {
            margin-right: 15px;
            color: var(--secondary-color);
            font-size: 2rem;
        }

        /* Welcome section */
        .welcome-section {
            background: linear-gradient(135deg, var(--secondary-color), rgba(44, 62, 80, 0.9));
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .welcome-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .welcome-text h2 {
            font-size: 1.8rem;
            margin-bottom: 8px;
        }

        .welcome-text p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .welcome-stats {
            display: flex;
            gap: 30px;
        }

        .welcome-stat {
            text-align: center;
        }

        .welcome-stat-number {
            font-size: 2rem;
            font-weight: bold;
            display: block;
        }

        .welcome-stat-label {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        /* Stats cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            position: relative;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--secondary-color);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .stat-title {
            font-size: 0.9rem;
            color: #666;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            background: var(--secondary-color);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--text-color);
            margin-bottom: 5px;
        }

        .stat-description {
            color: #666;
            font-size: 0.9rem;
        }

        /* Module cards */
        .modules-section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 1.3rem;
            color: var(--text-color);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }

        .section-title i {
            margin-right: 10px;
            color: var(--secondary-color);
        }

        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .module-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .module-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .module-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--module-color);
        }

        .module-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .module-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: white;
            margin-right: 15px;
        }

        .module-info h3 {
            font-size: 1.2rem;
            color: var(--text-color);
            margin-bottom: 5px;
        }

        .module-info p {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .module-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }

        .module-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: all 0.3s;
        }

        .module-btn i {
            margin-right: 6px;
        }

        .btn-primary {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: scale(1.05);
        }

        .btn-outline {
            border: 1px solid var(--secondary-color);
            color: var(--secondary-color);
            background: transparent;
        }

        .btn-outline:hover {
            background-color: var(--secondary-color);
            color: white;
        }

        /* Activity section */
        .activity-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        .activity-item {
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--secondary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 0.9rem;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 500;
            color: var(--text-color);
            margin-bottom: 3px;
        }

        .activity-description {
            color: #666;
            font-size: 0.9rem;
        }

        .activity-time {
            color: #999;
            font-size: 0.8rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .mobile-toggle {
                display: block;
                background: none;
                border: none;
                font-size: 20px;
                cursor: pointer;
                color: var(--primary-color);
            }

            .header {
                flex-direction: column;
                gap: 15px;
                padding: 20px;
                height: auto;
            }

            .header > div:first-child {
                display: flex;
                align-items: center;
                justify-content: space-between;
                width: 100%;
            }

            .welcome-content {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }

            .welcome-stats {
                justify-content: center;
            }

            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .modules-grid {
                grid-template-columns: 1fr;
            }
        }

        .mobile-toggle {
            display: none;
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #ddd;
        }

        .quick-actions {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .quick-action {
            padding: 12px 20px;
            background: white;
            border: 2px solid var(--secondary-color);
            color: var(--secondary-color);
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .quick-action:hover {
            background: var(--secondary-color);
            color: white;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3><?php echo $themeColors['title']; ?></h3>
            <button class="toggle-btn">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        
        <?php echo $menuManager->generateSidebar('dashboard.php'); ?>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="header">
            <div>
                <button class="mobile-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Sistema de Gerenciamento</h2>
            </div>
            <div class="user-info">
                <div style="width: 35px; height: 35px; border-radius: 50%; background-color: var(--secondary-color); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                    <?php echo strtoupper(substr($usuario_dados['usuario_nome'], 0, 1)); ?>
                </div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($usuario_dados['usuario_nome']); ?></div>
                    <div class="user-role">
                        <?php if ($is_admin): ?>
                        <span class="admin-badge">
                            <i class="fas fa-crown"></i> Administrador
                        </span>
                        <?php else: ?>
                        <span class="department-badge">
                            <?php echo htmlspecialchars($usuario_dados['usuario_departamento'] ?? 'Usuário'); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Welcome Section -->
        <div class="welcome-section">
            <div class="welcome-content">
                <div class="welcome-text">
                    <h2>Bem-vindo, <?php echo htmlspecialchars(explode(' ', $usuario_dados['usuario_nome'])[0]); ?>!</h2>
                    <p><?php echo $is_admin ? 'Painel Administrativo' : 'Sistema ' . $themeColors['title']; ?></p>
                </div>
                <?php if ($is_admin && !empty($estatisticas)): ?>
                <div class="welcome-stats">
                    <div class="welcome-stat">
                        <span class="welcome-stat-number"><?php echo $estatisticas['usuarios_ativos']; ?></span>
                        <span class="welcome-stat-label">Usuários Ativos</span>
                    </div>
                    <div class="welcome-stat">
                        <span class="welcome-stat-number"><?php echo $estatisticas['departamentos_ativos']; ?></span>
                        <span class="welcome-stat-label">Departamentos</span>
                    </div>
                    <div class="welcome-stat">
                        <span class="welcome-stat-number"><?php echo $estatisticas['usuarios_online']; ?></span>
                        <span class="welcome-stat-label">Online Agora</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats Cards (Admin Only) -->
        <?php if ($is_admin && !empty($estatisticas)): ?>
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Usuários do Sistema</div>
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-number"><?php echo $estatisticas['usuarios_ativos']; ?></div>
                <div class="stat-description">Usuários ativos no sistema</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Departamentos</div>
                    <div class="stat-icon">
                        <i class="fas fa-building"></i>
                    </div>
                </div>
                <div class="stat-number"><?php echo $estatisticas['departamentos_ativos']; ?></div>
                <div class="stat-description">Departamentos com usuários</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Online</div>
                    <div class="stat-icon">
                        <i class="fas fa-circle" style="color: #27ae60;"></i>
                    </div>
                </div>
                <div class="stat-number"><?php echo $estatisticas['usuarios_online']; ?></div>
                <div class="stat-description">Usuários online (30 min)</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Cadastros</div>
                    <div class="stat-icon">
                        <i class="fas fa-home"></i>
                    </div>
                </div>
                <div class="stat-number"><?php echo $estatisticas['cadastros_habitacionais']; ?></div>
                <div class="stat-description">Cadastros habitacionais</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Modules Section -->
        <?php if (!empty($modulos_cards)): ?>
        <div class="modules-section">
            <h2 class="section-title">
                <i class="fas fa-th-large"></i>
                <?php echo $is_admin ? 'Módulos Disponíveis' : 'Suas Funcionalidades'; ?>
            </h2>
            
            <div class="modules-grid">
                <?php foreach ($modulos_cards as $key => $module): ?>
                <div class="module-card" style="--module-color: <?php echo $module['info']['color']; ?>">
                    <div class="module-header">
                        <div class="module-icon" style="background-color: <?php echo $module['info']['color']; ?>">
                            <i class="<?php echo $module['info']['icon']; ?>"></i>
                        </div>
                        <div class="module-info">
                            <h3><?php echo htmlspecialchars($module['info']['name']); ?></h3>
                            <p><?php echo htmlspecialchars($module['info']['description']); ?></p>
                        </div>
                    </div>
                    
                    <div class="module-actions">
                        <?php 
                        $mainFile = $module['files']['main'] ?? '#';
                        if ($module['menu']['parent'] && !empty($module['menu']['submenu'])) {
                            $firstSubmenu = reset($module['menu']['submenu']);
                            $mainFile = $firstSubmenu['files']['main'] ?? $mainFile;
                        }
                        ?>
                        <a href="<?php echo $mainFile; ?>" class="module-btn btn-primary">
                            <i class="fas fa-arrow-right"></i>
                            Acessar
                        </a>
                        <?php if ($module['menu']['parent'] && !empty($module['menu']['submenu'])): ?>
                        <button class="module-btn btn-outline" onclick="toggleModuleSubmenu('<?php echo $key; ?>')">
                            <i class="fas fa-list"></i>
                            Ver Opções
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Submenu expandível -->
                    <?php if ($module['menu']['parent'] && !empty($module['menu']['submenu'])): ?>
                    <div class="module-submenu" id="submenu-<?php echo $key; ?>" style="display: none;">
                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #f0f0f0;">
                            <h4 style="font-size: 0.9rem; color: #666; margin-bottom: 10px;">Funcionalidades:</h4>
                            <?php foreach ($module['menu']['submenu'] as $subKey => $subItem): ?>
                            <a href="<?php echo $subItem['files']['main'] ?? '#'; ?>" 
                               class="submenu-option" 
                               style="display: block; padding: 8px 0; color: <?php echo $module['info']['color']; ?>; text-decoration: none; font-size: 0.9rem; transition: all 0.3s;">
                                <i class="<?php echo $subItem['icon']; ?>" style="margin-right: 8px; width: 16px;"></i>
                                <?php echo htmlspecialchars($subItem['name']); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-exclamation-circle"></i>
            <h3>Acesso Restrito</h3>
            <p>Seu usuário não possui módulos configurados ou o departamento não foi encontrado.</p>
            <p>Entre em contato com o administrador do sistema para resolver esta questão.</p>
            <div class="quick-actions">
                <a href="perfil.php" class="quick-action">
                    <i class="fas fa-user-cog"></i>
                    Meu Perfil
                </a>
                <a href="../controller/logout_system.php" class="quick-action">
                    <i class="fas fa-sign-out-alt"></i>
                    Sair do Sistema
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Activity Section -->
        <?php if (!empty($atividades_recentes)): ?>
        <div class="modules-section">
            <h2 class="section-title">
                <i class="fas fa-clock"></i>
                Atividades Recentes
            </h2>
            
            <div class="activity-section">
                <?php foreach ($atividades_recentes as $atividade): ?>
                <div class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-title"><?php echo htmlspecialchars($atividade['acao']); ?></div>
                        <div class="activity-description"><?php echo htmlspecialchars($atividade['detalhes']); ?></div>
                    </div>
                    <div class="activity-time">
                        <?php echo date('d/m/Y H:i', strtotime($atividade['data_atividade'])); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Actions for Admins -->
        <?php if ($is_admin): ?>
        <div class="modules-section">
            <h2 class="section-title">
                <i class="fas fa-bolt"></i>
                Ações Rápidas
            </h2>
            
            <div class="quick-actions">
                <a href="adicionar_usuario.php" class="quick-action">
                    <i class="fas fa-user-plus"></i>
                    Novo Usuário
                </a>
                <a href="lista_usuarios.php" class="quick-action">
                    <i class="fas fa-users"></i>
                    Gerenciar Usuários
                </a>
                <a href="permissoes.php" class="quick-action">
                    <i class="fas fa-shield-alt"></i>
                    Permissões
                </a>
                <a href="#" onclick="generateSystemReport()" class="quick-action">
                    <i class="fas fa-chart-pie"></i>
                    Relatório Geral
                </a>
                <a href="#" onclick="showSystemInfo()" class="quick-action">
                    <i class="fas fa-info-circle"></i>
                    Info do Sistema
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- System Info for Admins -->
        <?php if ($is_admin): ?>
        <div class="modules-section">
            <h2 class="section-title">
                <i class="fas fa-server"></i>
                Informações do Sistema
            </h2>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                <div style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);">
                    <h4 style="color: var(--text-color); margin-bottom: 15px; display: flex; align-items: center;">
                        <i class="fas fa-code" style="margin-right: 10px; color: var(--secondary-color);"></i>
                        Versão do Sistema
                    </h4>
                    <p style="color: #666; margin-bottom: 8px;"><strong>Versão:</strong> 2.0.0</p>
                    <p style="color: #666; margin-bottom: 8px;"><strong>PHP:</strong> <?php echo PHP_VERSION; ?></p>
                    <p style="color: #666;"><strong>Última Atualização:</strong> <?php echo date('d/m/Y'); ?></p>
                </div>
                
                <div style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);">
                    <h4 style="color: var(--text-color); margin-bottom: 15px; display: flex; align-items: center;">
                        <i class="fas fa-database" style="margin-right: 10px; color: var(--secondary-color);"></i>
                        Banco de Dados
                    </h4>
                    <p style="color: #666; margin-bottom: 8px;"><strong>Status:</strong> 
                        <span style="color: #27ae60;">Conectado</span>
                    </p>
                    <p style="color: #666; margin-bottom: 8px;"><strong>Servidor:</strong> MySQL</p>
                    <p style="color: #666;"><strong>Última Verificação:</strong> <?php echo date('H:i:s'); ?></p>
                </div>
                
                <div style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);">
                    <h4 style="color: var(--text-color); margin-bottom: 15px; display: flex; align-items: center;">
                        <i class="fas fa-shield-alt" style="margin-right: 10px; color: var(--secondary-color);"></i>
                        Segurança
                    </h4>
                    <p style="color: #666; margin-bottom: 8px;"><strong>Sessões Ativas:</strong> <?php echo $estatisticas['usuarios_online']; ?></p>
                    <p style="color: #666; margin-bottom: 8px;"><strong>Último Login:</strong> 
                        <?php echo $usuario_dados['usuario_ultimo_acesso'] ? date('d/m/Y H:i', strtotime($usuario_dados['usuario_ultimo_acesso'])) : 'Primeiro acesso'; ?>
                    </p>
                    <p style="color: #666;"><strong>Nível de Acesso:</strong> Administrador</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal para Informações do Sistema -->
    <div id="systemInfoModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 600px; margin: 50px auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #eee;">
                <h3 style="color: var(--text-color);">
                    <i class="fas fa-info-circle" style="color: var(--secondary-color); margin-right: 10px;"></i>
                    Informações Detalhadas do Sistema
                </h3>
                <button onclick="closeModal('systemInfoModal')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #999;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="systemInfoContent">
                <!-- Conteúdo será carregado via JavaScript -->
            </div>
        </div>
    </div>

    <script>
        // Variáveis globais
        let sidebarCollapsed = false;

        // Inicialização
        document.addEventListener('DOMContentLoaded', function() {
            initializePage();
            
            // Adicionar estilos dinâmicos para submenu options
            const style = document.createElement('style');
            style.textContent = `
                .submenu-option:hover {
                    transform: translateX(5px);
                    opacity: 0.8;
                }
                
                .modal {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.7);
                    z-index: 1000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
            `;
            document.head.appendChild(style);
        });

        function initializePage() {
            // Toggle sidebar
            const toggleBtn = document.querySelector('.toggle-btn');
            const mobileToggle = document.querySelector('.mobile-toggle');
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            
            function toggleSidebar() {
                if (window.innerWidth <= 768) {
                    sidebar.classList.toggle('show');
                } else {
                    sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('expanded');
                    sidebarCollapsed = !sidebarCollapsed;
                }
            }
            
            if (toggleBtn) {
                toggleBtn.addEventListener('click', toggleSidebar);
            }
            
            if (mobileToggle) {
                mobileToggle.addEventListener('click', toggleSidebar);
            }

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

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 768) {
                    const isClickInsideSidebar = sidebar.contains(e.target);
                    const isToggleBtn = e.target.closest('.mobile-toggle') || e.target.closest('.toggle-btn');
                    
                    if (!isClickInsideSidebar && !isToggleBtn && sidebar.classList.contains('show')) {
                        sidebar.classList.remove('show');
                    }
                }
            });
            
            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768) {
                    sidebar.classList.remove('show');
                }
            });

            // Animate stats cards on scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);

            // Observe stat cards and module cards
            document.querySelectorAll('.stat-card, .module-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(30px)';
                card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                observer.observe(card);
            });
        }

        // Função para toggle do submenu dos módulos
        function toggleModuleSubmenu(moduleKey) {
            const submenu = document.getElementById('submenu-' + moduleKey);
            const isVisible = submenu.style.display !== 'none';
            
            // Fechar todos os outros submenus
            document.querySelectorAll('.module-submenu').forEach(menu => {
                if (menu !== submenu) {
                    menu.style.display = 'none';
                }
            });
            
            // Toggle do submenu atual
            submenu.style.display = isVisible ? 'none' : 'block';
            
            // Animar a abertura
            if (!isVisible) {
                submenu.style.opacity = '0';
                submenu.style.maxHeight = '0';
                setTimeout(() => {
                    submenu.style.opacity = '1';
                    submenu.style.maxHeight = '200px';
                    submenu.style.transition = 'opacity 0.3s ease, max-height 0.3s ease';
                }, 10);
            }
        }

        // Função para gerar relatório do sistema
        function generateSystemReport() {
            showNotification('Gerando relatório do sistema...', 'info');
            
            // Simular geração de relatório
            setTimeout(() => {
                showNotification('Relatório gerado com sucesso!', 'success');
                // Aqui você pode implementar a lógica real de geração de relatório
            }, 2000);
        }

        // Função para mostrar informações do sistema
        function showSystemInfo() {
            const modal = document.getElementById('systemInfoModal');
            const content = document.getElementById('systemInfoContent');
            
            content.innerHTML = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <h4 style="color: var(--secondary-color); margin-bottom: 15px;">
                            <i class="fas fa-server" style="margin-right: 8px;"></i>
                            Servidor
                        </h4>
                        <p><strong>PHP:</strong> ${getPhpVersion()}</p>
                        <p><strong>Sistema:</strong> ${navigator.platform}</p>
                        <p><strong>Navegador:</strong> ${getBrowserInfo()}</p>
                        <p><strong>Resolução:</strong> ${screen.width}x${screen.height}</p>
                    </div>
                    <div>
                        <h4 style="color: var(--secondary-color); margin-bottom: 15px;">
                            <i class="fas fa-chart-line" style="margin-right: 8px;"></i>
                            Performance
                        </h4>
                        <p><strong>Tempo de Carregamento:</strong> ${getPageLoadTime()}ms</p>
                        <p><strong>Memória Usada:</strong> ${getMemoryUsage()}</p>
                        <p><strong>Status da Conexão:</strong> <span style="color: #27ae60;">Online</span></p>
                        <p><strong>Última Sincronização:</strong> ${new Date().toLocaleString('pt-BR')}</p>
                    </div>
                </div>
                
                <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid #eee;">
                    <h4 style="color: var(--secondary-color); margin-bottom: 15px;">
                        <i class="fas fa-cogs" style="margin-right: 8px;"></i>
                        Configurações do Sistema
                    </h4>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                        <p><strong>Versão do Sistema:</strong> 2.0.0</p>
                        <p><strong>Módulos Ativos:</strong> ${document.querySelectorAll('.module-card').length}</p>
                        <p><strong>Última Atualização:</strong> <?php echo date('d/m/Y H:i'); ?></p>
                        <p><strong>Ambiente:</strong> Produção</p>
                    </div>
                </div>
            `;
            
            modal.style.display = 'flex';
        }

        // Função para fechar modal
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Funções auxiliares para informações do sistema
        function getPhpVersion() {
            return '<?php echo PHP_VERSION; ?>';
        }

        function getBrowserInfo() {
            const ua = navigator.userAgent;
            if (ua.includes('Chrome')) return 'Chrome';
            if (ua.includes('Firefox')) return 'Firefox';
            if (ua.includes('Safari')) return 'Safari';
            if (ua.includes('Edge')) return 'Edge';
            return 'Desconhecido';
        }

        function getPageLoadTime() {
            return Math.round(performance.now());
        }

        function getMemoryUsage() {
            if (performance.memory) {
                const used = Math.round(performance.memory.usedJSHeapSize / 1048576);
                return `${used} MB`;
            }
            return 'N/A';
        }

        // Sistema de notificações
        function showNotification(message, type = 'info') {
            // Remove notificações existentes
            const existingNotifications = document.querySelectorAll('.notification');
            existingNotifications.forEach(notification => notification.remove());

            // Cria nova notificação
            const notification = document.createElement('div');
            notification.className = 'notification';
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 8px;
                color: white;
                z-index: 9999;
                opacity: 0;
                transform: translateX(100%);
                transition: all 0.3s ease;
                max-width: 300px;
                font-weight: 500;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            `;

            // Define cor baseada no tipo
            const colors = {
                success: '#27ae60',
                error: '#e74c3c',
                warning: '#f39c12',
                info: '#17a2b8'
            };

            notification.style.backgroundColor = colors[type] || colors.info;
            notification.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span>${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" 
                            style="background: none; border: none; color: inherit; margin-left: 10px; cursor: pointer; font-size: 18px;">
                        ×
                    </button>
                </div>
            `;

            document.body.appendChild(notification);

            // Animar entrada
            setTimeout(() => {
                notification.style.opacity = '1';
                notification.style.transform = 'translateX(0)';
            }, 10);

            // Auto-remover após 5 segundos
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => notification.remove(), 300);
            }, 5000);
        }

        // Atalhos de teclado
        document.addEventListener('keydown', function(e) {
            // Ctrl+Shift+D para dashboard
            if (e.ctrlKey && e.shiftKey && e.key === 'D') {
                e.preventDefault();
                window.location.href = 'dashboard.php';
            }
            
            // Ctrl+Shift+U para usuários (apenas admin)
            if (e.ctrlKey && e.shiftKey && e.key === 'U') {
                e.preventDefault();
                <?php if ($is_admin): ?>
                window.location.href = 'lista_usuarios.php';
                <?php endif; ?>
            }
            
            // ESC para fechar modais
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.style.display = 'none';
                });
            }
        });

        // Atualizar status online a cada 5 minutos
        setInterval(function() {
            fetch('controller/update_online_status.php', {
                method: 'POST',
                credentials: 'same-origin'
            });
        }, 5 * 60 * 1000);

        // Verificar se há atualizações do sistema (apenas admin)
        <?php if ($is_admin): ?>
        setTimeout(function() {
            // Aqui você pode implementar verificação de atualizações
            // fetch('controller/check_updates.php')...
        }, 2000);
        <?php endif; ?>
    </script>
</body>
</html>
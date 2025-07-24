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

// Buscar informações do usuário logado
$usuario_id = $_SESSION['usersystem_id'];
$usuario_dados = [];

try {
    $stmt = $conn->prepare("
        SELECT 
            usuario_id,
            usuario_nome, 
            usuario_departamento, 
            usuario_nivel_id,
            usuario_email
        FROM tb_usuarios_sistema 
        WHERE usuario_id = :id
    ");
    $stmt->bindParam(':id', $usuario_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $usuario_dados = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        session_destroy();
        header("Location: ../acessdeniedrestrict.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar dados do usuário: " . $e->getMessage());
    header("Location: ../acessdeniedrestrict.php");
    exit;
}

// Verificar permissões de acesso ao módulo de esporte
$is_admin = ($usuario_dados['usuario_nivel_id'] == 1);
$usuario_departamento = strtoupper($usuario_dados['usuario_departamento'] ?? '');
$tem_permissao = $is_admin || $usuario_departamento === 'ESPORTE';

if (!$tem_permissao) {
    header("Location: dashboard.php?erro=acesso_negado");
    exit;
}

// Inicializar o MenuManager
$userSession = [
    'usuario_id' => $usuario_dados['usuario_id'],
    'usuario_nome' => $usuario_dados['usuario_nome'],
    'usuario_departamento' => $usuario_dados['usuario_departamento'],
    'usuario_nivel_id' => $usuario_dados['usuario_nivel_id'],
    'usuario_email' => $usuario_dados['usuario_email']
];

$menuManager = new MenuManager($userSession);
$themeColors = $menuManager->getThemeColors();

// Buscar estatísticas do módulo de esporte
$estatisticas = [
    'atletas_ativos' => 0,
    'campeonatos_ativos' => 0,
    'espacos_ativos' => 0,
    'modalidades_ativas' => 0,
    'eventos_mes' => 0,
    'competicoes_andamento' => 0
];

try {
    // Total de atletas ativos
    $stmt = $conn->query("SELECT COUNT(*) as total FROM tb_atletas WHERE atleta_status = 'ATIVO'");
    if ($stmt) {
        $result = $stmt->fetch();
        $estatisticas['atletas_ativos'] = $result['total'] ?? 0;
    }
    
    // Total de modalidades distintas
    $stmt = $conn->query("SELECT COUNT(DISTINCT atleta_modalidade_principal) as total FROM tb_atletas WHERE atleta_status = 'ATIVO'");
    if ($stmt) {
        $result = $stmt->fetch();
        $estatisticas['modalidades_ativas'] = $result['total'] ?? 0;
    }
    
    $stmt = $conn->query("SELECT COUNT(*) as total FROM tb_campeonatos");
    if ($stmt) {
        $result = $stmt->fetch();
        $estatisticas['campeonatos_ativos'] = $result['total'] ?? 0;
    }

    $stmt = $conn->query("SELECT COUNT(*) as total FROM tb_espacos_fisicos");
    if ($stmt) {
        $result = $stmt->fetch();
        $estatisticas['espacos_ativos'] = $result['total'] ?? 0;
    }

    // Verificar se existem outras tabelas e contar
    $tables_to_check = [
        'tb_eventos_esporte' => 'eventos_mes',
        'tb_competicoes' => 'competicoes_andamento'
    ];
    
    foreach ($tables_to_check as $table => $stat_key) {
        try {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM $table WHERE status = 'ATIVO'");
            if ($stmt) {
                $result = $stmt->fetch();
                $estatisticas[$stat_key] = $result['total'] ?? 0;
            }
        } catch (PDOException $e) {
            // Tabela não existe, manter valor 0
            $estatisticas[$stat_key] = 0;
        }
    }
    
} catch (PDOException $e) {
    error_log("Erro ao buscar estatísticas do esporte: " . $e->getMessage());
}

// Buscar atividades recentes (se existir tabela de logs)
$atividades_recentes = [];
try {
    $stmt = $conn->prepare("
        SELECT acao, detalhes, data_atividade 
        FROM tb_log_atividades 
        WHERE usuario_id = :usuario_id 
        AND (acao LIKE '%atleta%' OR acao LIKE '%esporte%')
        ORDER BY data_atividade DESC 
        LIMIT 5
    ");
    $stmt->bindParam(':usuario_id', $usuario_id);
    $stmt->execute();
    $atividades_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Tabela de logs não existe
    $atividades_recentes = [];
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Esporte - Sistema da Prefeitura</title>
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
            --secondary-color: #4caf50; /* Verde esporte */
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
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .sidebar-header h3 {
            color: var(--secondary-color);
            font-weight: 600;
            font-size: 1.2rem;
        }

        .toggle-btn {
            background: none;
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            padding: 5px;
            border-radius: 5px;
            transition: background-color 0.3s;
        }

        .toggle-btn:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .menu {
            list-style: none;
            padding: 0;
        }

        .menu-separator {
            height: 1px;
            background-color: rgba(255, 255, 255, 0.1);
            margin: 10px 20px;
        }

        .menu-category {
            color: #bdc3c7;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 10px 20px 5px;
        }

        .menu-item {
            margin: 2px 0;
        }

        .menu-link {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #ecf0f1;
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }

        .menu-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
        }

        .menu-link.active {
            background-color: var(--secondary-color);
            border-left-color: var(--secondary-color);
        }

        .menu-icon {
            width: 20px;
            margin-right: 15px;
            text-align: center;
        }

        .menu-text {
            flex: 1;
        }

        .arrow {
            transition: transform 0.3s;
        }

        .menu-item.open .arrow {
            transform: rotate(90deg);
        }

        .submenu {
            list-style: none;
            padding: 0;
            background-color: rgba(0, 0, 0, 0.2);
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .menu-item.open .submenu {
            max-height: 500px;
        }

        .submenu-link {
            display: block;
            padding: 10px 20px 10px 55px;
            color: #bdc3c7;
            text-decoration: none;
            transition: all 0.3s;
            font-size: 0.9rem;
        }

        .submenu-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .submenu-link.active {
            background-color: var(--secondary-color);
            color: white;
        }

        /* Main content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .header {
            background-color: white;
            padding: 0 30px;
            height: var(--header-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header h2 {
            color: var(--primary-color);
            font-weight: 600;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: var(--primary-color);
        }

        .user-role {
            font-size: 0.8rem;
            color: #7f8c8d;
        }

        .admin-badge {
            background-color: var(--danger-color);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .department-badge {
            background-color: var(--secondary-color);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Page content */
        .page-content {
            flex: 1;
            padding: 30px;
        }

        .page-title {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-title i {
            color: var(--secondary-color);
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 30px;
            font-size: 0.9rem;
            color: #7f8c8d;
        }

        .breadcrumb a {
            color: var(--secondary-color);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        /* Welcome section */
        .welcome-section {
            background: linear-gradient(135deg, var(--secondary-color), #66bb6a);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }

        .welcome-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="2" fill="rgba(255,255,255,0.1)"/></svg>') repeat;
            animation: float 20s infinite linear;
        }

        @keyframes float {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }

        .welcome-content {
            position: relative;
            z-index: 1;
        }

        .welcome-content h2 {
            font-size: 2.2rem;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .welcome-content p {
            font-size: 1.1rem;
            opacity: 0.9;
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
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            background: linear-gradient(135deg, var(--secondary-color), #66bb6a);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-info {
            flex: 1;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .stat-label {
            color: #7f8c8d;
            font-weight: 500;
        }

        /* Module cards */
        .modules-section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 1.5rem;
            color: var(--primary-color);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .module-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .module-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .module-header {
            padding: 25px;
            background: linear-gradient(135deg, var(--secondary-color), #66bb6a);
            color: white;
        }

        .module-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .module-description {
            opacity: 0.9;
            line-height: 1.5;
        }

        .module-body {
            padding: 25px;
        }

        .module-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .btn-primary {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #45a049;
            transform: translateY(-2px);
        }

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background-color: #219a52;
            transform: translateY(-2px);
        }

        .btn-info {
            background-color: var(--info-color);
            color: white;
        }

        .btn-info:hover {
            background-color: #138496;
            transform: translateY(-2px);
        }

        .btn-warning {
            background-color: var(--warning-color);
            color: white;
        }

        .btn-warning:hover {
            background-color: #d68910;
            transform: translateY(-2px);
        }

        /* Recent activity */
        .activity-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #ecf0f1;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background-color: var(--secondary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .activity-info {
            flex: 1;
        }

        .activity-action {
            font-weight: 600;
            color: var(--primary-color);
        }

        .activity-details {
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .activity-time {
            color: #95a5a6;
            font-size: 0.8rem;
        }

        /* Mobile toggle */
        .mobile-toggle {
            display: none;
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
                padding: 0 20px;
            }

            .page-content {
                padding: 20px;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }

            .modules-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3><?php echo $themeColors['title'] ?? 'Sistema Esporte'; ?></h3>
            <button class="toggle-btn">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        
        <?php echo $menuManager->generateSidebar('esporte.php'); ?>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="header">
            <div>
                <button class="mobile-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Módulo Esporte</h2>
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
                            <i class="fas fa-running"></i> Esporte
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="page-content">
            <h1 class="page-title">
                <i class="fas fa-running"></i>
                Dashboard - Esporte
            </h1>

            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <span>Esporte</span>
            </div>

            <!-- Welcome Section -->
            <div class="welcome-section">
                <div class="welcome-content">
                    <h2>Bem-vindo ao Módulo de Esporte!</h2>
                    <p>Gerencie atletas, modalidades, campeonatos e todas as atividades esportivas do município.</p>
                </div>
            </div>

            <!-- Estatísticas -->
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo number_format($estatisticas['atletas_ativos']); ?></div>
                        <div class="stat-label">Atletas Ativos</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo number_format($estatisticas['campeonatos_ativos']); ?></div>
                        <div class="stat-label">Campeonatos</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo number_format($estatisticas['espacos_ativos']); ?></div>
                        <div class="stat-label">Espaços Esportivos</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-futbol"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo number_format($estatisticas['modalidades_ativas']); ?></div>
                        <div class="stat-label">Modalidades</div>
                    </div>
                </div>
            </div>

            <!-- Módulos de Funcionalidades -->
            <div class="modules-section">
                <h2 class="section-title">
                    <i class="fas fa-th-large"></i>
                    Funcionalidades Disponíveis
                </h2>
                
                <div class="modules-grid">
                    <!-- Card Atletas -->
                    <div class="module-card">
                        <div class="module-header">
                            <div class="module-title">
                                <i class="fas fa-user-friends"></i>
                                Gerenciar Atletas
                            </div>
                            <div class="module-description">
                                Cadastre e gerencie informações dos atletas, suas modalidades, categorias e status de participação.
                            </div>
                        </div>
                        <div class="module-body">
                            <div class="module-actions">
                                <a href="esporte_atletas.php" class="btn btn-primary">
                                    <i class="fas fa-list"></i> Listar Atletas
                                </a>
                                <a href="esporte_atletas.php?acao=adicionar" class="btn btn-success">
                                    <i class="fas fa-plus"></i> Novo Atleta
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Card Campeonatos -->
                    <div class="module-card">
                        <div class="module-header">
                            <div class="module-title">
                                <i class="fas fa-trophy"></i>
                                Campeonatos
                            </div>
                            <div class="module-description">
                                Organize e gerencie campeonatos, torneios e competições esportivas do município de Santa Izabel.
                            </div>
                        </div>
                        <div class="module-body">
                            <div class="module-actions">
                                <a href="esporte_campeonatos.php" class="btn btn-primary">
                                    <i class="fas fa-list"></i> Listar
                                </a>
                                <a href="esporte_campeonatos.php?acao=adicionar" class="btn btn-success">
                                    <i class="fas fa-plus"></i> Cadastrar
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Card Espaços Físicos -->
                    <div class="module-card">
                        <div class="module-header">
                            <div class="module-title">
                                <i class="fas fa-map-marker-alt"></i>
                                Espaços Esportivos
                            </div>
                            <div class="module-description">
                                Controle e mantenha informações sobre ginásios, campos, quadras e outros espaços esportivos.
                            </div>
                        </div>
                        <div class="module-body">
                            <div class="module-actions">
                                <a href="esporte_espacos.php" class="btn btn-primary">
                                    <i class="fas fa-list"></i> Ver Espaços
                                </a>
                                <a href="esporte_espacos.php?acao=adicionar" class="btn btn-success">
                                    <i class="fas fa-plus"></i> Novo Espaço
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Card Equipe -->
                    <div class="module-card">
                        <div class="module-header">
                            <div class="module-title">
                                <i class="fas fa-users-cog"></i>
                                Equipe de Esportes
                            </div>
                            <div class="module-description">
                                Gerencie a equipe responsável pelas atividades esportivas e suas atribuições, cadastro de equipes para campeonatos.
                            </div>
                        </div>
                        <div class="module-body">
                            <div class="module-actions">
                                <a href="esporte_equipe.php" class="btn btn-primary">
                                    <i class="fas fa-list"></i> Ver Equipe
                                </a>
                                <a href="esporte_relatorios.php" class="btn btn-info">
                                    <i class="fas fa-chart-bar"></i> Relatórios
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Atividades Recentes -->
            <?php if (!empty($atividades_recentes)): ?>
            <div class="modules-section">
                <h2 class="section-title">
                    <i class="fas fa-clock"></i>
                    Atividades Recentes
                </h2>
                
                <div class="activity-card">
                    <?php foreach ($atividades_recentes as $atividade): ?>
                    <div class="activity-item">
                        <div class="activity-icon">
                            <i class="fas fa-running"></i>
                        </div>
                        <div class="activity-info">
                           <div class="activity-action"><?php echo htmlspecialchars($atividade['acao']); ?></div>
                           <div class="activity-details"><?php echo htmlspecialchars($atividade['detalhes']); ?></div>
                       </div>
                       <div class="activity-time">
                           <?php 
                           $data = new DateTime($atividade['data_atividade']);
                           echo $data->format('d/m/Y H:i');
                           ?>
                       </div>
                   </div>
                   <?php endforeach; ?>
               </div>
           </div>
           <?php endif; ?>
       </div>
   </div>

   <script>
       // Toggle sidebar para mobile
       document.addEventListener('DOMContentLoaded', function() {
           const sidebar = document.getElementById('sidebar');
           const mobileToggle = document.querySelector('.mobile-toggle');
           const toggleBtn = document.querySelector('.toggle-btn');

           // Toggle para mobile
           if (mobileToggle) {
               mobileToggle.addEventListener('click', function() {
                   sidebar.classList.toggle('show');
               });
           }

           // Toggle para desktop
           if (toggleBtn) {
               toggleBtn.addEventListener('click', function() {
                   sidebar.classList.toggle('collapsed');
               });
           }

           // Fechar sidebar ao clicar fora (mobile)
           document.addEventListener('click', function(e) {
               if (window.innerWidth <= 768) {
                   if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                       sidebar.classList.remove('show');
                   }
               }
           });

           // Submenu toggle
           const menuItems = document.querySelectorAll('.menu-link');
           menuItems.forEach(item => {
               item.addEventListener('click', function(e) {
                   const parentItem = this.closest('.menu-item');
                   const submenu = parentItem.querySelector('.submenu');
                   
                   if (submenu) {
                       e.preventDefault();
                       parentItem.classList.toggle('open');
                   }
               });
           });

           // Highlight active menu based on current page
           const currentPage = window.location.pathname.split('/').pop();
           const menuLinks = document.querySelectorAll('.menu-link, .submenu-link');
           
           menuLinks.forEach(link => {
               const href = link.getAttribute('href');
               if (href && href.includes(currentPage)) {
                   link.classList.add('active');
                   
                   // Open parent menu if it's a submenu item
                   const parentMenuItem = link.closest('.menu-item');
                   if (parentMenuItem && parentMenuItem.querySelector('.submenu')) {
                       parentMenuItem.classList.add('open');
                   }
               }
           });

           // Animação dos cards de estatísticas
           const statCards = document.querySelectorAll('.stat-card');
           const observer = new IntersectionObserver((entries) => {
               entries.forEach(entry => {
                   if (entry.isIntersecting) {
                       entry.target.style.opacity = '1';
                       entry.target.style.transform = 'translateY(0)';
                   }
               });
           });

           statCards.forEach(card => {
               card.style.opacity = '0';
               card.style.transform = 'translateY(20px)';
               card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
               observer.observe(card);
           });

           // Contador animado para as estatísticas
           const animateCounters = () => {
               const counters = document.querySelectorAll('.stat-number');
               
               counters.forEach(counter => {
                   const target = parseInt(counter.textContent.replace(/[,.]/g, ''));
                   const increment = target / 100;
                   let current = 0;
                   
                   const timer = setInterval(() => {
                       current += increment;
                       if (current >= target) {
                           current = target;
                           clearInterval(timer);
                       }
                       counter.textContent = Math.floor(current).toLocaleString('pt-BR');
                   }, 20);
               });
           };

           // Iniciar animação dos contadores após um delay
           setTimeout(animateCounters, 500);
       });

       // Função para atualizar estatísticas via AJAX (opcional)
       function atualizarEstatisticas() {
           fetch('ajax/obter_estatisticas_esporte.php')
               .then(response => response.json())
               .then(data => {
                   if (data.success) {
                       document.querySelector('.stat-card:nth-child(1) .stat-number').textContent = 
                           data.atletas_ativos.toLocaleString('pt-BR');
                       document.querySelector('.stat-card:nth-child(2) .stat-number').textContent = 
                           data.campeonatos_ativos.toLocaleString('pt-BR');
                       document.querySelector('.stat-card:nth-child(3) .stat-number').textContent = 
                           data.espacos_ativos.toLocaleString('pt-BR');
                       document.querySelector('.stat-card:nth-child(4) .stat-number').textContent = 
                           data.modalidades_ativas.toLocaleString('pt-BR');
                   }
               })
               .catch(error => {
                   console.error('Erro ao atualizar estatísticas:', error);
               });
       }

       // Atualizar estatísticas a cada 5 minutos
       setInterval(atualizarEstatisticas, 300000);
   </script>
</body>
</html>
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
    <link rel="icon" href="../../img/logo_eai.ico" type="imagem/x-icon">
    <title>Dashboard - Sistema da Prefeitura</title>
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
        /* Sidebar styles com menu branco */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--bg-secondary); /* Branco */
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
            color: var(--text-color); /* Texto escuro */
            line-height: 1.2;
        }

        .toggle-btn {
            background: none;
            border: none;
            color: var(--text-color); /* Ícone escuro */
            font-size: 20px;
            cursor: pointer;
            padding: 5px;
            border-radius: 4px;
            transition: var(--transition);
        }

        .toggle-btn:hover {
            background-color: var(--bg-tertiary);
        }

        /* Menu Styles com fundo branco */
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
            color: var(--text-color); /* Texto escuro */
            text-decoration: none;
            border-radius: var(--radius);
            transition: var(--transition);
            font-weight: 500;
            font-size: 0.875rem;
        }

        .menu-link:hover,
        .menu-link.active {
            background: var(--secondary-color); /* Azul no hover/active */
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

        /* Submenu com fundo branco */
        .submenu {
            list-style: none;
            background: var(--bg-tertiary); /* Cinza claro */
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
            color: var(--text-secondary); /* Texto cinza */
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.8125rem;
        }

        .submenu-link:hover,
        .submenu-link.active {
            color: var(--secondary-color);
            background: rgba(52, 152, 219, 0.1); /* Azul claro no hover */
        }

        .menu-category {
            padding: 1rem 1rem 0.5rem;
            color: var(--text-muted); /* Texto cinza claro */
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

        /* Page title */
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
            background: var(--secondary-color);
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
            font-weight: 900;
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
            padding: 0;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
            position: relative;
            overflow: visible;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            min-height: 200px;
            z-index: 1; /* Z-index base */
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

        .module-card.submenu-open {
            z-index: 10; /* Fica por cima dos outros cards */
        }

        .module-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding: 25px 25px 0 25px;
        }

        .module-info {
            flex: 1;
            padding: 0 25px;
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

        .module-submenu {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fafafa;
            border: 1px solid #f0f0f0;
            border-top: none;
            border-radius: 0 0 12px 12px;
            padding: 15px 25px 20px 25px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15); /* Sombra mais forte */
            z-index: 15; /* Z-index bem alto */
            display: none;
        }

        .module-submenu h4 {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .submenu-option {
            display: block;
            padding: 8px 0;
            color: var(--secondary-color);
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s;
            border-radius: 4px;
            padding-left: 8px;
            margin-bottom: 2px;
        }

        .submenu-option:hover {
            transform: translateX(5px);
            background: rgba(52, 152, 219, 0.1);
            padding-left: 12px;
        }

        .submenu-option i {
            margin-right: 8px;
            width: 16px;
            text-align: center;
        }

        .module-actions {
            padding: 0 25px 25px 25px;
            display: flex;
            gap: 10px;
            justify-content: center;
            position: relative;
            z-index: 2;
            background: white;
            margin-top: 20px;
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
            text-align: right;
            background: transparent;
        }

        .btn-outline:hover {
            background-color: var(--secondary-color);
            color: white;
        }

        .module-card.expanded {
            min-height: auto; /* Permite crescer conforme necessário */
            height: auto;
        }

        .module-card.expanded .module-submenu {
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .module-submenu[style*="block"] {
            animation: slideDown 0.3s ease;
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

        /* Responsive */
        @media (max-width: 1024px) {
            .header-search {
                max-width: 400px;
                margin: 0 1rem;
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .modules-grid {
                grid-template-columns: 1fr;
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

        @media (max-width: 480px) {
            .header {
                padding: 0 0.75rem;
            }
            .main-content {
               padding: 1rem;
           }

           .welcome-section {
               padding: 1.5rem;
           }

           .welcome-text h2 {
               font-size: 1.5rem;
           }

           .module-header {
               padding: 1rem;
               flex-direction: column;
               text-align: center;
               gap: 1rem;
           }

           .module-actions {
               padding: 0 1rem 1rem;
               justify-content: center;
           }

           .stats-container {
               grid-template-columns: 1fr;
           }

           .quick-actions {
               flex-direction: column;
           }
       }

       /* System Info */
       .system-info-grid {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
           gap: 1.5rem;
       }

       .system-info-card {
           background: var(--bg-secondary);
           border: 1px solid var(--border-color);
           border-radius: var(--radius);
           padding: 1.5rem;
       }

       .system-info-card h4 {
           color: var(--text-color);
           margin-bottom: 1rem;
           display: flex;
           align-items: center;
           gap: 0.75rem;
           font-size: 1rem;
           font-weight: 600;
       }

       .system-info-card h4 i {
           color: var(--secondary-color);
       }

       .system-info-card p {
           color: var(--text-secondary);
           margin-bottom: 0.75rem;
           font-size: 0.875rem;
       }

       .system-info-card strong {
           color: var(--text-color);
           font-weight: 600;
       }

       .status-online {
           color: #059669;
           font-weight: 600;
       }

       /* Notification Badge */
       .notification-badge {
           position: absolute;
           top: -4px;
           right: -4px;
           background: #dc2626;
           color: white;
           border-radius: 50%;
           width: 18px;
           height: 18px;
           font-size: 0.6875rem;
           font-weight: 600;
           display: flex;
           align-items: center;
           justify-content: center;
           border: 2px solid var(--bg-secondary);
       }

       /* Modal para Informações do Sistema */
       .modal {
           position: fixed;
           top: 0;
           left: 0;
           width: 100%;
           height: 100%;
           background: rgba(0, 0, 0, 0.7);
           z-index: 1000;
           display: none;
           align-items: center;
           justify-content: center;
       }

       .modal-content {
           max-width: 600px;
           margin: 50px auto;
           background: white;
           padding: 30px;
           border-radius: 12px;
           box-shadow: 0 20px 40px rgba(0,0,0,0.3);
       }

       .modal-header {
           display: flex;
           justify-content: space-between;
           align-items: center;
           margin-bottom: 20px;
           padding-bottom: 15px;
           border-bottom: 1px solid #eee;
       }

       .modal-header h3 {
           color: var(--text-color);
       }

       .modal-header button {
           background: none;
           border: none;
           font-size: 1.5rem;
           cursor: pointer;
           color: #999;
       }

       /* Loading State */
       .loading {
           display: inline-block;
           width: 20px;
           height: 20px;
           border: 2px solid var(--border-color);
           border-radius: 50%;
           border-top-color: var(--secondary-color);
           animation: spin 1s ease-in-out infinite;
       }

       @keyframes spin {
           to { transform: rotate(360deg); }
       }

       /* Utility Classes */
       .text-center { text-align: center; }
       .font-bold { font-weight: 600; }
       .text-sm { font-size: 0.875rem; }
       .text-xs { font-size: 0.75rem; }
       .mb-4 { margin-bottom: 1rem; }
       .mt-4 { margin-top: 1rem; }

       /* Sidebar minimizada - apenas uma pequena caixa no topo */
        .sidebar.collapsed {
            width: 60px; /* Largura menor para a caixinha */
        }

        .sidebar.collapsed .menu-text,
        .sidebar.collapsed .sidebar-header h3,
        .sidebar.collapsed .menu,
        .sidebar.collapsed .menu-separator,
        .sidebar.collapsed .menu-category {
            display: none; /* Esconder todo o conteúdo do menu */
        }

        /* Header da sidebar quando colapsada - vira uma pequena caixa */
        .sidebar.collapsed .sidebar-header {
            padding: 15px 10px;
            height: 60px;
            border-bottom: 1px solid var(--border-color);
            justify-content: center;
        }

        /* Botão toggle centralizado na caixinha */
        .sidebar.collapsed .toggle-btn {
            margin: 0;
            padding: 8px;
            border-radius: 6px;
            background-color: var(--bg-tertiary);
        }

        .sidebar.collapsed .toggle-btn:hover {
            background-color: var(--secondary-color);
            color: white;
        }

        /* Tooltip para mostrar informação quando minimizado */
        .sidebar.collapsed .toggle-btn::after {
            content: "Abrir Menu";
            position: absolute;
            left: 70px;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .sidebar.collapsed .toggle-btn:hover::after {
            opacity: 1;
            visibility: visible;
        }

        /* Ajustar o conteúdo principal quando sidebar está colapsada */
        .main-content.expanded {
            margin-left: 60px; /* Ajustar para a nova largura */
        }

        /* Animação suave para a transição */
        .sidebar {
            transition: width 0.3s ease, box-shadow 0.3s ease;
        }

        .sidebar.collapsed {
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.15); /* Sombra mais destacada na caixinha */
        }

        /* Responsivo - no mobile, esconder completamente */
        @media (max-width: 768px) {
            .sidebar.collapsed {
                transform: translateX(-100%);
                width: var(--sidebar-width);
            }
            
            .main-content.expanded {
                margin-left: 0;
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
       
       <?php echo $menuManager->generateSidebar('dashboard.php'); ?>
   </div>

   <!-- Main Content -->
   <div class="main-content" id="mainContent">
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
                       <i class="fas fa-wifi"></i>
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
           
           <div class="system-info-grid">
               <div class="system-info-card">
                   <h4>
                       <i class="fas fa-code"></i>
                       Versão do Sistema
                   </h4>
                   <p><strong>Versão:</strong> 2.0.0</p>
                   <p><strong>PHP:</strong> <?php echo PHP_VERSION; ?></p>
                   <p><strong>Última Atualização:</strong> <?php echo date('d/m/Y'); ?></p>
               </div>
               
               <div class="system-info-card">
                   <h4>
                       <i class="fas fa-database"></i>
                       Banco de Dados
                   </h4>
                   <p><strong>Status:</strong> 
                       <span class="status-online">Conectado</span>
                   </p>
                   <p><strong>Servidor:</strong> MySQL</p>
                   <p><strong>Última Verificação:</strong> <?php echo date('H:i:s'); ?></p>
               </div>
               
               <div class="system-info-card">
                   <h4>
                       <i class="fas fa-shield-alt"></i>
                       Segurança
                   </h4>
                   <p><strong>Sessões Ativas:</strong> <?php echo $estatisticas['usuarios_online']; ?></p>
                   <p><strong>Último Login:</strong> 
                       <?php echo $usuario_dados['usuario_ultimo_acesso'] ? date('d/m/Y H:i', strtotime($usuario_dados['usuario_ultimo_acesso'])) : 'Primeiro acesso'; ?>
                   </p>
                   <p><strong>Nível de Acesso:</strong> Administrador</p>
               </div>
           </div>
       </div>
       <?php endif; ?>
   </div>

   <!-- Modal para Informações do Sistema -->
   <div id="systemInfoModal" class="modal">
       <div class="modal-content">
           <div class="modal-header">
               <h3>
                   <i class="fas fa-info-circle" style="color: var(--secondary-color); margin-right: 10px;"></i>
                   Informações Detalhadas do Sistema
               </h3>
               <button onclick="closeModal('systemInfoModal')">
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
           
           // Toggle sidebar
           const toggleBtn = document.querySelector('.toggle-btn');
           const sidebar = document.querySelector('.sidebar');
           const mainContent = document.querySelector('.main-content');
           
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

       function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const toggleBtn = document.querySelector('.toggle-btn');
            
            if (window.innerWidth <= 768) {
                // No mobile, comportamento normal (mostrar/esconder)
                sidebar.classList.toggle('show');
            } else {
                // No desktop, colapsar para caixinha
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                sidebarCollapsed = !sidebarCollapsed;
                
                // Mudar ícone do botão
                const icon = toggleBtn.querySelector('i');
                if (sidebar.classList.contains('collapsed')) {
                    icon.className = 'fas fa-bars'; // Ícone para expandir
                } else {
                    icon.className = 'fas fa-times'; // Ícone para colapsar
                }
                
                // Salvar estado se tiver a função
                if (typeof saveSidebarState === 'function') {
                    saveSidebarState();
                }
            }
        }

        // Função para expandir automaticamente no hover (opcional)
        function addHoverExpansion() {
            const sidebar = document.querySelector('.sidebar');
            let hoverTimeout;
            
            sidebar.addEventListener('mouseenter', function() {
                if (this.classList.contains('collapsed') && window.innerWidth > 768) {
                    hoverTimeout = setTimeout(() => {
                        this.style.width = '280px';
                        this.style.overflow = 'visible';
                        
                        // Mostrar conteúdo temporariamente
                        const hiddenElements = this.querySelectorAll('.menu-text, .sidebar-header h3, .menu, .menu-separator, .menu-category');
                        hiddenElements.forEach(el => {
                            el.style.display = el.tagName === 'UL' ? 'block' : 
                                            el.classList.contains('menu-text') ? 'inline' : 
                                            'block';
                        });
                    }, 200);
                }
            });
            
            sidebar.addEventListener('mouseleave', function() {
                clearTimeout(hoverTimeout);
                if (this.classList.contains('collapsed') && window.innerWidth > 768) {
                    this.style.width = '60px';
                    this.style.overflow = 'hidden';
                    
                    // Esconder conteúdo novamente
                    const hiddenElements = this.querySelectorAll('.menu-text, .sidebar-header h3, .menu, .menu-separator, .menu-category');
                    hiddenElements.forEach(el => {
                        el.style.display = 'none';
                    });
                }
            });
        }

        // Inicializar a expansão no hover (chame essa função após carregar a página)
        document.addEventListener('DOMContentLoaded', function() {
            addHoverExpansion();
        });

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
                   // Filtrar módulos
                   const moduleCards = document.querySelectorAll('.module-card');
                   moduleCards.forEach(card => {
                       const title = card.querySelector('h3').textContent.toLowerCase();
                       const description = card.querySelector('p').textContent.toLowerCase();
                       const isVisible = title.includes(query) || description.includes(query);
                       
                       card.style.display = isVisible ? 'block' : 'none';
                   });
               } else {
                   // Mostrar todos os módulos
                   document.querySelectorAll('.module-card').forEach(card => {
                       card.style.display = 'block';
                   });
               }
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
        }

       // Função para gerar relatório do sistema
       function generateSystemReport() {
           showNotification('Gerando relatório do sistema...', 'info');
           
           // Simular geração de relatório
           setTimeout(() => {
               showNotification('Relatório gerado com sucesso!', 'success');
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
           // Ctrl+K para focar na pesquisa
           if (e.ctrlKey && e.key === 'k') {
               e.preventDefault();
               document.getElementById('globalSearch').focus();
           }
           
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
           
           // ESC para fechar modais e menus
           if (e.key === 'Escape') {
               // Fechar modais
               document.querySelectorAll('.modal').forEach(modal => {
                   modal.style.display = 'none';
               });
               
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

       // Atualizar status online a cada 5 minutos
       setInterval(function() {
           fetch('controller/update_online_status.php', {
               method: 'POST',
               credentials: 'same-origin'
           }).catch(function(error) {
               console.log('Erro ao atualizar status online:', error);
           });
       }, 5 * 60 * 1000);

       // Verificar se há atualizações do sistema (apenas admin)
       <?php if ($is_admin): ?>
       setTimeout(function() {
           // Aqui você pode implementar verificação de atualizações
           // fetch('controller/check_updates.php')...
       }, 2000);
       <?php endif; ?>

       // Função para navegação
       function navigateTo(url) {
           window.location.href = url;
       }

       // Log de inicialização
       console.log('Dashboard carregado com sucesso!');
       console.log('Usuário:', '<?php echo htmlspecialchars($usuario_dados['usuario_nome']); ?>');
       console.log('Departamento:', '<?php echo htmlspecialchars($usuario_dados['usuario_departamento']); ?>');
       console.log('Nível:', '<?php echo $usuario_dados['usuario_nivel_id']; ?>');
       
       // Performance monitoring
       window.addEventListener('load', function() {
           const loadTime = performance.now();
           console.log(`Página carregada em ${Math.round(loadTime)}ms`);
           
           // Se o tempo de carregamento for muito alto, mostrar aviso
           if (loadTime > 3000) {
               setTimeout(() => {
                   showNotification('A página demorou mais que o esperado para carregar. Verifique sua conexão.', 'warning');
               }, 1000);
           }
       });

       // Auto-save de preferências do usuário
       function saveUserPreference(key, value) {
           try {
               const preferences = JSON.parse(localStorage.getItem('userPreferences') || '{}');
               preferences[key] = value;
               localStorage.setItem('userPreferences', JSON.stringify(preferences));
           } catch (error) {
               console.log('Erro ao salvar preferência:', error);
           }
       }

       function getUserPreference(key, defaultValue = null) {
           try {
               const preferences = JSON.parse(localStorage.getItem('userPreferences') || '{}');
               return preferences[key] || defaultValue;
           } catch (error) {
               console.log('Erro ao obter preferência:', error);
               return defaultValue;
           }
       }

       // Aplicar preferências salvas
       function applyUserPreferences() {
           // Aplicar estado do sidebar
           const sidebarState = getUserPreference('sidebarCollapsed', false);
           if (sidebarState && window.innerWidth > 768) {
               const sidebar = document.querySelector('.sidebar');
               const mainContent = document.querySelector('.main-content');
               sidebar.classList.add('collapsed');
               mainContent.classList.add('expanded');
               sidebarCollapsed = true;
           }
       }

       // Salvar estado do sidebar quando mudado
       function saveSidebarState() {
           saveUserPreference('sidebarCollapsed', sidebarCollapsed);
       }

       // Aplicar preferências na inicialização
       document.addEventListener('DOMContentLoaded', function() {
           setTimeout(applyUserPreferences, 100);
       });

       // Salvar estado quando sidebar é toggleado
       const originalToggleSidebar = toggleSidebar;
       toggleSidebar = function() {
           originalToggleSidebar();
           if (window.innerWidth > 768) {
               saveSidebarState();
           }
       };

       // Adicionar tooltips para melhor UX
       function addTooltips() {
           const elements = document.querySelectorAll('[title]');
           elements.forEach(element => {
               element.addEventListener('mouseenter', function(e) {
                   const tooltip = document.createElement('div');
                   tooltip.className = 'tooltip';
                   tooltip.textContent = this.getAttribute('title');
                   tooltip.style.cssText = `
                       position: absolute;
                       background: rgba(0, 0, 0, 0.8);
                       color: white;
                       padding: 5px 10px;
                       border-radius: 4px;
                       font-size: 0.8rem;
                       white-space: nowrap;
                       z-index: 10000;
                       pointer-events: none;
                       opacity: 0;
                       transition: opacity 0.2s;
                   `;
                   
                   document.body.appendChild(tooltip);
                   
                   const rect = this.getBoundingClientRect();
                   tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
                   tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';
                   
                   setTimeout(() => tooltip.style.opacity = '1', 10);
                   
                   this.addEventListener('mouseleave', function() {
                       tooltip.remove();
                   }, { once: true });
               });
           });
       }

       // Inicializar tooltips
       setTimeout(addTooltips, 500);

       // Debug mode para desenvolvimento
       <?php if (isset($_GET['debug']) && $_GET['debug'] === 'true'): ?>
       console.log('=== DEBUG MODE ATIVO ===');
       console.log('Dados do usuário:', <?php echo json_encode($usuario_dados); ?>);
       console.log('Módulos disponíveis:', <?php echo json_encode($modulos_cards); ?>);
       console.log('Estatísticas:', <?php echo json_encode($estatisticas); ?>);
       console.log('Cores do tema:', <?php echo json_encode($themeColors); ?>);
       
       // Adicionar indicador visual de debug
       const debugIndicator = document.createElement('div');
       debugIndicator.innerHTML = '🐛 DEBUG';
       debugIndicator.style.cssText = `
           position: fixed;
           top: 10px;
           left: 10px;
           background: #ff6b6b;
           color: white;
           padding: 5px 10px;
           border-radius: 15px;
           font-size: 0.7rem;
           font-weight: bold;
           z-index: 9999;
           animation: pulse 2s infinite;
       `;
       document.body.appendChild(debugIndicator);
       
       // Adicionar animação de pulse
       const style = document.createElement('style');
       style.textContent = `
           @keyframes pulse {
               0% { opacity: 1; }
               50% { opacity: 0.5; }
               100% { opacity: 1; }
           }
       `;
       document.head.appendChild(style);
       <?php endif; ?>
   </script>
</body>
</html>
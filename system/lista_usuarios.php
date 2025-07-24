<?php
// Inicia a sessão
session_start();

if (!isset($_SESSION['usersystem_logado'])) {
    header("Location: ../acessdeniedrestrict.php"); 
    exit;
}

// Incluir arquivo de configuração com conexão ao banco de dados
require_once "../lib/config.php";

// Buscar informações do usuário logado
$usuario_id = $_SESSION['usersystem_id'];
$usuario_nome = $_SESSION['usersystem_nome'] ?? 'Usuário';
$usuario_departamento = null;
$usuario_nivel_id = null;
$is_admin = false;

try {
    $stmt = $conn->prepare("SELECT usuario_nome, usuario_departamento, usuario_nivel_id FROM tb_usuarios_sistema WHERE usuario_id = :id");
    $stmt->bindParam(':id', $usuario_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        $usuario_nome = $usuario['usuario_nome'];
        $usuario_departamento = strtoupper($usuario['usuario_departamento']);
        $usuario_nivel_id = $usuario['usuario_nivel_id'];
        
        // Verificar se é administrador
        $is_admin = ($usuario_nivel_id == 1);
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar dados do usuário: " . $e->getMessage());
}

// Verificar se o usuário tem permissão para acessar esta página
if (!$is_admin) {
    header("Location: dashboard.php");
    exit;
}

// Parâmetros de paginação e filtros
$pagina_atual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$registros_por_pagina = 10;
$offset = ($pagina_atual - 1) * $registros_por_pagina;

$filtro_nome = isset($_GET['nome']) ? trim($_GET['nome']) : '';
$filtro_departamento = isset($_GET['departamento']) ? trim($_GET['departamento']) : '';
$filtro_status = isset($_GET['status']) ? trim($_GET['status']) : '';

// Construir query de busca
$where_conditions = [];
$params = [];

if (!empty($filtro_nome)) {
    $where_conditions[] = "usuario_nome LIKE :nome";
    $params[':nome'] = "%{$filtro_nome}%";
}

if (!empty($filtro_departamento)) {
    $where_conditions[] = "usuario_departamento = :departamento";
    $params[':departamento'] = $filtro_departamento;
}

if (!empty($filtro_status)) {
    $where_conditions[] = "usuario_status = :status";
    $params[':status'] = $filtro_status;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Buscar usuários com paginação
$usuarios = [];
$total_registros = 0;

try {
    // Contar total de registros
    $count_sql = "SELECT COUNT(*) as total FROM tb_usuarios_sistema {$where_clause}";
    $count_stmt = $conn->prepare($count_sql);
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_registros = $count_stmt->fetch()['total'];
    
    // Buscar usuários
    $sql = "SELECT usuario_id, usuario_nome, usuario_login, usuario_email, usuario_departamento, 
                   usuario_nivel_id, usuario_status, usuario_data_criacao, usuario_ultimo_acesso
            FROM tb_usuarios_sistema 
            {$where_clause}
            ORDER BY usuario_nome ASC 
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Erro ao buscar usuários: " . $e->getMessage());
}

// Calcular informações de paginação
$total_paginas = ceil($total_registros / $registros_por_pagina);

// Buscar lista de departamentos para o filtro
$departamentos = [];
try {
    $dept_stmt = $conn->prepare("SELECT DISTINCT usuario_departamento FROM tb_usuarios_sistema WHERE usuario_departamento IS NOT NULL ORDER BY usuario_departamento");
    $dept_stmt->execute();
    $departamentos = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Erro ao buscar departamentos: " . $e->getMessage());
}

// Definir menus baseados no departamento (mesmo array do dashboard)
$menus_departamentos = [
    'AGRICULTURA' => ['icon' => 'fas fa-leaf', 'name' => 'Agricultura', 'color' => '#2e7d32'],
    'ASSISTENCIA SOCIAL' => ['icon' => 'fas fa-hands-helping', 'name' => 'Assistência Social', 'color' => '#e91e63'],
    'CULTURA E TURISMO' => ['icon' => 'fas fa-palette', 'name' => 'Cultura e Turismo', 'color' => '#ff5722'],
    'EDUCACAO' => ['icon' => 'fas fa-graduation-cap', 'name' => 'Educação', 'color' => '#9c27b0'],
    'ESPORTE' => ['icon' => 'fas fa-running', 'name' => 'Esporte', 'color' => '#4caf50'],
    'FAZENDA' => ['icon' => 'fas fa-money-bill-wave', 'name' => 'Fazenda', 'color' => '#ff9800'],
    'FISCALIZACAO' => ['icon' => 'fas fa-search', 'name' => 'Fiscalização', 'color' => '#673ab7'],
    'MEIO AMBIENTE' => ['icon' => 'fas fa-tree', 'name' => 'Meio Ambiente', 'color' => '#009688'],
    'OBRAS' => ['icon' => 'fas fa-hard-hat', 'name' => 'Obras', 'color' => '#795548'],
    'RODOVIARIO' => ['icon' => 'fas fa-truck', 'name' => 'Rodoviário', 'color' => '#607d8b'],
    'SERVICOS URBANOS' => ['icon' => 'fas fa-city', 'name' => 'Serviços Urbanos', 'color' => '#2196f3']
];

$titulo_sistema = 'Administração Geral';
$cor_tema = '#e74c3c';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Usuários - Sistema da Prefeitura</title>
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
            --secondary-color: <?php echo $cor_tema; ?>;
            --text-color: #333;
            --light-color: #ecf0f1;
            --sidebar-width: 250px;
            --header-height: 60px;
        }

        body {
            display: flex;
            min-height: 100vh;
            background-color: #f5f7fa;
        }

        /* Sidebar styles - mesmos do dashboard */
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

        .admin-badge {
            background-color: #e74c3c;
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

        .admin-badge i {
            font-size: 0.7rem;
        }

        .page-title {
            margin-bottom: 20px;
            font-size: 1.5rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
        }

        .page-title i {
            margin-right: 10px;
            color: var(--secondary-color);
        }

        /* Filtros */
        .filters-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .filters-title {
            font-size: 1.1rem;
            margin-bottom: 15px;
            color: var(--text-color);
            display: flex;
            align-items: center;
        }

        .filters-title i {
            margin-right: 8px;
            color: var(--secondary-color);
        }

        .filters-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-weight: 500;
            margin-bottom: 5px;
            color: var(--text-color);
        }

        .filter-group input,
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn i {
            margin-right: 5px;
        }

        .btn-primary {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #c0392b;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #545b62;
        }

        .btn-success {
            background-color: #28a745;
            color: white;
        }

        .btn-success:hover {
            background-color: #1e7e34;
        }

        /* Tabela */
        .table-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .table-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            font-size: 1.1rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
        }

        .table-title i {
            margin-right: 8px;
            color: var(--secondary-color);
        }

        .table-info {
            color: #666;
            font-size: 0.9rem;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--text-color);
        }

        .table tbody tr:hover {
            background-color: #f8f9fa;
        }

        /* Status badges */
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .status-ativo {
            background-color: #d4edda;
            color: #155724;
        }

        .status-inativo {
            background-color: #f8d7da;
            color: #721c24;
        }

        .nivel-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .nivel-admin {
            background-color: #f8d7da;
            color: #721c24;
        }

        .nivel-user {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        /* Paginação */
        .pagination-container {
            padding: 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .pagination-info {
            color: #666;
            font-size: 0.9rem;
        }

        .pagination {
            display: flex;
            gap: 5px;
            margin-left: auto;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-decoration: none;
            color: var(--text-color);
            border-radius: 4px;
        }

        .pagination a:hover {
            background-color: #f8f9fa;
        }

        .pagination .active {
            background-color: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
        }

        /* Actions */
        .actions {
            display: flex;
            gap: 5px;
        }

        .btn-action {
            padding: 4px 8px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.8rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        .btn-edit {
            background-color: #007bff;
            color: white;
        }

        .btn-edit:hover {
            background-color: #0056b3;
        }

        .btn-delete {
            background-color: #dc3545;
            color: white;
        }

        .btn-delete:hover {
            background-color: #c82333;
        }

        /* Responsivo */
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

            .filters-row {
                grid-template-columns: 1fr;
            }

            .table-container {
                overflow-x: auto;
            }

            .filter-actions {
                flex-direction: column;
            }

            .header {
                flex-direction: column;
                gap: 10px;
                padding: 15px 20px;
                height: auto;
            }

            .header > div:first-child {
                display: flex;
                align-items: center;
                justify-content: space-between;
                width: 100%;
            }

            .user-details {
                align-items: flex-start;
            }

            .user-name {
                font-size: 0.9rem;
            }

            .admin-badge {
                font-size: 0.7rem;
                padding: 3px 6px;
            }
        }

        @media (max-width: 480px) {
            .header h2 {
                font-size: 1.2rem;
            }

            .user-info {
                gap: 8px;
            }

            .user-details {
                min-width: 0;
            }

            .user-name {
                font-size: 0.85rem;
                overflow: hidden;
                text-overflow: ellipsis;
            }
        }

        .mobile-toggle {
            display: none;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h3><?php echo $titulo_sistema; ?></h3>
            <button class="toggle-btn">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        
        <ul class="menu">
            <li class="menu-item">
                <a href="dashboard.php" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span class="menu-text">Dashboard</span>
                </a>
            </li>
            
            <div class="menu-separator"></div>
            <div class="menu-category">Administração</div>
            
            <li class="menu-item open">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-users-cog"></i></span>
                    <span class="menu-text">Gerenciar Usuários</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="lista_usuarios.php" class="submenu-link active">Lista de Usuários</a></li>
                    <li><a href="adicionar_usuario.php" class="submenu-link">Adicionar Usuário</a></li>
                    <li><a href="permissoes.php" class="submenu-link">Permissões</a></li>
                </ul>
            </li>
            
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-chart-pie"></i></span>
                    <span class="menu-text">Relatórios Gerais</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Consolidado Geral</a></li>
                    <li><a href="#" class="submenu-link">Por Departamento</a></li>
                    <li><a href="#" class="submenu-link">Estatísticas</a></li>
                </ul>
            </li>
            
            <div class="menu-separator"></div>
            <div class="menu-category">Departamentos</div>
            
            <?php foreach ($menus_departamentos as $dept => $config): ?>
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="<?php echo $config['icon']; ?>"></i></span>
                    <span class="menu-text"><?php echo $config['name']; ?></span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Relatórios</a></li>
                    <li><a href="#" class="submenu-link">Configurações</a></li>
                </ul>
            </li>
            <?php endforeach; ?>
            
            <div class="menu-separator"></div>
            
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-user-cog"></i></span>
                    <span class="menu-text">Meu Perfil</span>
                </a>
            </li>
            
            <li class="menu-item">
                <a href="../controller/logout_system.php" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span class="menu-text">Sair</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div>
                <button class="mobile-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Lista de Usuários</h2>
            </div>
            <div class="user-info">
                <div style="width: 35px; height: 35px; border-radius: 50%; background-color: var(--secondary-color); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                    <?php echo strtoupper(substr($usuario_nome, 0, 1)); ?>
                </div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($usuario_nome); ?></div>
                    <div class="user-role">
                        <span class="admin-badge">
                            <i class="fas fa-crown"></i> Administrador
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <h1 class="page-title">
            <i class="fas fa-users"></i>
            Gerenciamento de Usuários
        </h1>

        <!-- Filtros -->
        <div class="filters-container">
            <div class="filters-title">
                <i class="fas fa-filter"></i>
                Filtros de Busca
            </div>
            
            <form method="GET" action="">
                <div class="filters-row">
                    <div class="filter-group">
                        <label for="nome">Nome do Usuário</label>
                        <input type="text" id="nome" name="nome" value="<?php echo htmlspecialchars($filtro_nome); ?>" placeholder="Digite o nome...">
                    </div>
                    
                    <div class="filter-group">
                        <label for="departamento">Departamento</label>
                        <select id="departamento" name="departamento">
                            <option value="">Todos os Departamentos</option>
                            <?php foreach ($departamentos as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $filtro_departamento === $dept ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="">Todos os Status</option>
                            <option value="ativo" <?php echo $filtro_status === 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                            <option value="inativo" <?php echo $filtro_status === 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                        </select>
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                    <a href="lista_usuarios.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpar
                    </a>
                    <a href="adicionar_usuario.php" class="btn btn-success">
                        <i class="fas fa-plus"></i> Novo Usuário
                    </a>
                </div>
            </form>
        </div>

        <!-- Tabela de Usuários -->
        <div class="table-container">
            <div class="table-header">
                <div class="table-title">
                    <i class="fas fa-list"></i>
                    Lista de Usuários
                </div>
                <div class="table-info">
                    <?php echo $total_registros; ?> usuário(s) encontrado(s)
                </div>
            </div>
            
            <?php if (count($usuarios) > 0): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Login</th>
                        <th>E-mail</th>
                        <th>Departamento</th>
                        <th>Nível</th>
                        <th>Status</th>
                        <th>Cadastro</th>
                        <th>Último Acesso</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $usuario): ?>
                    <tr>
                        <td><?php echo $usuario['usuario_id']; ?></td>
                        <td><?php echo htmlspecialchars($usuario['usuario_nome']); ?></td>
                        <td><?php echo htmlspecialchars($usuario['usuario_login']); ?></td>
                        <td><?php echo htmlspecialchars($usuario['usuario_email']); ?></td>
                        <td><?php echo htmlspecialchars($usuario['usuario_departamento'] ?? 'Não definido'); ?></td>
                        <td>
                            <span class="nivel-badge <?php echo $usuario['usuario_nivel_id'] == 1 ? 'nivel-admin' : 'nivel-user'; ?>">
                                <?php echo $usuario['usuario_nivel_id'] == 1 ? 'Admin' : 'Usuário'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo $usuario['usuario_status']; ?>">
                                <?php echo ucfirst($usuario['usuario_status']); ?>
                            </span>
                        </td>
                        <td><?php echo date('d/m/Y', strtotime($usuario['usuario_data_criacao'])); ?></td>
                        <td>
                            <?php 
                            if ($usuario['usuario_ultimo_acesso']) {
                                echo date('d/m/Y H:i', strtotime($usuario['usuario_ultimo_acesso']));
                            } else {
                                echo '<span style="color: #999;">Nunca</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <div class="actions">
                                <a href="editar_usuario.php?id=<?php echo $usuario['usuario_id']; ?>" class="btn-action btn-edit" title="Editar">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if ($usuario['usuario_id'] != $usuario_id): // Não pode excluir a si mesmo ?>
                                <button type="button" class="btn-action btn-delete" title="Excluir" onclick="confirmarExclusao(<?php echo $usuario['usuario_id']; ?>, '<?php echo htmlspecialchars($usuario['usuario_nome']); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Paginação -->
            <?php if ($total_paginas > 1): ?>
            <div class="pagination-container">
                <div class="pagination-info">
                    Mostrando <?php echo (($pagina_atual - 1) * $registros_por_pagina) + 1; ?> a 
                    <?php echo min($pagina_atual * $registros_por_pagina, $total_registros); ?> de 
                    <?php echo $total_registros; ?> registros
                </div>
                
                <div class="pagination">
                    <?php if ($pagina_atual > 1): ?>
                    <a href="?pagina=<?php echo $pagina_atual - 1; ?>&nome=<?php echo urlencode($filtro_nome); ?>&departamento=<?php echo urlencode($filtro_departamento); ?>&status=<?php echo urlencode($filtro_status); ?>">
                        <i class="fas fa-chevron-left"></i> Anterior
                    </a>
                    <?php endif; ?>
                    
                    <?php
                    $inicio = max(1, $pagina_atual - 2);
                    $fim = min($total_paginas, $pagina_atual + 2);
                    
                    for ($i = $inicio; $i <= $fim; $i++):
                    ?>
                    <?php if ($i == $pagina_atual): ?>
                    <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                    <a href="?pagina=<?php echo $i; ?>&nome=<?php echo urlencode($filtro_nome); ?>&departamento=<?php echo urlencode($filtro_departamento); ?>&status=<?php echo urlencode($filtro_status); ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($pagina_atual < $total_paginas): ?>
                    <a href="?pagina=<?php echo $pagina_atual + 1; ?>&nome=<?php echo urlencode($filtro_nome); ?>&departamento=<?php echo urlencode($filtro_departamento); ?>&status=<?php echo urlencode($filtro_status); ?>">
                        Próxima <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php else: ?>
            <div style="padding: 40px; text-align: center; color: #666;">
                <i class="fas fa-users" style="font-size: 3rem; margin-bottom: 15px; color: #ddd;"></i>
                <h3>Nenhum usuário encontrado</h3>
                <p>Não há usuários que correspondam aos filtros aplicados.</p>
                <div style="margin-top: 20px;">
                    <a href="lista_usuarios.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpar Filtros
                    </a>
                    <a href="adicionar_usuario.php" class="btn btn-success">
                        <i class="fas fa-plus"></i> Adicionar Primeiro Usuário
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal de Confirmação de Exclusão -->
    <div id="deleteModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background-color: white; padding: 30px; border-radius: 8px; max-width: 400px; width: 90%; text-align: center;">
            <i class="fas fa-exclamation-triangle" style="font-size: 3rem; color: #dc3545; margin-bottom: 15px;"></i>
            <h3 style="margin-bottom: 15px; color: #333;">Confirmar Exclusão</h3>
            <p style="margin-bottom: 20px; color: #666;">Tem certeza que deseja excluir o usuário <strong id="userName"></strong>?</p>
            <p style="margin-bottom: 25px; color: #dc3545; font-size: 0.9rem;">Esta ação não pode ser desfeita!</p>
            
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button type="button" onclick="fecharModal()" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancelar
                </button>
                <button type="button" id="confirmDelete" class="btn" style="background-color: #dc3545; color: white;">
                    <i class="fas fa-trash"></i> Excluir
                </button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
                }
            }
            
            if (toggleBtn) {
                toggleBtn.addEventListener('click', toggleSidebar);
            }
            
            if (mobileToggle) {
                mobileToggle.addEventListener('click', toggleSidebar);
            }
            
            // Toggle submenu
            const menuItems = document.querySelectorAll('.menu-item');
            
            menuItems.forEach(function(item) {
                const menuLink = item.querySelector('.menu-link');
                
                if (menuLink && menuLink.querySelector('.arrow')) {
                    menuLink.addEventListener('click', function(e) {
                        e.preventDefault();
                        item.classList.toggle('open');
                        
                        // Close other open menus
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
        });
        
        // Funções para o modal de exclusão
        function confirmarExclusao(userId, userName) {
            document.getElementById('userName').textContent = userName;
            document.getElementById('deleteModal').style.display = 'flex';
            
            document.getElementById('confirmDelete').onclick = function() {
                excluirUsuario(userId);
            };
        }
        
        function fecharModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
        
        function excluirUsuario(userId) {
            // Implementar a exclusão via AJAX ou redirect
            window.location.href = 'excluir_usuario.php?id=' + userId;
        }
        
        // Fechar modal ao clicar fora
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                fecharModal();
            }
        });
        
        // Fechar modal com ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                fecharModal();
            }
        });
    </script>
</body>
</html>
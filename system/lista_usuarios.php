<?php
session_start();

if (!isset($_SESSION['usersystem_logado'])) {
    header("Location: ../acessdeniedrestrict.php"); 
    exit;
}

require_once "../lib/config.php";
require_once "./core/MenuManager.php";

// Configuração do usuário
$usuario_id = $_SESSION['usersystem_id'];
$usuario_dados = [];

try {
    $stmt = $conn->prepare("
        SELECT usuario_id, usuario_nome, usuario_departamento, usuario_nivel_id,
               usuario_email, usuario_telefone, usuario_status, usuario_data_criacao,
               usuario_ultimo_acesso
        FROM tb_usuarios_sistema WHERE usuario_id = :id
    ");
    $stmt->bindParam(':id', $usuario_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $usuario_dados = $stmt->fetch(PDO::FETCH_ASSOC);
        $_SESSION['usersystem_nome'] = $usuario_dados['usuario_nome'];
        $_SESSION['usersystem_departamento'] = $usuario_dados['usuario_departamento'];
        $_SESSION['usersystem_nivel'] = $usuario_dados['usuario_nivel_id'];
        
        $stmt_update = $conn->prepare("UPDATE tb_usuarios_sistema SET usuario_ultimo_acesso = NOW() WHERE usuario_id = :id");
        $stmt_update->bindParam(':id', $usuario_id);
        $stmt_update->execute();
    } else {
        session_destroy();
        header("Location: ../acessdeniedrestrict.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar dados do usuário: " . $e->getMessage());
}

// Verificar se é administrador
$is_admin = ($usuario_dados['usuario_nivel_id'] == 1);
if (!$is_admin) {
    header("Location: dashboard.php");
    exit;
}

// Inicializar MenuManager
$userSession = [
    'usuario_id' => $usuario_dados['usuario_id'],
    'usuario_nome' => $usuario_dados['usuario_nome'],
    'usuario_departamento' => $usuario_dados['usuario_departamento'],
    'usuario_nivel_id' => $usuario_dados['usuario_nivel_id'],
    'usuario_email' => $usuario_dados['usuario_email']
];

$menuManager = new MenuManager($userSession);
$themeColors = $menuManager->getThemeColors();
$availableModules = $menuManager->getAvailableModules();

// Parâmetros de paginação e filtros
$pagina_atual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$registros_por_pagina = 15;
$offset = ($pagina_atual - 1) * $registros_por_pagina;

$filtro_nome = isset($_GET['nome']) ? trim($_GET['nome']) : '';
$filtro_departamento = isset($_GET['departamento']) ? trim($_GET['departamento']) : '';
$filtro_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filtro_nivel = isset($_GET['nivel']) ? trim($_GET['nivel']) : '';

// Construir query de busca
$where_conditions = [];
$params = [];

if (!empty($filtro_nome)) {
    $where_conditions[] = "(usuario_nome LIKE :nome OR usuario_login LIKE :nome OR usuario_email LIKE :nome)";
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

if (!empty($filtro_nivel)) {
    $where_conditions[] = "usuario_nivel_id = :nivel";
    $params[':nivel'] = $filtro_nivel;
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

// Mensagens de feedback
$mensagem_sucesso = $_SESSION['sucesso_usuario'] ?? '';
$mensagem_erro = $_SESSION['erro_usuario'] ?? '';
unset($_SESSION['sucesso_usuario'], $_SESSION['erro_usuario']);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../../img/logo_eai.ico" type="imagem/x-icon">
    <title>Lista de Usuários - Sistema da Prefeitura</title>
    
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
    
    <style>
        /* Layout principal */
        body {
            margin: 0;
            padding: 0;
            background: #f8fafc;
        }

        .main-content {
            margin-left: var(--sidebar-width, 280px);
            margin-top: var(--header-height, 70px);
            min-height: calc(100vh - var(--header-height, 70px));
            transition: margin-left 0.3s ease;
        }

        .main-content.sidebar-collapsed {
            margin-left: 70px;
        }

        .usuarios-container {
            padding: 30px;
            width: 100%;
            max-width: none;
            box-sizing: border-box;
        }

        /* Header da página */
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.15);
        }

        .page-title {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 400;
        }

        /* Estatísticas */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 1.5rem;
            color: white;
        }

        .stat-icon.total { background: #3498db; }
        .stat-icon.ativos { background: #27ae60; }
        .stat-icon.inativos { background: #e74c3c; }
        .stat-icon.admins { background: #9b59b6; }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #7f8c8d;
            font-weight: 500;
        }

        /* Botão adicionar */
        .btn-adicionar {
            background: #27ae60;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 30px;
            transition: all 0.3s ease;
        }

        .btn-adicionar:hover {
            background: #219a52;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
        }

        /* Seção de filtros */
        .filtros-container {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .filtros-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .filtros-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #374151;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filtros-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .filtro-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filtro-group label {
            font-weight: 500;
            color: #374151;
            font-size: 14px;
        }

        .filtro-input,
        .filtro-select {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #f9fafb;
        }

        .filtro-input:focus,
        .filtro-select:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn-filtrar,
        .btn-limpar {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
        }

        .btn-filtrar {
            background: #667eea;
            color: white;
        }

        .btn-filtrar:hover {
            background: #5a6fd8;
        }

        .btn-limpar {
            background: #6b7280;
            color: white;
            margin-left: 10px;
        }

        .btn-limpar:hover {
            background: #4b5563;
        }

        /* Lista de usuários */
        .lista-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .lista-header {
            background: #f8fafc;
            padding: 20px 30px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .lista-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #374151;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .contador-resultados {
            background: #667eea;
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f8fafc;
            padding: 15px 20px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
            white-space: nowrap;
        }

        td {
            padding: 15px 20px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }

        tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-ativo {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inativo {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-bloqueado {
            background: #fef3c7;
            color: #92400e;
        }

        .nivel-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .nivel-admin {
            background: #fee2e2;
            color: #991b1b;
        }

        .nivel-user {
            background: #dbeafe;
            color: #1e40af;
        }

        .acoes {
            display: flex;
            gap: 8px;
        }

        .btn-acao {
            padding: 6px 10px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }

        .btn-editar {
            background: #fbbf24;
            color: white;
        }

        .btn-excluir {
            background: #ef4444;
            color: white;
        }

        .btn-acao:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        /* Paginação */
        .pagination-container {
            padding: 20px 30px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .pagination-info {
            color: #6b7280;
            font-size: 14px;
        }

        .pagination {
            display: flex;
            gap: 5px;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            text-decoration: none;
            color: #374151;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .pagination a:hover {
            background: #f3f4f6;
            border-color: #9ca3af;
        }

        .pagination .active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .sem-registros {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .sem-registros i {
            font-size: 3rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Alertas */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .filtros-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .usuarios-container {
                padding: 20px;
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .page-title {
                font-size: 1.8rem;
            }

            .acoes {
                flex-direction: column;
            }

            .btn-acao {
                width: 100%;
                justify-content: center;
            }

            .pagination-container {
                flex-direction: column;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="usuarios-container">
            <!-- Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-users-cog"></i>
                    Gerenciamento de Usuários
                </h1>
                <p class="page-subtitle">
                    Gerencie todos os usuários do sistema de forma centralizada
                </p>
            </div>

            <!-- Alertas -->
            <?php if (!empty($mensagem_sucesso)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($mensagem_sucesso) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($mensagem_erro)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($mensagem_erro) ?>
                </div>
            <?php endif; ?>

            <!-- Estatísticas -->
            <?php
            $stats = [
                'total' => 0,
                'ativos' => 0,
                'inativos' => 0,
                'admins' => 0
            ];
            
            try {
                $stmt = $conn->prepare("
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN usuario_status = 'ativo' THEN 1 ELSE 0 END) as ativos,
                        SUM(CASE WHEN usuario_status = 'inativo' THEN 1 ELSE 0 END) as inativos,
                        SUM(CASE WHEN usuario_nivel_id = 1 THEN 1 ELSE 0 END) as admins
                    FROM tb_usuarios_sistema
                ");
                $stmt->execute();
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("Erro ao buscar estatísticas: " . $e->getMessage());
            }
            ?>

            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-icon total">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-number"><?= $stats['total'] ?></div>
                    <div class="stat-label">Total de Usuários</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon ativos">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-number"><?= $stats['ativos'] ?></div>
                    <div class="stat-label">Usuários Ativos</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon inativos">
                        <i class="fas fa-user-times"></i>
                    </div>
                    <div class="stat-number"><?= $stats['inativos'] ?></div>
                    <div class="stat-label">Usuários Inativos</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon admins">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="stat-number"><?= $stats['admins'] ?></div>
                    <div class="stat-label">Administradores</div>
                </div>
            </div>

            <!-- Botão Adicionar -->
            <a href="adicionar_usuario.php" class="btn-adicionar">
                <i class="fas fa-plus"></i>
                Adicionar Novo Usuário
            </a>

            <!-- Filtros de Pesquisa -->
            <div class="filtros-container">
                <div class="filtros-header">
                    <h3 class="filtros-title">
                        <i class="fas fa-search"></i>
                        Filtros de Pesquisa
                    </h3>
                </div>
                
                <form method="GET" action="" id="formFiltros">
                    <div class="filtros-grid">
                        <div class="filtro-group">
                            <label for="nome">Nome, Login ou E-mail</label>
                            <input type="text" 
                                   id="nome" 
                                   name="nome" 
                                   class="filtro-input"
                                   placeholder="Digite para buscar..."
                                   value="<?= htmlspecialchars($filtro_nome) ?>">
                        </div>
                        
                        <div class="filtro-group">
                            <label for="departamento">Departamento</label>
                            <select id="departamento" name="departamento" class="filtro-select">
                                <option value="">Todos os departamentos</option>
                                <?php foreach ($departamentos as $dept): ?>
                                    <option value="<?= htmlspecialchars($dept) ?>" 
                                            <?= $filtro_departamento === $dept ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dept) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filtro-group">
                            <label for="status">Status</label>
                            <select id="status" name="status" class="filtro-select">
                                <option value="">Todos os status</option>
                                <option value="ativo" <?= $filtro_status === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                <option value="inativo" <?= $filtro_status === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                                <option value="bloqueado" <?= $filtro_status === 'bloqueado' ? 'selected' : '' ?>>Bloqueado</option>
                            </select>
                        </div>
                        
                        <div class="filtro-group">
                            <label for="nivel">Nível</label>
                            <select id="nivel" name="nivel" class="filtro-select">
                                <option value="">Todos os níveis</option>
                                <option value="1" <?= $filtro_nivel === '1' ? 'selected' : '' ?>>Administrador</option>
                                <option value="2" <?= $filtro_nivel === '2' ? 'selected' : '' ?>>Usuário</option>
                            </select>
                        </div>
                        
                        <div class="filtro-group">
                            <button type="submit" class="btn-filtrar">
                                <i class="fas fa-search"></i>
                                Filtrar
                            </button>
                            <a href="lista_usuarios.php" class="btn-limpar">
                                <i class="fas fa-eraser"></i>
                                Limpar
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Lista de Usuários -->
            <div class="lista-container">
                <div class="lista-header">
                    <h3 class="lista-title">
                        <i class="fas fa-list"></i>
                        Usuários Encontrados
                    </h3>
                    <div class="contador-resultados">
                        <?= $total_registros ?> resultado(s)
                    </div>
                </div>

                <?php if (count($usuarios) > 0): ?>
                    <div class="table-responsive">
                        <table>
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
                                    <td><?= $usuario['usuario_id'] ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($usuario['usuario_nome']) ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($usuario['usuario_login']) ?></td>
                                    <td><?= htmlspecialchars($usuario['usuario_email']) ?></td>
                                    <td><?= htmlspecialchars($usuario['usuario_departamento'] ?? 'Não definido') ?></td>
                                    <td>
                                        <span class="nivel-badge <?= $usuario['usuario_nivel_id'] == 1 ? 'nivel-admin' : 'nivel-user' ?>">
                                            <?= $usuario['usuario_nivel_id'] == 1 ? 'Admin' : 'Usuário' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= $usuario['usuario_status'] ?>">
                                            <?= ucfirst($usuario['usuario_status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('d/m/Y', strtotime($usuario['usuario_data_criacao'])) ?></td>
                                    <td>
                                        <?php if ($usuario['usuario_ultimo_acesso']): ?>
                                            <?= date('d/m/Y H:i', strtotime($usuario['usuario_ultimo_acesso'])) ?>
                                        <?php else: ?>
                                            <span style="color: #999;">Nunca</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="acoes">
                                        <a href="editar_usuario.php?id=<?= $usuario['usuario_id'] ?>" class="btn-acao btn-editar" title="Editar">
                                               <i class="fas fa-edit"></i>
                                               Editar
                                           </a>
                                           <?php if ($usuario['usuario_id'] != $usuario_id): // Não pode excluir a si mesmo ?>
                                           <button class="btn-acao btn-excluir" 
                                                   onclick="excluirUsuario(<?= $usuario['usuario_id'] ?>, '<?= htmlspecialchars($usuario['usuario_nome']) ?>')"
                                                   title="Excluir">
                                               <i class="fas fa-trash"></i>
                                               Excluir
                                           </button>
                                           <?php else: ?>
                                           <button class="btn-acao" 
                                                   style="background: #94a3b8; cursor: not-allowed;"
                                                   title="Não é possível excluir a si mesmo"
                                                   disabled>
                                               <i class="fas fa-lock"></i>
                                               Protegido
                                           </button>
                                           <?php endif; ?>
                                       </div>
                                   </td>
                               </tr>
                               <?php endforeach; ?>
                           </tbody>
                       </table>
                   </div>

                   <!-- Paginação -->
                   <?php if ($total_paginas > 1): ?>
                   <div class="pagination-container">
                       <div class="pagination-info">
                           Mostrando <?= (($pagina_atual - 1) * $registros_por_pagina) + 1 ?> a 
                           <?= min($pagina_atual * $registros_por_pagina, $total_registros) ?> de 
                           <?= $total_registros ?> registros
                       </div>
                       
                       <div class="pagination">
                           <?php if ($pagina_atual > 1): ?>
                           <a href="?pagina=<?= $pagina_atual - 1 ?>&nome=<?= urlencode($filtro_nome) ?>&departamento=<?= urlencode($filtro_departamento) ?>&status=<?= urlencode($filtro_status) ?>&nivel=<?= urlencode($filtro_nivel) ?>">
                               <i class="fas fa-chevron-left"></i> Anterior
                           </a>
                           <?php endif; ?>
                           
                           <?php
                           $inicio = max(1, $pagina_atual - 2);
                           $fim = min($total_paginas, $pagina_atual + 2);
                           
                           for ($i = $inicio; $i <= $fim; $i++):
                           ?>
                           <?php if ($i == $pagina_atual): ?>
                           <span class="active"><?= $i ?></span>
                           <?php else: ?>
                           <a href="?pagina=<?= $i ?>&nome=<?= urlencode($filtro_nome) ?>&departamento=<?= urlencode($filtro_departamento) ?>&status=<?= urlencode($filtro_status) ?>&nivel=<?= urlencode($filtro_nivel) ?>">
                               <?= $i ?>
                           </a>
                           <?php endif; ?>
                           <?php endfor; ?>
                           
                           <?php if ($pagina_atual < $total_paginas): ?>
                           <a href="?pagina=<?= $pagina_atual + 1 ?>&nome=<?= urlencode($filtro_nome) ?>&departamento=<?= urlencode($filtro_departamento) ?>&status=<?= urlencode($filtro_status) ?>&nivel=<?= urlencode($filtro_nivel) ?>">
                               Próxima <i class="fas fa-chevron-right"></i>
                           </a>
                           <?php endif; ?>
                       </div>
                   </div>
                   <?php endif; ?>

               <?php else: ?>
                   <div class="sem-registros">
                       <i class="fas fa-users"></i>
                       <h3>Nenhum usuário encontrado</h3>
                       <?php if (!empty($filtro_nome) || !empty($filtro_departamento) || !empty($filtro_status) || !empty($filtro_nivel)): ?>
                           <p>Nenhum usuário foi encontrado com os filtros aplicados.</p>
                           <a href="lista_usuarios.php" class="btn-limpar" style="margin-top: 15px;">
                               <i class="fas fa-eraser"></i>
                               Limpar Filtros
                           </a>
                       <?php else: ?>
                           <p>Ainda não há usuários cadastrados no sistema.</p>
                           <a href="adicionar_usuario.php" class="btn-adicionar" style="margin-top: 15px;">
                               <i class="fas fa-plus"></i>
                               Adicionar Primeiro Usuário
                           </a>
                       <?php endif; ?>
                   </div>
               <?php endif; ?>
           </div>
       </div>
   </div>

   <!-- JavaScript -->
   <script src="assets/js/main.js"></script>
   <script>
       // Função para excluir usuário
       function excluirUsuario(id, nome) {
           if (!confirm(`Tem certeza que deseja excluir o usuário "${nome}"?`)) {
               return;
           }

           if (!confirm(`ATENÇÃO: Esta ação não pode ser desfeita!\n\nO usuário "${nome}" será removido permanentemente do sistema.\n\nDeseja realmente continuar?`)) {
               return;
           }

           // Mostrar loading
           const loadingHtml = `
               <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                          background: rgba(0,0,0,0.5); display: flex; align-items: center; 
                          justify-content: center; z-index: 9999;">
                   <div style="background: white; padding: 30px; border-radius: 10px; text-align: center;">
                       <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #667eea; margin-bottom: 15px;"></i>
                       <p>Excluindo usuário...</p>
                   </div>
               </div>
           `;
           document.body.insertAdjacentHTML('beforeend', loadingHtml);

           // Fazer requisição AJAX para excluir
           fetch('controller/excluir_usuario.php', {
               method: 'POST',
               headers: {
                   'Content-Type': 'application/json',
               },
               body: JSON.stringify({ id: id })
           })
           .then(response => response.json())
           .then(data => {
               // Remover loading
               document.querySelector('[style*="z-index: 9999"]').remove();
               
               if (data.success) {
                   // Mostrar mensagem de sucesso
                   const successAlert = `
                       <div class="alert alert-success" style="position: fixed; top: 20px; right: 20px; z-index: 1000; min-width: 300px;">
                           <i class="fas fa-check-circle"></i>
                           ${data.message}
                       </div>
                   `;
                   document.body.insertAdjacentHTML('beforeend', successAlert);
                   
                   // Remover alerta após 3 segundos e recarregar página
                   setTimeout(() => {
                       location.reload();
                   }, 2000);
               } else {
                   // Mostrar mensagem de erro
                   const errorAlert = `
                       <div class="alert alert-error" style="position: fixed; top: 20px; right: 20px; z-index: 1000; min-width: 300px;">
                           <i class="fas fa-exclamation-circle"></i>
                           Erro: ${data.message}
                       </div>
                   `;
                   document.body.insertAdjacentHTML('beforeend', errorAlert);
                   
                   // Remover alerta após 5 segundos
                   setTimeout(() => {
                       document.querySelector('.alert-error').remove();
                   }, 5000);
               }
           })
           .catch(error => {
               // Remover loading
               document.querySelector('[style*="z-index: 9999"]').remove();
               
               console.error('Error:', error);
               const errorAlert = `
                   <div class="alert alert-error" style="position: fixed; top: 20px; right: 20px; z-index: 1000; min-width: 300px;">
                       <i class="fas fa-exclamation-circle"></i>
                       Erro interno do sistema. Tente novamente.
                   </div>
               `;
               document.body.insertAdjacentHTML('beforeend', errorAlert);
               
               setTimeout(() => {
                   document.querySelector('.alert-error').remove();
               }, 5000);
           });
       }

       // Filtro em tempo real no campo de busca
       let timeoutBusca;
       document.getElementById('nome').addEventListener('input', function() {
           clearTimeout(timeoutBusca);
           timeoutBusca = setTimeout(() => {
               document.getElementById('formFiltros').submit();
           }, 1000);
       });

       // Submeter filtros automaticamente quando alterar selects
       document.getElementById('departamento').addEventListener('change', function() {
           document.getElementById('formFiltros').submit();
       });

       document.getElementById('status').addEventListener('change', function() {
           document.getElementById('formFiltros').submit();
       });

       document.getElementById('nivel').addEventListener('change', function() {
           document.getElementById('formFiltros').submit();
       });

       // Ajustar margem do main-content quando sidebar for colapsada
       function adjustMainContent() {
           const sidebar = document.querySelector('.sidebar');
           const mainContent = document.querySelector('.main-content');
           
           if (sidebar && mainContent) {
               if (sidebar.classList.contains('collapsed')) {
                   mainContent.classList.add('sidebar-collapsed');
               } else {
                   mainContent.classList.remove('sidebar-collapsed');
               }
           }
       }

       // Observar mudanças na sidebar
       const sidebarObserver = new MutationObserver(adjustMainContent);
       const sidebar = document.querySelector('.sidebar');
       if (sidebar) {
           sidebarObserver.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
       }

       // Remover alertas automaticamente após alguns segundos
       document.addEventListener('DOMContentLoaded', function() {
           const alerts = document.querySelectorAll('.alert');
           alerts.forEach(alert => {
               setTimeout(() => {
                   alert.style.opacity = '0';
                   alert.style.transform = 'translateY(-20px)';
                   setTimeout(() => {
                       alert.remove();
                   }, 300);
               }, 5000);
           });

           // Ajustar layout inicial
           adjustMainContent();
       });
   </script>
</body>
</html>
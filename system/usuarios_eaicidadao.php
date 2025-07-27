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

// Função para sanitizar dados de entrada
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Determinar ação da página
$acao = sanitizeInput($_GET['acao'] ?? 'listar');
$user_id = intval($_GET['id'] ?? 0);

// Mensagens e tratamento de erros
$mensagem_sucesso = $_SESSION['sucesso_usuario'] ?? '';
$mensagem_erro = $_SESSION['erro_usuario'] ?? '';
unset($_SESSION['sucesso_usuario'], $_SESSION['erro_usuario']);

// Processar formulários
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    switch ($_POST['acao']) {
        case 'salvar':
            $user_id = intval($_POST['cad_usu_id'] ?? 0);
            
            $dados = [
                'nome' => sanitizeInput($_POST['cad_usu_nome'] ?? ''),
                'email' => sanitizeInput($_POST['cad_usu_email'] ?? ''),
                'cpf' => preg_replace('/[^0-9]/', '', $_POST['cad_usu_cpf'] ?? ''),
                'contato' => sanitizeInput($_POST['cad_usu_contato'] ?? ''),
                'data_nasc' => sanitizeInput($_POST['cad_usu_data_nasc'] ?? ''),
                'endereco' => sanitizeInput($_POST['cad_usu_endereco'] ?? ''),
                'numero' => sanitizeInput($_POST['cad_usu_numero'] ?? ''),
                'complemento' => sanitizeInput($_POST['cad_usu_complemento'] ?? ''),
                'bairro' => sanitizeInput($_POST['cad_usu_bairro'] ?? ''),
                'cidade' => sanitizeInput($_POST['cad_usu_cidade'] ?? ''),
                'estado' => sanitizeInput($_POST['cad_usu_estado'] ?? ''),
                'cep' => preg_replace('/[^0-9]/', '', $_POST['cad_usu_cep'] ?? ''),
                'status' => sanitizeInput($_POST['cad_usu_status'] ?? 'ativo'),
                'receber_notificacoes' => isset($_POST['cad_usu_receber_notificacoes']) ? 1 : 0
            ];
            
            if (!empty($_POST['cad_usu_senha'])) {
                $dados['senha'] = password_hash($_POST['cad_usu_senha'], PASSWORD_DEFAULT);
            }
            
            // Validações básicas
            if (empty($dados['nome'])) {
                $_SESSION['erro_usuario'] = 'Nome é obrigatório.';
            } elseif (empty($dados['email'])) {
                $_SESSION['erro_usuario'] = 'Email é obrigatório.';
            } elseif (!filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
                $_SESSION['erro_usuario'] = 'Email inválido.';
            } elseif (empty($dados['cpf'])) {
                $_SESSION['erro_usuario'] = 'CPF é obrigatório.';
            } else {
                try {
                    if ($user_id > 0) {
                        $sql = "UPDATE tb_cad_usuarios SET 
                                cad_usu_nome = :nome,
                                cad_usu_email = :email,
                                cad_usu_cpf = :cpf,
                                cad_usu_contato = :contato,
                                cad_usu_data_nasc = :data_nasc,
                                cad_usu_endereco = :endereco,
                                cad_usu_numero = :numero,
                                cad_usu_complemento = :complemento,
                                cad_usu_bairro = :bairro,
                                cad_usu_cidade = :cidade,
                                cad_usu_estado = :estado,
                                cad_usu_cep = :cep,
                                cad_usu_status = :status,
                                cad_usu_receber_notificacoes = :receber_notificacoes";
                        
                        if (!empty($dados['senha'])) {
                            $sql .= ", cad_usu_senha = :senha";
                        }
                        
                        $sql .= " WHERE cad_usu_id = :id";
                        
                        $stmt = $conn->prepare($sql);
                        $stmt->bindParam(':nome', $dados['nome']);
                        $stmt->bindParam(':email', $dados['email']);
                        $stmt->bindParam(':cpf', $dados['cpf']);
                        $stmt->bindParam(':contato', $dados['contato']);
                        $stmt->bindParam(':data_nasc', $dados['data_nasc']);
                        $stmt->bindParam(':endereco', $dados['endereco']);
                        $stmt->bindParam(':numero', $dados['numero']);
                        $stmt->bindParam(':complemento', $dados['complemento']);
                        $stmt->bindParam(':bairro', $dados['bairro']);
                        $stmt->bindParam(':cidade', $dados['cidade']);
                        $stmt->bindParam(':estado', $dados['estado']);
                        $stmt->bindParam(':cep', $dados['cep']);
                        $stmt->bindParam(':status', $dados['status']);
                        $stmt->bindParam(':receber_notificacoes', $dados['receber_notificacoes']);
                        $stmt->bindParam(':id', $user_id);
                        
                        if (!empty($dados['senha'])) {
                            $stmt->bindParam(':senha', $dados['senha']);
                        }
                        
                        $stmt->execute();
                        $_SESSION['sucesso_usuario'] = 'Usuário atualizado com sucesso!';
                        header("Location: usuarios_eaicidadao.php?acao=listar");
                        exit;
                    }
                } catch (PDOException $e) {
                    error_log("Erro ao salvar usuário: " . $e->getMessage());
                    $_SESSION['erro_usuario'] = 'Erro ao salvar usuário. Tente novamente.';
                }
            }
            break;
            
        case 'excluir':
            $user_id = intval($_POST['user_id'] ?? 0);
            
            if ($user_id > 0) {
                try {
                    $stmt = $conn->prepare("DELETE FROM tb_cad_usuarios WHERE cad_usu_id = :id");
                    $stmt->bindParam(':id', $user_id);
                    $stmt->execute();
                    
                    $_SESSION['sucesso_usuario'] = 'Usuário excluído com sucesso!';
                    header("Location: usuarios_eaicidadao.php?acao=listar");
                    exit;
                } catch (PDOException $e) {
                    error_log("Erro ao excluir usuário: " . $e->getMessage());
                    $_SESSION['erro_usuario'] = 'Erro ao excluir usuário. Tente novamente.';
                }
            }
            break;
    }
}

// Buscar dados do usuário para edição
$usuario_editando = null;
if ($acao === 'editar' && $user_id > 0) {
    try {
        $stmt = $conn->prepare("SELECT * FROM tb_cad_usuarios WHERE cad_usu_id = :id");
        $stmt->bindParam(':id', $user_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $usuario_editando = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $_SESSION['erro_usuario'] = 'Usuário não encontrado.';
            $acao = 'listar';
        }
    } catch (PDOException $e) {
        error_log("Erro ao buscar usuário: " . $e->getMessage());
        $_SESSION['erro_usuario'] = 'Erro ao carregar dados do usuário.';
        $acao = 'listar';
    }
}

// Parâmetros de paginação e filtros
$pagina_atual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$registros_por_pagina = 15;
$offset = ($pagina_atual - 1) * $registros_por_pagina;

$filtro_nome = isset($_GET['nome']) ? trim($_GET['nome']) : '';
$filtro_status = isset($_GET['status']) ? trim($_GET['status']) : '';

// Buscar lista de usuários
$usuarios = [];
$total_registros = 0;

if ($acao === 'listar') {
    try {
        $where_conditions = [];
        $params = [];

        if (!empty($filtro_nome)) {
            $where_conditions[] = "(cad_usu_nome LIKE :nome OR cad_usu_email LIKE :nome OR cad_usu_cpf LIKE :nome)";
            $params[':nome'] = "%{$filtro_nome}%";
        }

        if (!empty($filtro_status)) {
            $where_conditions[] = "cad_usu_status = :status";
            $params[':status'] = $filtro_status;
        }

        $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

        // Contar total de registros
        $count_sql = "SELECT COUNT(*) as total FROM tb_cad_usuarios {$where_clause}";
        $count_stmt = $conn->prepare($count_sql);
        foreach ($params as $key => $value) {
            $count_stmt->bindValue($key, $value);
        }
        $count_stmt->execute();
        $total_registros = $count_stmt->fetch()['total'];

        // Buscar usuários
        $sql = "SELECT cad_usu_id, cad_usu_nome, cad_usu_email, cad_usu_cpf, 
                       COALESCE(cad_usu_contato, '') as cad_usu_contato,
                       COALESCE(cad_usu_data_cad, '') as cad_usu_data_cad,
                       COALESCE(cad_usu_ultimo_acess, '') as cad_usu_ultimo_aces,
                       COALESCE(cad_usu_status, 'ativo') as cad_usu_status
                FROM tb_cad_usuarios {$where_clause}
                ORDER BY cad_usu_nome ASC 
                LIMIT :limit OFFSET :offset";
        
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Erro ao buscar usuários: " . $e->getMessage());
        $mensagem_erro = 'Erro ao carregar lista de usuários: ' . $e->getMessage();
    }
}

// Calcular informações de paginação
$total_paginas = ceil($total_registros / $registros_por_pagina);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../../img/logo_eai.ico" type="imagem/x-icon">
    <title>Usuários E-aiCidadão - Sistema da Prefeitura</title>
    
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

        /* Formulário de edição */
        .form-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
            margin-bottom: 30px;
        }

        .form-section {
            margin-bottom: 30px;
        }

        .form-section h3 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-weight: 500;
            color: #374151;
            font-size: 14px;
        }

        .form-control, .form-select {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #f9fafb;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }

        .form-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .required {
            color: #ef4444;
        }

        /* Responsividade */
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

            .pagination-container {
                flex-direction: column;
                gap: 15px;
            }

            .form-grid {
                grid-template-columns: 1fr;
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
                    <i class="fas fa-users"></i>
                    Usuários E-aiCidadão
                </h1>
                <p class="page-subtitle">
                    Gerencie os usuários cadastrados no sistema E-aiCidadão
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

            <?php if ($acao === 'listar'): ?>
                <!-- Estatísticas -->
                <?php
                $stats = [
                    'total' => 0,
                    'ativos' => 0,
                    'inativos' => 0
               ];
               
               try {
                   $stmt = $conn->prepare("
                       SELECT 
                           COUNT(*) as total,
                           SUM(CASE WHEN cad_usu_status = 'ativo' THEN 1 ELSE 0 END) as ativos,
                           SUM(CASE WHEN cad_usu_status = 'inativo' THEN 1 ELSE 0 END) as inativos
                       FROM tb_cad_usuarios
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
               </div>

               <!-- Filtros de Pesquisa -->
               <div class="filtros-container">
                   <div class="filtros-header">
                       <h3 class="filtros-title">
                           <i class="fas fa-search"></i>
                           Filtros de Pesquisa
                       </h3>
                   </div>
                   
                   <form method="GET" action="" id="formFiltros">
                       <input type="hidden" name="acao" value="listar">
                       <div class="filtros-grid">
                           <div class="filtro-group">
                               <label for="nome">Nome, E-mail ou CPF</label>
                               <input type="text" 
                                      id="nome" 
                                      name="nome" 
                                      class="filtro-input"
                                      placeholder="Digite para buscar..."
                                      value="<?= htmlspecialchars($filtro_nome) ?>">
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
                               <button type="submit" class="btn-filtrar">
                                   <i class="fas fa-search"></i>
                                   Filtrar
                               </button>
                               <a href="usuarios_eaicidadao.php?acao=listar" class="btn-limpar">
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
                                       <th>Nome</th>
                                       <th>E-mail</th>
                                       <th>CPF</th>
                                       <th>Contato</th>
                                       <th>Status</th>
                                       <th>Cadastro</th>
                                       <th>Último Acesso</th>
                                       <th>Ações</th>
                                   </tr>
                               </thead>
                               <tbody>
                                   <?php foreach ($usuarios as $usuario): ?>
                                   <tr>
                                       <td>
                                           <strong><?= htmlspecialchars($usuario['cad_usu_nome']) ?></strong>
                                       </td>
                                       <td><?= htmlspecialchars($usuario['cad_usu_email']) ?></td>
                                       <td>
                                           <span style="font-family: monospace;">
                                               <?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $usuario['cad_usu_cpf']) ?>
                                           </span>
                                       </td>
                                       <td><?= htmlspecialchars($usuario['cad_usu_contato']) ?></td>
                                       <td>
                                           <span class="status-badge status-<?= $usuario['cad_usu_status'] ?>">
                                               <?= ucfirst($usuario['cad_usu_status']) ?>
                                           </span>
                                       </td>
                                       <td>
                                           <?= !empty($usuario['cad_usu_data_cad']) ? date('d/m/Y', strtotime($usuario['cad_usu_data_cad'])) : '-' ?>
                                       </td>
                                       <td>
                                           <?= !empty($usuario['cad_usu_ultimo_aces']) ? date('d/m/Y H:i', strtotime($usuario['cad_usu_ultimo_aces'])) : 'Nunca' ?>
                                       </td>
                                       <td>
                                           <div class="acoes">
                                               <a href="usuarios_eaicidadao.php?acao=editar&id=<?= $usuario['cad_usu_id'] ?>" 
                                                  class="btn-acao btn-editar" title="Editar">
                                                   <i class="fas fa-edit"></i>
                                                   Editar
                                               </a>
                                               <button class="btn-acao btn-excluir" 
                                                       onclick="excluirUsuario(<?= $usuario['cad_usu_id'] ?>, '<?= htmlspecialchars($usuario['cad_usu_nome']) ?>')"
                                                       title="Excluir">
                                                   <i class="fas fa-trash"></i>
                                                   Excluir
                                               </button>
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
                               <a href="?acao=listar&pagina=<?= $pagina_atual - 1 ?>&nome=<?= urlencode($filtro_nome) ?>&status=<?= urlencode($filtro_status) ?>">
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
                               <a href="?acao=listar&pagina=<?= $i ?>&nome=<?= urlencode($filtro_nome) ?>&status=<?= urlencode($filtro_status) ?>">
                                   <?= $i ?>
                               </a>
                               <?php endif; ?>
                               <?php endfor; ?>
                               
                               <?php if ($pagina_atual < $total_paginas): ?>
                               <a href="?acao=listar&pagina=<?= $pagina_atual + 1 ?>&nome=<?= urlencode($filtro_nome) ?>&status=<?= urlencode($filtro_status) ?>">
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
                           <?php if (!empty($filtro_nome) || !empty($filtro_status)): ?>
                               <p>Nenhum usuário foi encontrado com os filtros aplicados.</p>
                               <a href="usuarios_eaicidadao.php?acao=listar" class="btn-limpar" style="margin-top: 15px;">
                                   <i class="fas fa-eraser"></i>
                                   Limpar Filtros
                               </a>
                           <?php else: ?>
                               <p>Ainda não há usuários cadastrados no sistema E-aiCidadão.</p>
                           <?php endif; ?>
                       </div>
                   <?php endif; ?>
               </div>

           <?php elseif ($acao === 'editar' && $usuario_editando): ?>
               <!-- Formulário de Edição -->
               <div class="form-container">
                   <form method="POST" class="form-cadastro" novalidate>
                       <input type="hidden" name="acao" value="salvar">
                       <input type="hidden" name="cad_usu_id" value="<?= $usuario_editando['cad_usu_id'] ?>">
                       
                       <!-- Dados Pessoais -->
                       <div class="form-section">
                           <h3>
                               <i class="fas fa-user"></i>
                               Dados Pessoais
                           </h3>
                           <div class="form-grid">
                               <div class="form-group">
                                   <label for="cad_usu_nome">Nome Completo <span class="required">*</span></label>
                                   <input type="text" class="form-control" id="cad_usu_nome" name="cad_usu_nome" 
                                          value="<?= htmlspecialchars($usuario_editando['cad_usu_nome']) ?>" required>
                               </div>
                               <div class="form-group">
                                   <label for="cad_usu_email">E-mail <span class="required">*</span></label>
                                   <input type="email" class="form-control" id="cad_usu_email" name="cad_usu_email" 
                                          value="<?= htmlspecialchars($usuario_editando['cad_usu_email']) ?>" required>
                               </div>
                           </div>
                           
                           <div class="form-grid">
                               <div class="form-group">
                                   <label for="cad_usu_cpf">CPF <span class="required">*</span></label>
                                   <input type="text" class="form-control cpf-mask" id="cad_usu_cpf" name="cad_usu_cpf" 
                                          value="<?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $usuario_editando['cad_usu_cpf']) ?>" required>
                               </div>
                               <div class="form-group">
                                   <label for="cad_usu_contato">Contato</label>
                                   <input type="text" class="form-control" id="cad_usu_contato" name="cad_usu_contato" 
                                          value="<?= htmlspecialchars($usuario_editando['cad_usu_contato']) ?>">
                               </div>
                           </div>
                           
                           <div class="form-grid">
                               <div class="form-group">
                                   <label for="cad_usu_data_nasc">Data de Nascimento</label>
                                   <input type="date" class="form-control" id="cad_usu_data_nasc" name="cad_usu_data_nasc" 
                                          value="<?= $usuario_editando['cad_usu_data_nasc'] ?>">
                               </div>
                               <div class="form-group">
                                   <label for="cad_usu_status">Status</label>
                                   <select class="form-select" id="cad_usu_status" name="cad_usu_status" required>
                                       <option value="ativo" <?= $usuario_editando['cad_usu_status'] == 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                       <option value="inativo" <?= $usuario_editando['cad_usu_status'] == 'inativo' ? 'selected' : '' ?>>Inativo</option>
                                       <option value="bloqueado" <?= $usuario_editando['cad_usu_status'] == 'bloqueado' ? 'selected' : '' ?>>Bloqueado</option>
                                   </select>
                               </div>
                           </div>
                       </div>

                       <!-- Endereço -->
                       <div class="form-section">
                           <h3>
                               <i class="fas fa-map-marker-alt"></i>
                               Endereço
                           </h3>
                           <div class="form-grid">
                               <div class="form-group">
                                   <label for="cad_usu_endereco">Endereço</label>
                                   <input type="text" class="form-control" id="cad_usu_endereco" name="cad_usu_endereco" 
                                          value="<?= htmlspecialchars($usuario_editando['cad_usu_endereco']) ?>">
                               </div>
                               <div class="form-group">
                                   <label for="cad_usu_numero">Número</label>
                                   <input type="text" class="form-control" id="cad_usu_numero" name="cad_usu_numero" 
                                          value="<?= htmlspecialchars($usuario_editando['cad_usu_numero']) ?>">
                               </div>
                           </div>
                           
                           <div class="form-grid">
                               <div class="form-group">
                                   <label for="cad_usu_complemento">Complemento</label>
                                   <input type="text" class="form-control" id="cad_usu_complemento" name="cad_usu_complemento" 
                                          value="<?= htmlspecialchars($usuario_editando['cad_usu_complemento']) ?>">
                               </div>
                               <div class="form-group">
                                   <label for="cad_usu_bairro">Bairro</label>
                                   <input type="text" class="form-control" id="cad_usu_bairro" name="cad_usu_bairro" 
                                          value="<?= htmlspecialchars($usuario_editando['cad_usu_bairro']) ?>">
                               </div>
                           </div>
                           
                           <div class="form-grid">
                               <div class="form-group">
                                   <label for="cad_usu_cidade">Cidade</label>
                                   <input type="text" class="form-control" id="cad_usu_cidade" name="cad_usu_cidade" 
                                          value="<?= htmlspecialchars($usuario_editando['cad_usu_cidade']) ?>">
                               </div>
                               <div class="form-group">
                                   <label for="cad_usu_estado">Estado</label>
                                   <select class="form-select" id="cad_usu_estado" name="cad_usu_estado">
                                       <option value="">Selecione...</option>
                                       <option value="AC" <?= $usuario_editando['cad_usu_estado'] == 'AC' ? 'selected' : '' ?>>Acre</option>
                                       <option value="AL" <?= $usuario_editando['cad_usu_estado'] == 'AL' ? 'selected' : '' ?>>Alagoas</option>
                                       <option value="AP" <?= $usuario_editando['cad_usu_estado'] == 'AP' ? 'selected' : '' ?>>Amapá</option>
                                       <option value="AM" <?= $usuario_editando['cad_usu_estado'] == 'AM' ? 'selected' : '' ?>>Amazonas</option>
                                       <option value="BA" <?= $usuario_editando['cad_usu_estado'] == 'BA' ? 'selected' : '' ?>>Bahia</option>
                                       <option value="CE" <?= $usuario_editando['cad_usu_estado'] == 'CE' ? 'selected' : '' ?>>Ceará</option>
                                       <option value="DF" <?= $usuario_editando['cad_usu_estado'] == 'DF' ? 'selected' : '' ?>>Distrito Federal</option>
                                       <option value="ES" <?= $usuario_editando['cad_usu_estado'] == 'ES' ? 'selected' : '' ?>>Espírito Santo</option>
                                       <option value="GO" <?= $usuario_editando['cad_usu_estado'] == 'GO' ? 'selected' : '' ?>>Goiás</option>
                                       <option value="MA" <?= $usuario_editando['cad_usu_estado'] == 'MA' ? 'selected' : '' ?>>Maranhão</option>
                                       <option value="MT" <?= $usuario_editando['cad_usu_estado'] == 'MT' ? 'selected' : '' ?>>Mato Grosso</option>
                                       <option value="MS" <?= $usuario_editando['cad_usu_estado'] == 'MS' ? 'selected' : '' ?>>Mato Grosso do Sul</option>
                                       <option value="MG" <?= $usuario_editando['cad_usu_estado'] == 'MG' ? 'selected' : '' ?>>Minas Gerais</option>
                                       <option value="PA" <?= $usuario_editando['cad_usu_estado'] == 'PA' ? 'selected' : '' ?>>Pará</option>
                                       <option value="PB" <?= $usuario_editando['cad_usu_estado'] == 'PB' ? 'selected' : '' ?>>Paraíba</option>
                                       <option value="PR" <?= $usuario_editando['cad_usu_estado'] == 'PR' ? 'selected' : '' ?>>Paraná</option>
                                       <option value="PE" <?= $usuario_editando['cad_usu_estado'] == 'PE' ? 'selected' : '' ?>>Pernambuco</option>
                                       <option value="PI" <?= $usuario_editando['cad_usu_estado'] == 'PI' ? 'selected' : '' ?>>Piauí</option>
                                       <option value="RJ" <?= $usuario_editando['cad_usu_estado'] == 'RJ' ? 'selected' : '' ?>>Rio de Janeiro</option>
                                       <option value="RN" <?= $usuario_editando['cad_usu_estado'] == 'RN' ? 'selected' : '' ?>>Rio Grande do Norte</option>
                                       <option value="RS" <?= $usuario_editando['cad_usu_estado'] == 'RS' ? 'selected' : '' ?>>Rio Grande do Sul</option>
                                       <option value="RO" <?= $usuario_editando['cad_usu_estado'] == 'RO' ? 'selected' : '' ?>>Rondônia</option>
                                       <option value="RR" <?= $usuario_editando['cad_usu_estado'] == 'RR' ? 'selected' : '' ?>>Roraima</option>
                                       <option value="SC" <?= $usuario_editando['cad_usu_estado'] == 'SC' ? 'selected' : '' ?>>Santa Catarina</option>
                                       <option value="SP" <?= $usuario_editando['cad_usu_estado'] == 'SP' ? 'selected' : '' ?>>São Paulo</option>
                                       <option value="SE" <?= $usuario_editando['cad_usu_estado'] == 'SE' ? 'selected' : '' ?>>Sergipe</option>
                                       <option value="TO" <?= $usuario_editando['cad_usu_estado'] == 'TO' ? 'selected' : '' ?>>Tocantins</option>
                                   </select>
                               </div>
                           </div>
                           
                           <div class="form-group">
                               <label for="cad_usu_cep">CEP</label>
                               <input type="text" class="form-control cep-mask" id="cad_usu_cep" name="cad_usu_cep" 
                                      value="<?= preg_replace('/(\d{5})(\d{3})/', '$1-$2', $usuario_editando['cad_usu_cep']) ?>">
                           </div>
                       </div>
                       
                       <!-- Configurações -->
                       <div class="form-section">
                           <h3>
                               <i class="fas fa-cog"></i>
                               Configurações
                           </h3>
                           <div class="form-grid">
                               <div class="form-group">
                                   <label for="cad_usu_senha">Nova Senha</label>
                                   <input type="password" class="form-control" id="cad_usu_senha" name="cad_usu_senha" 
                                          placeholder="Deixe em branco para manter a senha atual">
                                   <small style="color: #6b7280; font-size: 12px;">Mínimo 6 caracteres. Deixe em branco para não alterar.</small>
                               </div>
                           </div>
                           
                           <div class="form-check">
                               <input type="checkbox" class="form-check-input" id="cad_usu_receber_notificacoes" 
                                      name="cad_usu_receber_notificacoes" 
                                      <?= $usuario_editando['cad_usu_receber_notificacoes'] ? 'checked' : '' ?>>
                               <label class="form-check-label" for="cad_usu_receber_notificacoes">
                                   Receber notificações por e-mail
                               </label>
                           </div>
                       </div>
                       
                       <!-- Botões -->
                       <div class="form-buttons">
                           <a href="usuarios_eaicidadao.php?acao=listar" class="btn btn-secondary">
                               <i class="fas fa-arrow-left"></i> Cancelar
                           </a>
                           <button type="submit" class="btn btn-primary">
                               <i class="fas fa-save"></i> Salvar Alterações
                           </button>
                       </div>
                   </form>
               </div>
           <?php endif; ?>
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

           // Criar formulário para envio
           const form = document.createElement('form');
           form.method = 'POST';
           form.style.display = 'none';

           const actionInput = document.createElement('input');
           actionInput.type = 'hidden';
           actionInput.name = 'acao';
           actionInput.value = 'excluir';

           const idInput = document.createElement('input');
           idInput.type = 'hidden';
           idInput.name = 'user_id';
           idInput.value = id;

           form.appendChild(actionInput);
           form.appendChild(idInput);
           document.body.appendChild(form);

           form.submit();
       }

       // Máscaras de input
       document.addEventListener('DOMContentLoaded', function() {
           // Máscara para CPF
           const cpfInputs = document.querySelectorAll('.cpf-mask');
           cpfInputs.forEach(function(input) {
               input.addEventListener('input', function() {
                   let value = this.value.replace(/\D/g, '');
                   value = value.replace(/(\d{3})(\d)/, '$1.$2');
                   value = value.replace(/(\d{3})(\d)/, '$1.$2');
                   value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
                   this.value = value;
               });
           });

           // Máscara para CEP
           const cepInputs = document.querySelectorAll('.cep-mask');
           cepInputs.forEach(function(input) {
               input.addEventListener('input', function() {
                   let value = this.value.replace(/\D/g, '');
                   value = value.replace(/(\d{5})(\d)/, '$1-$2');
                   this.value = value;
               });
           });

           // Filtro em tempo real no campo de busca
           let timeoutBusca;
           const nomeInput = document.getElementById('nome');
           if (nomeInput) {
               nomeInput.addEventListener('input', function() {
                   clearTimeout(timeoutBusca);
                   timeoutBusca = setTimeout(() => {
                       document.getElementById('formFiltros').submit();
                   }, 1000);
               });
           }

           // Submeter filtros automaticamente quando alterar selects
           const statusSelect = document.getElementById('status');
           if (statusSelect) {
               statusSelect.addEventListener('change', function() {
                   document.getElementById('formFiltros').submit();
               });
           }

           // Auto dismiss alerts
           const alerts = document.querySelectorAll('.alert');
           alerts.forEach(function(alert) {
               setTimeout(function() {
                   alert.style.opacity = '0';
                   alert.style.transform = 'translateY(-20px)';
                   setTimeout(() => alert.remove(), 300);
               }, 5000);
           });
       });

       // Busca via ViaCEP
       const cepInput = document.getElementById('cad_usu_cep');
       if (cepInput) {
           cepInput.addEventListener('blur', function() {
               const cep = this.value.replace(/\D/g, '');
               
               if (cep.length === 8) {
                   fetch(`https://viacep.com.br/ws/${cep}/json/`)
                       .then(response => response.json())
                       .then(data => {
                           if (!data.erro) {
                               const endereco = document.getElementById('cad_usu_endereco');
                               const bairro = document.getElementById('cad_usu_bairro');
                               const cidade = document.getElementById('cad_usu_cidade');
                               const estado = document.getElementById('cad_usu_estado');
                               
                               if (endereco) endereco.value = data.logradouro;
                               if (bairro) bairro.value = data.bairro;
                               if (cidade) cidade.value = data.localidade;
                               if (estado) estado.value = data.uf;
                           }
                       })
                       .catch(error => console.log('Erro ao buscar CEP:', error));
               }
           });
       }

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

       // Ajustar layout inicial
       adjustMainContent();
   </script>
</body>
</html>
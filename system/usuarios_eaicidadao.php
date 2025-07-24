<?php
// Inicia a sessão
session_start();

// Verifica se o usuário está logado no sistema administrativo
if (!isset($_SESSION['usersystem_logado'])) {
    header("Location: ../acessdeniedrestrict.php"); 
    exit;
}

// Inclui arquivo de configuração com conexão ao banco de dados
require_once "../lib/config.php";
require_once "./core/MenuManager.php";

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

// Verificar permissões de acesso - só administradores podem acessar
if (!$is_admin) {
    header("Location: dashboard.php?erro=acesso_negado");
    exit;
}

// Inicializar MenuManager
$menuManager = new MenuManager([
    'usuario_id' => $usuario_id,
    'usuario_nome' => $usuario_nome,
    'usuario_departamento' => $usuario_departamento,
    'usuario_nivel_id' => $usuario_nivel_id
]);

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
$mensagem = '';
$tipo_mensagem = '';

// Processar formulários
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    switch ($_POST['acao']) {
        case 'salvar':
            // Obter ID do usuário do formulário
            $user_id = intval($_POST['cad_usu_id'] ?? 0);
            
            // Coletar e validar dados do formulário
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
            
            // Se foi informada nova senha, criptografar
            if (!empty($_POST['cad_usu_senha'])) {
                $dados['senha'] = password_hash($_POST['cad_usu_senha'], PASSWORD_DEFAULT);
            }
            
            // Validações básicas
            if (empty($dados['nome'])) {
                $mensagem = 'Nome é obrigatório.';
                $tipo_mensagem = 'error';
            } elseif (empty($dados['email'])) {
                $mensagem = 'Email é obrigatório.';
                $tipo_mensagem = 'error';
            } elseif (!filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
                $mensagem = 'Email inválido.';
                $tipo_mensagem = 'error';
            } elseif (empty($dados['cpf'])) {
                $mensagem = 'CPF é obrigatório.';
                $tipo_mensagem = 'error';
            } else {
                try {
                    if ($user_id > 0) {
                        // Atualizar usuário existente
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
                        
                        // Adicionar senha se foi informada
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
                        
                        $mensagem = 'Usuário atualizado com sucesso!';
                        $tipo_mensagem = 'success';
                        
                        // Redirecionar para evitar reenvio
                        header("Location: usuarios_eaicidadao.php?acao=listar&msg=atualizado");
                        exit;
                    }
                } catch (PDOException $e) {
                    error_log("Erro ao salvar usuário: " . $e->getMessage());
                    $mensagem = 'Erro ao salvar usuário. Tente novamente.';
                    $tipo_mensagem = 'error';
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
                    
                    $mensagem = 'Usuário excluído com sucesso!';
                    $tipo_mensagem = 'success';
                    
                    // Redirecionar para evitar reenvio
                    header("Location: usuarios_eaicidadao.php?acao=listar&msg=excluido");
                    exit;
                } catch (PDOException $e) {
                    error_log("Erro ao excluir usuário: " . $e->getMessage());
                    $mensagem = 'Erro ao excluir usuário. Tente novamente.';
                    $tipo_mensagem = 'error';
                }
            }
            break;
    }
}

// Processar mensagens da URL
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'atualizado':
            $mensagem = 'Usuário atualizado com sucesso!';
            $tipo_mensagem = 'success';
            break;
        case 'excluido':
            $mensagem = 'Usuário excluído com sucesso!';
            $tipo_mensagem = 'success';
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
            $mensagem = 'Usuário não encontrado.';
            $tipo_mensagem = 'error';
            $acao = 'listar';
        }
    } catch (PDOException $e) {
        error_log("Erro ao buscar usuário: " . $e->getMessage());
        $mensagem = 'Erro ao carregar dados do usuário.';
        $tipo_mensagem = 'error';
        $acao = 'listar';
    }
}

// Buscar lista de usuários
$usuarios = [];
$total_usuarios = 0;
$search = sanitizeInput($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

if ($acao === 'listar') {
    try {
        // Verificar se a tabela existe
        $check_table = $conn->query("SHOW TABLES LIKE 'tb_cad_usuarios'");
        if ($check_table->rowCount() == 0) {
            throw new Exception("Tabela tb_cad_usuarios não encontrada");
        }
        
        // Verificar estrutura da tabela
        $check_columns = $conn->query("DESCRIBE tb_cad_usuarios");
        $columns = $check_columns->fetchAll(PDO::FETCH_COLUMN);
        
        // Preparar consulta com filtro de pesquisa
        $where = '';
        $params = [];
        
        if (!empty($search)) {
            $where = "WHERE (cad_usu_nome LIKE :search OR cad_usu_email LIKE :search OR cad_usu_cpf LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        // Contar total de registros
        $count_sql = "SELECT COUNT(*) FROM tb_cad_usuarios $where";
        $count_stmt = $conn->prepare($count_sql);
        foreach ($params as $key => $value) {
            $count_stmt->bindValue($key, $value);
        }
        $count_stmt->execute();
        $total_usuarios = $count_stmt->fetchColumn();
        
        // Verificar se existem registros
        if ($total_usuarios > 0) {
            // Buscar registros da página atual
            $sql = "SELECT cad_usu_id, cad_usu_nome, cad_usu_email, cad_usu_cpf, 
                           COALESCE(cad_usu_contato, '') as cad_usu_contato,
                           COALESCE(cad_usu_data_cad, '') as cad_usu_data_cad,
                           COALESCE(cad_usu_ultimo_acess, '') as cad_usu_ultimo_aces,
                           COALESCE(cad_usu_status, 'ativo') as cad_usu_status
                    FROM tb_cad_usuarios $where 
                    ORDER BY cad_usu_nome ASC 
                    LIMIT :limit OFFSET :offset";
            
            $stmt = $conn->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (Exception $e) {
        error_log("Erro ao buscar usuários: " . $e->getMessage());
        $mensagem = 'Erro ao carregar lista de usuários: ' . $e->getMessage();
        $tipo_mensagem = 'error';
    } catch (PDOException $e) {
        error_log("Erro de banco ao buscar usuários: " . $e->getMessage());
        $mensagem = 'Erro de conexão com o banco de dados.';
        $tipo_mensagem = 'error';
    }
}

// Calcular paginação
$total_pages = ceil($total_usuarios / $per_page);
$current_page = basename($_SERVER['PHP_SELF']);
$breadcrumb = $menuManager->generateBreadcrumb($current_page);
$theme_colors = $menuManager->getThemeColors();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuários E-aiCidadão - Sistema Administrativo</title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/admin-style.css" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        :root {
            --primary-color: #2c3e50;
            --secondary-color: <?= $theme_colors['primary'] ?>;
            --text-color: #333;
            --light-color: #ecf0f1;
            --sidebar-width: 250px;
            --header-height: 60px;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }

        body {
            display: flex;
            min-height: 100vh;
            background-color: #f5f7fa;
        }

        /* Sidebar - Mesmo padrão das outras páginas */
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

        /* Main content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: all 0.3s;
        }

        .sidebar.collapsed + .main-content {
            margin-left: 70px;
        }

        /* Header */
        .header {
            background-color: white;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .header h1 {
            color: var(--secondary-color);
            font-size: 1.8rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            margin: 0;
        }

        .header h1 i {
            margin-right: 10px;
        }

        .header-buttons {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn i {
            margin-right: 8px;
        }

        .btn-primary {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-color);
            color: white;
            text-decoration: none;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
            color: white;
            text-decoration: none;
        }

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-outline-primary {
            background-color: transparent;
            color: var(--secondary-color);
            border: 1px solid var(--secondary-color);
        }

        .btn-outline-primary:hover {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-outline-danger {
            background-color: transparent;
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
        }

        .btn-outline-danger:hover {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.875rem;
        }

        /* Content Cards */
        .content-card {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 20px;
            margin-bottom: 20px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .stat-card {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 20px;
            border-left: 4px solid var(--secondary-color);
        }

        .stat-card h5 {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card h3 {
            color: var(--secondary-color);
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
        }

        /* Search Section */
        .search-section {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            align-items: center;
        }

        .search-input {
            flex: 1;
            max-width: 400px;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 144, 220, 0.1);
        }

        /* Tables */
        .table-container {
            background-color: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .table thead th {
            background-color: #f8f9fa;
            color: #333;
            font-weight: 600;
            padding: 15px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
        }

        .table tbody td {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Badges */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-ativo {
            background-color: #d4edda;
            color: #155724;
        }

        .status-inativo {
            background-color: #f8d7da;
            color: #721c24;
        }

        .status-bloqueado {
            background-color: #fff3cd;
            color: #856404;
        }

        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            background-color: white;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 144, 220, 0.1);
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }

        .form-check-input {
            width: 18px;
            height: 18px;
        }

        .required {
            color: var(--danger-color);
        }

        /* Messages */
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }

        .message i {
            margin-right: 10px;
            font-size: 1.2rem;
        }

        .message-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }

        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            color: var(--secondary-color);
            text-decoration: none;
            border-radius: 4px;
            transition: all 0.3s;
        }

        .pagination a:hover {
            background-color: var(--secondary-color);
            color: white;
        }

        .pagination .active {
            background-color: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
        }

        /* Utilities */
        .text-primary { color: var(--secondary-color) !important; }
        .text-success { color: var(--success-color) !important; }
        .text-danger { color: var(--danger-color) !important; }
        .text-warning { color: var(--warning-color) !important; }

        .d-flex { display: flex; }
        .align-items-center { align-items: center; }
        .justify-content-between { justify-content: space-between; }
        .justify-content-center { justify-content: center; }
        .gap-10 { gap: 10px; }
        .mb-20 { margin-bottom: 20px; }
        .mt-20 { margin-top: 20px; }

        .action-buttons {
            white-space: nowrap;
        }

        .cpf-mask {
            font-family: 'Courier New', monospace;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #ccc;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: #333;
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
            
            .header {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }
            
            .search-section {
                flex-direction: column;
                align-items: stretch;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                overflow-x: auto;
            }
            
            .action-buttons .btn {
                padding: 4px 8px;
                font-size: 0.8rem;
            }
        }

        /* Modal adjustments */
        .modal-content {
            border-radius: 10px;
            border: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            border-radius: 10px 10px 0 0;
        }

        .modal-footer {
            border-top: 1px solid #dee2e6;
            border-radius: 0 0 10px 10px;
        }

        /* Toggle button for mobile */
        .mobile-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--primary-color);
            padding: 10px;
        }

        @media (max-width: 768px) {
            .mobile-toggle {
                display: block;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <nav id="sidebar" class="sidebar">
            <div class="sidebar-header">
                <h3><?= $theme_colors['title'] ?></h3>
            </div>
            
            <ul class="list-unstyled components">
                <?= $menuManager->generateMenu($current_page) ?>
            </ul>
        </nav>

        <!-- Page Content -->
        <div id="content">
            <!-- Top Navigation -->
            <nav class="navbar navbar-expand-lg navbar-light bg-light">
                <div class="container-fluid">
                    <button type="button" id="sidebarCollapse" class="btn btn-primary">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <div class="ms-auto">
                        <div class="dropdown">
                            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user"></i> <?= htmlspecialchars($usuario_nome) ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="../controller/logout_system.php">
                                    <i class="fas fa-sign-out-alt"></i> Sair
                                </a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <?php foreach ($breadcrumb as $name => $link): ?>
                        <?php if ($link === '#'): ?>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($name) ?></li>
                        <?php else: ?>
                            <li class="breadcrumb-item"><a href="<?= $link ?>"><?= htmlspecialchars($name) ?></a></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <li class="breadcrumb-item active">Usuários E-aiCidadão</li>
                </ol>
            </nav>

            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0 text-primary">
                    <i class="fas fa-users"></i> Usuários E-aiCidadão
                </h1>
                
                <?php if ($acao === 'editar'): ?>
                    <a href="usuarios_eaicidadao.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                <?php endif; ?>
            </div>

            <!-- Mensagens -->
            <?php if (!empty($mensagem)): ?>
                <div class="alert alert-<?= $tipo_mensagem === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                    <i class="fas fa-<?= $tipo_mensagem === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                    <?= htmlspecialchars($mensagem) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Conteúdo Principal -->
            <div class="card">
                <div class="card-body">
                    <?php if ($acao === 'listar'): ?>
                        <!-- Estatísticas -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="card card-stats">
                                    <div class="card-body">
                                        <h5 class="card-title">Total de Usuários</h5>
                                        <h3 class="text-primary"><?= $total_usuarios ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Barra de Pesquisa -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <form method="GET" class="d-flex">
                                    <input type="hidden" name="acao" value="listar">
                                    <input type="text" name="search" class="form-control search-box" 
                                           placeholder="Pesquisar por nome, email ou CPF..." 
                                           value="<?= htmlspecialchars($search) ?>">
                                    <button type="submit" class="btn btn-outline-primary ms-2">
                                        <i class="fas fa-search"></i>
                                    </button>
                                    <?php if (!empty($search)): ?>
                                        <a href="usuarios_eaicidadao.php?acao=listar" class="btn btn-outline-secondary ms-2">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>

                        <!-- Tabela de Usuários -->
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Nome</th>
                                        <th>Email</th>
                                        <th>CPF</th>
                                        <th>Contato</th>
                                        <th>Status</th>
                                        <th>Cadastro</th>
                                        <th>Último Acesso</th>
                                        <th width="150">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($usuarios)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4">
                                                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                                <p class="text-muted">Nenhum usuário encontrado</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($usuarios as $usuario): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($usuario['cad_usu_nome']) ?></strong>
                                                </td>
                                                <td><?= htmlspecialchars($usuario['cad_usu_email']) ?></td>
                                                <td>
                                                    <span class="cpf-mask">
                                                        <?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $usuario['cad_usu_cpf']) ?>
                                                    </span>
                                                </td>
                                                <td><?= htmlspecialchars($usuario['cad_usu_contato']) ?></td>
                                                <td>
                                                    <span class="badge status-<?= $usuario['cad_usu_status'] ?>">
                                                        <?= ucfirst($usuario['cad_usu_status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?= !empty($usuario['cad_usu_data_cad']) ? date('d/m/Y', strtotime($usuario['cad_usu_data_cad'])) : '-' ?>
                                                </td>
                                                <td>
                                                    <?= !empty($usuario['cad_usu_ultimo_aces']) ? date('d/m/Y H:i', strtotime($usuario['cad_usu_ultimo_aces'])) : 'Nunca' ?>
                                                </td>
                                                <td class="action-buttons">
                                                    <a href="usuarios_eaicidadao.php?acao=editar&id=<?= $usuario['cad_usu_id'] ?>" 
                                                       class="btn btn-sm btn-outline-primary" title="Editar">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                                            onclick="confirmarExclusao(<?= $usuario['cad_usu_id'] ?>, '<?= htmlspecialchars($usuario['cad_usu_nome']) ?>')" 
                                                            title="Excluir">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Paginação -->
                        <?php if ($total_pages > 1): ?>
                            <nav class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?acao=listar&page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">
                                                <i class="fas fa-chevron-left"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                            <a class="page-link" href="?acao=listar&page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?acao=listar&page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>">
                                                <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                        
                    <?php elseif ($acao === 'editar' && $usuario_editando): ?>
                        <!-- Formulário de Edição -->
                        <form method="POST" class="needs-validation" novalidate>
                            <input type="hidden" name="acao" value="salvar">
                            <input type="hidden" name="cad_usu_id" value="<?= $usuario_editando['cad_usu_id'] ?>">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="cad_usu_nome" class="form-label">Nome Completo <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="cad_usu_nome" name="cad_usu_nome" 
                                               value="<?= htmlspecialchars($usuario_editando['cad_usu_nome']) ?>" required>
                                        <div class="invalid-feedback">Campo obrigatório</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="cad_usu_email" class="form-label">Email <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" id="cad_usu_email" name="cad_usu_email" 
                                               value="<?= htmlspecialchars($usuario_editando['cad_usu_email']) ?>" required>
                                        <div class="invalid-feedback">Email válido é obrigatório</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="cad_usu_cpf" class="form-label">CPF <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control cpf-mask" id="cad_usu_cpf" name="cad_usu_cpf" 
                                               value="<?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $usuario_editando['cad_usu_cpf']) ?>" required>
                                        <div class="invalid-feedback">CPF é obrigatório</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="cad_usu_contato" class="form-label">Contato</label>
                                        <input type="text" class="form-control" id="cad_usu_contato" name="cad_usu_contato" 
                                               value="<?= htmlspecialchars($usuario_editando['cad_usu_contato']) ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="cad_usu_data_nasc" class="form-label">Data de Nascimento</label>
                                        <input type="date" class="form-control" id="cad_usu_data_nasc" name="cad_usu_data_nasc" 
                                               value="<?= $usuario_editando['cad_usu_data_nasc'] ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="mb-3">
                                        <label for="cad_usu_endereco" class="form-label">Endereço</label>
                                        <input type="text" class="form-control" id="cad_usu_endereco" name="cad_usu_endereco" 
                                               value="<?= htmlspecialchars($usuario_editando['cad_usu_endereco']) ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                   <div class="mb-3">
                                       <label for="cad_usu_numero" class="form-label">Número</label>
                                       <input type="text" class="form-control" id="cad_usu_numero" name="cad_usu_numero" 
                                              value="<?= htmlspecialchars($usuario_editando['cad_usu_numero']) ?>">
                                   </div>
                               </div>
                           </div>
                           
                           <div class="row">
                               <div class="col-md-4">
                                   <div class="mb-3">
                                       <label for="cad_usu_complemento" class="form-label">Complemento</label>
                                       <input type="text" class="form-control" id="cad_usu_complemento" name="cad_usu_complemento" 
                                              value="<?= htmlspecialchars($usuario_editando['cad_usu_complemento']) ?>">
                                   </div>
                               </div>
                               <div class="col-md-4">
                                   <div class="mb-3">
                                       <label for="cad_usu_bairro" class="form-label">Bairro</label>
                                       <input type="text" class="form-control" id="cad_usu_bairro" name="cad_usu_bairro" 
                                              value="<?= htmlspecialchars($usuario_editando['cad_usu_bairro']) ?>">
                                   </div>
                               </div>
                               <div class="col-md-4">
                                   <div class="mb-3">
                                       <label for="cad_usu_cep" class="form-label">CEP</label>
                                       <input type="text" class="form-control cep-mask" id="cad_usu_cep" name="cad_usu_cep" 
                                              value="<?= preg_replace('/(\d{5})(\d{3})/', '$1-$2', $usuario_editando['cad_usu_cep']) ?>">
                                   </div>
                               </div>
                           </div>
                           
                           <div class="row">
                               <div class="col-md-6">
                                   <div class="mb-3">
                                       <label for="cad_usu_cidade" class="form-label">Cidade</label>
                                       <input type="text" class="form-control" id="cad_usu_cidade" name="cad_usu_cidade" 
                                              value="<?= htmlspecialchars($usuario_editando['cad_usu_cidade']) ?>">
                                   </div>
                               </div>
                               <div class="col-md-6">
                                   <div class="mb-3">
                                       <label for="cad_usu_estado" class="form-label">Estado</label>
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
                           </div>
                           
                           <div class="row">
                               <div class="col-md-6">
                                   <div class="mb-3">
                                       <label for="cad_usu_senha" class="form-label">Nova Senha</label>
                                       <input type="password" class="form-control" id="cad_usu_senha" name="cad_usu_senha" 
                                              placeholder="Deixe em branco para manter a senha atual">
                                       <div class="form-text">Mínimo 6 caracteres. Deixe em branco para não alterar.</div>
                                   </div>
                               </div>
                               <div class="col-md-6">
                                   <div class="mb-3">
                                       <label for="cad_usu_status" class="form-label">Status</label>
                                       <select class="form-select" id="cad_usu_status" name="cad_usu_status" required>
                                           <option value="ativo" <?= $usuario_editando['cad_usu_status'] == 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                           <option value="inativo" <?= $usuario_editando['cad_usu_status'] == 'inativo' ? 'selected' : '' ?>>Inativo</option>
                                           <option value="bloqueado" <?= $usuario_editando['cad_usu_status'] == 'bloqueado' ? 'selected' : '' ?>>Bloqueado</option>
                                       </select>
                                   </div>
                               </div>
                           </div>
                           
                           <div class="row">
                               <div class="col-md-12">
                                   <div class="mb-3 form-check">
                                       <input type="checkbox" class="form-check-input" id="cad_usu_receber_notificacoes" 
                                              name="cad_usu_receber_notificacoes" 
                                              <?= $usuario_editando['cad_usu_receber_notificacoes'] ? 'checked' : '' ?>>
                                       <label class="form-check-label" for="cad_usu_receber_notificacoes">
                                           Receber notificações por email
                                       </label>
                                   </div>
                               </div>
                           </div>
                           
                           <div class="d-flex justify-content-between">
                               <a href="usuarios_eaicidadao.php" class="btn btn-secondary">
                                   <i class="fas fa-arrow-left"></i> Cancelar
                               </a>
                               <button type="submit" class="btn btn-primary">
                                   <i class="fas fa-save"></i> Salvar Alterações
                               </button>
                           </div>
                       </form>
                   <?php endif; ?>
               </div>
           </div>
       </div>
   </div>

   <!-- Modal de Confirmação de Exclusão -->
   <div class="modal fade" id="confirmDeleteModal" tabindex="-1">
       <div class="modal-dialog">
           <div class="modal-content">
               <div class="modal-header">
                   <h5 class="modal-title">Confirmar Exclusão</h5>
                   <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
               </div>
               <div class="modal-body">
                   <p>Tem certeza que deseja excluir o usuário <strong id="nomeUsuarioExcluir"></strong>?</p>
                   <p class="text-danger"><i class="fas fa-exclamation-triangle"></i> Esta ação não pode ser desfeita!</p>
               </div>
               <div class="modal-footer">
                   <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                   <form method="POST" style="display: inline;">
                       <input type="hidden" name="acao" value="excluir">
                       <input type="hidden" name="user_id" id="userIdExcluir">
                       <button type="submit" class="btn btn-danger">
                           <i class="fas fa-trash"></i> Excluir
                       </button>
                   </form>
               </div>
           </div>
       </div>
   </div>

   <!-- Scripts -->
   <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
   <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
   <script src="js/admin-script.js"></script>
   
   <script>
       // Sidebar toggle
       document.getElementById('sidebarCollapse').addEventListener('click', function() {
           document.getElementById('sidebar').classList.toggle('active');
       });

       // Função para confirmar exclusão
       function confirmarExclusao(userId, nomeUsuario) {
           document.getElementById('userIdExcluir').value = userId;
           document.getElementById('nomeUsuarioExcluir').textContent = nomeUsuario;
           
           var modal = new bootstrap.Modal(document.getElementById('confirmDeleteModal'));
           modal.show();
       }

       // Máscaras de input
       $(document).ready(function() {
           // Máscara para CPF
           $('.cpf-mask').on('input', function() {
               let value = this.value.replace(/\D/g, '');
               value = value.replace(/(\d{3})(\d)/, '$1.$2');
               value = value.replace(/(\d{3})(\d)/, '$1.$2');
               value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
               this.value = value;
           });

           // Máscara para CEP
           $('.cep-mask').on('input', function() {
               let value = this.value.replace(/\D/g, '');
               value = value.replace(/(\d{5})(\d)/, '$1-$2');
               this.value = value;
           });

           // Validação de formulário
           $('.needs-validation').on('submit', function(event) {
               if (!this.checkValidity()) {
                   event.preventDefault();
                   event.stopPropagation();
               }
               this.classList.add('was-validated');
           });

           // Validação de senha
           $('#cad_usu_senha').on('input', function() {
               const senha = this.value;
               if (senha && senha.length < 6) {
                   this.setCustomValidity('A senha deve ter pelo menos 6 caracteres');
               } else {
                   this.setCustomValidity('');
               }
           });

           // Auto dismiss alerts
           setTimeout(function() {
               $('.alert').fadeOut();
           }, 5000);
       });

       // Busca via ViaCEP
       $('#cad_usu_cep').on('blur', function() {
           const cep = this.value.replace(/\D/g, '');
           
           if (cep.length === 8) {
               fetch(`https://viacep.com.br/ws/${cep}/json/`)
                   .then(response => response.json())
                   .then(data => {
                       if (!data.erro) {
                           $('#cad_usu_endereco').val(data.logradouro);
                           $('#cad_usu_bairro').val(data.bairro);
                           $('#cad_usu_cidade').val(data.localidade);
                           $('#cad_usu_estado').val(data.uf);
                       }
                   })
                   .catch(error => console.log('Erro ao buscar CEP:', error));
           }
       });
       document.addEventListener('DOMContentLoaded', function() {
    
        // Toggle do sidebar
        const sidebarCollapse = document.getElementById('sidebarCollapse');
        const sidebar = document.getElementById('sidebar');
        
        if (sidebarCollapse && sidebar) {
            sidebarCollapse.addEventListener('click', function() {
                sidebar.classList.toggle('active');
            });
        }
        
        // Menu com submenu
        const menuItems = document.querySelectorAll('.menu-link');
        menuItems.forEach(function(item) {
            item.addEventListener('click', function(e) {
                const menuItem = this.closest('.menu-item');
                const submenu = menuItem.querySelector('.submenu');
                
                if (submenu) {
                    e.preventDefault();
                    menuItem.classList.toggle('open');
                }
            });
        });
        
        // Auto-dismiss alerts
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
        
        // Validação de formulários
        const forms = document.querySelectorAll('.needs-validation');
        forms.forEach(function(form) {
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            });
        });
        
    });

    // Funções utilitárias
    function showLoading() {
        document.body.classList.add('loading');
    }

    function hideLoading() {
        document.body.classList.remove('loading');
    }

    function showMessage(message, type = 'info') {
        const alertClass = type === 'error' ? 'alert-danger' : `alert-${type}`;
        const alertHtml = `
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        
        const container = document.querySelector('.container-fluid');
        if (container) {
            container.insertAdjacentHTML('afterbegin', alertHtml);
        }
    }
    
   </script>
</body>
</html>
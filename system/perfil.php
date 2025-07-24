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
$usuario_atual = null;
$erro = null;
$sucesso = null;

// Processar atualizações do perfil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao === 'atualizar_perfil') {
        // Capturar dados do formulário
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        
        // Validações
        $erros = [];
        
        if (empty($nome)) {
            $erros[] = "O nome é obrigatório.";
        } elseif (strlen($nome) > 255) {
            $erros[] = "O nome deve ter no máximo 255 caracteres.";
        }
        
        if (empty($email)) {
            $erros[] = "O e-mail é obrigatório.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erros[] = "O e-mail informado não é válido.";
        } elseif (strlen($email) > 255) {
            $erros[] = "O e-mail deve ter no máximo 255 caracteres.";
        }
        
        // Verificar se o email já está sendo usado por outro usuário
        if (empty($erros)) {
            try {
                $stmt = $conn->prepare("SELECT usuario_id FROM tb_usuarios_sistema WHERE usuario_email = :email AND usuario_id != :id");
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':id', $usuario_id);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $erros[] = "Este e-mail já está sendo usado por outro usuário.";
                }
            } catch (PDOException $e) {
                $erros[] = "Erro ao verificar e-mail.";
                error_log("Erro ao verificar email: " . $e->getMessage());
            }
        }
        
        // Se não há erros, atualizar
        if (empty($erros)) {
            try {
                $stmt = $conn->prepare("UPDATE tb_usuarios_sistema SET usuario_nome = :nome, usuario_email = :email, usuario_telefone = :telefone WHERE usuario_id = :id");
                $stmt->bindParam(':nome', $nome);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':telefone', $telefone);
                $stmt->bindParam(':id', $usuario_id);
                
                if ($stmt->execute()) {
                    $sucesso = "Perfil atualizado com sucesso!";
                    $_SESSION['usersystem_nome'] = $nome; // Atualizar nome na sessão
                } else {
                    $erro = "Erro ao atualizar perfil.";
                }
            } catch (PDOException $e) {
                $erro = "Erro ao atualizar perfil.";
                error_log("Erro ao atualizar perfil: " . $e->getMessage());
            }
        } else {
            $erro = implode("<br>", $erros);
        }
    }
    
    elseif ($acao === 'alterar_senha') {
        $senha_atual = $_POST['senha_atual'] ?? '';
        $nova_senha = $_POST['nova_senha'] ?? '';
        $confirmar_senha = $_POST['confirmar_senha'] ?? '';
        
        // Validações
        $erros = [];
        
        if (empty($senha_atual)) {
            $erros[] = "A senha atual é obrigatória.";
        }
        
        if (empty($nova_senha)) {
            $erros[] = "A nova senha é obrigatória.";
        } elseif (strlen($nova_senha) < 8) {
            $erros[] = "A nova senha deve ter pelo menos 8 caracteres.";
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*])[A-Za-z\d!@#$%^&*]{8,}$/', $nova_senha)) {
            $erros[] = "A nova senha deve conter pelo menos uma letra maiúscula, uma minúscula, um número e um caractere especial.";
        }
        
        if ($nova_senha !== $confirmar_senha) {
            $erros[] = "As senhas não coincidem.";
        }
        
        // Verificar senha atual
        if (empty($erros)) {
            try {
                $stmt = $conn->prepare("SELECT usuario_senha FROM tb_usuarios_sistema WHERE usuario_id = :id");
                $stmt->bindParam(':id', $usuario_id);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!password_verify($senha_atual, $user_data['usuario_senha'])) {
                        $erros[] = "A senha atual está incorreta.";
                    }
                } else {
                    $erros[] = "Usuário não encontrado.";
                }
            } catch (PDOException $e) {
                $erros[] = "Erro ao verificar senha atual.";
                error_log("Erro ao verificar senha: " . $e->getMessage());
            }
        }
        
        // Se não há erros, atualizar senha
        if (empty($erros)) {
            try {
                $senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE tb_usuarios_sistema SET usuario_senha = :senha WHERE usuario_id = :id");
                $stmt->bindParam(':senha', $senha_hash);
                $stmt->bindParam(':id', $usuario_id);
                
                if ($stmt->execute()) {
                    $sucesso = "Senha alterada com sucesso!";
                } else {
                    $erro = "Erro ao alterar senha.";
                }
            } catch (PDOException $e) {
                $erro = "Erro ao alterar senha.";
                error_log("Erro ao alterar senha: " . $e->getMessage());
            }
        } else {
            $erro = implode("<br>", $erros);
        }
    }
}

// Buscar dados atuais do usuário
try {
    $stmt = $conn->prepare("SELECT usuario_nome, usuario_login, usuario_email, usuario_telefone, usuario_departamento, usuario_nivel_id, usuario_status, usuario_data_criacao, usuario_ultimo_acesso FROM tb_usuarios_sistema WHERE usuario_id = :id");
    $stmt->bindParam(':id', $usuario_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $usuario_atual = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $erro = "Usuário não encontrado.";
    }
} catch (PDOException $e) {
    $erro = "Erro ao buscar dados do usuário.";
    error_log("Erro ao buscar dados do usuário: " . $e->getMessage());
}

// Verificar se é administrador
$is_admin = ($usuario_atual['usuario_nivel_id'] == 1);

// Definir menus baseados no departamento
$menus_departamentos = [
    'AGRICULTURA' => ['icon' => 'fas fa-leaf', 'name' => 'Agricultura', 'color' => '#2e7d32'],
    'ASSISTENCIA_SOCIAL' => ['icon' => 'fas fa-hands-helping', 'name' => 'Assistência Social', 'color' => '#e91e63'],
    'CULTURA_E_TURISMO' => ['icon' => 'fas fa-palette', 'name' => 'Cultura e Turismo', 'color' => '#ff5722'],
    'EDUCACAO' => ['icon' => 'fas fa-graduation-cap', 'name' => 'Educação', 'color' => '#9c27b0'],
    'ESPORTE' => ['icon' => 'fas fa-running', 'name' => 'Esporte', 'color' => '#4caf50'],
    'FAZENDA' => ['icon' => 'fas fa-money-bill-wave', 'name' => 'Fazenda', 'color' => '#ff9800'],
    'FISCALIZACAO' => ['icon' => 'fas fa-search', 'name' => 'Fiscalização', 'color' => '#673ab7'],
    'MEIO_AMBIENTE' => ['icon' => 'fas fa-tree', 'name' => 'Meio Ambiente', 'color' => '#009688'],
    'OBRAS' => ['icon' => 'fas fa-hard-hat', 'name' => 'Obras', 'color' => '#795548'],
    'RODOVIARIO' => ['icon' => 'fas fa-truck', 'name' => 'Rodoviário', 'color' => '#607d8b'],
    'SERVICOS_URBANOS' => ['icon' => 'fas fa-city', 'name' => 'Serviços Urbanos', 'color' => '#2196f3']
];

$titulo_sistema = $is_admin ? 'Administração Geral' : ($menus_departamentos[strtoupper($usuario_atual['usuario_departamento'])]['name'] ?? 'Sistema');
$cor_tema = $is_admin ? '#e74c3c' : ($menus_departamentos[strtoupper($usuario_atual['usuario_departamento'])]['color'] ?? '#3498db');
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Perfil - Sistema da Prefeitura</title>
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
            font-size: 1.5rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
        }

        .page-title i {
            margin-right: 10px;
            color: var(--secondary-color);
        }

        /* Breadcrumb */
        .breadcrumb {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            color: #666;
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: var(--secondary-color);
            text-decoration: none;
            margin-right: 8px;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb i {
            margin: 0 8px;
            font-size: 0.8rem;
        }

        /* Alertas */
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }

        .alert i {
            margin-right: 10px;
            font-size: 1.1rem;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        /* Profile Cards */
        .profile-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .profile-card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .profile-card-header {
            padding: 20px;
            background-color: var(--secondary-color);
            color: white;
            display: flex;
            align-items: center;
        }

        .profile-card-header i {
            margin-right: 10px;
            font-size: 1.2rem;
        }

        .profile-card-body {
            padding: 20px;
        }

        /* User Info Card */
        .user-info-card {
            grid-column: 1 / -1;
            margin-bottom: 20px;
        }

        .user-avatar-section {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

        .user-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: var(--secondary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            font-weight: bold;
            margin-right: 20px;
        }

        .user-info-details h2 {
            color: var(--text-color);
            margin-bottom: 5px;
        }

        .user-info-details p {
            color: #666;
            margin-bottom: 3px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 5px;
            font-size: 0.9rem;
        }

        .info-value {
            color: #666;
            font-size: 1rem;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
            display: inline-block;
            max-width: fit-content;
        }

        .status-ativo {
            background-color: #d4edda;
            color: #155724;
        }

        .status-inativo {
            background-color: #f8d7da;
            color: #721c24;
        }

        /* Forms */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--text-color);
        }

        .form-group label.required::after {
            content: "*";
            color: #e74c3c;
            margin-left: 4px;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-group input:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 2px rgba(231, 76, 60, 0.1);
            outline: none;
        }

        .password-toggle {
            position: relative;
        }

        .password-toggle input {
            padding-right: 45px;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            padding: 5px;
        }

        .toggle-password:hover {
            color: var(--secondary-color);
        }

        .help-text {
            font-size: 0.85rem;
            color: #666;
            margin-top: 5px;
        }

        .help-text i {
            margin-right: 5px;
            color: #999;
        }

        .password-requirements {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-top: 10px;
            font-size: 0.85rem;
        }

        .password-requirements h4 {
            margin-bottom: 8px;
            color: var(--text-color);
        }

        .password-requirements ul {
            margin: 0;
            padding-left: 20px;
        }

        .password-requirements li {
            margin-bottom: 3px;
            color: #666;
        }

        /* Botões */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn i {
            margin-right: 8px;
        }

        .btn-primary {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #c0392b;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #545b62;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-start;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        /* Responsivo */
        @media (max-width: 968px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
        }

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

            .admin-badge, .department-badge {
                font-size: 0.7rem;
                padding: 3px 6px;
            }

            .user-avatar-section {
                flex-direction: column;
                text-align: center;
            }

            .user-avatar {
                margin-right: 0;
                margin-bottom: 15px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .header h2 {
                font-size: 1.2rem;
            }

            .profile-card-body {
                padding: 15px;
            }

            .page-title {
                font-size: 1.3rem;
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
            
            <?php if ($is_admin): ?>
            <div class="menu-separator"></div>
            <div class="menu-category">Administração</div>
            
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-users-cog"></i></span>
                    <span class="menu-text">Gerenciar Usuários</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="lista_usuarios.php" class="submenu-link">Lista de Usuários</a></li>
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
            
            <?php else: ?>
            <!-- Menu específico do departamento para usuários normais -->
            <?php 
            $dept_config = $menus_departamentos[strtoupper($usuario_atual['usuario_departamento'])] ?? null;
            if ($dept_config):
            ?>
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="<?php echo $dept_config['icon']; ?>"></i></span>
                    <span class="menu-text"><?php echo $dept_config['name']; ?></span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Relatórios</a></li>
                    <li><a href="#" class="submenu-link">Configurações</a></li>
                </ul>
            </li>
            <?php endif; ?>
            <?php endif; ?>
            
            <div class="menu-separator"></div>
            
            <li class="menu-item">
                <a href="perfil.php" class="menu-link active">
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
                <h2>Meu Perfil</h2>
            </div>
            <div class="user-info">
                <div style="width: 35px; height: 35px; border-radius: 50%; background-color: var(--secondary-color); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                    <?php echo strtoupper(substr($usuario_atual['usuario_nome'], 0, 1)); ?>
                </div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($usuario_atual['usuario_nome']); ?></div>
                    <div class="user-role">
                        <?php if ($is_admin): ?>
                        <span class="admin-badge">
                            <i class="fas fa-crown"></i> Administrador
                        </span>
                        <?php else: ?>
                        <span class="department-badge">
                            <?php echo htmlspecialchars($usuario_atual['usuario_departamento']); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <h1 class="page-title">
            <i class="fas fa-user-edit"></i>
            Meu Perfil
        </h1>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="dashboard.php">Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <span>Meu Perfil</span>
        </div>

        <!-- Mensagens de alerta -->
        <?php if ($sucesso): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($sucesso); ?>
        </div>
        <?php endif; ?>

        <?php if ($erro): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo $erro; ?>
        </div>
        <?php endif; ?>

        <?php if ($usuario_atual): ?>
        <!-- Card de Informações do Usuário -->
        <div class="profile-card user-info-card">
            <div class="profile-card-header">
                <i class="fas fa-id-card"></i>
                <span>Informações da Conta</span>
            </div>
            <div class="profile-card-body">
                <div class="user-avatar-section">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($usuario_atual['usuario_nome'], 0, 1)); ?>
                    </div>
                    <div class="user-info-details">
                        <h2><?php echo htmlspecialchars($usuario_atual['usuario_nome']); ?></h2>
                        <p><strong>Login:</strong> <?php echo htmlspecialchars($usuario_atual['usuario_login']); ?></p>
                        <p><strong>E-mail:</strong> <?php echo htmlspecialchars($usuario_atual['usuario_email']); ?></p>
                        <p><strong>Departamento:</strong> <?php echo htmlspecialchars($usuario_atual['usuario_departamento'] ?? 'Não definido'); ?></p>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Status da Conta</div>
                        <div class="info-value">
                            <span class="status-badge status-<?php echo $usuario_atual['usuario_status']; ?>">
                                <?php echo ucfirst($usuario_atual['usuario_status']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Nível de Acesso</div>
                        <div class="info-value">
                            <?php echo $is_admin ? 'Administrador' : 'Usuário'; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Data de Cadastro</div>
                        <div class="info-value">
                            <?php echo date('d/m/Y H:i', strtotime($usuario_atual['usuario_data_criacao'])); ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Último Acesso</div>
                        <div class="info-value">
                            <?php 
                            if ($usuario_atual['usuario_ultimo_acesso']) {
                                echo date('d/m/Y H:i', strtotime($usuario_atual['usuario_ultimo_acesso']));
                            } else {
                                echo '<span style="color: #999;">Primeiro acesso</span>';
                            }
                            ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Telefone</div>
                        <div class="info-value">
                            <?php echo $usuario_atual['usuario_telefone'] ? htmlspecialchars($usuario_atual['usuario_telefone']) : '<span style="color: #999;">Não informado</span>'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cards de Formulários -->
        <div class="profile-container">
            <!-- Card de Editar Perfil -->
            <div class="profile-card">
                <div class="profile-card-header">
                    <i class="fas fa-user-edit"></i>
                    <span>Editar Dados Pessoais</span>
                </div>
                <div class="profile-card-body">
                    <form action="" method="POST" id="perfilForm">
                        <input type="hidden" name="acao" value="atualizar_perfil">
                        
                        <div class="form-group">
                            <label for="nome" class="required">Nome Completo</label>
                            <input type="text" id="nome" name="nome" value="<?php echo htmlspecialchars($usuario_atual['usuario_nome']); ?>" required maxlength="255">
                            <div class="help-text">
                                <i class="fas fa-info-circle"></i>
                                Seu nome completo como aparecerá no sistema
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email" class="required">E-mail</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($usuario_atual['usuario_email']); ?>" required maxlength="255">
                            <div class="help-text">
                                <i class="fas fa-info-circle"></i>
                                E-mail para comunicações do sistema
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="telefone">Telefone</label>
                            <input type="text" id="telefone" name="telefone" value="<?php echo htmlspecialchars($usuario_atual['usuario_telefone'] ?? ''); ?>" maxlength="20" placeholder="(00) 00000-0000">
                            <div class="help-text">
                                <i class="fas fa-info-circle"></i>
                                Número de telefone para contato (opcional)
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i>
                                Salvar Alterações
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Card de Alterar Senha -->
            <div class="profile-card">
                <div class="profile-card-header">
                    <i class="fas fa-lock"></i>
                    <span>Alterar Senha</span>
                </div>
                <div class="profile-card-body">
                    <form action="" method="POST" id="senhaForm">
                        <input type="hidden" name="acao" value="alterar_senha">
                        
                        <div class="form-group">
                            <label for="senha_atual" class="required">Senha Atual</label>
                            <div class="password-toggle">
                                <input type="password" id="senha_atual" name="senha_atual" required>
                                <button type="button" class="toggle-password" onclick="togglePassword('senha_atual')">
                                    <i class="fas fa-eye" id="senha_atual-icon"></i>
                                </button>
                            </div>
                            <div class="help-text">
                                <i class="fas fa-info-circle"></i>
                                Digite sua senha atual para confirmar
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="nova_senha" class="required">Nova Senha</label>
                            <div class="password-toggle">
                                <input type="password" id="nova_senha" name="nova_senha" required minlength="8">
                                <button type="button" class="toggle-password" onclick="togglePassword('nova_senha')">
                                    <i class="fas fa-eye" id="nova_senha-icon"></i>
                                </button>
                            </div>
                            <div class="password-requirements">
                                <h4>Requisitos da nova senha:</h4>
                                <ul>
                                    <li>Mínimo de 8 caracteres</li>
                                    <li>Pelo menos uma letra maiúscula</li>
                                    <li>Pelo menos uma letra minúscula</li>
                                    <li>Pelo menos um número</li>
                                    <li>Pelo menos um caractere especial (!@#$%^&*)</li>
                                </ul>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirmar_senha" class="required">Confirmar Nova Senha</label>
                            <div class="password-toggle">
                                <input type="password" id="confirmar_senha" name="confirmar_senha" required minlength="8">
                                <button type="button" class="toggle-password" onclick="togglePassword('confirmar_senha')">
                                    <i class="fas fa-eye" id="confirmar_senha-icon"></i>
                                </button>
                            </div>
                            <div class="help-text">
                                <i class="fas fa-info-circle"></i>
                                Digite a nova senha novamente para confirmação
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-key"></i>
                                Alterar Senha
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php else: ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-triangle"></i>
            Erro ao carregar dados do usuário.
        </div>
        <?php endif; ?>
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
        
        // Função para mostrar/esconder senha
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '-icon');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Validação do formulário de perfil
        document.getElementById('perfilForm').addEventListener('submit', function(e) {
            const nome = document.getElementById('nome').value.trim();
            const email = document.getElementById('email').value.trim();
            
            if (!nome) {
                e.preventDefault();
                alert('O nome é obrigatório.');
                document.getElementById('nome').focus();
                return false;
            }
            
            if (!email) {
                e.preventDefault();
                alert('O e-mail é obrigatório.');
                document.getElementById('email').focus();
                return false;
            }
            
            // Validar formato do e-mail
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Por favor, insira um e-mail válido.');
                document.getElementById('email').focus();
                return false;
            }
        });
        
        // Validação do formulário de senha
        document.getElementById('senhaForm').addEventListener('submit', function(e) {
            const senhaAtual = document.getElementById('senha_atual').value;
            const novaSenha = document.getElementById('nova_senha').value;
            const confirmarSenha = document.getElementById('confirmar_senha').value;
            
            // Validar se as senhas coincidem
            if (novaSenha !== confirmarSenha) {
                e.preventDefault();
                alert('As senhas não coincidem. Por favor, verifique.');
                document.getElementById('confirmar_senha').focus();
                return false;
            }
            
            // Validar força da senha
            const senhaRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*])[A-Za-z\d!@#$%^&*]/;
            if (!senhaRegex.test(novaSenha)) {
                e.preventDefault();
                alert('A nova senha deve conter pelo menos:\n- Uma letra minúscula\n- Uma letra maiúscula\n- Um número\n- Um caractere especial (!@#$%^&*)');
                document.getElementById('nova_senha').focus();
                return false;
            }
            
            if (novaSenha.length < 8) {
                e.preventDefault();
                alert('A nova senha deve ter pelo menos 8 caracteres.');
                document.getElementById('nova_senha').focus();
                return false;
            }
        });
        
        // Validação em tempo real das senhas
        document.getElementById('confirmar_senha').addEventListener('input', function() {
            const novaSenha = document.getElementById('nova_senha').value;
            const confirmarSenha = this.value;
            
            if (confirmarSenha && novaSenha !== confirmarSenha) {
                this.style.borderColor = '#e74c3c';
                this.style.backgroundColor = '#fff5f5';
            } else {
                this.style.borderColor = '#ddd';
                this.style.backgroundColor = 'white';
            }
        });
        
        // Validação em tempo real da força da senha
        document.getElementById('nova_senha').addEventListener('input', function() {
            const senha = this.value;
            const senhaRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*])[A-Za-z\d!@#$%^&*]/;
            
            if (senha.length >= 8 && senhaRegex.test(senha)) {
                this.style.borderColor = '#28a745';
                this.style.backgroundColor = '#f8fff8';
            } else if (senha.length > 0) {
                this.style.borderColor = '#ffc107';
                this.style.backgroundColor = '#fffbf0';
            } else {
                this.style.borderColor = '#ddd';
                this.style.backgroundColor = 'white';
            }
        });
        
        // Máscara para telefone
        document.getElementById('telefone').addEventListener('input', function() {
            let value = this.value.replace(/\D/g, '');
            
            if (value.length >= 11) {
                value = value.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
            } else if (value.length >= 7) {
                value = value.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3');
            } else if (value.length >= 3) {
                value = value.replace(/(\d{2})(\d{0,5})/, '($1) $2');
            }
            
            this.value = value;
        });
        
        // Auto-hide alerts após 5 segundos
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-20px)';
                setTimeout(function() {
                    alert.style.display = 'none';
                }, 300);
            });
        }, 5000);
    </script>
</body>
</html>
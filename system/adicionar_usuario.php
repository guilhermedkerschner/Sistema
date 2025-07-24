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

// Buscar lista de departamentos para o select
$departamentos = [
    'ADMINISTRAÇÃO' => 'Administração',
    'AGRICULTURA' => 'Agricultura',
    'ASSISTENCIA_SOCIAL' => 'Assistência Social',
    'CULTURA_E_TURISMO' => 'Cultura e Turismo',
    'EDUCACAO' => 'Educação',
    'ESPORTE' => 'Esporte',
    'FAZENDA' => 'Fazenda',
    'FISCALIZACAO' => 'Fiscalização',
    'MEIO_AMBIENTE' => 'Meio Ambiente',
    'OBRAS' => 'Obras',
    'RODOVIARIO' => 'Rodoviário',
    'SERVICOS_URBANOS' => 'Serviços Urbanos'
];

// Verificar se há mensagens de erro ou sucesso
$erro = isset($_SESSION['erro_usuario']) ? $_SESSION['erro_usuario'] : null;
$sucesso = isset($_SESSION['sucesso_usuario']) ? $_SESSION['sucesso_usuario'] : null;

// Limpar mensagens da sessão
unset($_SESSION['erro_usuario']);
unset($_SESSION['sucesso_usuario']);

// Manter dados do formulário em caso de erro
$dados_form = isset($_SESSION['dados_form_usuario']) ? $_SESSION['dados_form_usuario'] : [];
unset($_SESSION['dados_form_usuario']);

// Definir menus baseados no departamento (mesmo array do dashboard)
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

$titulo_sistema = 'Administração Geral';
$cor_tema = '#e74c3c';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adicionar Usuário - Sistema da Prefeitura</title>
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

        /* Formulário */
        .form-container {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            max-width: 800px;
        }

        .form-title {
            font-size: 1.2rem;
            margin-bottom: 20px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }

        .form-title i {
            margin-right: 10px;
            color: var(--secondary-color);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-row.single {
            grid-template-columns: 1fr;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--text-color);
            display: flex;
            align-items: center;
        }

        .form-group label.required::after {
            content: "*";
            color: #e74c3c;
            margin-left: 4px;
        }

        .form-group input,
        .form-group select {
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 2px rgba(231, 76, 60, 0.1);
            outline: none;
        }

        .form-group.error input,
        .form-group.error select {
            border-color: #e74c3c;
            background-color: #fff5f5;
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

        /* Botões */
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-start;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

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

        /* Toggle para mostrar senha */
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

            .form-container {
                padding: 20px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
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

            .form-container {
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
            
            <div class="menu-separator"></div>
            <div class="menu-category">Administração</div>
            
            <li class="menu-item open">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-users-cog"></i></span>
                    <span class="menu-text">Gerenciar Usuários</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="lista_usuarios.php" class="submenu-link">Lista de Usuários</a></li>
                    <li><a href="adicionar_usuario.php" class="submenu-link active">Adicionar Usuário</a></li>
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
                <h2>Adicionar Usuário</h2>
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
            <i class="fas fa-user-plus"></i>
            Adicionar Novo Usuário
        </h1>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="dashboard.php">Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <a href="lista_usuarios.php">Lista de Usuários</a>
            <i class="fas fa-chevron-right"></i>
            <span>Adicionar Usuário</span>
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
            <?php echo htmlspecialchars($erro); ?>
        </div>
        <?php endif; ?>

        <!-- Formulário -->
        <div class="form-container">
            <div class="form-title">
                <i class="fas fa-user-plus"></i>
                Dados do Novo Usuário
            </div>
            
            <form action="controller/processar_adicionar_usuario.php" method="POST" id="userForm">
                <!-- Dados Pessoais -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="nome" class="required">Nome Completo</label>
                        <input type="text" id="nome" name="nome" value="<?php echo htmlspecialchars($dados_form['nome'] ?? ''); ?>" required maxlength="255">
                        <div class="help-text">
                            <i class="fas fa-info-circle"></i>
                            Nome completo do usuário
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email" class="required">E-mail</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($dados_form['email'] ?? ''); ?>" required maxlength="255">
                        <div class="help-text">
                            <i class="fas fa-info-circle"></i>
                            E-mail será usado para comunicações do sistema
                        </div>
                    </div>
                </div>

                <!-- Login e Departamento -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="login" class="required">Login de Acesso</label>
                        <input type="text" id="login" name="login" value="<?php echo htmlspecialchars($dados_form['login'] ?? ''); ?>" required maxlength="100" pattern="[a-zA-Z0-9._-]+" title="Apenas letras, números, pontos, hífens e sublinhados">
                        <div class="help-text">
                            <i class="fas fa-info-circle"></i>
                            Apenas letras, números, pontos, hífens e sublinhados
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="departamento" class="required">Departamento</label>
                        <select id="departamento" name="departamento" required>
                            <option value="">Selecione o departamento</option>
                            <?php foreach ($departamentos as $key => $nome): ?>
                            <option value="<?php echo $key; ?>" <?php echo (isset($dados_form['departamento']) && $dados_form['departamento'] === $key) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($nome); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">
                            <i class="fas fa-info-circle"></i>
                            Departamento ao qual o usuário pertence
                        </div>
                    </div>
                </div>

                <!-- Nível de Acesso e Status -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="nivel" class="required">Nível de Acesso</label>
                        <select id="nivel" name="nivel" required>
                            <option value="">Selecione o nível</option>
                            <option value="1" <?php echo (isset($dados_form['nivel']) && $dados_form['nivel'] == '1') ? 'selected' : ''; ?>>Administrador</option>
                            <option value="2" <?php echo (isset($dados_form['nivel']) && $dados_form['nivel'] == '2') ? 'selected' : ''; ?>>Gestor</option>
                            <option value="3" <?php echo (isset($dados_form['nivel']) && $dados_form['nivel'] == '3') ? 'selected' : ''; ?>>Colaborador</option>
                            <option value="4" <?php echo (isset($dados_form['nivel']) && $dados_form['nivel'] == '4') ? 'selected' : ''; ?>>Consulta</option>
                        </select>
                        <div class="help-text">
                            <i class="fas fa-info-circle"></i>
                            Administrador tem acesso total, Usuário apenas ao seu departamento
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="status" class="required">Status</label>
                        <select id="status" name="status" required>
                            <option value="ativo" <?php echo (isset($dados_form['status']) && $dados_form['status'] === 'ativo') ? 'selected' : ''; ?>>Ativo</option>
                            <option value="inativo" <?php echo (isset($dados_form['status']) && $dados_form['status'] === 'inativo') ? 'selected' : ''; ?>>Inativo</option>
                        </select>
                        <div class="help-text">
                            <i class="fas fa-info-circle"></i>
                            Status inicial do usuário no sistema
                        </div>
                    </div>
                </div>

                <!-- Senhas -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="senha" class="required">Senha</label>
                        <div class="password-toggle">
                            <input type="password" id="senha" name="senha" required minlength="8">
                            <button type="button" class="toggle-password" onclick="togglePassword('senha')">
                                <i class="fas fa-eye" id="senha-icon"></i>
                            </button>
                        </div>
                        <div class="password-requirements">
                            <h4>Requisitos da senha:</h4>
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
                        <label for="confirmar_senha" class="required">Confirmar Senha</label>
                        <div class="password-toggle">
                            <input type="password" id="confirmar_senha" name="confirmar_senha" required minlength="8">
                            <button type="button" class="toggle-password" onclick="togglePassword('confirmar_senha')">
                                <i class="fas fa-eye" id="confirmar_senha-icon"></i>
                            </button>
                        </div>
                        <div class="help-text">
                            <i class="fas fa-info-circle"></i>
                            Digite a senha novamente para confirmação
                        </div>
                    </div>
                </div>

                <!-- Observações -->
                <div class="form-row single">
                    <div class="form-group">
                        <label for="observacoes">Observações (opcional)</label>
                        <textarea id="observacoes" name="observacoes" rows="3" maxlength="500" style="padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 1rem; font-family: inherit; resize: vertical;"><?php echo htmlspecialchars($dados_form['observacoes'] ?? ''); ?></textarea>
                        <div class="help-text">
                            <i class="fas fa-info-circle"></i>
                            Informações adicionais sobre o usuário (máximo 500 caracteres)
                        </div>
                    </div>
                </div>

                <!-- Botões de ação -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-save"></i>
                        Criar Usuário
                    </button>
                    
                    <a href="lista_usuarios.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </a>
                </div>
            </form>
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
        
        // Validação do formulário
        document.getElementById('userForm').addEventListener('submit', function(e) {
            const senha = document.getElementById('senha').value;
            const confirmarSenha = document.getElementById('confirmar_senha').value;
            const login = document.getElementById('login').value;
            
            // Validar se as senhas coincidem
            if (senha !== confirmarSenha) {
                e.preventDefault();
                alert('As senhas não coincidem. Por favor, verifique.');
                document.getElementById('confirmar_senha').focus();
                return false;
            }
            
            // Validar força da senha
            const senhaRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*])[A-Za-z\d!@#$%^&*]/;
            if (!senhaRegex.test(senha)) {
                e.preventDefault();
                alert('A senha deve conter pelo menos:\n- Uma letra minúscula\n- Uma letra maiúscula\n- Um número\n- Um caractere especial (!@#$%^&*)');
                document.getElementById('senha').focus();
                return false;
            }
            
            // Validar login (apenas caracteres permitidos)
            const loginRegex = /^[a-zA-Z0-9._-]+$/;
            if (!loginRegex.test(login)) {
                e.preventDefault();
                alert('O login deve conter apenas letras, números, pontos, hífens e sublinhados.');
                document.getElementById('login').focus();
                return false;
            }
            
            // Desabilitar botão de submit para evitar duplo clique
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Criando Usuário...';
        });
        
        // Validação em tempo real das senhas
        document.getElementById('confirmar_senha').addEventListener('input', function() {
            const senha = document.getElementById('senha').value;
            const confirmarSenha = this.value;
            
            if (confirmarSenha && senha !== confirmarSenha) {
                this.style.borderColor = '#e74c3c';
                this.style.backgroundColor = '#fff5f5';
            } else {
                this.style.borderColor = '#ddd';
                this.style.backgroundColor = 'white';
            }
        });
        
        // Validação em tempo real da força da senha
        document.getElementById('senha').addEventListener('input', function() {
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
        
        // Validação em tempo real do login
        document.getElementById('login').addEventListener('input', function() {
            const login = this.value;
            const loginRegex = /^[a-zA-Z0-9._-]+$/;
            
            if (login && !loginRegex.test(login)) {
                this.style.borderColor = '#e74c3c';
                this.style.backgroundColor = '#fff5f5';
            } else {
                this.style.borderColor = '#ddd';
                this.style.backgroundColor = 'white';
            }
        });
        
        // Auto-sugestão de login baseado no nome
        document.getElementById('nome').addEventListener('blur', function() {
            const nome = this.value.trim();
            const loginField = document.getElementById('login');
            
            if (nome && !loginField.value) {
                // Gerar sugestão de login: primeiro nome + primeira letra do sobrenome
                const partesNome = nome.toLowerCase().split(' ');
                if (partesNome.length >= 2) {
                    const sugestao = partesNome[0] + '.' + partesNome[partesNome.length - 1].charAt(0);
                    loginField.value = sugestao.replace(/[^a-zA-Z0-9._-]/g, '');
                } else {
                    loginField.value = partesNome[0].replace(/[^a-zA-Z0-9._-]/g, '');
                }
            }
        });
    </script>
</body>
</html>
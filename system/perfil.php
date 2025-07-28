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
$erro = null;
$sucesso = null;

// Buscar dados do usuário logado
try {
    $stmt = $conn->prepare("
        SELECT usuario_id, usuario_nome, usuario_login, usuario_email, usuario_telefone, 
               usuario_departamento, usuario_nivel_id, usuario_status, usuario_data_criacao,
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
    } else {
        $erro = "Usuário não encontrado.";
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar dados do usuário: " . $e->getMessage());
    $erro = "Erro ao carregar dados do usuário.";
}

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
                    $_SESSION['usersystem_nome'] = $nome;
                    // Recarregar dados do usuário
                    $usuario_dados['usuario_nome'] = $nome;
                    $usuario_dados['usuario_email'] = $email;
                    $usuario_dados['usuario_telefone'] = $telefone;
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
$is_admin = ($usuario_dados['usuario_nivel_id'] == 1);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../../img/logo_eai.ico" type="imagem/x-icon">
    <title>Meu Perfil - Sistema da Prefeitura</title>
    
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

        .perfil-container {
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

        /* Breadcrumb */
        .breadcrumb {
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            color: #666;
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
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
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
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

        /* Cards */
        .cards-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .card-header {
            background: #f8fafc;
            padding: 20px 30px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header h3 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #374151;
        }

        .card-body {
            padding: 30px;
        }

        /* User Info Card */
        .user-info-card {
            margin-bottom: 30px;
        }

        .user-profile-section {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 25px;
            border-bottom: 1px solid #e5e7eb;
        }

        .user-profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            font-weight: 600;
            margin-right: 25px;
        }

        .user-profile-details h2 {
            font-size: 1.8rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }

        .user-profile-details p {
            color: #6b7280;
            margin-bottom: 4px;
            font-weight: 500;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .info-value {
            color: #6b7280;
            font-size: 1rem;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
        }

        .status-ativo {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inativo {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Forms */
        .forms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 30px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #374151;
        }

        .form-group label.required::after {
            content: "*";
            color: #ef4444;
            margin-left: 4px;
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f9fafb;
        }

        .form-group input:focus {
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            outline: none;
        }

        .password-toggle {
            position: relative;
        }

        .password-toggle input {
            padding-right: 50px;
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            padding: 8px;
            border-radius: 4px;
        }

        .toggle-password:hover {
            color: #667eea;
            background: rgba(0,0,0,0.05);
        }

        .help-text {
            font-size: 0.85rem;
            color: #6b7280;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .password-requirements {
            background: #f8fafc;
            padding: 16px;
            border-radius: 8px;
            margin-top: 12px;
            border-left: 4px solid #667eea;
        }

        .password-requirements h4 {
            margin-bottom: 8px;
            color: #374151;
            font-size: 0.9rem;
        }

        .password-requirements ul {
            margin: 0;
            padding-left: 16px;
        }

        .password-requirements li {
            margin-bottom: 4px;
            color: #6b7280;
            font-size: 0.85rem;
        }

        /* Botões */
        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5a6fd8;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-start;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }

        /* Responsividade */
        @media (max-width: 1024px) {
            .forms-grid {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .perfil-container {
                padding: 20px;
            }
            
            .user-profile-section {
                flex-direction: column;
                text-align: center;
            }
            
            .user-profile-avatar {
                margin-right: 0;
                margin-bottom: 20px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }

            .page-title {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="perfil-container">
            <!-- Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-user-edit"></i>
                    Meu Perfil
                </h1>
                <p class="page-subtitle">
                    Gerencie suas informações pessoais e configurações de conta
                </p>
            </div>

            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <span>Meu Perfil</span>
            </div>

            <!-- Alertas -->
            <?php if (!empty($sucesso)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($sucesso) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($erro)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= $erro ?>
                </div>
            <?php endif; ?>

            <?php if ($usuario_dados): ?>
            <!-- Card de Informações do Usuário -->
            <div class="card user-info-card">
                <div class="card-header">
                    <i class="fas fa-id-card"></i>
                    <h3>Informações da Conta</h3>
                </div>
                <div class="card-body">
                    <div class="user-profile-section">
                        <div class="user-profile-avatar">
                            <?= strtoupper(substr($usuario_dados['usuario_nome'], 0, 1)) ?>
                        </div>
                        <div class="user-profile-details">
                            <h2><?= htmlspecialchars($usuario_dados['usuario_nome']) ?></h2>
                            <p><strong>Login:</strong> <?= htmlspecialchars($usuario_dados['usuario_login']) ?></p>
                            <p><strong>E-mail:</strong> <?= htmlspecialchars($usuario_dados['usuario_email']) ?></p>
                            <p><strong>Departamento:</strong> <?= htmlspecialchars($usuario_dados['usuario_departamento'] ?? 'Não definido') ?></p>
                        </div>
                    </div>

                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Status da Conta</div>
                            <div class="info-value">
                                <span class="status-badge status-<?= $usuario_dados['usuario_status'] ?>">
                                    <?= ucfirst($usuario_dados['usuario_status']) ?>
                                </span>
                            </div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Nível de Acesso</div>
                            <div class="info-value">
                                <?= $usuario_dados['usuario_nivel_id'] == 1 ? 'Administrador' : 'Usuário' ?>
                            </div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Data de Cadastro</div>
                            <div class="info-value">
                                <?= date('d/m/Y H:i', strtotime($usuario_dados['usuario_data_criacao'])) ?>
                            </div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Último Acesso</div>
                            <div class="info-value">
                                <?php if ($usuario_dados['usuario_ultimo_acesso']): ?>
                                    <?= date('d/m/Y H:i', strtotime($usuario_dados['usuario_ultimo_acesso'])) ?>
                                <?php else: ?>
                                    <span style="color: #999;">Primeiro acesso</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Telefone</div>
                            <div class="info-value">
                                <?= $usuario_dados['usuario_telefone'] ? htmlspecialchars($usuario_dados['usuario_telefone']) : '<span style="color: #999;">Não informado</span>' ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cards de Formulários -->
            <div class="forms-grid">
                <!-- Card de Editar Perfil -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-user-edit"></i>
                        <h3>Editar Dados Pessoais</h3>
                    </div>
                    <div class="card-body">
                        <form action="" method="POST" id="perfilForm">
                            <input type="hidden" name="acao" value="atualizar_perfil">
                            
                            <div class="form-group">
                                <label for="nome" class="required">Nome Completo</label>
                                <input type="text" id="nome" name="nome" 
                                       value="<?= htmlspecialchars($usuario_dados['usuario_nome']) ?>" 
                                       required maxlength="255">
                                <div class="help-text">
                                    <i class="fas fa-info-circle"></i>
                                    Seu nome completo como aparecerá no sistema
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="email" class="required">E-mail</label>
                                <input type="email" id="email" name="email" 
                                       value="<?= htmlspecialchars($usuario_dados['usuario_email']) ?>" 
                                       required maxlength="255">
                                <div class="help-text">
                                    <i class="fas fa-info-circle"></i>
                                    E-mail para comunicações do sistema
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="telefone">Telefone</label>
                                <input type="text" id="telefone" name="telefone" 
                                       value="<?= htmlspecialchars($usuario_dados['usuario_telefone'] ?? '') ?>" 
                                       maxlength="20" placeholder="(00) 00000-0000">
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
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-lock"></i>
                        <h3>Alterar Senha</h3>
                    </div>
                    <div class="card-body">
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
   </div>

   <!-- JavaScript -->
   <script src="assets/js/main.js"></script>
   <script>
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

           // Mostrar loading
           const submitBtn = this.querySelector('button[type="submit"]');
           const originalText = submitBtn.innerHTML;
           submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
           submitBtn.disabled = true;

           // Simular delay para mostrar feedback visual
           setTimeout(() => {
               // O formulário vai ser submetido normalmente
           }, 100);
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

           // Mostrar loading
           const submitBtn = this.querySelector('button[type="submit"]');
           const originalText = submitBtn.innerHTML;
           submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Alterando...';
           submitBtn.disabled = true;

           // Simular delay para mostrar feedback visual
           setTimeout(() => {
               // O formulário vai ser submetido normalmente
           }, 100);
       });
       
       // Validação em tempo real das senhas
       document.getElementById('confirmar_senha').addEventListener('input', function() {
           const novaSenha = document.getElementById('nova_senha').value;
           const confirmarSenha = this.value;
           
           if (confirmarSenha && novaSenha !== confirmarSenha) {
               this.style.borderColor = '#ef4444';
               this.style.backgroundColor = '#fef2f2';
           } else {
               this.style.borderColor = '#e5e7eb';
               this.style.backgroundColor = '#f9fafb';
           }
       });
       
       // Validação em tempo real da força da senha
       document.getElementById('nova_senha').addEventListener('input', function() {
           const senha = this.value;
           const senhaRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*])[A-Za-z\d!@#$%^&*]/;
           
           if (senha.length >= 8 && senhaRegex.test(senha)) {
               this.style.borderColor = '#10b981';
               this.style.backgroundColor = '#f0fdf4';
           } else if (senha.length > 0) {
               this.style.borderColor = '#f59e0b';
               this.style.backgroundColor = '#fffbeb';
           } else {
               this.style.borderColor = '#e5e7eb';
               this.style.backgroundColor = '#f9fafb';
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
       document.addEventListener('DOMContentLoaded', function() {
           const alerts = document.querySelectorAll('.alert');
           alerts.forEach(function(alert) {
               setTimeout(function() {
                   alert.style.opacity = '0';
                   alert.style.transform = 'translateY(-20px)';
                   setTimeout(function() {
                       alert.remove();
                   }, 300);
               }, 5000);
           });

           // Ajustar layout quando sidebar for colapsada
           adjustMainContent();
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

       // Feedback visual para campos obrigatórios
       document.querySelectorAll('input[required]').forEach(input => {
           input.addEventListener('blur', function() {
               if (!this.value.trim()) {
                   this.style.borderColor = '#ef4444';
                   this.style.backgroundColor = '#fef2f2';
               } else {
                   this.style.borderColor = '#e5e7eb';
                   this.style.backgroundColor = '#f9fafb';
               }
           });

           input.addEventListener('input', function() {
               if (this.value.trim()) {
                   this.style.borderColor = '#e5e7eb';
                   this.style.backgroundColor = '#f9fafb';
               }
           });
       });

       // Animação suave para os cards
       document.addEventListener('DOMContentLoaded', function() {
           const cards = document.querySelectorAll('.card');
           cards.forEach((card, index) => {
               card.style.opacity = '0';
               card.style.transform = 'translateY(20px)';
               
               setTimeout(() => {
                   card.style.transition = 'all 0.5s ease';
                   card.style.opacity = '1';
                   card.style.transform = 'translateY(0)';
               }, index * 100);
           });
       });

       // Confirmar antes de sair da página com alterações não salvas
       let formAlterado = false;
       
       document.querySelectorAll('input').forEach(input => {
           const valorInicial = input.value;
           input.addEventListener('input', function() {
               if (this.value !== valorInicial) {
                   formAlterado = true;
               }
           });
       });

       window.addEventListener('beforeunload', function(e) {
           if (formAlterado) {
               e.preventDefault();
               e.returnValue = 'Você tem alterações não salvas. Deseja realmente sair?';
               return 'Você tem alterações não salvas. Deseja realmente sair?';
           }
       });

       // Resetar flag quando formulário for submetido
       document.querySelectorAll('form').forEach(form => {
           form.addEventListener('submit', function() {
               formAlterado = false;
           });
       });
   </script>
</body>
</html>
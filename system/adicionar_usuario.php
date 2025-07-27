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

// Buscar lista de departamentos
$departamentos = [
    'ADMINISTRACAO' => 'Administração',
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

// Mensagens de feedback
$mensagem_sucesso = $_SESSION['sucesso_usuario'] ?? '';
$mensagem_erro = $_SESSION['erro_usuario'] ?? '';
$dados_form = $_SESSION['dados_form_usuario'] ?? [];

unset($_SESSION['sucesso_usuario'], $_SESSION['erro_usuario'], $_SESSION['dados_form_usuario']);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../../img/logo_eai.ico" type="imagem/x-icon">
    <title>Adicionar Usuário - Sistema da Prefeitura</title>
    
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

        .usuario-container {
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
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: #6b7280;
        }

        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb i {
            font-size: 12px;
            color: #9ca3af;
        }

        /* Formulário */
        .form-container {
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 900px;
        }

        .form-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f1f5f9;
        }

        .form-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        .form-header-text h2 {
            font-size: 1.5rem;
            color: #1e293b;
            margin-bottom: 5px;
        }

        .form-header-text p {
            color: #64748b;
            margin: 0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .form-section {
            margin-bottom: 40px;
            padding: 25px;
            background: #f8fafc;
            border-radius: 10px;
            border-left: 4px solid #667eea;
        }

        .form-section h3 {
            color: #1f2937;
            margin-bottom: 20px;
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section h3 i {
            color: #667eea;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-weight: 600;
            color: #374151;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .form-group label.required::after {
            content: "*";
            color: #ef4444;
            font-weight: 700;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #f9fafb;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .help-text {
            font-size: 12px;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .help-text i {
            color: #9ca3af;
        }

        /* Password toggle */
        .password-group {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            padding: 5px;
            border-radius: 4px;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: #667eea;
        }

        /* Password requirements */
        .password-requirements {
            background: #f0f9ff;
            border: 1px solid #e0f2fe;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
        }

        .password-requirements h4 {
            color: #0369a1;
            font-size: 14px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .password-requirements ul {
            margin: 0;
            padding-left: 20px;
            color: #0284c7;
            font-size: 13px;
        }

        .password-requirements li {
            margin-bottom: 4px;
        }

        /* Botões */
        .form-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #f1f5f9;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 14px 0 rgba(102, 126, 234, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px 0 rgba(102, 126, 234, 0.4);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-secondary:hover {
            background: #4b5563;
            transform: translateY(-1px);
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

        /* Estados de validação */
        .form-group.success input,
        .form-group.success select {
            border-color: #10b981;
            background-color: #f0fdf4;
        }

        .form-group.error input,
        .form-group.error select {
            border-color: #ef4444;
            background-color: #fef2f2;
        }

        .form-group.warning input,
        .form-group.warning select {
            border-color: #f59e0b;
            background-color: #fffbeb;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .usuario-container {
                padding: 20px;
            }
            
            .form-container {
                padding: 25px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .page-title {
                font-size: 1.8rem;
            }

            .form-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="usuario-container">
            <!-- Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-user-plus"></i>
                    Adicionar Usuário
                </h1>
                <p class="page-subtitle">
                    Crie uma nova conta de usuário para o sistema
                </p>
            </div>

            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <a href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
                <i class="fas fa-chevron-right"></i>
                <a href="lista_usuarios.php">
                    <i class="fas fa-users"></i>
                    Lista de Usuários
                </a>
                <i class="fas fa-chevron-right"></i>
                <span>Adicionar Usuário</span>
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

            <!-- Formulário -->
            <div class="form-container">
                <div class="form-header">
                    <div class="form-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="form-header-text">
                        <h2>Novo Usuário</h2>
                        <p>Preencha os dados para criar uma nova conta de usuário</p>
                    </div>
                </div>

                <form action="controller/processar_adicionar_usuario.php" method="POST" id="userForm">
                    
                    <!-- Dados Pessoais -->
                    <div class="form-section">
                        <h3>
                            <i class="fas fa-user"></i>
                            Dados Pessoais
                        </h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="nome" class="required">Nome Completo</label>
                                <input type="text" id="nome" name="nome" 
                                       value="<?= htmlspecialchars($dados_form['nome'] ?? '') ?>" 
                                       required maxlength="255" placeholder="Digite o nome completo">
                                <div class="help-text">
                                    <i class="fas fa-info-circle"></i>
                                    Nome completo do usuário
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="email" class="required">E-mail</label>
                                <input type="email" id="email" name="email" 
                                       value="<?= htmlspecialchars($dados_form['email'] ?? '') ?>" 
                                       required maxlength="255" placeholder="usuario@exemplo.com">
                                <div class="help-text">
                                    <i class="fas fa-info-circle"></i>
                                    E-mail será usado para comunicações do sistema
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Dados de Acesso -->
                    <div class="form-section">
                        <h3>
                            <i class="fas fa-key"></i>
                            Dados de Acesso
                        </h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="login" class="required">Login de Acesso</label>
                                <input type="text" id="login" name="login" 
                                       value="<?= htmlspecialchars($dados_form['login'] ?? '') ?>" 
                                       required maxlength="100" placeholder="login.usuario"
                                       pattern="[a-zA-Z0-9._-]+" 
                                       title="Apenas letras, números, pontos, hífens e sublinhados">
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
                                    <option value="<?= $key ?>" 
                                            <?= (isset($dados_form['departamento']) && $dados_form['departamento'] === $key) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($nome) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="help-text">
                                    <i class="fas fa-info-circle"></i>
                                    Departamento ao qual o usuário pertence
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Permissões -->
                    <div class="form-section">
                        <h3>
                            <i class="fas fa-shield-alt"></i>
                            Permissões e Status
                        </h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="nivel" class="required">Nível de Acesso</label>
                                <select id="nivel" name="nivel" required>
                                    <option value="">Selecione o nível</option>
                                    <option value="1" <?= (isset($dados_form['nivel']) && $dados_form['nivel'] == '1') ? 'selected' : '' ?>>
                                        Administrador - Acesso total ao sistema
                                    </option>
                                    <option value="2" <?= (isset($dados_form['nivel']) && $dados_form['nivel'] == '2') ? 'selected' : '' ?>>
                                        Gestor - Gerenciamento do departamento
                                    </option>
                                    <option value="3" <?= (isset($dados_form['nivel']) && $dados_form['nivel'] == '3') ? 'selected' : '' ?>>
                                        Colaborador - Operações do departamento
                                    </option>
                                    <option value="4" <?= (isset($dados_form['nivel']) && $dados_form['nivel'] == '4') ? 'selected' : '' ?>>
                                        Consulta - Apenas visualização
                                    </option>
                                </select>
                                <div class="help-text">
                                    <i class="fas fa-info-circle"></i>
                                    Define as permissões do usuário no sistema
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="status" class="required">Status</label>
                                <select id="status" name="status" required>
                                    <option value="ativo" <?= (isset($dados_form['status']) && $dados_form['status'] === 'ativo') ? 'selected' : '' ?>>
                                        Ativo - Usuário pode acessar o sistema
                                    </option>
                                    <option value="inativo" <?= (isset($dados_form['status']) && $dados_form['status'] === 'inativo') ? 'selected' : '' ?>>
                                        Inativo - Usuário bloqueado
                                    </option>
                                </select>
                                <div class="help-text">
                                    <i class="fas fa-info-circle"></i>
                                    Status inicial do usuário no sistema
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Senhas -->
                    <div class="form-section">
                        <h3>
                            <i class="fas fa-lock"></i>
                            Definir Senha
                        </h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="senha" class="required">Senha</label>
                                <div class="password-group">
                                    <input type="password" id="senha" name="senha" required minlength="8" 
                                           placeholder="Digite uma senha segura">
                                    <button type="button" class="password-toggle" onclick="togglePassword('senha')">
                                        <i class="fas fa-eye" id="senha-icon"></i>
                                    </button>
                                </div>
                                <div class="password-requirements">
                                    <h4>
                                        <i class="fas fa-shield-alt"></i>
                                        Requisitos de Segurança:
                                    </h4>
                                    <ul>
                                        <li>Mínimo de 8 caracteres</li>
                                        <li>Pelo menos uma letra maiúscula (A-Z)</li>
                                        <li>Pelo menos uma letra minúscula (a-z)</li>
                                        <li>Pelo menos um número (0-9)</li>
                                        <li>Pelo menos um caractere especial (!@#$%^&*)</li>
                                    </ul>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirmar_senha" class="required">Confirmar Senha</label>
                                <div class="password-group">
                                    <input type="password" id="confirmar_senha" name="confirmar_senha" 
                                           required minlength="8" placeholder="Digite a senha novamente">
                                    <button type="button" class="password-toggle" onclick="togglePassword('confirmar_senha')">
                                        <i class="fas fa-eye" id="confirmar_senha-icon"></i>
                                    </button>
                                </div>
                                <div class="help-text">
                                    <i class="fas fa-info-circle"></i>
                                    Digite a senha novamente para confirmação
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Observações -->
                    <div class="form-section">
                        <h3>
                            <i class="fas fa-sticky-note"></i>
                            Informações Adicionais
                        </h3>
                        <div class="form-group">
                            <label for="observacoes">Observações (opcional)</label>
                            <textarea id="observacoes" name="observacoes" rows="4" maxlength="500" 
                                      placeholder="Informações adicionais sobre o usuário..."><?= htmlspecialchars($dados_form['observacoes'] ?? '') ?></textarea>
                            <div class="help-text">
                                <i class="fas fa-info-circle"></i>
                                Informações extras sobre o usuário (máximo 500 caracteres)
                            </div>
                        </div>
                    </div>

                    <!-- Botões de ação -->
                    <div class="form-buttons">
                        <a href="lista_usuarios.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i>
                            Cancelar
                        </a>
                        
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save"></i>
                            Criar Usuário
                        </button>
                    </div>
                </form>
            </div>
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

        // Auto-sugestão de login baseado no nome
        document.getElementById('nome').addEventListener('blur', function() {
            const nome = this.value.trim();
            const loginField = document.getElementById('login');
            
            if (nome && !loginField.value) {
                const partesNome = nome.toLowerCase().split(' ');
                if (partesNome.length >= 2) {
                    const sugestao = partesNome[0] + '.' + partesNome[partesNome.length - 1].charAt(0);
                    loginField.value = sugestao.replace(/[^a-zA-Z0-9._-]/g, '');
                } else {
                    loginField.value = partesNome[0].replace(/[^a-zA-Z0-9._-]/g, '');
                }
            }
        });

        // Validação em tempo real do login
        document.getElementById('login').addEventListener('input', function() {
            const login = this.value;
            const loginRegex = /^[a-zA-Z0-9._-]+$/;
            const group = this.closest('.form-group');
            
            if (login && !loginRegex.test(login)) {
                group.classList.add('error');
                group.classList.remove('success');
            } else if (login) {
                group.classList.add('success');
                group.classList.remove('error');
            } else {
                group.classList.remove('error', 'success');
            }
        });

        // Validação em tempo real da força da senha
        document.getElementById('senha').addEventListener('input', function() {
            const senha = this.value;
            const senhaRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*])[A-Za-z\d!@#$%^&*]/;
            const group = this.closest('.form-group');
            
            if (senha.length >= 8 && senhaRegex.test(senha)) {
                group.classList.add('success');
                group.classList.remove('error', 'warning');
            } else if (senha.length > 0) {
                group.classList.add('warning');
                group.classList.remove('error', 'success');
            } else {
                group.classList.remove('error', 'success', 'warning');
            }
        });

        // Validação em tempo real da confirmação de senha
        document.getElementById('confirmar_senha').addEventListener('input', function() {
            const senha = document.getElementById('senha').value;
            const confirmarSenha = this.value;
            const group = this.closest('.form-group');
            
            <?php
session_start();

if (!isset($_SESSION['usersystem_logado'])) {
    $_SESSION['erro_usuario'] = "Acesso negado.";
    header("Location: ../adicionar_usuario.php");
    exit;
}

require_once "../../lib/config.php";

// Verificar se é administrador
$usuario_logado_id = $_SESSION['usersystem_id'];
try {
    $stmt = $conn->prepare("SELECT usuario_nivel_id FROM tb_usuarios_sistema WHERE usuario_id = :id");
    $stmt->bindParam(':id', $usuario_logado_id);
    $stmt->execute();
    $usuario_logado = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario_logado || $usuario_logado['usuario_nivel_id'] != 1) {
        $_SESSION['erro_usuario'] = "Acesso negado. Apenas administradores podem criar usuários.";
        header("Location: ../adicionar_usuario.php");
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['erro_usuario'] = "Erro ao verificar permissões.";
    header("Location: ../adicionar_usuario.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['erro_usuario'] = "Método não permitido.";
    header("Location: ../adicionar_usuario.php");
    exit;
}

try {
    function sanitize($data) {
        return htmlspecialchars(strip_tags(trim($data)));
    }

    function validarSenha($senha) {
        // Mínimo 8 caracteres, pelo menos uma maiúscula, uma minúscula, um número e um caractere especial
        return strlen($senha) >= 8 && 
               preg_match('/[A-Z]/', $senha) && 
               preg_match('/[a-z]/', $senha) && 
               preg_match('/\d/', $senha) && 
               preg_match('/[!@#$%^&*]/', $senha);
    }

    // Capturar e sanitizar dados
    $nome = sanitize($_POST['nome'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $login = sanitize($_POST['login'] ?? '');
    $departamento = sanitize($_POST['departamento'] ?? '');
    $nivel = (int)($_POST['nivel'] ?? 0);
    $status = sanitize($_POST['status'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $confirmar_senha = $_POST['confirmar_senha'] ?? '';
    $observacoes = sanitize($_POST['observacoes'] ?? '');
    
    $erros = [];
    
    // Validações básicas
    if (empty($nome)) {
        $erros[] = "Nome é obrigatório";
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = "E-mail válido é obrigatório";
    }
    
    if (empty($login) || !preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
        $erros[] = "Login deve conter apenas letras, números, pontos, hífens e sublinhados";
    }
    
    if (empty($departamento)) {
        $erros[] = "Departamento é obrigatório";
    }
    
    if (!in_array($nivel, [1, 2, 3, 4])) {
        $erros[] = "Nível de acesso inválido";
    }
    
    if (!in_array($status, ['ativo', 'inativo'])) {
        $erros[] = "Status inválido";
    }
    
    if (empty($senha)) {
        $erros[] = "Senha é obrigatória";
    } elseif (!validarSenha($senha)) {
        $erros[] = "Senha deve ter pelo menos 8 caracteres, incluindo maiúscula, minúscula, número e caractere especial";
    }
    
    if ($senha !== $confirmar_senha) {
        $erros[] = "Senhas não coincidem";
    }
    
    // Verificar se login já existe
    if (empty($erros)) {
        $stmt = $conn->prepare("SELECT usuario_id FROM tb_usuarios_sistema WHERE usuario_login = :login");
        $stmt->bindParam(':login', $login);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $erros[] = "Login já está em uso";
        }
    }
    
    // Verificar se email já existe
    if (empty($erros)) {
        $stmt = $conn->prepare("SELECT usuario_id FROM tb_usuarios_sistema WHERE usuario_email = :email");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $erros[] = "E-mail já está em uso";
        }
    }
    
    // Se houver erros, retornar com dados preservados
    if (!empty($erros)) {
        $_SESSION['erro_usuario'] = implode(', ', $erros);
        $_SESSION['dados_form_usuario'] = $_POST;
        unset($_SESSION['dados_form_usuario']['senha'], $_SESSION['dados_form_usuario']['confirmar_senha']);
        header("Location: ../adicionar_usuario.php");
        exit;
    }
    
    // Criptografar senha
    $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
    
    // Inserir no banco de dados
    $stmt = $conn->prepare("
        INSERT INTO tb_usuarios_sistema (
            usuario_nome,
            usuario_email,
            usuario_login,
            usuario_senha,
            usuario_departamento,
            usuario_nivel_id,
            usuario_status,
            usuario_data_criacao
        ) VALUES (
            :nome,
            :email,
            :login,
            :senha,
            :departamento,
            :nivel,
            :status,
            NOW()
        )
    ");
    
    $stmt->bindParam(':nome', $nome);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':login', $login);
    $stmt->bindParam(':senha', $senha_hash);
    $stmt->bindParam(':departamento', $departamento);
    $stmt->bindParam(':nivel', $nivel);
    $stmt->bindParam(':status', $status);
    
    $stmt->execute();
    
    $_SESSION['sucesso_usuario'] = "Usuário '{$nome}' criado com sucesso!";
    header("Location: ../lista_usuarios.php");
    exit;
    
} catch (PDOException $e) {
    error_log("Erro ao criar usuário: " . $e->getMessage());
    $_SESSION['erro_usuario'] = "Erro interno do sistema. Tente novamente.";
    $_SESSION['dados_form_usuario'] = $_POST;
    unset($_SESSION['dados_form_usuario']['senha'], $_SESSION['dados_form_usuario']['confirmar_senha']);
    header("Location: ../adicionar_usuario.php");
    exit;
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
    $_SESSION['erro_usuario'] = "Erro inesperado. Tente novamente.";
    $_SESSION['dados_form_usuario'] = $_POST;
    unset($_SESSION['dados_form_usuario']['senha'], $_SESSION['dados_form_usuario']['confirmar_senha']);
    header("Location: ../adicionar_usuario.php");
    exit;
}
?>
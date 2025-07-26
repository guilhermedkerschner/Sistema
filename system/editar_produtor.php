<?php
// Inicia a sessão
session_start();

// Verificar se o usuário está logado
if (!isset($_SESSION['usersystem_logado'])) {
    header("Location: ../acessdeniedrestrict.php"); 
    exit;
}

// Incluir dependências
require_once "../database/conect.php";
require_once "./core/MenuManager.php";

// Verificar se foi passado um ID
$produtor_id = $_GET['id'] ?? null;
if (!$produtor_id || !is_numeric($produtor_id)) {
    $_SESSION['erro_cadastro'] = "ID do produtor inválido.";
    header("Location: agricultura_cadastros.php?aba=produtores&erro=1");
    exit;
}

// Buscar dados do produtor
$produtor = null;
try {
    $stmt = $conn->prepare("
        SELECT p.*, c.com_nome, b.ban_nome, b.ban_codigo
        FROM tb_cad_produtores p
        LEFT JOIN tb_cad_comunidades c ON p.cad_pro_comunidade_id = c.com_id
        LEFT JOIN tb_cad_bancos b ON p.cad_pro_banco_id = b.ban_id
        WHERE p.cad_pro_id = :id AND p.cad_pro_status = 'ativo'
    ");
    $stmt->bindParam(':id', $produtor_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        $_SESSION['erro_cadastro'] = "Produtor não encontrado.";
        header("Location: agricultura_cadastros.php?aba=produtores&erro=1");
        exit;
    }
    
    $produtor = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar produtor: " . $e->getMessage());
    $_SESSION['erro_cadastro'] = "Erro ao carregar dados do produtor.";
    header("Location: agricultura_cadastros.php?aba=produtores&erro=1");
    exit;
}

// Buscar informações do usuário logado
$usuario_id = $_SESSION['usersystem_id'];
$usuario_dados = [];

try {
    $stmt = $conn->prepare("
        SELECT 
            usuario_id, usuario_nome, usuario_departamento, usuario_nivel_id,
            usuario_email, usuario_telefone, usuario_status, usuario_data_criacao,
            usuario_ultimo_acesso
        FROM tb_usuarios_sistema 
        WHERE usuario_id = :id
    ");
    $stmt->bindParam(':id', $usuario_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $usuario_dados = $stmt->fetch(PDO::FETCH_ASSOC);
        $_SESSION['usersystem_nome'] = $usuario_dados['usuario_nome'];
        $_SESSION['usersystem_departamento'] = $usuario_dados['usuario_departamento'];
        $_SESSION['usersystem_nivel'] = $usuario_dados['usuario_nivel_id'];
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar dados do usuário: " . $e->getMessage());
}

// Inicializar MenuManager
$userSession = [
    'usuario_id' => $usuario_dados['usuario_id'] ?? $usuario_id,
    'usuario_nome' => $usuario_dados['usuario_nome'] ?? $_SESSION['usersystem_nome'],
    'usuario_departamento' => $usuario_dados['usuario_departamento'] ?? '',
    'usuario_nivel_id' => $usuario_dados['usuario_nivel_id'] ?? 4,
    'usuario_email' => $usuario_dados['usuario_email'] ?? ''
];

try {
    $menuManager = new MenuManager($userSession);
    $themeColors = $menuManager->getThemeColors();
    $availableModules = $menuManager->getAvailableModules();
} catch (Exception $e) {
    $themeColors = ['primary' => '#4169E1'];
    $availableModules = [];
}

// Buscar comunidades
$comunidades = [];
try {
    $stmt = $conn->prepare("SELECT com_id, com_nome FROM tb_cad_comunidades WHERE com_status = 'ativo' ORDER BY com_nome");
    $stmt->execute();
    $comunidades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar comunidades: " . $e->getMessage());
}

// Buscar bancos
$bancos = [];
try {
    $stmt = $conn->prepare("SELECT ban_id, ban_codigo, ban_nome FROM tb_cad_bancos WHERE ban_status = 'ativo' ORDER BY ban_nome");
    $stmt->execute();
    $bancos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar bancos: " . $e->getMessage());
}

// Verificar mensagens de feedback
$mensagem_sucesso = $_SESSION['sucesso_edicao'] ?? '';
$mensagem_erro = $_SESSION['erro_edicao'] ?? '';
unset($_SESSION['sucesso_edicao'], $_SESSION['erro_edicao']);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../../img/logo_eai.ico" type="imagem/x-icon">
    <title>Editar Produtor - Sistema da Prefeitura</title>
    
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
        /* Container principal - respeitar o menu lateral */
        .editar-container {
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 30px;
            min-height: calc(100vh - 70px);
            background: #f8fafc;
            box-sizing: border-box;
        }

        /* Header da página */
        .page-header {
            background: #4169E1;
            color: white;
            padding: 40px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(65, 105, 225, 0.15);
            width: 100%;
            box-sizing: border-box;
        }

        .page-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .page-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            font-weight: 400;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            font-size: 15px;
            color: #6b7280;
            background: white;
            padding: 20px 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            width: 100%;
            box-sizing: border-box;
        }

        .breadcrumb a {
            color: #4169E1;
            text-decoration: none;
            font-weight: 500;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb i {
            color: #9ca3af;
        }

        /* Container do formulário */
        .form-container {
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            width: 100%;
            box-sizing: border-box;
        }

        /* Grid do formulário - usar mais colunas quando há espaço */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        /* Para telas grandes, usar 3 colunas */
        @media (min-width: 1400px) {
            .form-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 30px;
            }
        }

        /* Para telas extra grandes, usar 4 colunas */
        @media (min-width: 1800px) {
            .form-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 35px;
            }
        }

        /* Grupos de formulário */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #fafbfc;
            box-sizing: border-box;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #4169E1;
            background: white;
            box-shadow: 0 0 0 3px rgba(65, 105, 225, 0.1);
        }

        /* Seções do formulário */
        .form-section {
            margin-bottom: 35px;
            padding: 30px;
            background: #f8fafc;
            border-radius: 12px;
            border-left: 4px solid #4169E1;
            border: 1px solid #e5e7eb;
            width: 100%;
            box-sizing: border-box;
        }

        .form-section h3 {
            color: #1f2937;
            margin-bottom: 20px;
            font-size: 1.3rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Botões */
        .form-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 2px solid #e5e7eb;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 14px;
            min-width: 120px;
            justify-content: center;
        }

        .btn-primary {
            background: #4169E1;
            color: white;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .btn-primary:hover {
            background: #3557c4;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        /* Alertas */
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 500;
            width: 100%;
            box-sizing: border-box;
        }

        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        /* Caixa de informações */
        .info-box {
            background: #f0f9ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            padding: 18px 22px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 14px;
            width: 100%;
            box-sizing: border-box;
        }

        .info-box i {
            font-size: 16px;
            margin-top: 2px;
        }

        /* Melhorar a aparência dos selects */
        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 14px;
            padding-right: 35px;
        }

        /* Layout responsivo - ajustado para respeitar o sidebar */
        @media (min-width: 1200px) and (max-width: 1399px) {
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 25px;
            }
        }

        @media (max-width: 1199px) {
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }
            
            .editar-container {
                padding: 25px;
            }
            
            .form-container {
                padding: 30px;
            }
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .form-buttons {
                flex-direction: column;
                align-items: stretch;
            }
            
            .btn {
                min-width: auto;
            }
            
            .editar-container {
                padding: 20px;
            }
            
            .form-container {
                padding: 25px;
            }
            
            .page-header {
                padding: 25px;
            }
            
            .page-title {
                font-size: 2rem;
            }
            
            .breadcrumb {
                padding: 15px 20px;
                font-size: 14px;
            }
            
            .form-section {
                padding: 20px;
            }
        }

        @media (max-width: 480px) {
            .page-title {
                font-size: 1.8rem;
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            
            .form-section h3 {
                font-size: 1.1rem;
            }
            
            .editar-container {
                padding: 15px;
            }
        }

        /* Garantir que todos os elementos usem box-sizing */
        * {
            box-sizing: border-box;
        }

        /* Efeitos de hover nos campos */
        .form-group input:hover,
        .form-group select:hover {
            border-color: #9ca3af;
        }

        /* Melhorar contraste dos placeholders */
        .form-group input::placeholder {
            color: #9ca3af;
            font-weight: 400;
        }

        /* Animações suaves */
        .form-group input,
        .form-group select,
        .btn {
            transition: all 0.3s ease;
        }

        /* Ajustes específicos para sidebar */
        @media (min-width: 769px) {
            /* Quando o sidebar está visível, garantir que não sobreponha */
            .main-content {
                margin-left: 0; /* Deixar o CSS base.css gerenciar */
            }
        }

        /* Para telas muito pequenas, quando o sidebar fica oculto */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }

        /* Melhorar espaçamento em telas grandes */
        @media (min-width: 1600px) {
            .editar-container {
                padding: 40px 50px;
            }
            
            .form-container {
                padding: 50px;
            }
            
            .page-header {
                padding: 50px;
            }
        }

        /* Ajustar para ultra wide screens */
        @media (min-width: 2000px) {
            .form-grid {
                grid-template-columns: repeat(5, 1fr);
                gap: 40px;
            }
            
            .editar-container {
                padding: 50px 60px;
            }
        }
    </style>
</head>
<body>
    <?php 
    try {
        include 'includes/header.php'; 
        include 'includes/sidebar.php';
    } catch (Exception $e) {
        echo "<!-- Erro ao incluir header/sidebar -->";
    }
    ?>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="editar-container">
            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <a href="dashboard.php">
                    <i class="fas fa-home"></i>
                    Dashboard
                </a>
                <i class="fas fa-chevron-right"></i>
                <a href="cadastros.php?aba=produtores">Cadastros</a>
                <i class="fas fa-chevron-right"></i>
                <span>Editar Produtor</span>
            </div>

            <!-- Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-user-edit"></i>
                    Editar Produtor
                </h1>
                <p class="page-subtitle">
                    Altere os dados do produtor: <?= htmlspecialchars($produtor['cad_pro_nome']) ?>
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

            <!-- Info Box -->
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Informações do cadastro:</strong><br>
                    Cadastrado em: <?= date('d/m/Y H:i', strtotime($produtor['cad_pro_data_cadastro'])) ?>
                    <?php if ($produtor['cad_pro_data_atualizacao'] != $produtor['cad_pro_data_cadastro']): ?>
                        | Última atualização: <?= date('d/m/Y H:i', strtotime($produtor['cad_pro_data_atualizacao'])) ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Formulário -->
            <div class="form-container">
                <form id="formEditarProdutor" method="POST" action="controller/atualizar_produtor.php">
                    <input type="hidden" name="produtor_id" value="<?= $produtor['cad_pro_id'] ?>">
                    
                    <!-- Dados Pessoais -->
                    <div class="form-section">
                        <h3>
                            <i class="fas fa-user"></i>
                            Dados Pessoais
                        </h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="nome">Nome Completo *</label>
                                <input type="text" id="nome" name="nome" value="<?= htmlspecialchars($produtor['cad_pro_nome']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="cpf">CPF *</label>
                                <input type="text" id="cpf" name="cpf" value="<?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $produtor['cad_pro_cpf']) ?>" required maxlength="14" placeholder="000.000.000-00">
                            </div>
                            <div class="form-group">
                                <label for="telefone">Telefone</label>
                                <input type="text" id="telefone" name="telefone" value="<?= htmlspecialchars($produtor['cad_pro_telefone']) ?>" maxlength="20" placeholder="(00) 00000-0000">
                            </div>
                            <div class="form-group">
                                <label for="comunidade">Comunidade</label>
                                <select id="comunidade" name="comunidade_id">
                                    <option value="">Selecione uma comunidade</option>
                                    <?php foreach ($comunidades as $comunidade): ?>
                                        <option value="<?= $comunidade['com_id'] ?>" <?= $comunidade['com_id'] == $produtor['cad_pro_comunidade_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($comunidade['com_nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Dados Bancários -->
                    <div class="form-section">
                        <h3>
                            <i class="fas fa-credit-card"></i>
                            Dados Bancários
                        </h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="titular_nome">Nome do Titular *</label>
                                <input type="text" id="titular_nome" name="titular_nome" value="<?= htmlspecialchars($produtor['cad_pro_titular_nome']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="titular_cpf">CPF do Titular *</label>
                                <input type="text" id="titular_cpf" name="titular_cpf" value="<?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $produtor['cad_pro_titular_cpf']) ?>" required maxlength="14" placeholder="000.000.000-00">
                            </div>
                            <div class="form-group">
                                <label for="titular_telefone">Telefone do Titular</label>
                                <input type="text" id="titular_telefone" name="titular_telefone" value="<?= htmlspecialchars($produtor['cad_pro_titular_telefone']) ?>" maxlength="20" placeholder="(00) 00000-0000">
                            </div>
                            <div class="form-group">
                                <label for="banco">Banco *</label>
                                <select id="banco" name="banco_id" required>
                                    <option value="">Selecione um banco</option>
                                    <?php foreach ($bancos as $banco): ?>
                                        <option value="<?= $banco['ban_id'] ?>" <?= $banco['ban_id'] == $produtor['cad_pro_banco_id'] ? 'selected' : '' ?>>
                                            <?= $banco['ban_codigo'] . ' - ' . htmlspecialchars($banco['ban_nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="agencia">Agência *</label>
                                <input type="text" id="agencia" name="agencia" value="<?= htmlspecialchars($produtor['cad_pro_agencia']) ?>" required maxlength="20" placeholder="0000">
                            </div>
                            <div class="form-group">
                                <label for="conta">Conta *</label>
                                <input type="text" id="conta" name="conta" value="<?= htmlspecialchars($produtor['cad_pro_conta']) ?>" required maxlength="30" placeholder="00000-0">
                            </div>
                            <div class="form-group">
                                <label for="tipo_conta">Tipo de Conta *</label>
                                <select id="tipo_conta" name="tipo_conta" required>
                                    <option value="">Selecione o tipo</option>
                                    <option value="corrente" <?= $produtor['cad_pro_tipo_conta'] == 'corrente' ? 'selected' : '' ?>>Conta Corrente</option>
                                    <option value="poupanca" <?= $produtor['cad_pro_tipo_conta'] == 'poupanca' ? 'selected' : '' ?>>Conta Poupança</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Botões -->
                    <div class="form-buttons">
                        <a href="agricultura_cadastros.php?aba=produtores" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>
                            Voltar
                        </a>
                        <button type="button" class="btn btn-danger" onclick="excluirProdutor(<?= $produtor['cad_pro_id'] ?>, '<?= htmlspecialchars($produtor['cad_pro_nome']) ?>')">
                            <i class="fas fa-trash"></i>
                            Excluir
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            Salvar Alterações
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="assets/js/main.js"></script>
    <script>
        // Máscara para CPF
        function mascaraCPF(cpf) {
            cpf = cpf.replace(/\D/g, '');
            cpf = cpf.replace(/(\d{3})(\d)/, '$1.$2');
            cpf = cpf.replace(/(\d{3})(\d)/, '$1.$2');
            cpf = cpf.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            return cpf;
        }

        // Máscara para telefone
        function mascaraTelefone(telefone) {
            telefone = telefone.replace(/\D/g, '');
            if (telefone.length <= 10) {
                telefone = telefone.replace(/(\d{2})(\d)/, '($1) $2');
                telefone = telefone.replace(/(\d{4})(\d)/, '$1-$2');
            } else {
                telefone = telefone.replace(/(\d{2})(\d)/, '($1) $2');
                telefone = telefone.replace(/(\d{5})(\d)/, '$1-$2');
            }
            return telefone;
        }

        // Aplicar máscaras
        document.getElementById('cpf').addEventListener('input', function(e) {
            e.target.value = mascaraCPF(e.target.value);
        });

        document.getElementById('titular_cpf').addEventListener('input', function(e) {
            e.target.value = mascaraCPF(e.target.value);
        });

        document.getElementById('telefone').addEventListener('input', function(e) {
            e.target.value = mascaraTelefone(e.target.value);
        });

        document.getElementById('titular_telefone').addEventListener('input', function(e) {
            e.target.value = mascaraTelefone(e.target.value);
        });

        // Função para excluir produtor
        function excluirProdutor(id, nome) {
            if (!confirm(`Tem certeza que deseja excluir o produtor "${nome}"?`)) {
                return;
            }

            if (!confirm(`ATENÇÃO: Esta ação não pode ser desfeita!\n\nO produtor "${nome}" será removido permanentemente do sistema.\n\nDeseja realmente continuar?`)) {
                return;
            }

            // Mostrar loading
            const loadingHtml = `
                <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                           background: rgba(0,0,0,0.5); display: flex; align-items: center; 
                           justify-content: center; z-index: 9999;">
                    <div style="background: white; padding: 30px; border-radius: 10px; text-align: center;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #4169E1; margin-bottom: 15px;"></i>
                        <p>Excluindo produtor...</p>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', loadingHtml);

            fetch('controller/excluir_produtor.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ id: id })
            })
            .then(response => response.json())
            .then(data => {
                document.querySelector('[style*="z-index: 9999"]').remove();
                
                if (data.success) {
                    alert('Produtor excluído com sucesso!');
                    window.location.href = 'cadastros.php?aba=produtores&sucesso_exclusao=1';
                } else {
                    alert('Erro ao excluir produtor: ' + data.message);
                }
            })
            .catch(error => {
                document.querySelector('[style*="z-index: 9999"]').remove();
                console.error('Error:', error);
                alert('Erro interno do sistema.');
            });
        }

        // Validação do formulário
        document.getElementById('formEditarProdutor').addEventListener('submit', function(e) {
            const nome = document.getElementById('nome').value.trim();
            const cpf = document.getElementById('cpf').value.replace(/\D/g, '');
            const titular_nome = document.getElementById('titular_nome').value.trim();
            const titular_cpf = document.getElementById('titular_cpf').value.replace(/\D/g, '');
            const banco = document.getElementById('banco').value;
            const agencia = document.getElementById('agencia').value.trim();
            const conta = document.getElementById('conta').value.trim();
            const tipo_conta = document.getElementById('tipo_conta').value;

            let erros = [];

            if (!nome) erros.push('Nome é obrigatório');
            if (!cpf || cpf.length !== 11) erros.push('CPF deve ter 11 dígitos');
            if (!titular_nome) erros.push('Nome do titular é obrigatório');
            if (!titular_cpf || titular_cpf.length !== 11) erros.push('CPF do titular deve ter 11 dígitos');
            if (!banco) erros.push('Banco é obrigatório');
            if (!agencia) erros.push('Agência é obrigatória');
            if (!conta) erros.push('Conta é obrigatória');
            if (!tipo_conta) erros.push('Tipo de conta é obrigatório');

            if (erros.length > 0) {
                e.preventDefault();
                alert('Por favor, corrija os seguintes erros:\n\n• ' + erros.join('\n• '));
                return false;
            }

            if (!confirm('Confirma a atualização dos dados do produtor?')) {
                e.preventDefault();
                return false;
            }
        });
    </script>
</body>
</html>
   <!-- JavaScript -->
   <script src="assets/js/main.js"></script>
   <script>
       // Máscara para CPF
       function mascaraCPF(cpf) {
           cpf = cpf.replace(/\D/g, '');
           cpf = cpf.replace(/(\d{3})(\d)/, '$1.$2');
           cpf = cpf.replace(/(\d{3})(\d)/, '$1.$2');
           cpf = cpf.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
           return cpf;
       }

       // Máscara para telefone
       function mascaraTelefone(telefone) {
           telefone = telefone.replace(/\D/g, '');
           if (telefone.length <= 10) {
               telefone = telefone.replace(/(\d{2})(\d)/, '($1) $2');
               telefone = telefone.replace(/(\d{4})(\d)/, '$1-$2');
           } else {
               telefone = telefone.replace(/(\d{2})(\d)/, '($1) $2');
               telefone = telefone.replace(/(\d{5})(\d)/, '$1-$2');
           }
           return telefone;
       }

       // Aplicar máscaras
       document.getElementById('cpf').addEventListener('input', function(e) {
           e.target.value = mascaraCPF(e.target.value);
       });

       document.getElementById('titular_cpf').addEventListener('input', function(e) {
           e.target.value = mascaraCPF(e.target.value);
       });

       document.getElementById('telefone').addEventListener('input', function(e) {
           e.target.value = mascaraTelefone(e.target.value);
       });

       document.getElementById('titular_telefone').addEventListener('input', function(e) {
           e.target.value = mascaraTelefone(e.target.value);
       });

       // Função para excluir produtor
       function excluirProdutor(id, nome) {
           if (!confirm(`Tem certeza que deseja excluir o produtor "${nome}"?`)) {
               return;
           }

           if (!confirm(`ATENÇÃO: Esta ação não pode ser desfeita!\n\nO produtor "${nome}" será removido permanentemente do sistema.\n\nDeseja realmente continuar?`)) {
               return;
           }

           // Mostrar loading
           const loadingHtml = `
               <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                          background: rgba(0,0,0,0.5); display: flex; align-items: center; 
                          justify-content: center; z-index: 9999;">
                   <div style="background: white; padding: 30px; border-radius: 10px; text-align: center;">
                       <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #4169E1; margin-bottom: 15px;"></i>
                       <p>Excluindo produtor...</p>
                   </div>
               </div>
           `;
           document.body.insertAdjacentHTML('beforeend', loadingHtml);

           fetch('controller/excluir_produtor.php', {
               method: 'POST',
               headers: {
                   'Content-Type': 'application/json',
               },
               body: JSON.stringify({ id: id })
           })
           .then(response => response.json())
           .then(data => {
               document.querySelector('[style*="z-index: 9999"]').remove();
               
               if (data.success) {
                   alert('Produtor excluído com sucesso!');
                   window.location.href = 'agricultura_cadastros.php?aba=produtores&sucesso_exclusao=1';
               } else {
                   alert('Erro ao excluir produtor: ' + data.message);
               }
           })
           .catch(error => {
               document.querySelector('[style*="z-index: 9999"]').remove();
               console.error('Error:', error);
               alert('Erro interno do sistema.');
           });
       }

       // Validação do formulário
       document.getElementById('formEditarProdutor').addEventListener('submit', function(e) {
           const nome = document.getElementById('nome').value.trim();
           const cpf = document.getElementById('cpf').value.replace(/\D/g, '');
           const titular_nome = document.getElementById('titular_nome').value.trim();
           const titular_cpf = document.getElementById('titular_cpf').value.replace(/\D/g, '');
           const banco = document.getElementById('banco').value;
           const agencia = document.getElementById('agencia').value.trim();
           const conta = document.getElementById('conta').value.trim();
           const tipo_conta = document.getElementById('tipo_conta').value;

           let erros = [];

           if (!nome) erros.push('Nome é obrigatório');
           if (!cpf || cpf.length !== 11) erros.push('CPF deve ter 11 dígitos');
           if (!titular_nome) erros.push('Nome do titular é obrigatório');
           if (!titular_cpf || titular_cpf.length !== 11) erros.push('CPF do titular deve ter 11 dígitos');
           if (!banco) erros.push('Banco é obrigatório');
           if (!agencia) erros.push('Agência é obrigatória');
           if (!conta) erros.push('Conta é obrigatória');
           if (!tipo_conta) erros.push('Tipo de conta é obrigatório');

           if (erros.length > 0) {
               e.preventDefault();
               alert('Por favor, corrija os seguintes erros:\n\n• ' + erros.join('\n• '));
               return false;
           }

           if (!confirm('Confirma a atualização dos dados do produtor?')) {
               e.preventDefault();
               return false;
           }
       });
   </script>
</body>
</html>
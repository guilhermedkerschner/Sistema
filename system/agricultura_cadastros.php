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
$is_admin = ($usuario_dados['usuario_nivel_id'] == 1);

// Buscar comunidades para o select
$comunidades = [];
try {
    $stmt = $conn->prepare("SELECT com_id, com_nome FROM tb_cad_comunidades WHERE com_status = 'ativo' ORDER BY com_nome");
    $stmt->execute();
    $comunidades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar comunidades: " . $e->getMessage());
}

// Buscar bancos para o select
$bancos = [];
try {
    $stmt = $conn->prepare("SELECT ban_id, ban_codigo, ban_nome FROM tb_cad_bancos WHERE ban_status = 'ativo' ORDER BY ban_nome");
    $stmt->execute();
    $bancos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar bancos: " . $e->getMessage());
}

// Definir qual aba está ativa
$aba_ativa = $_GET['aba'] ?? 'produtores';

// Buscar produtores cadastrados para a lista
$produtores = [];
if ($aba_ativa === 'produtores') {
    try {
        $stmt = $conn->prepare("
            SELECT 
                p.*,
                c.com_nome,
                b.ban_nome,
                b.ban_codigo,
                u.usuario_nome as cadastrado_por
            FROM tb_cad_produtores p
            LEFT JOIN tb_cad_comunidades c ON p.cad_pro_comunidade_id = c.com_id
            LEFT JOIN tb_cad_bancos b ON p.cad_pro_banco_id = b.ban_id
            LEFT JOIN tb_usuarios_sistema u ON p.cad_pro_usuario_cadastro = u.usuario_id
            WHERE p.cad_pro_status = 'ativo'
            ORDER BY p.cad_pro_nome
        ");
        $stmt->execute();
        $produtores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erro ao buscar produtores: " . $e->getMessage());
    }
}

// Verificar se há mensagens de feedback
$sucesso = $_GET['sucesso'] ?? '';
$erro = $_GET['erro'] ?? '';
$mensagem_sucesso = $_SESSION['sucesso_cadastro'] ?? '';
$mensagem_erro = $_SESSION['erro_cadastro'] ?? '';
unset($_SESSION['sucesso_cadastro'], $_SESSION['erro_cadastro']);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../../img/logo_eai.ico" type="imagem/x-icon">
    <title>Sistema de Cadastros - Sistema da Prefeitura</title>
    
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
        .cadastros-container {
            padding: 30px;
        }

        .page-header {
            background: #4169E1;
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(65, 105, 225, 0.15);
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

        /* Menu de abas */
        .tabs-menu {
            display: flex;
            background: white;
            border-radius: 12px;
            padding: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow-x: auto;
        }

        .tab-item {
            flex: 1;
            min-width: 140px;
            padding: 15px 20px;
            text-align: center;
            background: transparent;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            color: #6b7280;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .tab-item:hover {
            background: #f3f4f6;
            color: #374151;
        }

        .tab-item.active {
            background: #4169E1;
            color: white;
            box-shadow: 0 2px 8px rgba(65, 105, 225, 0.3);
        }

        /* Conteúdo das abas */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Botão cadastrar */
        .btn-cadastrar {
            background: #4169E1;
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

        .btn-cadastrar:hover {
            background: #3557c4;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(65, 105, 225, 0.3);
        }

        /* Formulário de cadastro */
        .form-cadastro {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: none;
        }

        .form-cadastro.show {
            display: block;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #f9fafb;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #4169E1;
            background: white;
            box-shadow: 0 0 0 3px rgba(65, 105, 225, 0.1);
        }

        /* Seções do formulário */
        .form-section {
            margin-bottom: 40px;
            padding: 25px;
            background: #f8fafc;
            border-radius: 10px;
            border-left: 4px solid #4169E1;
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

        /* Botões do formulário */
        .form-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        .btn-primary {
            background: #4169E1;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary:hover {
            background: #3557c4;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        /* Lista de produtores */
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
        }

        .lista-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #374151;
            display: flex;
            align-items: center;
            gap: 10px;
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

        /* Em desenvolvimento */
        .em-desenvolvimento {
            text-align: center;
            padding: 80px 20px;
            color: #6b7280;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .em-desenvolvimento i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .tabs-menu {
                flex-wrap: wrap;
            }
            
            .tab-item {
                min-width: auto;
                flex: 1 1 calc(50% - 4px);
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
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="cadastros-container">
            <!-- Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-users"></i>
                    Sistema de Cadastros
                </h1>
                <p class="page-subtitle">
                    Gerencie todos os cadastros do sistema de forma centralizada
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

            <!-- Menu de Abas -->
            <div class="tabs-menu">
                <a href="?aba=produtores" class="tab-item <?= $aba_ativa === 'produtores' ? 'active' : '' ?>">
                    <i class="fas fa-user-tie"></i>
                    Produtores
                </a>
                <a href="?aba=servicos" class="tab-item <?= $aba_ativa === 'servicos' ? 'active' : '' ?>">
                    <i class="fas fa-cogs"></i>
                    Serviços
                </a>
                <a href="?aba=maquinas" class="tab-item <?= $aba_ativa === 'maquinas' ? 'active' : '' ?>">
                    <i class="fas fa-tractor"></i>
                    Máquinas
                </a>
                <a href="?aba=comunidades" class="tab-item <?= $aba_ativa === 'comunidades' ? 'active' : '' ?>">
                    <i class="fas fa-home"></i>
                    Comunidades
                </a>
                <a href="?aba=veterinarios" class="tab-item <?= $aba_ativa === 'veterinarios' ? 'active' : '' ?>">
                    <i class="fas fa-user-md"></i>
                    Veterinários
                </a>
                <a href="?aba=responsaveis" class="tab-item <?= $aba_ativa === 'responsaveis' ? 'active' : '' ?>">
                    <i class="fas fa-user-shield"></i>
                    Responsáveis
                </a>
                <a href="?aba=bancos" class="tab-item <?= $aba_ativa === 'bancos' ? 'active' : '' ?>">
                    <i class="fas fa-university"></i>
                    Bancos
                </a>
            </div>

            <!-- Conteúdo da aba Produtores -->
            <div class="tab-content <?= $aba_ativa === 'produtores' ? 'active' : '' ?>">
                
                <!-- Botão Cadastrar -->
                <button type="button" class="btn-cadastrar" onclick="toggleFormulario()">
                    <i class="fas fa-plus"></i>
                    Cadastrar Novo Produtor
                </button>

                <!-- Formulário de Cadastro -->
                <div class="form-cadastro" id="formCadastro">
                    <form id="formProdutor" method="POST" action="controller/salvar_produtor.php">
                        <!-- Dados Pessoais -->
                        <div class="form-section">
                            <h3>
                                <i class="fas fa-user"></i>
                                Dados Pessoais
                            </h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="nome">Nome Completo *</label>
                                    <input type="text" id="nome" name="nome" required>
                                </div>
                                <div class="form-group">
                                    <label for="cpf">CPF *</label>
                                    <input type="text" id="cpf" name="cpf" required maxlength="14" placeholder="000.000.000-00">
                                </div>
                                <div class="form-group">
                                    <label for="telefone">Telefone</label>
                                    <input type="text" id="telefone" name="telefone" maxlength="20" placeholder="(00) 00000-0000">
                                </div>
                                <div class="form-group">
                                    <label for="comunidade">Comunidade</label>
                                    <select id="comunidade" name="comunidade_id">
                                        <option value="">Selecione uma comunidade</option>
                                        <?php foreach ($comunidades as $comunidade): ?>
                                            <option value="<?= $comunidade['com_id'] ?>"><?= htmlspecialchars($comunidade['com_nome']) ?></option>
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
                                    <input type="text" id="titular_nome" name="titular_nome" required>
                                </div>
                                <div class="form-group">
                                    <label for="titular_cpf">CPF do Titular *</label>
                                    <input type="text" id="titular_cpf" name="titular_cpf" required maxlength="14" placeholder="000.000.000-00">
                                </div>
                                <div class="form-group">
                                    <label for="titular_telefone">Telefone do Titular</label>
                                    <input type="text" id="titular_telefone" name="titular_telefone" maxlength="20" placeholder="(00) 00000-0000">
                                </div>
                                <div class="form-group">
                                    <label for="banco">Banco *</label>
                                    <select id="banco" name="banco_id" required>
                                        <option value="">Selecione um banco</option>
                                        <?php foreach ($bancos as $banco): ?>
                                            <option value="<?= $banco['ban_id'] ?>"><?= $banco['ban_codigo'] . ' - ' . htmlspecialchars($banco['ban_nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="agencia">Agência *</label>
                                    <input type="text" id="agencia" name="agencia" required maxlength="20" placeholder="0000">
                                </div>
                                <div class="form-group">
                                    <label for="conta">Conta *</label>
                                    <input type="text" id="conta" name="conta" required maxlength="30" placeholder="00000-0">
                                </div>
                                <div class="form-group">
                                    <label for="tipo_conta">Tipo de Conta *</label>
                                    <select id="tipo_conta" name="tipo_conta" required>
                                        <option value="">Selecione o tipo</option>
                                        <option value="corrente">Conta Corrente</option>
                                        <option value="poupanca">Conta Poupança</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Botões -->
                        <div class="form-buttons">
                            <button type="button" class="btn-secondary" onclick="cancelarCadastro()">
                                <i class="fas fa-times"></i>
                                Cancelar
                            </button>
                            <button type="submit" class="btn-primary">
                                <i class="fas fa-save"></i>
                                Salvar Produtor
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Lista de Produtores -->
                <div class="lista-container">
                    <div class="lista-header">
                        <h3 class="lista-title">
                            <i class="fas fa-list"></i>
                            Produtores Cadastrados (<?= count($produtores) ?>)
                        </h3>
                    </div>

                    <?php if (count($produtores) > 0): ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th>CPF</th>
                                        <th>Telefone</th>
                                        <th>Comunidade</th>
                                        <th>Banco</th>
                                        <th>Status</th>
                                        <th>Cadastrado em</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($produtores as $produtor): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($produtor['cad_pro_nome']) ?></strong>
                                            <br>
                                            <small style="color: #6b7280;">
                                                Titular: <?= htmlspecialchars($produtor['cad_pro_titular_nome']) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $produtor['cad_pro_cpf']) ?>
                                        </td>
                                        <td><?= htmlspecialchars($produtor['cad_pro_telefone']) ?></td>
                                        <td><?= htmlspecialchars($produtor['com_nome'] ?? 'Não informada') ?></td>
                                        <td>
                                            <?= $produtor['ban_codigo'] ?> - <?= htmlspecialchars($produtor['ban_nome']) ?>
                                            <br>
                                            <small style="color: #6b7280;">
                                                Ag: <?= $produtor['cad_pro_agencia'] ?> 
                                                Conta: <?= $produtor['cad_pro_conta'] ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= $produtor['cad_pro_status'] ?>">
                                                <?= ucfirst($produtor['cad_pro_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= date('d/m/Y H:i', strtotime($produtor['cad_pro_data_cadastro'])) ?>
                                            <br>
                                            <small style="color: #6b7280;">
                                                por <?= htmlspecialchars($produtor['cadastrado_por']) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="acoes">
                                                <button class="btn-acao btn-editar" 
                                                        onclick="editarProdutor(<?= $produtor['cad_pro_id'] ?>)"
                                                        title="Editar">
                                                    <i class="fas fa-edit"></i>
                                                    Editar
                                                </button>
                                                <button class="btn-acao btn-excluir" 
                                                        onclick="excluirProdutor(<?= $produtor['cad_pro_id'] ?>, '<?= htmlspecialchars($produtor['cad_pro_nome']) ?>')"
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
                    <?php else: ?>
                        <div class="sem-registros">
                            <i class="fas fa-user-times"></i>
                            <h3>Nenhum produtor cadastrado</h3>
                            <p>Ainda não há produtores cadastrados no sistema.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Outras abas (em desenvolvimento) -->
            <?php if ($aba_ativa !== 'produtores'): ?>
           <div class="tab-content active">
               <div class="em-desenvolvimento">
                   <i class="fas fa-tools"></i>
                   <h3>Em Desenvolvimento</h3>
                   <p>Esta seção está sendo desenvolvida pela nossa equipe.</p>
                   <p>Em breve estará disponível com todas as funcionalidades.</p>
               </div>
           </div>
           <?php endif; ?>
       </div>
   </div>

   <!-- JavaScript -->
   <script src="assets/js/main.js"></script>
   <script>
       // Controlar exibição do formulário
       function toggleFormulario() {
           const form = document.getElementById('formCadastro');
           const btn = document.querySelector('.btn-cadastrar');
           
           if (form.classList.contains('show')) {
               form.classList.remove('show');
               btn.innerHTML = '<i class="fas fa-plus"></i> Cadastrar Novo Produtor';
           } else {
               form.classList.add('show');
               btn.innerHTML = '<i class="fas fa-minus"></i> Ocultar Formulário';
               // Scroll suave para o formulário
               form.scrollIntoView({ behavior: 'smooth', block: 'start' });
           }
       }

       function cancelarCadastro() {
           if (confirm('Tem certeza que deseja cancelar? Todos os dados preenchidos serão perdidos.')) {
               document.getElementById('formProdutor').reset();
               toggleFormulario();
           }
       }

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

       // Auto-preencher dados do titular se for o mesmo produtor
       document.getElementById('nome').addEventListener('blur', function() {
           if (!document.getElementById('titular_nome').value) {
               document.getElementById('titular_nome').value = this.value;
           }
       });

       document.getElementById('cpf').addEventListener('blur', function() {
           if (!document.getElementById('titular_cpf').value) {
               document.getElementById('titular_cpf').value = this.value;
           }
       });

       document.getElementById('telefone').addEventListener('blur', function() {
           if (!document.getElementById('titular_telefone').value) {
               document.getElementById('titular_telefone').value = this.value;
           }
       });

       // Função para editar produtor
       function editarProdutor(id) {
           // Por enquanto, apenas redireciona para uma página de edição
           // Você pode implementar um modal ou página separada
           window.location.href = `editar_produtor.php?id=${id}`;
       }

       // Função para excluir produtor com confirmação
       function excluirProdutor(id, nome) {
           // Primeira confirmação
           if (!confirm(`Tem certeza que deseja excluir o produtor "${nome}"?`)) {
               return;
           }

           // Segunda confirmação mais específica
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

           // Fazer requisição AJAX para excluir
           fetch('controller/excluir_produtor.php', {
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

       // Validação do formulário antes do envio
       document.getElementById('formProdutor').addEventListener('submit', function(e) {
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

           // Confirmar salvamento
           if (!confirm('Confirma o cadastro do produtor com os dados informados?')) {
               e.preventDefault();
               return false;
           }
       });

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
       });
   </script>
</body>
</html>
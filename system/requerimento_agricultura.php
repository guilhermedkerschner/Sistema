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

// Inicializar MenuManager
$userSession = [
    'usuario_id' => $usuario_dados['usuario_id'],
    'usuario_nome' => $usuario_dados['usuario_nome'],
    'usuario_departamento' => $usuario_dados['usuario_departamento'],
    'usuario_nivel_id' => $usuario_dados['usuario_nivel_id'],
    'usuario_email' => $usuario_dados['usuario_email']
];

$menuManager = new MenuManager($userSession);
$availableModules = $menuManager->getAvailableModules();
$is_admin = ($usuario_dados['usuario_nivel_id'] == 1);

// Buscar dados auxiliares
$produtores = [];
$servicos_agricultura = [];

try {
    // Produtores ativos
    $stmt = $conn->prepare("
        SELECT p.cad_pro_id, p.cad_pro_nome, p.cad_pro_cpf, c.com_nome
        FROM tb_cad_produtores p
        LEFT JOIN tb_cad_comunidades c ON p.cad_pro_comunidade_id = c.com_id
        WHERE p.cad_pro_status = 'ativo' 
        ORDER BY p.cad_pro_nome
    ");
    $stmt->execute();
    $produtores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Serviços de agricultura - filtrar pela secretaria de agricultura
    $stmt = $conn->prepare("
        SELECT s.ser_id, s.ser_nome, sec.sec_nome
        FROM tb_cad_servicos s
        LEFT JOIN tb_cad_secretarias sec ON s.ser_secretaria_id = sec.sec_id
        WHERE s.ser_status = 'ativo' 
        AND (sec.sec_nome LIKE '%agricultur%' OR sec.sec_nome LIKE '%agrícol%' OR sec.sec_nome LIKE '%rural%')
        ORDER BY s.ser_nome
    ");
    $stmt->execute();
    $servicos_agricultura = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Erro ao buscar dados auxiliares: " . $e->getMessage());
}

// Função para buscar requerimentos de agricultura
function buscarRequerimentosAgricultura($conn, $filtros = []) {
    $sql = "
        SELECT r.*, p.cad_pro_nome, p.cad_pro_cpf, c.com_nome, 
               s.ser_nome, sec.sec_nome,
               u.usuario_nome as cadastrado_por,
               ua.usuario_nome as atendente
        FROM tb_requerimentos r
        INNER JOIN tb_cad_produtores p ON r.req_produtor_id = p.cad_pro_id
        LEFT JOIN tb_cad_comunidades c ON p.cad_pro_comunidade_id = c.com_id
        INNER JOIN tb_cad_servicos s ON r.req_servico_id = s.ser_id
        LEFT JOIN tb_cad_secretarias sec ON s.ser_secretaria_id = sec.sec_id
        LEFT JOIN tb_usuarios_sistema u ON r.req_usuario_cadastro = u.usuario_id
        LEFT JOIN tb_usuarios_sistema ua ON r.req_usuario_atendimento = ua.usuario_id
        WHERE r.req_tipo = 'agricultura'
    ";
    
    $params = [];
    
    if (!empty($filtros['busca'])) {
        $sql .= " AND (r.req_numero LIKE :busca OR p.cad_pro_nome LIKE :busca OR p.cad_pro_cpf LIKE :busca)";
        $params[':busca'] = '%' . $filtros['busca'] . '%';
    }
    
    if (!empty($filtros['status'])) {
        $sql .= " AND r.req_status = :status";
        $params[':status'] = $filtros['status'];
    }
    
    if (!empty($filtros['data_inicio'])) {
        $sql .= " AND DATE(r.req_data_solicitacao) >= :data_inicio";
        $params[':data_inicio'] = $filtros['data_inicio'];
    }
    
    if (!empty($filtros['data_fim'])) {
        $sql .= " AND DATE(r.req_data_solicitacao) <= :data_fim";
        $params[':data_fim'] = $filtros['data_fim'];
    }
    
    $sql .= " ORDER BY r.req_data_solicitacao DESC";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Capturar filtros
$filtros = [
    'busca' => $_GET['busca'] ?? '',
    'status' => $_GET['status'] ?? '',
    'data_inicio' => $_GET['data_inicio'] ?? '',
    'data_fim' => $_GET['data_fim'] ?? ''
];

// Buscar requerimentos
$requerimentos = buscarRequerimentosAgricultura($conn, $filtros);

// Mensagens de feedback
$mensagem_sucesso = $_SESSION['sucesso_requerimento'] ?? '';
$mensagem_erro = $_SESSION['erro_requerimento'] ?? '';
$dados_form = $_SESSION['dados_form_requerimento'] ?? [];
unset($_SESSION['sucesso_requerimento'], $_SESSION['erro_requerimento'], $_SESSION['dados_form_requerimento']);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../../img/logo_eai.ico" type="imagem/x-icon">
    <title>Requerimentos de Agricultura - Sistema da Prefeitura</title>
    
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
        /* Variáveis CSS seguindo o padrão do sistema */
        :root {
            --primary-color: #28a745;
            --primary-dark: #1e7e34;
            --secondary-color: #6c757d;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-color: #f8fafc;
            --dark-color: #2c3e50;
            --sidebar-width: 280px;
            --header-height: 70px;
            --border-radius: 12px;
            --box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            --transition: all 0.3s ease;
        }

        /* Layout base */
        body {
            margin: 0;
            padding: 0;
            background: var(--light-color);
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            min-height: calc(100vh - var(--header-height));
            transition: var(--transition);
        }

        .main-content.sidebar-collapsed {
            margin-left: 70px;
        }

        .agricultura-container {
            padding: 30px;
            width: 100%;
            max-width: none;
            box-sizing: border-box;
        }

        /* Header da página com tema agricultura (verde) */
        .page-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 30px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(40, 167, 69, 0.15);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(50%, -50%);
        }

        .page-title {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
            z-index: 2;
        }

        .page-title i {
            background: rgba(255, 255, 255, 0.2);
            padding: 15px;
            border-radius: 50%;
            font-size: 1.5rem;
        }

        .page-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 400;
            margin: 0;
            position: relative;
            z-index: 2;
        }

        /* Botão principal */
        .btn-novo-requerimento {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 30px;
            transition: var(--transition);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .btn-novo-requerimento:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }

        /* Card containers */
        .card-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
            border: 1px solid #e5e7eb;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
        }

        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title i {
            color: var(--primary-color);
        }

        /* Formulário */
        .form-requerimento {
            background: white;
            border-radius: var(--border-radius);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
            border: 1px solid #e5e7eb;
            display: none;
        }

        .form-requerimento.show {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-header {
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
        }

        .form-header h3 {
            color: var(--dark-color);
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-weight: 600;
            color: var(--dark-color);
            font-size: 0.95rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: var(--transition);
            background: #f9fafb;
            box-sizing: border-box;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            background: white;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        /* Botões */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        .btn-secondary:hover {
            background: #4b5563;
            transform: translateY(-1px);
        }

        .form-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #f3f4f6;
        }

        /* Filtros */
        .filtros-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
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
            color: var(--dark-color);
            font-size: 0.9rem;
        }

        .filtro-input,
        .filtro-select {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: var(--transition);
            background: #f9fafb;
        }

        .filtro-input:focus,
        .filtro-select:focus {
            outline: none;
            border-color: var(--primary-color);
            background: white;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }

        .btn-filtrar {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .btn-filtrar:hover {
            background: var(--primary-dark);
        }

        .btn-limpar {
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            margin-left: 10px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .btn-limpar:hover {
            background: #4b5563;
        }

        /* Lista de registros */
        .lista-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }

        .lista-header {
            background: #f8fafc;
            padding: 20px 30px;
            border-bottom: 2px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .lista-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .contador-resultados {
            background: var(--primary-color);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
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
            color: var(--dark-color);
            border-bottom: 2px solid #e5e7eb;
            font-size: 0.9rem;
            white-space: nowrap;
        }

        td {
            padding: 15px 20px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
            font-size: 0.9rem;
        }

        tr:hover {
            background: #f8fafc;
        }

        /* Status badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-agendado {
            background: #fef3c7;
            color: #92400e;
        }

        .status-em_andamento {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-concluido {
            background: #d1fae5;
            color: #065f46;
        }

        .status-cancelado {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Ações */
        .acoes {
            display: flex;
            gap: 8px;
        }

        .btn-acao {
            padding: 8px 10px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .btn-visualizar {
            background: var(--info-color);
            color: white;
        }

        .btn-status {
            background: var(--warning-color);
            color: white;
        }

        .btn-imprimir {
            background: var(--primary-color);
            color: white;
        }

        .btn-acao:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        /* Sem registros */
        .sem-registros {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .sem-registros i {
            font-size: 3rem;
            margin-bottom: 20px;
            opacity: 0.5;
            color: var(--primary-color);
        }

        .sem-registros h4 {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: var(--dark-color);
        }

        /* Alertas */
        .alert {
            padding: 15px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
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

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background-color: white;
            margin: 3% auto;
            padding: 0;
            width: 90%;
            max-width: 800px;
            border-radius: var(--border-radius);
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            background: var(--primary-color);
            color: white;
            padding: 20px 30px;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .modal-body {
            padding: 30px;
        }

        .close {
            color: white;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            padding: 5px;
            border-radius: 50%;
            transition: var(--transition);
        }

        .close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .filtros-grid {
                grid-template-columns: 1fr !important;
                gap: 15px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .agricultura-container {
                padding: 20px;
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

            .acoes {
                flex-direction: column;
            }

            .btn-acao {
                width: 100%;
                justify-content: center;
            }

            .modal-content {
                margin: 10px;
                width: calc(100% - 20px);
            }
        }

        @media (max-width: 480px) {
            .page-title {
                font-size: 1.5rem;
                flex-direction: column;
                gap: 10px;
            }
            
            .page-title i {
                font-size: 1.2rem;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="agricultura-container">
            <!-- Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-leaf"></i>
                    <div>
                        <div>Requerimentos de Agricultura</div>
                        <p class="page-subtitle">
                            Gerencie solicitações de serviços agrícolas e rurais
                        </p>
                    </div>
                </h1>
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

            <!-- Botão Novo Requerimento -->
            <button type="button" class="btn-novo-requerimento" onclick="toggleFormulario()">
                <i class="fas fa-plus"></i>
                Novo Requerimento de Agricultura
            </button>

            <!-- Formulário de Novo Requerimento -->
            <div class="form-requerimento" id="formRequerimento">
                <div class="form-header">
                    <h3>
                        <i class="fas fa-leaf"></i>
                        Novo Requerimento de Agricultura
                    </h3>
                </div>
                
                <form action="controller/salvar_requerimento.php" method="POST" id="formNovoRequerimento">
                    <input type="hidden" name="tipo" value="agricultura">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="produtor_id">Produtor *</label>
                            <select id="produtor_id" name="produtor_id" required>
                                <option value="">Selecione o produtor</option>
                                <?php foreach ($produtores as $produtor): ?>
                                    <option value="<?= $produtor['cad_pro_id'] ?>" 
                                            data-comunidade="<?= htmlspecialchars($produtor['com_nome'] ?? 'Não informado') ?>"
                                            <?= (isset($dados_form['produtor_id']) && $dados_form['produtor_id'] == $produtor['cad_pro_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($produtor['cad_pro_nome']) ?> - <?= htmlspecialchars($produtor['cad_pro_cpf']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">
                                Selecione o produtor que está fazendo a solicitação
                            </small>
                        </div>
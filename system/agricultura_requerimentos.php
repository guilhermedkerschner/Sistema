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

// Configuração de módulos de requerimentos
$configuracao_modulos = [
    'agricultura' => [
        'icone' => 'fas fa-leaf',
        'titulo' => 'Agricultura',
        'descricao' => 'Requerimentos de serviços agrícolas'
    ],
    'meio_ambiente' => [
        'icone' => 'fas fa-tree',
        'titulo' => 'Meio Ambiente',
        'descricao' => 'Requerimentos ambientais e licenciamentos'
    ],
    'vacinas' => [
        'icone' => 'fas fa-syringe',
        'titulo' => 'Vacinas',
        'descricao' => 'Requerimentos de vacinação animal'
    ],
    'exames' => [
        'icone' => 'fas fa-microscope',
        'titulo' => 'Exames',
        'descricao' => 'Requerimentos de exames veterinários'
    ]
];

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

// Definir aba ativa
$aba_ativa = $_GET['aba'] ?? 'agricultura';
$config_aba = $configuracao_modulos[$aba_ativa] ?? $configuracao_modulos['agricultura'];

// Buscar dados auxiliares
$produtores = [];
$servicos = [];

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
    
    // Serviços por tipo
    $tipo_servico = match($aba_ativa) {
        'agricultura' => 'agricultura',
        'meio_ambiente' => 'meio_ambiente',
        'vacinas' => 'veterinario',
        'exames' => 'veterinario',
        default => 'agricultura'
    };
    
    $stmt = $conn->prepare("
        SELECT s.ser_id, s.ser_nome, s.ser_descricao, sec.sec_nome
        FROM tb_cad_servicos s
        LEFT JOIN tb_cad_secretarias sec ON s.ser_secretaria_id = sec.sec_id
        WHERE s.ser_status = 'ativo' AND s.ser_tipo = :tipo
        ORDER BY s.ser_nome
    ");
    $stmt->bindParam(':tipo', $tipo_servico);
    $stmt->execute();
    $servicos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Erro ao buscar dados auxiliares: " . $e->getMessage());
}

// Função para gerar número do requerimento
function gerarNumeroRequerimento($conn, $tipo) {
    $ano = date('Y');
    $prefixo = strtoupper(substr($tipo, 0, 3)) . $ano;
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM tb_requerimentos 
        WHERE req_numero LIKE :prefixo AND YEAR(req_data_criacao) = :ano
    ");
    $stmt->bindValue(':prefixo', $prefixo . '%');
    $stmt->bindValue(':ano', $ano);
    $stmt->execute();
    
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $sequencial = str_pad($total + 1, 4, '0', STR_PAD_LEFT);
    
    return $prefixo . $sequencial;
}

// Função para buscar requerimentos
function buscarRequerimentos($conn, $tipo, $filtros = []) {
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
        WHERE r.req_tipo = :tipo
    ";
    
    $params = [':tipo' => $tipo];
    
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
    
    if (!empty($filtros['secretaria'])) {
        $sql .= " AND s.ser_secretaria_id = :secretaria";
        $params[':secretaria'] = $filtros['secretaria'];
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

// Buscar dados da aba ativa
$dados_aba = buscarRequerimentos($conn, $aba_ativa, $filtros);

// Mensagens de feedback
$mensagem_sucesso = $_SESSION['sucesso_requerimento'] ?? '';
$mensagem_erro = $_SESSION['erro_requerimento'] ?? '';
unset($_SESSION['sucesso_requerimento'], $_SESSION['erro_requerimento']);

$produtores = [];
$servicos = [];

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
    
    // Serviços ativos da tabela tb_cad_servicos
    $stmt = $conn->prepare("
        SELECT s.ser_id, s.ser_nome, sec.sec_nome
        FROM tb_cad_servicos s
        LEFT JOIN tb_cad_secretarias sec ON s.ser_secretaria_id = sec.sec_id
        WHERE s.ser_status = 'ativo'
        ORDER BY sec.sec_nome, s.ser_nome
    ");
    $stmt->execute();
    $servicos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Erro ao buscar dados auxiliares: " . $e->getMessage());
}

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../../img/logo_eai.ico" type="imagem/x-icon">
    <title>Sistema de Requerimentos - Sistema da Prefeitura</title>
    
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
            --primary-color: #667eea;
            --primary-dark: #5a6fd8;
            --secondary-color: #3498db;
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

        .requerimentos-container {
            padding: 30px;
            width: 100%;
            max-width: none;
            box-sizing: border-box;
        }

        /* Header da página com gradiente seguindo o padrão */
        .page-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: var(--border-radius);
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
        }

        /* Menu de abas moderno */
        .tabs-menu {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
        }

        .tab-item {
            padding: 20px;
            text-align: center;
            background: #f8fafc;
            border: 2px solid #e5e7eb;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            color: #6b7280;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            min-height: 120px;
            justify-content: center;
        }

        .tab-item:hover {
            background: #f3f4f6;
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2);
        }

        .tab-item.active {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
        }

        .tab-item i {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .tab-item .tab-title {
            font-weight: 600;
            font-size: 1rem;
        }

        .tab-item .tab-desc {
            font-size: 0.85rem;
            opacity: 0.8;
            line-height: 1.3;
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
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-novo-requerimento:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-novo-requerimento i {
            font-size: 1.1rem;
        }

        /* Container moderno para seções */
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
            justify-content: between;
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

        /* Formulário de requerimento */
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

        .form-header i {
            color: var(--primary-color);
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
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        /* Filtros */
        .filtros-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
            border: 1px solid #e5e7eb;
        }

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
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
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

        .form-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #f3f4f6;
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

        .lista-title i {
            color: var(--primary-color);
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
            background: var(--secondary-color);
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

        /* Detalhes do requerimento */
        .info-section {
            margin-bottom: 25px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 4px solid var(--primary-color);
        }

        .info-section h4 {
            margin: 0 0 15px 0;
            color: var(--dark-color);
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-item label {
            font-weight: 600;
            color: #6b7280;
            font-size: 0.9rem;
        }

        .info-item span {
            color: var(--dark-color);
            font-size: 1rem;
        }

        .descricao-completa,
        .observacoes {
            padding: 15px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            line-height: 1.6;
            color: var(--dark-color);
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .filtros-grid {
                grid-template-columns: 1fr !important;
                gap: 15px;
            }
            
            .tabs-menu {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .requerimentos-container {
                padding: 20px;
            }
            
            .tabs-menu {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .tab-item {
                min-height: 80px;
                padding: 15px;
            }
            
            .tab-item i {
                font-size: 1.3rem;
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
        <div class="requerimentos-container">
            <!-- Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-file-alt"></i>
                    Sistema de Requerimentos
                </h1>
                <p class="page-subtitle">
                    Gerencie todos os requerimentos de serviços da prefeitura
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
                <?php foreach ($configuracao_modulos as $key => $config): ?>
                    <a href="?aba=<?= $key ?>" class="tab-item <?= $aba_ativa === $key ? 'active' : '' ?>">
                        <i class="<?= $config['icone'] ?>"></i>
                        <div>
                            <?= $config['titulo'] ?>
                            <span><?= $config['descricao'] ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Conteúdo Dinâmico -->
            <div class="tab-content active">
                
                <!-- Botão Novo Requerimento -->
                <button type="button" class="btn-novo-requerimento" onclick="toggleFormulario()">
                    <i class="fas fa-plus"></i>
                    Novo Requerimento
                </button>

                <!-- Formulário de Novo Requerimento -->
                <div class="form-requerimento" id="formRequerimento">
                    <div class="form-header">
                        <h3>
                            <i class="<?= $config_aba['icone'] ?>"></i>
                            Novo Requerimento - <?= $config_aba['titulo'] ?>
                        </h3>
                    </div>
                    
                    <form action="controller/salvar_requerimento.php" method="POST" id="formNovoRequerimento">
                        <input type="hidden" name="tipo" value="<?= $aba_ativa ?>">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="produtor_id">Produtor *</label>
                                <select id="produtor_id" name="produtor_id" required>
                                    <option value="">Selecione o produtor</option>
                                    <?php foreach ($produtores as $produtor): ?>
                                        <option value="<?= $produtor['cad_pro_id'] ?>" 
                                                data-comunidade="<?= htmlspecialchars($produtor['com_nome'] ?? 'Não informado') ?>">
                                            <?= htmlspecialchars($produtor['cad_pro_nome']) ?> - <?= htmlspecialchars($produtor['cad_pro_cpf']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="data_solicitacao">Data da Solicitação *</label>
                                <input type="date" 
                                       id="data_solicitacao" 
                                       name="data_solicitacao" 
                                       value="<?= date('Y-m-d') ?>" 
                                       required>
                            </div>

                            <div class="form-group">
                                <label for="servico_id">Serviço *</label>
                                <select id="servico_id" name="servico_id" required>
                                    <option value="">Selecione o serviço</option>
                                    <?php 
                                    $secretaria_atual = '';
                                    foreach ($servicos as $servico): 
                                        // Agrupar por secretaria
                                        if ($secretaria_atual !== $servico['sec_nome']) {
                                            if ($secretaria_atual !== '') {
                                                echo '</optgroup>';
                                            }
                                            $secretaria_atual = $servico['sec_nome'];
                                            echo '<optgroup label="' . htmlspecialchars($secretaria_atual ?: 'Secretaria não definida') . '">';
                                        }
                                    ?>
                                        <option value="<?= $servico['ser_id'] ?>">
                                            <?= htmlspecialchars($servico['ser_nome']) ?>
                                        </option>
                                    <?php 
                                    endforeach; 
                                    if ($secretaria_atual !== '') {
                                        echo '</optgroup>';
                                    }
                                    ?>
                                </select>
                                <small class="form-text text-muted">
                                    Selecione o serviço que está sendo solicitado
                                </small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="descricao">Descrição do Requerimento *</label>
                            <textarea id="descricao" 
                                      name="descricao" 
                                      rows="4" 
                                      required 
                                      placeholder="Descreva detalhadamente o que está sendo solicitado..."></textarea>
                        </div>

                        <div class="form-buttons">
                            <button type="button" class="btn-secondary" onclick="toggleFormulario()">
                                <i class="fas fa-times"></i>
                                Cancelar
                            </button>
                            <button type="submit" class="btn-primary">
                                <i class="fas fa-save"></i>
                                Salvar Requerimento
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Filtros de Pesquisa -->
                <div class="filtros-container">
                    <div class="filtros-header">
                        <h3 class="filtros-title">
                            <i class="fas fa-search"></i>
                            Filtros de Pesquisa
                        </h3>
                    </div>
                    
                    <form method="GET" action="">
                        <input type="hidden" name="aba" value="<?= $aba_ativa ?>">
                        <div class="filtros-grid">
                            <div class="filtro-group">
                                <label for="busca">Buscar</label>
                                <input type="text" 
                                       id="busca" 
                                       name="busca" 
                                       class="filtro-input"
                                       placeholder="Número, produtor ou CPF..."
                                       value="<?= htmlspecialchars($filtros['busca']) ?>">
                            </div>
                            
                            <div class="filtro-group">
                                <label for="status">Status</label>
                                <select id="status" name="status" class="filtro-select">
                                    <option value="">Todos os status</option>
                                    <option value="agendado" <?= $filtros['status'] == 'agendado' ? 'selected' : '' ?>>Agendado</option>
                                    <option value="em_andamento" <?= $filtros['status'] == 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
                                    <option value="concluido" <?= $filtros['status'] == 'concluido' ? 'selected' : '' ?>>Concluído</option>
                                </select>
                            </div>
                            
                            <div class="filtro-group">
                                <label for="data_inicio">Data Início</label>
                                <input type="date" 
                                       id="data_inicio" 
                                       name="data_inicio" 
                                       class="filtro-input"
                                       value="<?= htmlspecialchars($filtros['data_inicio']) ?>">
                            </div>
                            
                            <div class="filtro-group">
                                <label for="data_fim">Data Fim</label>
                                <input type="date" 
                                       id="data_fim" 
                                       name="data_fim" 
                                       class="filtro-input"
                                       value="<?= htmlspecialchars($filtros['data_fim']) ?>">
                            </div>
                            
                            <div class="filtro-group">
                                <button type="submit" class="btn-filtrar">
                                    <i class="fas fa-search"></i>
                                    Filtrar
                                </button>
                                <a href="?aba=<?= $aba_ativa ?>" class="btn-limpar">
                                    <i class="fas fa-eraser"></i>
                                    Limpar
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Lista de Requerimentos -->
                <div class="lista-container">
                    <div class="lista-header">
                        <h3 class="lista-title">
                            <i class="<?= $config_aba['icone'] ?>"></i>
                            Requerimentos - <?= $config_aba['titulo'] ?>
                        </h3>
                        <div class="contador-resultados">
                            <?= count($dados_aba) ?> registro(s)
                        </div>
                    </div>

                    <?php if (empty($dados_aba)): ?>
                        <div class="sem-registros">
                            <i class="fas fa-inbox"></i>
                            <h4>Nenhum requerimento encontrado</h4>
                            <p>Não há requerimentos cadastrados com os filtros selecionados.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nº</th>
                                        <th>Data</th>
                                        <th>Produtor</th>
                                        <th>Comunidade</th>
                                        <th>Serviço</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($dados_aba as $requerimento): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($requerimento['req_numero']) ?></strong>
                                            </td>
                                            <td>
                                                <?= date('d/m/Y H:i', strtotime($requerimento['req_data_solicitacao'])) ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($requerimento['cad_pro_nome']) ?>
                                                <small class="text-muted d-block">
                                                    CPF: <?= htmlspecialchars($requerimento['cad_pro_cpf']) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($requerimento['com_nome'] ?? 'Não informado') ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($requerimento['ser_nome']) ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?= $requerimento['req_status'] ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $requerimento['req_status'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="acoes">
                                                    <button type="button" 
                                                            class="btn-acao btn-visualizar" 
                                                            onclick="visualizarRequerimento(<?= $requerimento['req_id'] ?>)"
                                                            title="Visualizar">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button type="button" 
                                                            class="btn-acao btn-status" 
                                                            onclick="alterarStatus(<?= $requerimento['req_id'] ?>, '<?= $requerimento['req_status'] ?>')"
                                                            title="Alterar Status">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" 
                                                            class="btn-acao btn-imprimir" 
                                                            onclick="imprimirRequerimento(<?= $requerimento['req_id'] ?>)"
                                                            title="Imprimir">
                                                        <i class="fas fa-print"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Visualizar Requerimento -->
    <div id="modalVisualizarRequerimento" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-file-alt"></i> Detalhes do Requerimento</h3>
                <span class="close" onclick="fecharModal('modalVisualizarRequerimento')">&times;</span>
            </div>
            <div class="modal-body" id="conteudoRequerimento">
                <!-- Conteúdo será carregado via AJAX -->
            </div>
        </div>
    </div>

    <!-- Modal Alterar Status -->
    <div id="modalAlterarStatus" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Alterar Status do Requerimento</h3>
                <span class="close" onclick="fecharModal('modalAlterarStatus')">&times;</span>
            </div>
            <div class="modal-body">
                <form id="formAlterarStatus">
                    <input type="hidden" id="requerimento_id" name="requerimento_id">
                    
                    <div class="form-group">
                        <label for="novo_status">Novo Status *</label>
                        <select id="novo_status" name="novo_status" required>
                            <option value="agendado">Agendado</option>
                            <option value="em_andamento">Em Andamento</option>
                            <option value="concluido">Concluído</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="observacoes">Observações</label>
                        <textarea id="observacoes" 
                                  name="observacoes" 
                                  rows="3" 
                                  placeholder="Digite observações sobre a alteração de status..."></textarea>
                    </div>

                    <div class="form-buttons">
                        <button type="button" class="btn-secondary" onclick="fecharModal('modalAlterarStatus')">
                            <i class="fas fa-times"></i>
                            Cancelar
                        </button>
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save"></i>
                            Salvar Alteração
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="assets/js/main.js"></script>
    <script>
        // Função para toggle do formulário
        function toggleFormulario() {
            const form = document.getElementById('formRequerimento');
            form.classList.toggle('show');
        }

        // Função para visualizar requerimento
        function visualizarRequerimento(id) {
            fetch(`controller/buscar_requerimento.php?id=${id}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('conteudoRequerimento').innerHTML = html;
                    document.getElementById('modalVisualizarRequerimento').style.display = 'block';
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Erro ao carregar dados do requerimento');
                });
        }

        // Função para alterar status
        function alterarStatus(id, statusAtual) {
            document.getElementById('requerimento_id').value = id;
            document.getElementById('novo_status').value = statusAtual;
            document.getElementById('modalAlterarStatus').style.display = 'block';
        }

        // Função para imprimir requerimento
        function imprimirRequerimento(id) {
            window.open(`relatorios/requerimento_pdf.php?id=${id}`, '_blank');
        }

        // Função para fechar modal
        function fecharModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Fechar modal clicando fora
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // Submit do formulário de alterar status
        document.getElementById('formAlterarStatus').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('controller/alterar_status_requerimento.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Status alterado com sucesso!');
                    location.reload();
                } else {
                    alert('Erro: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                alert('Erro ao alterar status');
            });
        });

        // Atualizar comunidade quando selecionar produtor
        document.getElementById('produtor_id').addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            const comunidade = option.getAttribute('data-comunidade');
            // Você pode mostrar a comunidade em algum lugar se necessário
        });
    </script>
</body>
</html>
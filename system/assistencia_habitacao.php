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
$themeColors = $menuManager->getThemeColors();

// Verificar permissões
$is_admin = ($usuario_dados['usuario_nivel_id'] == 1);
$is_associacao = (strtoupper(trim($usuario_dados['usuario_nome'])) === "ASSOCIAÇÃO EMPRESARIAL DE SANTA IZABEL DO OESTE");
$tem_permissao = $is_admin || strtoupper($usuario_dados['usuario_departamento']) === 'ASSISTENCIA_SOCIAL' || $is_associacao;

if (!$tem_permissao) {
    header("Location: dashboard.php?erro=acesso_negado");
    exit;
}

// Mensagens de feedback
$mensagem_sucesso = $_SESSION['sucesso_habitacao'] ?? '';
$mensagem_erro = $_SESSION['erro_habitacao'] ?? '';
unset($_SESSION['sucesso_habitacao'], $_SESSION['erro_habitacao']);

// Função para sanitizar inputs
function sanitizeInput($data) {
    if (is_null($data) || $data === '') {
        return null;
    }
    return trim(htmlspecialchars(stripslashes($data)));
}

// Função para log de atividades
function logActivity($conn, $acao, $detalhes, $usuario_id, $inscricao_id = null) {
    try {
        $stmt = $conn->prepare("INSERT INTO tb_log_atividades (usuario_id, acao, detalhes, inscricao_id, data_atividade) VALUES (:usuario_id, :acao, :detalhes, :inscricao_id, NOW())");
        $stmt->bindParam(':usuario_id', $usuario_id);
        $stmt->bindParam(':acao', $acao);
        $stmt->bindParam(':detalhes', $detalhes);
        $stmt->bindParam(':inscricao_id', $inscricao_id);
        $stmt->execute();
    } catch (PDOException $e) {
        error_log("Erro ao registrar log: " . $e->getMessage());
    }
}

// Processamento de ações via AJAX
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    header('Content-Type: application/json');
    
    $acao = $_POST['acao'] ?? '';
    $response = ['success' => false, 'message' => ''];
    
    try {
        switch ($acao) {
            case 'atualizar_status':
                $inscricao_id = filter_input(INPUT_POST, 'inscricao_id', FILTER_VALIDATE_INT);
                $novo_status = sanitizeInput($_POST['novo_status'] ?? '');
                $observacao = sanitizeInput($_POST['observacao'] ?? '');
                
                if (!$inscricao_id || !$novo_status) {
                    throw new Exception("Dados obrigatórios não informados.");
                }
                
                if ($is_associacao && !in_array($novo_status, ['FINANCEIRO APROVADO', 'FINANCEIRO REPROVADO'])) {
                    throw new Exception("Usuário da Associação Empresarial só pode aprovar ou reprovar financeiramente.");
                }
                
                // Buscar dados atuais
                $stmt = $conn->prepare("SELECT cad_social_protocolo, cad_social_status, cad_usu_id FROM tb_cad_social WHERE cad_social_id = :id");
                $stmt->bindParam(':id', $inscricao_id);
                $stmt->execute();

                if ($stmt->rowCount() === 0) {
                    throw new Exception("Inscrição não encontrada.");
                }

                $inscricao_data = $stmt->fetch(PDO::FETCH_ASSOC);
                $status_anterior = $inscricao_data['cad_social_status'];

                $conn->beginTransaction();

                // Atualizar status na tb_cad_social
                $stmt = $conn->prepare("UPDATE tb_cad_social SET cad_social_status = :status WHERE cad_social_id = :id");
                $stmt->bindParam(':status', $novo_status);
                $stmt->bindParam(':id', $inscricao_id);
                $stmt->execute();

                // Atualizar status na tb_solicitacoes
                $stmt = $conn->prepare("UPDATE tb_solicitacoes SET status = :status WHERE protocolo = :protocolo");
                $stmt->bindParam(':status', $novo_status);
                $stmt->bindParam(':protocolo', $inscricao_data['cad_social_protocolo']);
                $stmt->execute();

                // Registrar no histórico
                $stmt = $conn->prepare("INSERT INTO tb_cad_social_historico (cad_social_id, cad_social_hist_acao, cad_social_hist_observacao, cad_social_hist_usuario, cad_social_hist_data) VALUES (:inscricao_id, :acao, :observacao, :usuario, NOW())");
                $acao_hist = "Alteração de status: {$status_anterior} → {$novo_status}";
                $stmt->bindParam(':inscricao_id', $inscricao_id);
                $stmt->bindParam(':acao', $acao_hist);
                $stmt->bindParam(':observacao', $observacao);
                $stmt->bindParam(':usuario', $usuario_id);
                $stmt->execute();

                $conn->commit();

                logActivity($conn, 'Alteração de Status', "Status alterado de '{$status_anterior}' para '{$novo_status}'", $usuario_id, $inscricao_id);
                
                $response = ['success' => true, 'message' => 'Status atualizado com sucesso!'];
                break;
                
            case 'adicionar_comentario':
                $inscricao_id = filter_input(INPUT_POST, 'inscricao_id', FILTER_VALIDATE_INT);
                $comentario = sanitizeInput($_POST['comentario'] ?? '');
                
                if (!$inscricao_id || !$comentario) {
                    throw new Exception("Dados obrigatórios não informados.");
                }
                
                $stmt = $conn->prepare("INSERT INTO tb_cad_social_historico (cad_social_id, cad_social_hist_acao, cad_social_hist_observacao, cad_social_hist_usuario, cad_social_hist_data) VALUES (:inscricao_id, :acao, :observacao, :usuario, NOW())");
                $stmt->bindParam(':inscricao_id', $inscricao_id);
                $acao = "Comentário";
                $stmt->bindParam(':acao', $acao);
                $stmt->bindParam(':observacao', $comentario);
                $stmt->bindParam(':usuario', $usuario_id);
                $stmt->execute();
                
                logActivity($conn, 'Novo Comentário', substr($comentario, 0, 100), $usuario_id, $inscricao_id);
                
                $response = ['success' => true, 'message' => 'Comentário adicionado com sucesso!'];
                break;
                
            default:
                throw new Exception("Ação não reconhecida.");
        }
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $response = ['success' => false, 'message' => $e->getMessage()];
        error_log("Erro na ação AJAX: " . $e->getMessage());
    }
    
    echo json_encode($response);
    exit;
}

// Parâmetros de paginação e filtros
$pagina_atual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$registros_por_pagina = 15;
$offset = ($pagina_atual - 1) * $registros_por_pagina;

// Filtros de busca
$filtros = [
    'protocolo' => sanitizeInput($_GET['filtro_protocolo'] ?? ''),
    'cpf' => preg_replace('/[^0-9]/', '', $_GET['filtro_cpf'] ?? ''),
    'nome' => sanitizeInput($_GET['filtro_nome'] ?? ''),
    'status' => $is_associacao ? 'EM ANÁLISE FINANCEIRA' : sanitizeInput($_GET['filtro_status'] ?? ''),
    'data_inicio' => sanitizeInput($_GET['filtro_data_inicio'] ?? ''),
    'data_fim' => sanitizeInput($_GET['filtro_data_fim'] ?? ''),
    'programa' => sanitizeInput($_GET['filtro_programa'] ?? '')
];

// Construir condições WHERE
$where_conditions = [];
$params = [];

foreach ($filtros as $key => $value) {
    if (!empty($value)) {
        switch ($key) {
            case 'protocolo':
                $where_conditions[] = "cs.cad_social_protocolo LIKE :protocolo";
                $params[':protocolo'] = "%{$value}%";
                break;
            case 'cpf':
                $where_conditions[] = "cs.cad_social_cpf LIKE :cpf";
                $params[':cpf'] = "%{$value}%";
                break;
            case 'nome':
                $where_conditions[] = "cs.cad_social_nome LIKE :nome";
                $params[':nome'] = "%{$value}%";
                break;
            case 'status':
                $where_conditions[] = "cs.cad_social_status = :status";
                $params[':status'] = $value;
                break;
            case 'data_inicio':
                $where_conditions[] = "DATE(cs.cad_social_data_cadastro) >= :data_inicio";
                $params[':data_inicio'] = $value;
                break;
            case 'data_fim':
                $where_conditions[] = "DATE(cs.cad_social_data_cadastro) <= :data_fim";
                $params[':data_fim'] = $value;
                break;
            case 'programa':
                $where_conditions[] = "cs.cad_social_programa_interesse = :programa";
                $params[':programa'] = $value;
                break;
        }
    }
}

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Buscar estatísticas resumidas
$estatisticas = [];
try {
    $stats_sql = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN cad_social_status = 'EM ANÁLISE' THEN 1 ELSE 0 END) as pendentes,
        SUM(CASE WHEN cad_social_status = 'EM ANÁLISE FINANCEIRA' THEN 1 ELSE 0 END) as em_analise_fin,
        SUM(CASE WHEN cad_social_status = 'EM FASE DE SELEÇÃO' THEN 1 ELSE 0 END) as fase_selecao,
        SUM(CASE WHEN cad_social_status = 'CONTEMPLADO' THEN 1 ELSE 0 END) as contemplados
        FROM tb_cad_social cs {$where_sql}";
    
    $stmt = $conn->prepare($stats_sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $estatisticas = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar estatísticas: " . $e->getMessage());
    $estatisticas = ['total' => 0, 'pendentes' => 0, 'em_analise_fin' => 0, 'fase_selecao' => 0, 'contemplados' => 0];
}

// Consulta para obter as inscrições com paginação e filtros
$inscricoes = [];
$total_registros = 0;

try {
    // Contar total de registros
    $count_sql = "SELECT COUNT(*) as total FROM tb_cad_social cs {$where_sql}";
    $stmt = $conn->prepare($count_sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $total_registros = $stmt->fetch()['total'];
    
    // Buscar registros
    $sql = "SELECT cs.*, cu.cad_usu_nome 
            FROM tb_cad_social cs
            LEFT JOIN tb_cad_usuarios cu ON cs.cad_usu_id = cu.cad_usu_id
            {$where_sql}
            ORDER BY cs.cad_social_data_cadastro DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $inscricoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $mensagem_erro = "Erro ao buscar inscrições: " . $e->getMessage();
    error_log("Erro na consulta de inscrições: " . $e->getMessage());
}

$total_paginas = ceil($total_registros / $registros_por_pagina);

// Listas de opções
if ($is_associacao) {
    $lista_status = [
        'FINANCEIRO APROVADO',
        'FINANCEIRO REPROVADO'
    ];
} else {
    $lista_status = [
        'PENDENTE DE ANÁLISE',
        'EM ANÁLISE', 
        'EM ANÁLISE FINANCEIRA',
        'FINANCEIRO APROVADO',
        'FINANCEIRO REPROVADO',
        'CADASTRO REPROVADO',
        'EM FASE DE SELEÇÃO',
        'CONTEMPLADO'
    ];
}

$programas_habitacionais = ['HABITASIO'];

// Funções auxiliares
function formatarCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) != 11) return $cpf;
    return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
}

function formatarData($data) {
    return date('d/m/Y H:i', strtotime($data));
}

function getStatusClass($status) {
    $classes = [
        'PENDENTE DE ANÁLISE' => 'status-pendente',
        'EM ANÁLISE' => 'status-analise',
        'EM ANÁLISE FINANCEIRA' => 'status-documentacao',
        'FINANCEIRO APROVADO' => 'status-aprovado',
        'FINANCEIRO REPROVADO' => 'status-reprovado',
        'CADASTRO REPROVADO' => 'status-cancelado',
        'EM FASE DE SELEÇÃO' => 'status-espera',
        'CONTEMPLADO' => 'status-aprovado'
    ];
    return $classes[$status] ?? '';
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../../img/logo_eai.ico" type="imagem/x-icon">
    <title>Assistência Habitacional - Sistema da Prefeitura</title>
    
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

        .habitacao-container {
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
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--secondary-color, #667eea);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #7f8c8d;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .stat-icon {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 2rem;
            color: rgba(0, 0, 0, 0.1);
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

        .filtros-toggle {
            background: none;
            border: none;
            color: var(--secondary-color, #667eea);
            cursor: pointer;
            font-size: 1.1rem;
            padding: 5px;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        .filtros-content {
            max-height: 1000px;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .filtros-content.collapsed {
            max-height: 0;
        }

        .filtros-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
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

        .filtros-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Botões */
        .btn {
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
            justify-content: center;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5a6fd8;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
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

        /* Status badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }

        .status-pendente {
            background: #fff3cd;
            color: #856404;
        }

        .status-analise {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-documentacao {
            background: #ffd7a6;
            color: #8b4513;
        }

        .status-aprovado {
            background: #d4edda;
            color: #155724;
        }

        .status-reprovado {
            background: #f8d7da;
            color: #721c24;
        }

        .status-cancelado {
            background: #e2e3e5;
            color: #383d41;
        }

        .status-espera {
            background: #e7e3ff;
            color: #6f42c1;
        }

        /* Actions */
        .acoes {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
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

        .btn-view {
            background-color: #667eea;
            color: white;
        }

        .btn-view:hover {
            background-color: #5a6fd8;
        }

        .btn-edit {
            background-color: #fbbf24;
            color: white;
        }

        .btn-comment {
            background-color: #6c757d;
            color: white;
        }

        .btn-attach {
            background-color: #17a2b8;
            color: white;
        }

        /* Paginação */
        .pagination-container {
            padding: 20px 30px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
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

       /* Modal */
       .modal-overlay {
           position: fixed;
           top: 0;
           left: 0;
           width: 100%;
           height: 100%;
           background-color: rgba(0, 0, 0, 0.7);
           z-index: 1000;
           display: none;
           align-items: center;
           justify-content: center;
           padding: 20px;
       }

       .modal-overlay.show {
           display: flex;
       }

       .modal {
           background: white;
           border-radius: 12px;
           max-width: 90vw;
           max-height: 90vh;
           overflow: hidden;
           box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
           animation: modalSlideIn 0.3s ease;
       }

       @keyframes modalSlideIn {
           from {
               opacity: 0;
               transform: translateY(-50px);
           }
           to {
               opacity: 1;
               transform: translateY(0);
           }
       }

       .modal-header {
           padding: 20px 25px;
           border-bottom: 1px solid #eee;
           display: flex;
           justify-content: space-between;
           align-items: center;
           background: linear-gradient(135deg, #667eea, #5a6fd8);
           color: white;
       }

       .modal-title {
           font-size: 1.3rem;
           font-weight: 600;
           display: flex;
           align-items: center;
           gap: 10px;
       }

       .modal-close {
           background: none;
           border: none;
           color: white;
           font-size: 1.5rem;
           cursor: pointer;
           padding: 5px;
           border-radius: 4px;
           transition: background-color 0.3s;
       }

       .modal-close:hover {
           background-color: rgba(255, 255, 255, 0.1);
       }

       .modal-body {
           padding: 25px;
           max-height: 70vh;
           overflow-y: auto;
       }

       .modal-footer {
           padding: 20px 25px;
           border-top: 1px solid #eee;
           background: #f8f9fa;
           display: flex;
           justify-content: flex-end;
           gap: 10px;
       }

       /* Form styles */
       .form-group {
           margin-bottom: 20px;
       }

       .form-group label {
           display: block;
           font-weight: 500;
           margin-bottom: 8px;
           color: #374151;
       }

       .form-group label.required::after {
           content: "*";
           color: #dc3545;
           margin-left: 4px;
       }

       .form-group input,
       .form-group select,
       .form-group textarea {
           width: 100%;
           padding: 12px;
           border: 1px solid #ddd;
           border-radius: 6px;
           font-size: 1rem;
           transition: all 0.3s;
       }

       .form-group input:focus,
       .form-group select:focus,
       .form-group textarea:focus {
           border-color: #667eea;
           box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
           outline: none;
       }

       .form-group textarea {
           resize: vertical;
           min-height: 100px;
       }

       /* Loading */
       .loading {
           display: none;
           position: fixed;
           top: 0;
           left: 0;
           width: 100%;
           height: 100%;
           background-color: rgba(0, 0, 0, 0.5);
           z-index: 9999;
           align-items: center;
           justify-content: center;
       }

       .loading.show {
           display: flex;
       }

       .loading-content {
           background: white;
           padding: 30px;
           border-radius: 12px;
           text-align: center;
           box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
       }

       .spinner {
           width: 40px;
           height: 40px;
           border: 4px solid #f3f3f3;
           border-top: 4px solid #667eea;
           border-radius: 50%;
           animation: spin 1s linear infinite;
           margin: 0 auto 15px;
       }

       @keyframes spin {
           0% { transform: rotate(0deg); }
           100% { transform: rotate(360deg); }
       }

       /* Responsivo */
       @media (max-width: 768px) {
           .main-content {
               margin-left: 0;
           }
           
           .habitacao-container {
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

           .filtros-grid {
               grid-template-columns: 1fr;
           }

           .filtros-actions {
               flex-direction: column;
           }

           .modal {
               max-width: 95vw;
               max-height: 95vh;
           }
       }
       
   </style>
</head>
<body>
   <!-- Loading -->
   <div class="loading" id="loading">
       <div class="loading-content">
           <div class="spinner"></div>
           <p>Processando...</p>
       </div>
   </div>

   <?php include 'includes/header.php'; ?>
   <?php include 'includes/sidebar.php'; ?>

   <!-- Main Content -->
   <div class="main-content" id="mainContent">
       <div class="habitacao-container">
           <!-- Header -->
           <div class="page-header">
               <h1 class="page-title">
                   <i class="fas fa-home"></i>
                   <?php if ($is_associacao): ?>
                       Análise Financeira Habitacional
                   <?php else: ?>
                       Assistência Habitacional
                   <?php endif; ?>
               </h1>
               <p class="page-subtitle">
                   <?php if ($is_associacao): ?>
                       Análise financeira dos cadastros habitacionais
                   <?php else: ?>
                       Gerencie os cadastros dos programas habitacionais
                   <?php endif; ?>
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

           <!-- Estatísticas -->
           <div class="stats-container">
               <div class="stat-card">
                   <div class="stat-number"><?= number_format($estatisticas['total']) ?></div>
                   <div class="stat-label">Total de Cadastros</div>
                   <i class="fas fa-home stat-icon"></i>
               </div>
               <div class="stat-card">
                   <div class="stat-number"><?= number_format($estatisticas['pendentes']) ?></div>
                   <div class="stat-label">Em Análise</div>
                   <i class="fas fa-clock stat-icon"></i>
               </div>
               <div class="stat-card">
                   <div class="stat-number"><?= number_format($estatisticas['em_analise_fin']) ?></div>
                   <div class="stat-label">Análise Financeira</div>
                   <i class="fas fa-search stat-icon"></i>
               </div>
               <div class="stat-card">
                   <div class="stat-number"><?= number_format($estatisticas['fase_selecao']) ?></div>
                   <div class="stat-label">Fase de Seleção</div>
                   <i class="fas fa-users stat-icon"></i>
               </div>
               <div class="stat-card">
                   <div class="stat-number"><?= number_format($estatisticas['contemplados']) ?></div>
                   <div class="stat-label">Contemplados</div>
                   <i class="fas fa-check-circle stat-icon"></i>
               </div>
           </div>

           <!-- Filtros de Pesquisa -->
           <div class="filtros-container">
               <div class="filtros-header">
                   <h3 class="filtros-title">
                       <i class="fas fa-search"></i>
                       Filtros de Pesquisa
                   </h3>
                   <button class="filtros-toggle" onclick="toggleFilters()">
                       <i class="fas fa-chevron-down" id="filtersChevron"></i>
                   </button>
               </div>
               
               <div class="filtros-content" id="filtersContent">
                   <form method="GET" action="" id="formFiltros">
                       <div class="filtros-grid">
                           <div class="filtro-group">
                               <label for="filtro_protocolo">Protocolo</label>
                               <input type="text" 
                                      id="filtro_protocolo" 
                                      name="filtro_protocolo" 
                                      class="filtro-input"
                                      placeholder="Digite o protocolo..."
                                      value="<?= htmlspecialchars($filtros['protocolo']) ?>">
                           </div>
                           
                           <div class="filtro-group">
                               <label for="filtro_cpf">CPF</label>
                               <input type="text" 
                                      id="filtro_cpf" 
                                      name="filtro_cpf" 
                                      class="filtro-input cpf-mask"
                                      placeholder="000.000.000-00"
                                      value="<?= htmlspecialchars($filtros['cpf']) ?>">
                           </div>
                           
                           <div class="filtro-group">
                               <label for="filtro_nome">Nome</label>
                               <input type="text" 
                                      id="filtro_nome" 
                                      name="filtro_nome" 
                                      class="filtro-input"
                                      placeholder="Digite o nome..."
                                      value="<?= htmlspecialchars($filtros['nome']) ?>">
                           </div>
                           
                           <?php if (!$is_associacao): ?>
                           <div class="filtro-group">
                               <label for="filtro_status">Status</label>
                               <select id="filtro_status" name="filtro_status" class="filtro-select">
                                   <option value="">Todos os Status</option>
                                   <?php foreach ($lista_status as $status): ?>
                                   <option value="<?= $status ?>" 
                                           <?= ($filtros['status'] == $status) ? 'selected' : '' ?>>
                                       <?= $status ?>
                                   </option>
                                   <?php endforeach; ?>
                               </select>
                           </div>
                           <?php endif; ?>
                           
                           <div class="filtro-group">
                               <label for="filtro_programa">Programa</label>
                               <select id="filtro_programa" name="filtro_programa" class="filtro-select">
                                   <option value="">Todos os Programas</option>
                                   <?php foreach ($programas_habitacionais as $programa): ?>
                                   <option value="<?= $programa ?>" 
                                           <?= ($filtros['programa'] == $programa) ? 'selected' : '' ?>>
                                       <?= $programa ?>
                                   </option>
                                   <?php endforeach; ?>
                               </select>
                           </div>
                           
                           <div class="filtro-group">
                               <label for="filtro_data_inicio">Data Início</label>
                               <input type="date" 
                                      id="filtro_data_inicio" 
                                      name="filtro_data_inicio" 
                                      class="filtro-input"
                                      value="<?= htmlspecialchars($filtros['data_inicio']) ?>">
                           </div>
                           
                           <div class="filtro-group">
                               <label for="filtro_data_fim">Data Fim</label>
                               <input type="date" 
                                      id="filtro_data_fim" 
                                      name="filtro_data_fim" 
                                      class="filtro-input"
                                      value="<?= htmlspecialchars($filtros['data_fim']) ?>">
                           </div>
                       </div>
                       
                       <div class="filtros-actions">
                           <button type="submit" class="btn btn-primary">
                               <i class="fas fa-search"></i> Buscar
                           </button>
                           <a href="assistencia_habitacao.php" class="btn btn-secondary">
                               <i class="fas fa-times"></i> Limpar
                           </a>
                           <button type="button" class="btn btn-info" onclick="exportarDados()">
                               <i class="fas fa-download"></i> Exportar
                           </button>
                       </div>
                   </form>
               </div>
           </div>

           <!-- Lista de Cadastros -->
           <div class="lista-container">
               <div class="lista-header">
                   <h3 class="lista-title">
                       <i class="fas fa-list"></i>
                       Cadastros Habitacionais
                   </h3>
                   <div class="contador-resultados">
                       <?= $total_registros ?> resultado(s)
                   </div>
               </div>

               <?php if (count($inscricoes) > 0): ?>
                   <div class="table-responsive">
                       <table>
                           <thead>
                               <tr>
                                   <th>Protocolo</th>
                                   <th>Nome</th>
                                   <th>CPF</th>
                                   <th>Programa</th>
                                   <th>Status</th>
                                   <th>Data Cadastro</th>
                                   <th>Ações</th>
                               </tr>
                           </thead>
                           <tbody>
                               <?php foreach ($inscricoes as $inscricao): ?>
                               <tr>
                                   <td>
                                       <strong><?= htmlspecialchars($inscricao['cad_social_protocolo']) ?></strong>
                                   </td>
                                   <td>
                                       <div>
                                           <strong><?= htmlspecialchars($inscricao['cad_social_nome']) ?></strong>
                                           <?php if ($inscricao['cad_usu_nome']): ?>
                                           <br><small style="color: #6b7280;">Cadastrado por: <?= htmlspecialchars($inscricao['cad_usu_nome']) ?></small>
                                           <?php endif; ?>
                                       </div>
                                   </td>
                                   <td style="font-family: monospace;"><?= formatarCPF($inscricao['cad_social_cpf']) ?></td>
                                   <td>
                                       <small><?= htmlspecialchars($inscricao['cad_social_programa_interesse'] ?? 'Não informado') ?></small>
                                   </td>
                                   <td>
                                       <span class="status-badge <?= getStatusClass($inscricao['cad_social_status']) ?>">
                                           <?= htmlspecialchars($inscricao['cad_social_status']) ?>
                                       </span>
                                   </td>
                                   <td>
                                       <small><?= formatarData($inscricao['cad_social_data_cadastro']) ?></small>
                                   </td>
                                   <td>
                                       <div class="acoes">
                                           <a href="visualizar_cadastro_habitacao.php?id=<?= $inscricao['cad_social_id'] ?>" 
                                              class="btn-acao btn-view" title="Ver Detalhes">
                                               <i class="fas fa-eye"></i>
                                           </a>
                                           
                                           <button type="button" class="btn-acao btn-edit" 
                                                   title="Alterar Status"
                                                   onclick="openStatusModal(<?= $inscricao['cad_social_id'] ?>, '<?= htmlspecialchars($inscricao['cad_social_status']) ?>')">
                                               <i class="fas fa-edit"></i>
                                           </button>
                                           
                                           <button type="button" class="btn-acao btn-comment" 
                                                   title="Adicionar Comentário"
                                                   onclick="openCommentModal(<?= $inscricao['cad_social_id'] ?>)">
                                               <i class="fas fa-comment"></i>
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
                           <?= number_format($total_registros) ?> registros
                       </div>
                       
                       <div class="pagination">
                           <?php if ($pagina_atual > 1): ?>
                           <a href="?pagina=<?= $pagina_atual - 1 ?>&<?= http_build_query(array_diff_key($_GET, ['pagina' => ''])) ?>">
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
                           <a href="?pagina=<?= $i ?>&<?= http_build_query(array_diff_key($_GET, ['pagina' => ''])) ?>">
                               <?= $i ?>
                           </a>
                           <?php endif; ?>
                           <?php endfor; ?>
                           
                           <?php if ($pagina_atual < $total_paginas): ?>
                           <a href="?pagina=<?= $pagina_atual + 1 ?>&<?= http_build_query(array_diff_key($_GET, ['pagina' => ''])) ?>">
                               Próxima <i class="fas fa-chevron-right"></i>
                           </a>
                           <?php endif; ?>
                       </div>
                   </div>
                   <?php endif; ?>

               <?php else: ?>
                   <div class="sem-registros">
                       <i class="fas fa-search"></i>
                       <h3>Nenhum cadastro encontrado</h3>
                       <?php if (!empty($filtros['nome']) || !empty($filtros['cpf']) || !empty($filtros['protocolo'])): ?>
                           <p>Nenhum cadastro foi encontrado com os filtros aplicados.</p>
                           <a href="assistencia_habitacao.php" class="btn btn-secondary" style="margin-top: 15px;">
                               <i class="fas fa-eraser"></i>
                               Limpar Filtros
                           </a>
                       <?php else: ?>
                           <p>Ainda não há cadastros habitacionais no sistema.</p>
                       <?php endif; ?>
                   </div>
               <?php endif; ?>
           </div>
       </div>
   </div>

   <!-- Modal de Alterar Status -->
   <div class="modal-overlay" id="statusModal">
       <div class="modal" style="max-width: 500px;">
           <div class="modal-header">
               <div class="modal-title">
                   <i class="fas fa-edit"></i>
                   Alterar Status
               </div>
               <button class="modal-close" onclick="closeModal('statusModal')">
                   <i class="fas fa-times"></i>
               </button>
           </div>
           <div class="modal-body">
               <form id="statusForm">
                   <input type="hidden" id="status_inscricao_id" name="inscricao_id">
                   
                   <div class="form-group">
                       <label>Status Atual</label>
                       <input type="text" id="status_atual" readonly>
                   </div>
                   
                   <div class="form-group">
                       <label for="novo_status" class="required">Novo Status</label>
                       <select id="novo_status" name="novo_status" required>
                           <?php foreach ($lista_status as $status): ?>
                           <option value="<?= $status ?>"><?= $status ?></option>
                           <?php endforeach; ?>
                       </select>
                   </div>
                   
                   <div class="form-group">
                       <label for="observacao_status">Observação</label>
                       <textarea id="observacao_status" name="observacao" rows="4" 
                               placeholder="Descreva o motivo da alteração de status..."></textarea>
                   </div>
               </form>
           </div>
           <div class="modal-footer">
               <button type="button" class="btn btn-secondary" onclick="closeModal('statusModal')">
                   Cancelar
               </button>
               <button type="button" class="btn btn-primary" onclick="updateStatus()">
                   <i class="fas fa-save"></i> Salvar
               </button>
           </div>
       </div>
   </div>

   <!-- Modal de Comentário -->
   <div class="modal-overlay" id="commentModal">
       <div class="modal" style="max-width: 500px;">
           <div class="modal-header">
               <div class="modal-title">
                   <i class="fas fa-comment"></i>
                   Adicionar Comentário
               </div>
               <button class="modal-close" onclick="closeModal('commentModal')">
                   <i class="fas fa-times"></i>
               </button>
           </div>
           <div class="modal-body">
               <form id="commentForm">
                   <input type="hidden" id="comment_inscricao_id" name="inscricao_id">
                   
                   <div class="form-group">
                       <label for="comentario" class="required">Comentário</label>
                       <textarea id="comentario" name="comentario" rows="5" required
                               placeholder="Digite seu comentário ou observação..."></textarea>
                   </div>
               </form>
           </div>
           <div class="modal-footer">
               <button type="button" class="btn btn-secondary" onclick="closeModal('commentModal')">
                   Cancelar
               </button>
               <button type="button" class="btn btn-primary" onclick="addComment()">
                   <i class="fas fa-save"></i> Salvar
               </button>
           </div>
       </div>
   </div>

   <!-- JavaScript -->
   <script src="assets/js/main.js"></script>
   <script>
       // Variáveis globais
       let currentInscricaoId = null;

       // Inicialização
       document.addEventListener('DOMContentLoaded', function() {
           initializePage();
       });

       function initializePage() {
           // CPF mask
           const cpfInput = document.getElementById('filtro_cpf');
           if (cpfInput) {
               cpfInput.addEventListener('input', function() {
                   this.value = formatCPF(this.value);
               });
           }

           // Auto-hide alerts
           const alerts = document.querySelectorAll('.alert');
           alerts.forEach(function(alert) {
               setTimeout(function() {
                   alert.style.opacity = '0';
                   alert.style.transform = 'translateY(-20px)';
                   setTimeout(() => alert.remove(), 300);
               }, 5000);
           });

           // Close modal on outside click
           document.addEventListener('click', function(e) {
               if (e.target.classList.contains('modal-overlay')) {
                   const modalId = e.target.id;
                   closeModal(modalId);
               }
           });

           // Filtro em tempo real
           let timeoutBusca;
           const nomeInput = document.getElementById('filtro_nome');
           if (nomeInput) {
               nomeInput.addEventListener('input', function() {
                   clearTimeout(timeoutBusca);
                   timeoutBusca = setTimeout(() => {
                       document.getElementById('formFiltros').submit();
                   }, 1000);
               });
           }
       }

       // Funções de Modal
       function openModal(modalId) {
           document.getElementById(modalId).classList.add('show');
           document.body.style.overflow = 'hidden';
       }

       function closeModal(modalId) {
           document.getElementById(modalId).classList.remove('show');
           document.body.style.overflow = 'auto';
           // Limpar formulários
           const forms = document.querySelectorAll(`#${modalId} form`);
           forms.forEach(form => form.reset());
       }

       // Funções específicas dos modals
       function openStatusModal(inscricaoId, statusAtual) {
           currentInscricaoId = inscricaoId;
           document.getElementById('status_inscricao_id').value = inscricaoId;
           document.getElementById('status_atual').value = statusAtual;
           document.getElementById('novo_status').value = statusAtual;
           openModal('statusModal');
       }

       function openCommentModal(inscricaoId) {
           currentInscricaoId = inscricaoId;
           document.getElementById('comment_inscricao_id').value = inscricaoId;
           openModal('commentModal');
       }

       // Funções de ação
       function updateStatus() {
           const form = document.getElementById('statusForm');
           const formData = new FormData(form);
           formData.append('acao', 'atualizar_status');
           formData.append('ajax', '1');

           showLoading();
           
           fetch('', {
               method: 'POST',
               body: formData
           })
           .then(response => response.json())
           .then(data => {
               hideLoading();
               if (data.success) {
                   showAlert(data.message, 'success');
                   closeModal('statusModal');
                   setTimeout(() => location.reload(), 1500);
               } else {
                   showAlert(data.message, 'error');
               }
           })
           .catch(error => {
               hideLoading();
               showAlert('Erro de comunicação. Tente novamente.', 'error');
               console.error('Error:', error);
           });
       }

       function addComment() {
           const form = document.getElementById('commentForm');
           const formData = new FormData(form);
           formData.append('acao', 'adicionar_comentario');
           formData.append('ajax', '1');

           showLoading();
           
           fetch('', {
               method: 'POST',
               body: formData
           })
           .then(response => response.json())
           .then(data => {
               hideLoading();
               if (data.success) {
                   showAlert(data.message, 'success');
                   closeModal('commentModal');
               } else {
                   showAlert(data.message, 'error');
               }
           })
           .catch(error => {
               hideLoading();
               showAlert('Erro de comunicação. Tente novamente.', 'error');
               console.error('Error:', error);
           });
       }

       // Utilitários
       function toggleFilters() {
           const content = document.getElementById('filtersContent');
           const chevron = document.getElementById('filtersChevron');
           
           content.classList.toggle('collapsed');
           chevron.classList.toggle('fa-chevron-down');
           chevron.classList.toggle('fa-chevron-up');
       }

       function formatCPF(cpf) {
           cpf = cpf.replace(/\D/g, '');
           cpf = cpf.replace(/(\d{3})(\d)/, '$1.$2');
           cpf = cpf.replace(/(\d{3})(\d)/, '$1.$2');
           cpf = cpf.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
           return cpf;
       }

       function showLoading() {
           document.getElementById('loading').classList.add('show');
       }

       function hideLoading() {
           document.getElementById('loading').classList.remove('show');
       }

       function showAlert(message, type) {
           // Remove existing alerts
           const existingAlerts = document.querySelectorAll('.alert');
           existingAlerts.forEach(alert => alert.remove());

           // Create new alert
           const alert = document.createElement('div');
           alert.className = `alert alert-${type}`;
           alert.innerHTML = `
               <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
               ${message}
           `;

           // Insert after page header
           const pageHeader = document.querySelector('.page-header');
           pageHeader.parentNode.insertBefore(alert, pageHeader.nextSibling);

           // Auto-hide after 5 seconds
           setTimeout(() => {
               alert.style.opacity = '0';
               alert.style.transform = 'translateY(-20px)';
               setTimeout(() => alert.remove(), 300);
           }, 5000);
       }

       function exportarDados() {
           showAlert('Funcionalidade de exportação em desenvolvimento.', 'info');
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

       // Keyboard shortcuts
       document.addEventListener('keydown', function(e) {
           // ESC to close modals
           if (e.key === 'Escape') {
               const openModals = document.querySelectorAll('.modal-overlay.show');
               openModals.forEach(modal => {
                   closeModal(modal.id);
               });
           }
       });

       // Ajustar layout inicial
       adjustMainContent();
   </script>
</body>
</html>
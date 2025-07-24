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

// Verificar permissões de acesso
$tem_permissao = $is_admin || strtoupper($usuario_departamento) === 'ESPORTE';

if (!$tem_permissao) {
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
$espaco_id = intval($_GET['id'] ?? 0);

// Mensagens e tratamento de erros
$mensagem = '';
$tipo_mensagem = '';

// Processar formulários
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar') {
    // Obter ID do espaço do formulário
    $espaco_id = intval($_POST['espaco_id'] ?? 0);
    
    // Coletar e validar dados do formulário
    $dados = [
        'nome' => sanitizeInput($_POST['espaco_nome'] ?? ''),
        'descricao' => sanitizeInput($_POST['espaco_descricao'] ?? ''),
        'tipo' => sanitizeInput($_POST['espaco_tipo'] ?? ''),
        'endereco' => sanitizeInput($_POST['espaco_endereco'] ?? ''),
        'bairro' => sanitizeInput($_POST['espaco_bairro'] ?? ''),
        'cep' => preg_replace('/[^0-9]/', '', $_POST['espaco_cep'] ?? ''),
        'capacidade' => intval($_POST['espaco_capacidade'] ?? 0),
        'area_m2' => floatval($_POST['espaco_area_m2'] ?? 0),
        'iluminacao' => sanitizeInput($_POST['espaco_iluminacao'] ?? 'NAO'),
        'cobertura' => sanitizeInput($_POST['espaco_cobertura'] ?? 'NAO'),
        'vestiario' => sanitizeInput($_POST['espaco_vestiario'] ?? 'NAO'),
        'arquibancada' => sanitizeInput($_POST['espaco_arquibancada'] ?? 'NAO'),
        'acessibilidade' => sanitizeInput($_POST['espaco_acessibilidade'] ?? 'NAO'),
        'equipamentos' => sanitizeInput($_POST['espaco_equipamentos'] ?? ''),
        'observacoes' => sanitizeInput($_POST['espaco_observacoes'] ?? ''),
        'status' => sanitizeInput($_POST['espaco_status'] ?? 'ATIVO')
    ];
    
    // Validação básica
    $erros = [];
    
    if (empty($dados['nome'])) {
        $erros[] = "Nome do espaço é obrigatório.";
    }
    
    if (empty($dados['tipo'])) {
        $erros[] = "Tipo de espaço é obrigatório.";
    }
    
    if (empty($erros)) {
        try {
            $conn->beginTransaction();
            
            if ($espaco_id > 0) {
                // Atualizar
                $sql = "UPDATE tb_espacos_fisicos SET 
                        espaco_nome = :nome,
                        espaco_descricao = :descricao,
                        espaco_tipo = :tipo,
                        espaco_endereco = :endereco,
                        espaco_bairro = :bairro,
                        espaco_cep = :cep,
                        espaco_capacidade = :capacidade,
                        espaco_area_m2 = :area_m2,
                        espaco_iluminacao = :iluminacao,
                        espaco_cobertura = :cobertura,
                        espaco_vestiario = :vestiario,
                        espaco_arquibancada = :arquibancada,
                        espaco_acessibilidade = :acessibilidade,
                        espaco_equipamentos = :equipamentos,
                        espaco_observacoes = :observacoes,
                        espaco_status = :status,
                        espaco_data_modificacao = NOW()
                        WHERE espaco_id = :espaco_id";
                
                $stmt = $conn->prepare($sql);
                
                foreach ($dados as $key => $value) {
                    $stmt->bindValue(':' . $key, $value);
                }
                $stmt->bindValue(':espaco_id', $espaco_id, PDO::PARAM_INT);
                
                $acao_historico = "Espaço físico atualizado";
            } else {
                // Inserir
                $sql = "INSERT INTO tb_espacos_fisicos (
                        espaco_nome, espaco_descricao, espaco_tipo,
                        espaco_endereco, espaco_bairro, espaco_cep,
                        espaco_capacidade, espaco_area_m2, espaco_iluminacao,
                        espaco_cobertura, espaco_vestiario, espaco_arquibancada,
                        espaco_acessibilidade, espaco_equipamentos, espaco_observacoes,
                        espaco_status, espaco_cadastrado_por, espaco_data_cadastro
                    ) VALUES (
                        :nome, :descricao, :tipo,
                        :endereco, :bairro, :cep,
                        :capacidade, :area_m2, :iluminacao,
                        :cobertura, :vestiario, :arquibancada,
                        :acessibilidade, :equipamentos, :observacoes,
                        :status, :cadastrado_por, NOW()
                    )";
                
                $stmt = $conn->prepare($sql);
                
                foreach ($dados as $key => $value) {
                    $stmt->bindValue(':' . $key, $value);
                }
                $stmt->bindValue(':cadastrado_por', $usuario_id, PDO::PARAM_INT);
                
                $acao_historico = "Espaço físico cadastrado";
            }
            
            $stmt->execute();
            
            if ($espaco_id == 0) {
                $espaco_id = $conn->lastInsertId();
            }
            
            // Registrar no histórico
            $stmt_hist = $conn->prepare("INSERT INTO tb_esporte_historico (historico_tipo, historico_referencia_id, historico_acao, historico_detalhes, historico_usuario_id) VALUES (?, ?, ?, ?, ?)");
            $stmt_hist->execute(['ESPACO_FISICO', $espaco_id, $acao_historico, "Espaço: " . $dados['nome'], $usuario_id]);
            
            $conn->commit();
            
            $mensagem = $acao_historico . " com sucesso!";
            $tipo_mensagem = "success";
            $acao = 'listar'; // Redirecionar para listagem
            
            // Limpar dados do formulário
            $espaco_atual = null;
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $mensagem = "Erro ao salvar espaço físico: " . $e->getMessage();
            $tipo_mensagem = "error";
            error_log("Erro ao salvar espaço físico: " . $e->getMessage());
        }
    } else {
        $mensagem = implode("<br>", $erros);
        $tipo_mensagem = "error";
    }
}

// Buscar dados para edição
$espaco_atual = null;
if ($acao === 'editar' && $espaco_id > 0) {
    try {
        $stmt = $conn->prepare("SELECT * FROM tb_espacos_fisicos WHERE espaco_id = ?");
        $stmt->execute([$espaco_id]);
        
        if ($stmt->rowCount() > 0) {
            $espaco_atual = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $mensagem = "Espaço físico não encontrado.";
            $tipo_mensagem = "error";
            $acao = 'listar';
        }
    } catch (PDOException $e) {
        $mensagem = "Erro ao buscar espaço físico: " . $e->getMessage();
        $tipo_mensagem = "error";
        $acao = 'listar';
        error_log("Erro ao buscar espaço físico: " . $e->getMessage());
    }
}

// Buscar lista de espaços para exibição
$espacos = [];
$total_registros = 0;
$total_paginas = 1;

if ($acao === 'listar') {
    // Parâmetros de paginação e filtros
    $registros_por_pagina = 15;
    $pagina_atual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
    $offset = ($pagina_atual - 1) * $registros_por_pagina;
    
    // Filtros de busca
    $filtros = [
        'nome' => sanitizeInput($_GET['filtro_nome'] ?? ''),
        'tipo' => sanitizeInput($_GET['filtro_tipo'] ?? ''),
        'bairro' => sanitizeInput($_GET['filtro_bairro'] ?? ''),
        'status' => sanitizeInput($_GET['filtro_status'] ?? '')
    ];
    
    // Construir query com filtros
    $where_conditions = [];
    $params = [];
    
    foreach ($filtros as $key => $value) {
        if (!empty($value)) {
            switch ($key) {
                case 'nome':
                    $where_conditions[] = "espaco_nome LIKE ?";
                    $params[] = "%{$value}%";
                    break;
                case 'tipo':
                    $where_conditions[] = "espaco_tipo = ?";
                    $params[] = $value;
                    break;
                case 'bairro':
                    $where_conditions[] = "espaco_bairro LIKE ?";
                    $params[] = "%{$value}%";
                    break;
                case 'status':
                    $where_conditions[] = "espaco_status = ?";
                    $params[] = $value;
                    break;
            }
        }
    }
    
    $where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    try {
        // Contar total de registros
        $count_sql = "SELECT COUNT(*) as total FROM tb_espacos_fisicos {$where_sql}";
        $stmt = $conn->prepare($count_sql);
        $stmt->execute($params);
        $total_registros = $stmt->fetch()['total'];
        
        // Buscar registros com paginação
        $sql = "SELECT * FROM tb_espacos_fisicos {$where_sql} ORDER BY espaco_nome ASC LIMIT {$registros_por_pagina} OFFSET {$offset}";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        $espacos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $mensagem = "Erro ao buscar espaços físicos: " . $e->getMessage();
        $tipo_mensagem = "error";
        error_log("Erro ao buscar espaços físicos: " . $e->getMessage());
    }
    
    $total_paginas = ceil($total_registros / $registros_por_pagina);
}

// Funções específicas da página
function formatarCEP($cep) {
    if (empty($cep)) return '';
    $cep = preg_replace('/[^0-9]/', '', $cep);
    if (strlen($cep) != 8) return $cep;
    return substr($cep, 0, 5) . '-' . substr($cep, 5);
}

function getStatusClass($status) {
    $classes = [
        'ATIVO' => 'status-ativo',
        'INATIVO' => 'status-inativo',
        'MANUTENCAO' => 'status-manutencao',
        'REFORMANDO' => 'status-reformando'
    ];
    return $classes[$status] ?? '';
}

function getStatusTexto($status) {
    $textos = [
        'ATIVO' => 'Ativo',
        'INATIVO' => 'Inativo',
        'MANUTENCAO' => 'Manutenção',
        'REFORMANDO' => 'Reformando'
    ];
    return $textos[$status] ?? $status;
}

function getTipoTexto($tipo) {
    $textos = [
        'QUADRA_POLIESPORTIVA' => 'Quadra Poliesportiva',
        'CAMPO_FUTEBOL' => 'Campo de Futebol',
        'CAMPO_SOCIETY' => 'Campo Society',
        'PISCINA' => 'Piscina',
        'GINASIO' => 'Ginásio',
        'PISTA_ATLETISMO' => 'Pista de Atletismo',
        'QUADRA_TENIS' => 'Quadra de Tênis',
        'QUADRA_VOLEI' => 'Quadra de Vôlei',
        'QUADRA_BASQUETE' => 'Quadra de Basquete',
        'OUTRO' => 'Outro'
    ];
    return $textos[$tipo] ?? $tipo;
}

// Listas para dropdowns
$tipos_espaco = [
    'QUADRA_POLIESPORTIVA' => 'Quadra Poliesportiva',
    'CAMPO_FUTEBOL' => 'Campo de Futebol',
    'CAMPO_SOCIETY' => 'Campo Society',
    'PISCINA' => 'Piscina',
    'GINASIO' => 'Ginásio',
    'PISTA_ATLETISMO' => 'Pista de Atletismo',
    'QUADRA_TENIS' => 'Quadra de Tênis',
    'QUADRA_VOLEI' => 'Quadra de Vôlei',
    'QUADRA_BASQUETE' => 'Quadra de Basquete',
    'OUTRO' => 'Outro'
];

$status_espaco = [
    'ATIVO' => 'Ativo',
    'INATIVO' => 'Inativo',
    'MANUTENCAO' => 'Manutenção',
    'REFORMANDO' => 'Reformando'
];

$opcoes_sim_nao = [
    'SIM' => 'Sim',
    'NAO' => 'Não'
];

// Configurar tema e título
$tema_cores = $menuManager->getThemeColors();
$titulo_sistema = $tema_cores['title'];
$cor_tema = $tema_cores['primary'];
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espaços Físicos - Sistema da Prefeitura</title>
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

        /* Sidebar styles */
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

        /* Alerts */
        .alert {
            padding: 12px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            transition: opacity 0.3s;
        }

        .alert i {
            margin-right: 10px;
            font-size: 1.1rem;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Filters */
        .filters-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text-color);
        }

        .filter-group input,
        .filter-group select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
        }

        /* Actions */
        .actions-container {
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Table */
        .table-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .data-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--text-color);
        }

        .data-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-ativo {
            background-color: #d4edda;
            color: #155724;
       }

       .status-inativo {
           background-color: #f8d7da;
           color: #721c24;
       }

       .status-manutencao {
           background-color: #fff3cd;
           color: #856404;
       }

       .status-reformando {
           background-color: #cce5ff;
           color: #004085;
       }

       .actions {
           display: flex;
           gap: 5px;
       }

       .btn-action {
           padding: 6px 10px;
           border-radius: 4px;
           text-decoration: none;
           font-size: 0.9rem;
           transition: all 0.3s;
       }

       .btn-edit {
           background-color: #ffc107;
           color: #212529;
       }

       .btn-edit:hover {
           background-color: #e0a800;
       }

       .btn-view {
           background-color: #17a2b8;
           color: white;
       }

       .btn-view:hover {
           background-color: #138496;
       }

       /* Pagination */
       .pagination-container {
           display: flex;
           justify-content: space-between;
           align-items: center;
           padding: 20px;
           background-color: white;
           border-radius: 8px;
           box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
       }

       .pagination {
           display: flex;
           gap: 5px;
       }

       .pagination a,
       .pagination span {
           padding: 8px 12px;
           border: 1px solid #ddd;
           text-decoration: none;
           color: var(--text-color);
           border-radius: 4px;
           transition: all 0.3s;
       }

       .pagination a:hover {
           background-color: var(--secondary-color);
           color: white;
           border-color: var(--secondary-color);
       }

       .pagination .active {
           background-color: var(--secondary-color);
           color: white;
           border-color: var(--secondary-color);
       }

       /* Forms */
       .form-container {
           background-color: white;
           padding: 25px;
           border-radius: 8px;
           box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
       }

       .form-section {
           margin-bottom: 25px;
           padding-bottom: 20px;
           border-bottom: 1px solid #eee;
       }

       .form-section:last-child {
           border-bottom: none;
           margin-bottom: 0;
       }

       .form-section h3 {
           color: var(--text-color);
           margin-bottom: 15px;
           padding-bottom: 8px;
           border-bottom: 2px solid var(--secondary-color);
           display: inline-block;
       }

       .form-row {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
           gap: 20px;
           margin-bottom: 15px;
       }

       .form-group {
           display: flex;
           flex-direction: column;
       }

       .form-group label {
           margin-bottom: 5px;
           font-weight: 500;
           color: var(--text-color);
       }

       .form-group input,
       .form-group select,
       .form-group textarea {
           padding: 10px;
           border: 1px solid #ddd;
           border-radius: 6px;
           font-size: 14px;
           transition: border-color 0.3s;
       }

       .form-group input:focus,
       .form-group select:focus,
       .form-group textarea:focus {
           outline: none;
           border-color: var(--secondary-color);
           box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.2);
       }

       .form-actions {
           display: flex;
           gap: 10px;
           justify-content: flex-end;
           margin-top: 25px;
           padding-top: 20px;
           border-top: 1px solid #eee;
       }

       .checkbox-group {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
           gap: 15px;
           margin-top: 10px;
       }

       .checkbox-item {
           display: flex;
           align-items: center;
           gap: 8px;
           padding: 10px;
           border: 1px solid #ddd;
           border-radius: 6px;
           background-color: #f8f9fa;
       }

       .checkbox-item input[type="radio"] {
           margin: 0;
       }

       .checkbox-item label {
           margin: 0;
           cursor: pointer;
           font-weight: normal;
       }

       /* Buttons */
       .btn {
           padding: 10px 20px;
           border: none;
           border-radius: 6px;
           cursor: pointer;
           text-decoration: none;
           display: inline-flex;
           align-items: center;
           font-size: 14px;
           font-weight: 500;
           transition: all 0.3s;
       }

       .btn i {
           margin-right: 6px;
       }

       .btn-primary {
           background-color: var(--secondary-color);
           color: white;
       }

       .btn-primary:hover {
           background-color: #45a049;
           transform: translateY(-1px);
       }

       .btn-secondary {
           background-color: #6c757d;
           color: white;
       }

       .btn-secondary:hover {
           background-color: #545b62;
       }

       .btn-success {
           background-color: var(--success-color);
           color: white;
       }

       .btn-success:hover {
           background-color: #218838;
       }

       .btn-info {
           background-color: var(--info-color);
           color: white;
       }

       .btn-info:hover {
           background-color: #138496;
       }

       .text-center {
           text-align: center;
       }

       .text-muted {
           color: #6c757d;
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

           .filters-form {
               grid-template-columns: 1fr;
           }

           .form-row {
               grid-template-columns: 1fr;
           }

           .checkbox-group {
               grid-template-columns: 1fr;
           }

           .actions-container {
               flex-direction: column;
               gap: 10px;
           }

           .pagination-container {
               flex-direction: column;
               gap: 10px;
           }
       }

       .mobile-toggle {
           display: none;
       }
   </style>
</head>
<body>
   <!-- Menu lateral gerado pelo MenuManager -->
   <div class="sidebar" id="sidebar">
       <div class="sidebar-header">
           <h3><?php echo $titulo_sistema; ?></h3>
           <button class="toggle-btn">
               <i class="fas fa-bars"></i>
           </button>
       </div>
       
       <?php echo $menuManager->generateMenu(basename(__FILE__, '.php')); ?>
   </div>

   <!-- Conteúdo principal -->
   <div class="main-content">
       <!-- Header -->
       <div class="header">
           <div>
               <button class="mobile-toggle">
                   <i class="fas fa-bars"></i>
               </button>
           </div>
           
           <div class="user-info">
               <div class="user-details">
                   <span class="user-name"><?php echo htmlspecialchars($usuario_nome); ?></span>
                   <div class="user-role">
                       <?php if ($is_admin): ?>
                       <span class="admin-badge">
                           <i class="fas fa-crown"></i> Administrador
                       </span>
                       <?php else: ?>
                       <span class="department-badge">
                           <i class="fas fa-running"></i> Esporte
                       </span>
                       <?php endif; ?>
                   </div>
               </div>
           </div>
       </div>
       
       <h1 class="page-title">
           <i class="fas fa-map-marker-alt"></i>
           <?php 
           switch($acao) {
               case 'adicionar':
                   echo 'Cadastrar Espaço Físico';
                   break;
               case 'editar':
                   echo 'Editar Espaço Físico';
                   break;
               default:
                   echo 'Gerenciar Espaços Físicos';
           }
           ?>
       </h1>

       <!-- Breadcrumb -->
       <div class="breadcrumb">
           <a href="dashboard.php">Dashboard</a>
           <i class="fas fa-chevron-right"></i>
           <a href="esporte.php">Esporte</a>
           <i class="fas fa-chevron-right"></i>
           <span>Espaços Físicos</span>
       </div>

       <!-- Mensagens -->
       <?php if ($mensagem): ?>
       <div class="alert alert-<?php echo $tipo_mensagem == 'success' ? 'success' : 'error'; ?>">
           <i class="fas fa-<?php echo $tipo_mensagem == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
           <?php echo $mensagem; ?>
       </div>
       <?php endif; ?>

       <?php if ($acao === 'listar'): ?>
       <!-- Filtros de busca -->
       <div class="filters-container">
           <form method="GET" class="filters-form">
               <input type="hidden" name="acao" value="listar">
               
               <div class="filter-group">
                   <label for="filtro_nome">Nome do Espaço:</label>
                   <input type="text" id="filtro_nome" name="filtro_nome" 
                          value="<?php echo htmlspecialchars($filtros['nome']); ?>" 
                          placeholder="Digite o nome...">
               </div>
               
               <div class="filter-group">
                   <label for="filtro_tipo">Tipo:</label>
                   <select id="filtro_tipo" name="filtro_tipo">
                       <option value="">Todos os tipos</option>
                       <?php foreach ($tipos_espaco as $tipo_key => $tipo_text): ?>
                       <option value="<?php echo $tipo_key; ?>" 
                               <?php echo ($filtros['tipo'] === $tipo_key) ? 'selected' : ''; ?>>
                           <?php echo $tipo_text; ?>
                       </option>
                       <?php endforeach; ?>
                   </select>
               </div>
               
               <div class="filter-group">
                   <label for="filtro_bairro">Bairro:</label>
                   <input type="text" id="filtro_bairro" name="filtro_bairro" 
                          value="<?php echo htmlspecialchars($filtros['bairro']); ?>" 
                          placeholder="Digite o bairro...">
               </div>
               
               <div class="filter-group">
                   <label for="filtro_status">Status:</label>
                   <select id="filtro_status" name="filtro_status">
                       <option value="">Todos os status</option>
                       <?php foreach ($status_espaco as $status_key => $status_text): ?>
                       <option value="<?php echo $status_key; ?>" 
                               <?php echo ($filtros['status'] === $status_key) ? 'selected' : ''; ?>>
                           <?php echo $status_text; ?>
                       </option>
                       <?php endforeach; ?>
                   </select>
               </div>
               
               <div class="filter-actions">
                   <button type="submit" class="btn btn-primary">
                       <i class="fas fa-search"></i> Filtrar
                   </button>
                   <a href="?acao=listar" class="btn btn-secondary">
                       <i class="fas fa-times"></i> Limpar
                   </a>
               </div>
           </form>
       </div>

       <!-- Ações principais -->
       <div class="actions-container">
           <a href="?acao=adicionar" class="btn btn-success">
               <i class="fas fa-plus"></i> Cadastrar Espaço
           </a>
       </div>

       <!-- Lista de espaços -->
       <div class="table-container">
           <table class="data-table">
               <thead>
                   <tr>
                       <th>Nome</th>
                       <th>Tipo</th>
                       <th>Endereço</th>
                       <th>Capacidade</th>
                       <th>Características</th>
                       <th>Status</th>
                       <th>Ações</th>
                   </tr>
               </thead>
               <tbody>
                   <?php if (empty($espacos)): ?>
                   <tr>
                       <td colspan="7" class="text-center">Nenhum espaço físico encontrado.</td>
                   </tr>
                   <?php else: ?>
                   <?php foreach ($espacos as $espaco): ?>
                   <tr>
                       <td>
                           <strong><?php echo htmlspecialchars($espaco['espaco_nome']); ?></strong>
                           <?php if (!empty($espaco['espaco_area_m2'])): ?>
                           <br><small class="text-muted"><?php echo number_format($espaco['espaco_area_m2'], 0); ?>m²</small>
                           <?php endif; ?>
                       </td>
                       <td>
                           <small><?php echo getTipoTexto($espaco['espaco_tipo']); ?></small>
                       </td>
                       <td>
                           <small>
                               <?php echo htmlspecialchars($espaco['espaco_endereco'] ?: 'Não informado'); ?>
                               <?php if (!empty($espaco['espaco_bairro'])): ?>
                               <br><?php echo htmlspecialchars($espaco['espaco_bairro']); ?>
                               <?php endif; ?>
                           </small>
                       </td>
                       <td>
                           <small><?php echo $espaco['espaco_capacidade'] ? number_format($espaco['espaco_capacidade']) . ' pessoas' : 'Não informado'; ?></small>
                       </td>
                       <td>
                           <small>
                               <?php
                               $caracteristicas = [];
                               if ($espaco['espaco_iluminacao'] === 'SIM') $caracteristicas[] = 'Iluminação';
                               if ($espaco['espaco_cobertura'] === 'SIM') $caracteristicas[] = 'Cobertura';
                               if ($espaco['espaco_vestiario'] === 'SIM') $caracteristicas[] = 'Vestiário';
                               if ($espaco['espaco_arquibancada'] === 'SIM') $caracteristicas[] = 'Arquibancada';
                               if ($espaco['espaco_acessibilidade'] === 'SIM') $caracteristicas[] = 'Acessibilidade';
                               echo !empty($caracteristicas) ? implode(', ', $caracteristicas) : 'Nenhuma';
                               ?>
                           </small>
                       </td>
                       <td>
                           <span class="status-badge <?php echo getStatusClass($espaco['espaco_status']); ?>">
                               <?php echo getStatusTexto($espaco['espaco_status']); ?>
                           </span>
                       </td>
                       <td>
                           <div class="actions">
                               <a href="?acao=editar&id=<?php echo $espaco['espaco_id']; ?>" 
                                  class="btn-action btn-edit" title="Editar">
                                   <i class="fas fa-edit"></i>
                               </a>
                               <a href="?acao=visualizar&id=<?php echo $espaco['espaco_id']; ?>" 
                                  class="btn-action btn-view" title="Visualizar">
                                   <i class="fas fa-eye"></i>
                               </a>
                           </div>
                       </td>
                   </tr>
                   <?php endforeach; ?>
                   <?php endif; ?>
               </tbody>
           </table>
       </div>
       
       <!-- Paginação -->
       <?php if ($total_paginas > 1): ?>
       <div class="pagination-container">
           <div class="pagination-info">
               Mostrando <?php echo (($pagina_atual - 1) * $registros_por_pagina) + 1; ?> a 
               <?php echo min($pagina_atual * $registros_por_pagina, $total_registros); ?> de 
               <?php echo number_format($total_registros); ?> registros
           </div>
           
           <div class="pagination">
               <?php if ($pagina_atual > 1): ?>
               <a href="?acao=listar&pagina=1<?php echo '&' . http_build_query(array_filter($filtros)); ?>">
                   <i class="fas fa-angle-double-left"></i>
               </a>
               <a href="?acao=listar&pagina=<?php echo $pagina_atual - 1; ?><?php echo '&' . http_build_query(array_filter($filtros)); ?>">
                   <i class="fas fa-angle-left"></i>
               </a>
               <?php endif; ?>
               
               <?php
               $inicio = max(1, $pagina_atual - 2);
               $fim = min($total_paginas, $pagina_atual + 2);
               
               for ($i = $inicio; $i <= $fim; $i++):
               ?>
               <?php if ($i == $pagina_atual): ?>
               <span class="active"><?php echo $i; ?></span>
               <?php else: ?>
               <a href="?acao=listar&pagina=<?php echo $i; ?><?php echo '&' . http_build_query(array_filter($filtros)); ?>">
                   <?php echo $i; ?>
               </a>
               <?php endif; ?>
               <?php endfor; ?>
               
               <?php if ($pagina_atual < $total_paginas): ?>
               <a href="?acao=listar&pagina=<?php echo $pagina_atual + 1; ?><?php echo '&' . http_build_query(array_filter($filtros)); ?>">
                   <i class="fas fa-angle-right"></i>
               </a>
               <a href="?acao=listar&pagina=<?php echo $total_paginas; ?><?php echo '&' . http_build_query(array_filter($filtros)); ?>">
                   <i class="fas fa-angle-double-right"></i>
               </a>
               <?php endif; ?>
           </div>
       </div>
       <?php endif; ?>

       <?php elseif ($acao === 'adicionar' || $acao === 'editar'): ?>
       <!-- Formulário de cadastro/edição -->
       <form method="POST" class="form-container">
           <input type="hidden" name="acao" value="salvar">
           <input type="hidden" name="espaco_id" value="<?php echo $espaco_id; ?>">
           
           <div class="form-section">
               <h3>Informações Básicas</h3>
               
               <div class="form-row">
                   <div class="form-group">
                       <label for="espaco_nome">Nome do Espaço *</label>
                       <input type="text" id="espaco_nome" name="espaco_nome" 
                              value="<?php echo htmlspecialchars($espaco_atual['espaco_nome'] ?? ''); ?>" 
                              required maxlength="255" placeholder="Ex: Ginásio Municipal João Silva">
                   </div>
                   
                   <div class="form-group">
                       <label for="espaco_tipo">Tipo de Espaço *</label>
                       <select id="espaco_tipo" name="espaco_tipo" required>
                           <option value="">Selecione...</option>
                           <?php foreach ($tipos_espaco as $tipo_key => $tipo_text): ?>
                           <option value="<?php echo $tipo_key; ?>" 
                                   <?php echo (($espaco_atual['espaco_tipo'] ?? '') === $tipo_key) ? 'selected' : ''; ?>>
                               <?php echo $tipo_text; ?>
                           </option>
                           <?php endforeach; ?>
                       </select>
                   </div>
               </div>
               
               <div class="form-group">
                   <label for="espaco_descricao">Descrição</label>
                   <textarea id="espaco_descricao" name="espaco_descricao" 
                             rows="3" maxlength="1000" 
                             placeholder="Descrição detalhada do espaço..."><?php echo htmlspecialchars($espaco_atual['espaco_descricao'] ?? ''); ?></textarea>
               </div>
           </div>
           
           <div class="form-section">
               <h3>Localização</h3>
               
               <div class="form-row">
                   <div class="form-group">
                       <label for="espaco_endereco">Endereço</label>
                       <input type="text" id="espaco_endereco" name="espaco_endereco" 
                              value="<?php echo htmlspecialchars($espaco_atual['espaco_endereco'] ?? ''); ?>" 
                              maxlength="255" placeholder="Rua, número">
                   </div>
                   
                   <div class="form-group">
                       <label for="espaco_bairro">Bairro</label>
                       <input type="text" id="espaco_bairro" name="espaco_bairro" 
                              value="<?php echo htmlspecialchars($espaco_atual['espaco_bairro'] ?? ''); ?>" 
                              maxlength="100" placeholder="Nome do bairro">
                   </div>
               </div>
               
               <div class="form-row">
                   <div class="form-group">
                       <label for="espaco_cep">CEP</label>
                       <input type="text" id="espaco_cep" name="espaco_cep" 
                              value="<?php echo formatarCEP($espaco_atual['espaco_cep'] ?? ''); ?>" 
                              maxlength="10" placeholder="00000-000">
                   </div>
                   
                   <div class="form-group">
                       <label for="espaco_status">Status</label>
                       <select id="espaco_status" name="espaco_status">
                           <?php foreach ($status_espaco as $status_key => $status_text): ?>
                           <option value="<?php echo $status_key; ?>" 
                                   <?php echo (($espaco_atual['espaco_status'] ?? 'ATIVO') === $status_key) ? 'selected' : ''; ?>>
                               <?php echo $status_text; ?>
                           </option>
                           <?php endforeach; ?>
                       </select>
                   </div>
               </div>
           </div>
           
           <div class="form-section">
               <h3>Características Físicas</h3>
               
               <div class="form-row">
                   <div class="form-group">
                       <label for="espaco_capacidade">Capacidade (pessoas)</label>
                       <input type="number" id="espaco_capacidade" name="espaco_capacidade" 
                              value="<?php echo $espaco_atual['espaco_capacidade'] ?? ''; ?>" 
                              min="0" placeholder="Número máximo de pessoas">
                   </div>
                   
                   <div class="form-group">
                       <label for="espaco_area_m2">Área (m²)</label>
                       <input type="number" id="espaco_area_m2" name="espaco_area_m2" 
                              value="<?php echo $espaco_atual['espaco_area_m2'] ?? ''; ?>" 
                              min="0" step="0.01" placeholder="Área em metros quadrados">
                   </div>
               </div>
           </div>
           
           <div class="form-section">
               <h3>Infraestrutura</h3>
               
               <div class="form-row">
                   <div class="form-group">
                       <label>Iluminação</label>
                       <div class="checkbox-group">
                           <?php foreach ($opcoes_sim_nao as $opcao_key => $opcao_text): ?>
                           <div class="checkbox-item">
                               <input type="radio" id="iluminacao_<?php echo $opcao_key; ?>" 
                                      name="espaco_iluminacao" value="<?php echo $opcao_key; ?>"
                                      <?php echo (($espaco_atual['espaco_iluminacao'] ?? 'NAO') === $opcao_key) ? 'checked' : ''; ?>>
                               <label for="iluminacao_<?php echo $opcao_key; ?>"><?php echo $opcao_text; ?></label>
                           </div>
                           <?php endforeach; ?>
                       </div>
                   </div>
                   
                   <div class="form-group">
                       <label>Cobertura</label>
                       <div class="checkbox-group">
                           <?php foreach ($opcoes_sim_nao as $opcao_key => $opcao_text): ?>
                           <div class="checkbox-item">
                               <input type="radio" id="cobertura_<?php echo $opcao_key; ?>" 
                                      name="espaco_cobertura" value="<?php echo $opcao_key; ?>"
                                      <?php echo (($espaco_atual['espaco_cobertura'] ?? 'NAO') === $opcao_key) ? 'checked' : ''; ?>>
                               <label for="cobertura_<?php echo $opcao_key; ?>"><?php echo $opcao_text; ?></label>
                           </div>
                           <?php endforeach; ?>
                       </div>
                   </div>
               </div>
               
               <div class="form-row">
                   <div class="form-group">
                       <label>Vestiário</label>
                       <div class="checkbox-group">
                           <?php foreach ($opcoes_sim_nao as $opcao_key => $opcao_text): ?>
                           <div class="checkbox-item">
                               <input type="radio" id="vestiario_<?php echo $opcao_key; ?>" 
                                      name="espaco_vestiario" value="<?php echo $opcao_key; ?>"
                                      <?php echo (($espaco_atual['espaco_vestiario'] ?? 'NAO') === $opcao_key) ? 'checked' : ''; ?>>
                               <label for="vestiario_<?php echo $opcao_key; ?>"><?php echo $opcao_text; ?></label>
                           </div>
                           <?php endforeach; ?>
                       </div>
                   </div>
                   
                   <div class="form-group">
                       <label>Arquibancada</label>
                       <div class="checkbox-group">
                           <?php foreach ($opcoes_sim_nao as $opcao_key => $opcao_text): ?>
                           <div class="checkbox-item">
                               <input type="radio" id="arquibancada_<?php echo $opcao_key; ?>" 
                                      name="espaco_arquibancada" value="<?php echo $opcao_key; ?>"
                                      <?php echo (($espaco_atual['espaco_arquibancada'] ?? 'NAO') === $opcao_key) ? 'checked' : ''; ?>>
                               <label for="arquibancada_<?php echo $opcao_key; ?>"><?php echo $opcao_text; ?></label>
                           </div>
                           <?php endforeach; ?>
                       </div>
                   </div>
               </div>
               
               <div class="form-row">
                   <div class="form-group">
                       <label>Acessibilidade</label>
                       <div class="checkbox-group">
                           <?php foreach ($opcoes_sim_nao as $opcao_key => $opcao_text): ?>
                           <div class="checkbox-item">
                               <input type="radio" id="acessibilidade_<?php echo $opcao_key; ?>" 
                                      name="espaco_acessibilidade" value="<?php echo $opcao_key; ?>"
                                      <?php echo (($espaco_atual['espaco_acessibilidade'] ?? 'NAO') === $opcao_key) ? 'checked' : ''; ?>>
                               <label for="acessibilidade_<?php echo $opcao_key; ?>"><?php echo $opcao_text; ?></label>
                           </div>
                           <?php endforeach; ?>
                       </div>
                   </div>
               </div>
           </div>
           
           <div class="form-section">
               <h3>Equipamentos e Observações</h3>
               
               <div class="form-group">
                   <label for="espaco_equipamentos">Equipamentos Disponíveis</label>
                   <textarea id="espaco_equipamentos" name="espaco_equipamentos" 
                             rows="3" maxlength="1000" 
                             placeholder="Liste os equipamentos disponíveis no espaço..."><?php echo htmlspecialchars($espaco_atual['espaco_equipamentos'] ?? ''); ?></textarea>
               </div>
               
               <div class="form-group">
                   <label for="espaco_observacoes">Observações Gerais</label>
                   <textarea id="espaco_observacoes" name="espaco_observacoes" 
                             rows="4" maxlength="2000" 
                             placeholder="Informações adicionais, regras de uso, horários de funcionamento..."><?php echo htmlspecialchars($espaco_atual['espaco_observacoes'] ?? ''); ?></textarea>
               </div>
           </div>
           
           <div class="form-actions">
               <button type="submit" class="btn btn-success">
                   <i class="fas fa-save"></i> 
                   <?php echo ($acao === 'editar') ? 'Atualizar' : 'Cadastrar'; ?> Espaço
               </button>
               <a href="?acao=listar" class="btn btn-secondary">
                   <i class="fas fa-times"></i> Cancelar
               </a>
           </div>
       </form>
       
       <?php endif; ?>
   </div>

   <script>
       // Toggle do menu lateral
       document.addEventListener('DOMContentLoaded', function() {
           const toggleBtn = document.querySelector('.toggle-btn');
           const sidebar = document.getElementById('sidebar');
           const mainContent = document.querySelector('.main-content');
           
           if (toggleBtn) {
               toggleBtn.addEventListener('click', function() {
                   sidebar.classList.toggle('collapsed');
               });
           }
           
           // Submenu toggle
           const menuLinks = document.querySelectorAll('.menu-link');
           menuLinks.forEach(link => {
               link.addEventListener('click', function(e) {
                   const menuItem = this.parentElement;
                   const submenu = menuItem.querySelector('.submenu');
                   
                   if (submenu) {
                       e.preventDefault();
                       menuItem.classList.toggle('open');
                   }
               });
           });
           
           // Máscara de CEP
           const cepInput = document.getElementById('espaco_cep');
           if (cepInput) {
               cepInput.addEventListener('input', function(e) {
                   let value = e.target.value.replace(/\D/g, '');
                   value = value.replace(/^(\d{5})(\d)/, '$1-$2');
                   e.target.value = value;
               });
           }
           
           // Validação de números
           const numberInputs = document.querySelectorAll('input[type="number"]');
           numberInputs.forEach(input => {
               input.addEventListener('blur', function() {
                   if (this.value < 0) {
                       this.value = 0;
                   }
               });
           });
           
           // Auto-hide alerts
           const alerts = document.querySelectorAll('.alert');
           alerts.forEach(alert => {
               setTimeout(() => {
                   alert.style.opacity = '0';
                   setTimeout(() => {
                       alert.remove();
                   }, 300);
               }, 5000);
           });
           
           // Mobile menu toggle
           const mobileToggle = document.querySelector('.mobile-toggle');
           if (mobileToggle) {
               mobileToggle.addEventListener('click', function() {
                   sidebar.classList.toggle('show');
               });
           }
           
           // Fechar menu mobile ao clicar fora
           document.addEventListener('click', function(e) {
               if (window.innerWidth <= 768) {
                   if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                       sidebar.classList.remove('show');
                   }
               }
           });
           
           // Formatação automática de área
           const areaInput = document.getElementById('espaco_area_m2');
           if (areaInput) {
               areaInput.addEventListener('blur', function() {
                   const value = parseFloat(this.value);
                   if (!isNaN(value) && value > 0) {
                       this.value = value.toFixed(2);
                   }
               });
           }
       });
   </script>
</body>
</html>
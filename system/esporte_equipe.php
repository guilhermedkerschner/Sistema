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
$equipe_id = intval($_GET['id'] ?? 0);

// Mensagens e tratamento de erros
$mensagem = '';
$tipo_mensagem = '';

// Processar formulários
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar') {
    // Obter ID da equipe do formulário
    $equipe_id = intval($_POST['equipe_id'] ?? 0);
    
    // Coletar e validar dados do formulário
    $dados = [
        'nome' => sanitizeInput($_POST['equipe_nome'] ?? ''),
        'modalidade' => sanitizeInput($_POST['equipe_modalidade'] ?? ''),
        'categoria' => sanitizeInput($_POST['equipe_categoria'] ?? ''),
        'responsavel' => sanitizeInput($_POST['equipe_responsavel'] ?? ''),
        'telefone' => sanitizeInput($_POST['equipe_telefone'] ?? ''),
        'email' => sanitizeInput($_POST['equipe_email'] ?? ''),
        'observacoes' => sanitizeInput($_POST['equipe_observacoes'] ?? ''),
        'status' => sanitizeInput($_POST['equipe_status'] ?? 'ATIVA')
    ];
    
    // Atletas selecionados
    $atletas_selecionados = $_POST['atletas'] ?? [];
    
    // Validação básica
    $erros = [];
    
    if (empty($dados['nome'])) {
        $erros[] = "Nome da equipe é obrigatório.";
    }
    
    if (empty($dados['modalidade'])) {
        $erros[] = "Modalidade é obrigatória.";
    }
    
    if (empty($dados['categoria'])) {
        $erros[] = "Categoria é obrigatória.";
    }
    
    if (empty($erros)) {
        try {
            $conn->beginTransaction();
            
            if ($equipe_id > 0) {
                // Atualizar
                $sql = "UPDATE tb_equipes SET 
                        equipe_nome = :nome,
                        equipe_modalidade = :modalidade,
                        equipe_categoria = :categoria,
                        equipe_responsavel = :responsavel,
                        equipe_telefone = :telefone,
                        equipe_email = :email,
                        equipe_observacoes = :observacoes,
                        equipe_status = :status
                        WHERE equipe_id = :equipe_id";
                
                $stmt = $conn->prepare($sql);
                
                foreach ($dados as $key => $value) {
                    $stmt->bindValue(':' . $key, $value);
                }
                $stmt->bindValue(':equipe_id', $equipe_id, PDO::PARAM_INT);
                
                $acao_historico = "Equipe atualizada";
            } else {
                // Inserir
                $sql = "INSERT INTO tb_equipes (
                        equipe_nome, equipe_modalidade, equipe_categoria,
                        equipe_responsavel, equipe_telefone, equipe_email,
                        equipe_observacoes, equipe_status, equipe_cadastrado_por
                    ) VALUES (
                        :nome, :modalidade, :categoria,
                        :responsavel, :telefone, :email,
                        :observacoes, :status, :cadastrado_por
                    )";
                
                $stmt = $conn->prepare($sql);
                
                foreach ($dados as $key => $value) {
                    $stmt->bindValue(':' . $key, $value);
                }
                $stmt->bindValue(':cadastrado_por', $usuario_id, PDO::PARAM_INT);
                
                $acao_historico = "Equipe cadastrada";
            }
            
            $stmt->execute();
            
            if ($equipe_id == 0) {
                $equipe_id = $conn->lastInsertId();
            }
            
            // Gerenciar atletas da equipe
            if (!empty($atletas_selecionados)) {
                // Remover atletas existentes
                $stmt = $conn->prepare("DELETE FROM tb_equipe_atletas WHERE equipe_id = ?");
                $stmt->execute([$equipe_id]);
                
                // Adicionar novos atletas
                $stmt = $conn->prepare("INSERT INTO tb_equipe_atletas (equipe_id, atleta_id, numero_camisa) VALUES (?, ?, ?)");
                
                foreach ($atletas_selecionados as $atleta_id_sel) {
                    $numero_camisa = intval($_POST['numero_' . $atleta_id_sel] ?? 0);
                    $stmt->execute([$equipe_id, $atleta_id_sel, $numero_camisa > 0 ? $numero_camisa : null]);
                }
            }
            
            $conn->commit();
            
            $mensagem = $acao_historico . " com sucesso!";
            $tipo_mensagem = "success";
            $acao = 'listar';
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $mensagem = "Erro ao salvar equipe: " . $e->getMessage();
            $tipo_mensagem = "error";
            error_log("Erro ao salvar equipe: " . $e->getMessage());
        }
    } else {
        $mensagem = implode("<br>", $erros);
        $tipo_mensagem = "error";
    }
}

// Buscar dados para edição
$equipe_atual = null;
$atletas_equipe = [];
if ($acao === 'editar' && $equipe_id > 0) {
    try {
        $stmt = $conn->prepare("SELECT * FROM tb_equipes WHERE equipe_id = ?");
        $stmt->execute([$equipe_id]);
        
        if ($stmt->rowCount() > 0) {
            $equipe_atual = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Buscar atletas da equipe
            $stmt = $conn->prepare("
                SELECT ea.*, a.atleta_nome, a.atleta_modalidade_principal
                FROM tb_equipe_atletas ea
                JOIN tb_atletas a ON ea.atleta_id = a.atleta_id
                WHERE ea.equipe_id = ?
                ORDER BY a.atleta_nome
            ");
            $stmt->execute([$equipe_id]);
            $atletas_equipe = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } else {
            $mensagem = "Equipe não encontrada.";
            $tipo_mensagem = "error";
            $acao = 'listar';
        }
    } catch (PDOException $e) {
        $mensagem = "Erro ao buscar equipe: " . $e->getMessage();
        $tipo_mensagem = "error";
        $acao = 'listar';
        error_log("Erro ao buscar equipe: " . $e->getMessage());
    }
}

// Buscar dados para visualização
$equipe_visualizar = null;
$estatisticas_equipe = [];
if ($acao === 'visualizar' && $equipe_id > 0) {
    try {
        // Buscar dados da equipe
        $stmt = $conn->prepare("SELECT * FROM tb_equipes WHERE equipe_id = ?");
        $stmt->execute([$equipe_id]);
        
        if ($stmt->rowCount() > 0) {
            $equipe_visualizar = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Buscar estatísticas da equipe
            $stmt = $conn->prepare("SELECT COUNT(*) as total_atletas FROM tb_equipe_atletas WHERE equipe_id = ?");
            $stmt->execute([$equipe_id]);
            $estatisticas_equipe['total_atletas'] = $stmt->fetchColumn();
            
            // Total de campeonatos
            $stmt = $conn->prepare("SELECT COUNT(*) as total_campeonatos FROM tb_campeonato_equipes WHERE equipe_id = ?");
            $stmt->execute([$equipe_id]);
            $estatisticas_equipe['total_campeonatos'] = $stmt->fetchColumn();
            
            // Buscar atletas da equipe
            $stmt = $conn->prepare("
                SELECT ea.*, a.atleta_nome, a.atleta_modalidade_principal, a.atleta_categoria
                FROM tb_equipe_atletas ea
                JOIN tb_atletas a ON ea.atleta_id = a.atleta_id
                WHERE ea.equipe_id = ?
                ORDER BY ea.numero_camisa, a.atleta_nome
            ");
            $stmt->execute([$equipe_id]);
            $atletas_equipe = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } else {
            $mensagem = "Equipe não encontrada.";
            $tipo_mensagem = "error";
            $acao = 'listar';
        }
    } catch (PDOException $e) {
        $mensagem = "Erro ao buscar equipe: " . $e->getMessage();
        $tipo_mensagem = "error";
        $acao = 'listar';
        error_log("Erro ao buscar equipe: " . $e->getMessage());
    }
}

// Buscar lista de equipes para exibição
$equipes = [];
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
        'modalidade' => sanitizeInput($_GET['filtro_modalidade'] ?? ''),
        'categoria' => sanitizeInput($_GET['filtro_categoria'] ?? ''),
        'status' => sanitizeInput($_GET['filtro_status'] ?? '')
    ];
    
    // Construir query com filtros
    $where_conditions = [];
    $params = [];
    
    foreach ($filtros as $key => $value) {
        if (!empty($value)) {
            switch ($key) {
                case 'nome':
                    $where_conditions[] = "equipe_nome LIKE ?";
                    $params[] = "%{$value}%";
                    break;
                case 'modalidade':
                    $where_conditions[] = "equipe_modalidade LIKE ?";
                    $params[] = "%{$value}%";
                    break;
                case 'categoria':
                    $where_conditions[] = "equipe_categoria = ?";
                    $params[] = $value;
                    break;
                case 'status':
                    $where_conditions[] = "equipe_status = ?";
                    $params[] = $value;
                    break;
            }
        }
    }
    
    $where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    try {
        // Contar total de registros
        $count_sql = "SELECT COUNT(*) as total FROM tb_equipes {$where_sql}";
        $stmt = $conn->prepare($count_sql);
        $stmt->execute($params);
        $total_registros = $stmt->fetch()['total'];
        
        // Buscar registros com paginação
        $sql = "SELECT e.*, 
                (SELECT COUNT(*) FROM tb_equipe_atletas ea WHERE ea.equipe_id = e.equipe_id) as total_atletas
                FROM tb_equipes e {$where_sql} 
                ORDER BY e.equipe_nome ASC 
                LIMIT {$registros_por_pagina} OFFSET {$offset}";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        $equipes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $mensagem = "Erro ao buscar equipes: " . $e->getMessage();
        $tipo_mensagem = "error";
        error_log("Erro ao buscar equipes: " . $e->getMessage());
    }
    
    $total_paginas = ceil($total_registros / $registros_por_pagina);
}

// Buscar atletas disponíveis para formulário
$atletas_disponiveis = [];
if ($acao === 'adicionar' || $acao === 'editar') {
    try {
        $stmt = $conn->query("
            SELECT atleta_id, atleta_nome, atleta_modalidade_principal, atleta_categoria
            FROM tb_atletas 
            WHERE atleta_status = 'ATIVO'
            ORDER BY atleta_nome
        ");
        $atletas_disponiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erro ao buscar atletas: " . $e->getMessage());
    }
}

// Funções específicas da página
function getStatusClass($status) {
    $classes = [
        'ATIVA' => 'status-ativo',
        'INATIVA' => 'status-inativo',
        'SUSPENSA' => 'status-suspenso'
    ];
    return $classes[$status] ?? '';
}

function getStatusTexto($status) {
    $textos = [
        'ATIVA' => 'Ativa',
        'INATIVA' => 'Inativa',
        'SUSPENSA' => 'Suspensa'
    ];
    return $textos[$status] ?? $status;
}

// Listas para dropdowns
$modalidades = [
    'Futebol', 'Futsal', 'Vôlei', 'Basquete', 'Handebol', 'Tênis', 'Tênis de Mesa',
    'Natação', 'Atletismo', 'Judô', 'Karatê', 'Taekwondo', 'Capoeira', 'Xadrez',
    'Damas', 'Corrida', 'Ciclismo', 'Ginástica', 'Outros'
];

$categorias = ['INFANTIL', 'JUVENIL', 'JUNIOR', 'ADULTO', 'MASTER', 'LIVRE'];

$status_equipe = [
    'ATIVA' => 'Ativa',
    'INATIVA' => 'Inativa',
    'SUSPENSA' => 'Suspensa'
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
    <title>Equipes - Sistema da Prefeitura</title>
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

        /* Sidebar styles - COPIANDO O CSS COMPLETO DO ARQUIVO ORIGINAL */
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

       .table-header {
           background-color: #f8f9fa;
           padding: 15px 20px;
           border-bottom: 1px solid #dee2e6;
           display: flex;
           align-items: center;
           justify-content: space-between;
       }

       .table-title {
           font-weight: 600;
           color: var(--text-color);
           display: flex;
           align-items: center;
       }

       .table-title i {
           margin-right: 10px;
           color: var(--secondary-color);
       }

       .table-info {
           color: #666;
           font-size: 0.9rem;
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

       .status-suspenso {
           background-color: #fff3cd;
           color: #856404;
       }

       .actions {
           display: flex;
           gap: 5px;
           align-items: center;
       }

       .btn-action {
           padding: 6px 10px;
           border-radius: 4px;
           text-decoration: none;
           font-size: 0.9rem;
           transition: all 0.3s;
           display: inline-flex;
           align-items: center;
           justify-content: center;
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

       /* Athletes selection */
       .athletes-section {
           background-color: #f8f9fa;
           padding: 20px;
           border-radius: 8px;
           margin-top: 20px;
       }

       .athletes-grid {
           display: grid;
           grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
           gap: 15px;
           max-height: 400px;
           overflow-y: auto;
           border: 1px solid #ddd;
           padding: 15px;
           border-radius: 6px;
           background-color: white;
       }

       .athlete-item {
           display: flex;
           align-items: center;
           padding: 10px;
           border: 1px solid #ddd;
           border-radius: 6px;
           background-color: #fff;
           transition: all 0.3s;
       }

       .athlete-item:hover {
           border-color: var(--secondary-color);
           box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
       }

       .athlete-item input[type="checkbox"] {
           margin-right: 10px;
       }

       .athlete-info {
           flex: 1;
       }

       .athlete-name {
           font-weight: bold;
           color: var(--text-color);
       }

       .athlete-details {
           font-size: 0.85rem;
           color: #666;
       }

       .jersey-number {
           width: 60px;
           margin-left: 10px;
           padding: 5px;
           border: 1px solid #ddd;
           border-radius: 4px;
           text-align: center;
       }

       /* Visualização */
       .view-container {
           background-color: white;
           border-radius: 8px;
           box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
           overflow: hidden;
       }

       .view-header {
           background: linear-gradient(45deg, var(--secondary-color), #45a049);
           color: white;
           padding: 30px;
           text-align: center;
       }

       .view-header h2 {
           margin: 0;
           font-size: 2rem;
       }

       .view-header p {
           margin: 10px 0 0 0;
           opacity: 0.9;
           font-size: 1.1rem;
       }

       .view-stats {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
           gap: 0;
           border-bottom: 1px solid #eee;
       }

       .stat-item {
           padding: 20px;
           text-align: center;
           border-right: 1px solid #eee;
       }

       .stat-item:last-child {
           border-right: none;
       }

       .stat-number {
           font-size: 2rem;
           font-weight: bold;
           color: var(--secondary-color);
           margin-bottom: 5px;
       }

       .stat-label {
           color: #666;
           font-size: 0.9rem;
       }

       .view-content {
           padding: 30px;
       }

       .info-grid {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
           gap: 25px;
           margin-bottom: 30px;
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
           text-transform: uppercase;
           letter-spacing: 0.5px;
       }

       .info-value {
           color: #666;
           font-size: 1rem;
       }

       .athletes-list {
           margin-top: 30px;
       }

       .athletes-list h4 {
           color: var(--text-color);
           margin-bottom: 15px;
           padding-bottom: 8px;
           border-bottom: 2px solid var(--secondary-color);
       }

       .athletes-table {
           width: 100%;
           border-collapse: collapse;
           margin-top: 15px;
       }

       .athletes-table th,
       .athletes-table td {
           padding: 10px 15px;
           text-align: left;
           border-bottom: 1px solid #eee;
       }

       .athletes-table th {
           background-color: #f8f9fa;
           font-weight: 600;
           color: var(--text-color);
       }

       .view-actions {
           display: flex;
           gap: 15px;
           justify-content: center;
           flex-wrap: wrap;
           padding: 20px;
           border-top: 1px solid #eee;
           background-color: #f8f9fa;
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

       .btn-warning {
           background-color: var(--warning-color);
           color: #212529;
       }

       .btn-warning:hover {
           background-color: #e0a800;
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

           .actions-container {
               flex-direction: column;
               gap: 10px;
           }

           .pagination-container {
               flex-direction: column;
               gap: 10px;
           }

           .view-stats {
               grid-template-columns: repeat(2, 1fr);
           }

           .info-grid {
               grid-template-columns: 1fr;
           }

           .actions {
               flex-direction: column;
               gap: 8px;
           }

           .view-actions {
               flex-direction: column;
           }

           .athletes-grid {
               grid-template-columns: 1fr;
           }
       }

       .mobile-toggle {
           display: none;
       }

       /* Estilos para seleção de atletas */
        .athletes-section {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }

        .athlete-search-container {
            margin-bottom: 30px;
        }

        .search-box {
            position: relative;
            max-width: 500px;
        }

        .search-box input {
            width: 100%;
            padding: 15px 20px;
            font-size: 16px;
            border: 2px solid #ddd;
            border-radius: 25px;
            outline: none;
            transition: all 0.3s;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .search-box input:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 4px 20px rgba(76, 175, 80, 0.2);
        }

        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }

        .search-result-item {
            padding: 12px 20px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .search-result-item:hover {
            background-color: #f8f9fa;
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .athlete-info {
            flex: 1;
        }

        .athlete-name {
            font-weight: bold;
            color: var(--text-color);
            margin-bottom: 2px;
        }

        .athlete-details {
            font-size: 0.85rem;
            color: #666;
        }

        .add-athlete-btn {
            background: var(--secondary-color);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .add-athlete-btn:hover {
            background: #45a049;
            transform: scale(1.05);
        }

        .selected-athletes-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 2px dashed #ddd;
            min-height: 200px;
        }

        .selected-athletes-container h4 {
            color: var(--text-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .selected-athletes {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }

        .empty-selection {
            grid-column: 1 / -1;
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }

        .empty-selection i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #ddd;
        }

        .selected-athlete-card {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            position: relative;
            transition: all 0.2s;
        }

        .selected-athlete-card:hover {
            border-color: var(--secondary-color);
            box-shadow: 0 2px 10px rgba(76, 175, 80, 0.1);
        }

        .athlete-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .athlete-card-name {
            font-weight: bold;
            color: var(--text-color);
            flex: 1;
        }

        .remove-athlete-btn {
            background: #dc3545;
            color: white;
            border: none;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .remove-athlete-btn:hover {
            background: #c82333;
            transform: scale(1.1);
        }

        .athlete-card-details {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 10px;
        }

        .jersey-input-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .jersey-input-group label {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-color);
        }

        .jersey-input {
            width: 60px;
            padding: 5px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
            font-weight: bold;
        }

        .jersey-input:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        .no-results {
            padding: 20px;
            text-align: center;
            color: #666;
            font-style: italic;
        }

        .loading-results {
            padding: 20px;
            text-align: center;
            color: #666;
        }

        /* Indicador de atleta já selecionado */
        .search-result-item.already-selected {
            background-color: #f0f0f0;
            opacity: 0.6;
        }

        .search-result-item.already-selected .add-athlete-btn {
            background: #6c757d;
            cursor: not-allowed;
        }

        /* Responsivo */
        @media (max-width: 768px) {
            .selected-athletes {
                grid-template-columns: 1fr;
            }
            
            .athlete-card-header {
                flex-direction: column;
                gap: 10px;
            }
            
            .jersey-input-group {
                justify-content: center;
            }
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
           <i class="fas fa-users-cog"></i>
           <?php 
           switch($acao) {
               case 'adicionar':
                   echo 'Criar Equipe';
                   break;
               case 'editar':
                   echo 'Editar Equipe';
                   break;
               case 'visualizar':
                   echo 'Visualizar Equipe';
                   break;
               default:
                   echo 'Gerenciar Equipes';
           }
           ?>
       </h1>

       <!-- Breadcrumb -->
       <div class="breadcrumb">
           <a href="dashboard.php">Dashboard</a>
           <i class="fas fa-chevron-right"></i>
           <a href="esporte.php">Esporte</a>
           <i class="fas fa-chevron-right"></i>
           <span>Equipes</span>
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
                   <label for="filtro_nome">Nome da Equipe:</label>
                   <input type="text" id="filtro_nome" name="filtro_nome" 
                          value="<?php echo htmlspecialchars($filtros['nome']); ?>" 
                          placeholder="Digite o nome...">
               </div>
               
               <div class="filter-group">
                   <label for="filtro_modalidade">Modalidade:</label>
                   <select id="filtro_modalidade" name="filtro_modalidade">
                       <option value="">Todas as modalidades</option>
                       <?php foreach ($modalidades as $modalidade): ?>
                       <option value="<?php echo $modalidade; ?>" 
                               <?php echo ($filtros['modalidade'] === $modalidade) ? 'selected' : ''; ?>>
                           <?php echo $modalidade; ?>
                       </option>
                       <?php endforeach; ?>
                   </select>
               </div>
               
               <div class="filter-group">
                   <label for="filtro_categoria">Categoria:</label>
                   <select id="filtro_categoria" name="filtro_categoria">
                       <option value="">Todas as categorias</option>
                       <?php foreach ($categorias as $categoria): ?>
                       <option value="<?php echo $categoria; ?>" 
                               <?php echo ($filtros['categoria'] === $categoria) ? 'selected' : ''; ?>>
                           <?php echo $categoria; ?>
                       </option>
                       <?php endforeach; ?>
                   </select>
               </div>
               
               <div class="filter-group">
                   <label for="filtro_status">Status:</label>
                   <select id="filtro_status" name="filtro_status">
                       <option value="">Todos os status</option>
                       <?php foreach ($status_equipe as $status_key => $status_text): ?>
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
               <i class="fas fa-plus"></i> Criar Equipe
           </a>
       </div>

       <!-- Lista de equipes -->
       <div class="table-container">
           <div class="table-header">
               <div class="table-title">
                   <i class="fas fa-list"></i>
                   Lista de Equipes
               </div>
               <div class="table-info">
                   <i class="fas fa-info-circle"></i>
                   <?php echo number_format($total_registros); ?> equipe(s) encontrada(s)
               </div>
           </div>
           
           <?php if (empty($equipes)): ?>
           <div class="text-center" style="padding: 40px;">
               <i class="fas fa-users-slash" style="font-size: 3rem; color: #ddd; margin-bottom: 15px;"></i>
               <h3>Nenhuma equipe encontrada</h3>
               <p class="text-muted">Crie a primeira equipe usando o botão acima.</p>
           </div>
           <?php else: ?>
           <table class="data-table">
               <thead>
                   <tr>
                       <th>Nome</th>
                       <th>Modalidade</th>
                       <th>Categoria</th>
                       <th>Responsável</th>
                       <th>Atletas</th>
                       <th>Status</th>
                       <th>Ações</th>
                   </tr>
               </thead>
               <tbody>
                   <?php foreach ($equipes as $equipe): ?>
                   <tr>
                       <td>
                           <strong><?php echo htmlspecialchars($equipe['equipe_nome']); ?></strong>
                       </td>
                       <td>
                           <small><?php echo htmlspecialchars($equipe['equipe_modalidade'] ?: 'Não informado'); ?></small>
                       </td>
                       <td>
                           <small><?php echo htmlspecialchars($equipe['equipe_categoria'] ?: 'Não informado'); ?></small>
                       </td>
                       <td>
                           <small><?php echo htmlspecialchars($equipe['equipe_responsavel'] ?: 'Não informado'); ?></small>
                       </td>
                       <td>
                           <small><strong><?php echo $equipe['total_atletas']; ?></strong> atleta(s)</small>
                       </td>
                       <td>
                           <span class="status-badge <?php echo getStatusClass($equipe['equipe_status']); ?>">
                               <?php echo getStatusTexto($equipe['equipe_status']); ?>
                           </span>
                       </td>
                       <td>
                           <div class="actions">
                               <a href="?acao=visualizar&id=<?php echo $equipe['equipe_id']; ?>" 
                                  class="btn-action btn-view" title="Visualizar">
                                   <i class="fas fa-eye"></i>
                               </a>
                               <a href="?acao=editar&id=<?php echo $equipe['equipe_id']; ?>" 
                                  class="btn-action btn-edit" title="Editar">
                                   <i class="fas fa-edit"></i>
                               </a>
                           </div>
                       </td>
                   </tr>
                   <?php endforeach; ?>
               </tbody>
           </table>
           <?php endif; ?>
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

       <?php elseif ($acao === 'visualizar' && $equipe_visualizar): ?>
       <!-- Visualização da equipe -->
       <div class="view-container">
           <!-- Header -->
           <div class="view-header">
               <h2><?php echo htmlspecialchars($equipe_visualizar['equipe_nome']); ?></h2>
               <p><?php echo htmlspecialchars($equipe_visualizar['equipe_modalidade']); ?> - <?php echo htmlspecialchars($equipe_visualizar['equipe_categoria']); ?></p>
           </div>

           <!-- Estatísticas -->
           <div class="view-stats">
               <div class="stat-item">
                   <div class="stat-number"><?php echo $estatisticas_equipe['total_atletas']; ?></div>
                   <div class="stat-label">Atletas</div>
               </div>
               <div class="stat-item">
                   <div class="stat-number"><?php echo $estatisticas_equipe['total_campeonatos']; ?></div>
                   <div class="stat-label">Campeonatos</div>
               </div>
               <div class="stat-item">
                   <div class="stat-number">
                       <span class="status-badge <?php echo getStatusClass($equipe_visualizar['equipe_status']); ?>">
                           <?php echo getStatusTexto($equipe_visualizar['equipe_status']); ?>
                       </span>
                   </div>
                   <div class="stat-label">Status</div>
               </div>
           </div>

           <!-- Conteúdo -->
           <div class="view-content">
               <div class="info-grid">
                   <div class="info-item">
                       <div class="info-label">Responsável</div>
                       <div class="info-value"><?php echo htmlspecialchars($equipe_visualizar['equipe_responsavel'] ?: 'Não informado'); ?></div>
                   </div>
                   
                   <div class="info-item">
                       <div class="info-label">Telefone</div>
                       <div class="info-value"><?php echo htmlspecialchars($equipe_visualizar['equipe_telefone'] ?: 'Não informado'); ?></div>
                   </div>
                   
                   <div class="info-item">
                       <div class="info-label">E-mail</div>
                       <div class="info-value"><?php echo htmlspecialchars($equipe_visualizar['equipe_email'] ?: 'Não informado'); ?></div>
                   </div>
                   
                   <div class="info-item">
                       <div class="info-label">Data de Criação</div>
                       <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($equipe_visualizar['equipe_data_criacao'])); ?></div>
                   </div>
               </div>

               <?php if (!empty($equipe_visualizar['equipe_observacoes'])): ?>
               <div class="info-item" style="margin-bottom: 20px;">
                   <div class="info-label">Observações</div>
                   <div class="info-value"><?php echo nl2br(htmlspecialchars($equipe_visualizar['equipe_observacoes'])); ?></div>
               </div>
               <?php endif; ?>

               <!-- Lista de atletas -->
               <?php if (!empty($atletas_equipe)): ?>
               <div class="athletes-list">
                   <h4><i class="fas fa-users"></i> Atletas da Equipe</h4>
                   <table class="athletes-table">
                       <thead>
                           <tr>
                               <th>Nº</th>
                               <th>Nome</th>
                               <th>Modalidade</th>
                               <th>Categoria</th>
                               <th>Posição</th>
                           </tr>
                       </thead>
                       <tbody>
                           <?php foreach ($atletas_equipe as $atleta): ?>
                           <tr>
                               <td>
                                   <?php if ($atleta['numero_camisa']): ?>
                                   <strong style="color: var(--secondary-color);">#<?php echo $atleta['numero_camisa']; ?></strong>
                                   <?php else: ?>
                                   <small class="text-muted">-</small>
                                   <?php endif; ?>
                               </td>
                               <td>
                                   <strong><?php echo htmlspecialchars($atleta['atleta_nome']); ?></strong>
                                   <?php if ($atleta['eh_capitao']): ?>
                                   <span style="color: gold;" title="Capitão"><i class="fas fa-star"></i></span>
                                   <?php endif; ?>
                               </td>
                               <td><small><?php echo htmlspecialchars($atleta['atleta_modalidade_principal'] ?: 'Não informado'); ?></small></td>
                               <td><small><?php echo htmlspecialchars($atleta['atleta_categoria'] ?: 'Não informado'); ?></small></td>
                               <td><small><?php echo htmlspecialchars($atleta['posicao'] ?: 'Não informado'); ?></small></td>
                           </tr>
                           <?php endforeach; ?>
                       </tbody>
                   </table>
               </div>
               <?php else: ?>
               <div class="athletes-list">
                   <h4><i class="fas fa-users"></i> Atletas da Equipe</h4>
                   <div class="text-center" style="padding: 20px; background-color: #f8f9fa; border-radius: 8px;">
                       <i class="fas fa-user-slash" style="font-size: 2rem; color: #ddd; margin-bottom: 10px;"></i>
                       <p class="text-muted">Nenhum atleta cadastrado nesta equipe.</p>
                   </div>
               </div>
               <?php endif; ?>
           </div>

           <!-- Ações -->
           <div class="view-actions">
               <a href="?acao=editar&id=<?php echo $equipe_visualizar['equipe_id']; ?>" 
                  class="btn btn-warning">
                   <i class="fas fa-edit"></i> Editar Equipe
               </a>
               
               <a href="?acao=listar" class="btn btn-secondary">
                   <i class="fas fa-arrow-left"></i> Voltar à Lista
               </a>
           </div>
       </div>

       <?php elseif ($acao === 'adicionar' || $acao === 'editar'): ?>
       <!-- Formulário de cadastro/edição -->
       <form method="POST" class="form-container">
           <input type="hidden" name="acao" value="salvar">
           <input type="hidden" name="equipe_id" value="<?php echo $equipe_id; ?>">
           
           <div class="form-section">
               <h3>Informações da Equipe</h3>
               
               <div class="form-row">
                   <div class="form-group">
                       <label for="equipe_nome">Nome da Equipe *</label>
                       <input type="text" id="equipe_nome" name="equipe_nome" 
                              value="<?php echo htmlspecialchars($equipe_atual['equipe_nome'] ?? ''); ?>" 
                              required maxlength="100" placeholder="Ex: Tigres FC">
                   </div>
                   
                   <div class="form-group">
                       <label for="equipe_status">Status</label>
                       <select id="equipe_status" name="equipe_status">
                           <?php foreach ($status_equipe as $status_key => $status_text): ?>
                           <option value="<?php echo $status_key; ?>" 
                                   <?php echo (($equipe_atual['equipe_status'] ?? 'ATIVA') === $status_key) ? 'selected' : ''; ?>>
                               <?php echo $status_text; ?>
                           </option>
                           <?php endforeach; ?>
                       </select>
                   </div>
               </div>
               
               <div class="form-row">
                   <div class="form-group">
                       <label for="equipe_modalidade">Modalidade *</label>
                       <select id="equipe_modalidade" name="equipe_modalidade" required>
                           <option value="">Selecione...</option>
                           <?php foreach ($modalidades as $modalidade): ?>
                           <option value="<?php echo $modalidade; ?>" 
                                   <?php echo (($equipe_atual['equipe_modalidade'] ?? '') === $modalidade) ? 'selected' : ''; ?>>
                               <?php echo $modalidade; ?>
                           </option>
                           <?php endforeach; ?>
                       </select>
                   </div>
                   
                   <div class="form-group">
                       <label for="equipe_categoria">Categoria *</label>
                       <select id="equipe_categoria" name="equipe_categoria" required>
                           <option value="">Selecione...</option>
                           <?php foreach ($categorias as $categoria): ?>
                           <option value="<?php echo $categoria; ?>" 
                                   <?php echo (($equipe_atual['equipe_categoria'] ?? '') === $categoria) ? 'selected' : ''; ?>>
                               <?php echo $categoria; ?>
                           </option>
                           <?php endforeach; ?>
                       </select>
                   </div>
               </div>
           </div>
           
           <div class="form-section">
               <h3>Responsável pela Equipe</h3>
               
               <div class="form-row">
                   <div class="form-group">
                       <label for="equipe_responsavel">Nome do Responsável</label>
                       <input type="text" id="equipe_responsavel" name="equipe_responsavel" 
                              value="<?php echo htmlspecialchars($equipe_atual['equipe_responsavel'] ?? ''); ?>" 
                              maxlength="100" placeholder="Nome do técnico ou responsável">
                   </div>
                   
                   <div class="form-group">
                       <label for="equipe_telefone">Telefone</label>
                       <input type="tel" id="equipe_telefone" name="equipe_telefone" 
                              value="<?php echo htmlspecialchars($equipe_atual['equipe_telefone'] ?? ''); ?>" 
                              maxlength="20" placeholder="(00) 00000-0000">
                   </div>
               </div>
               
               <div class="form-group">
                   <label for="equipe_email">E-mail</label>
                   <input type="email" id="equipe_email" name="equipe_email" 
                          value="<?php echo htmlspecialchars($equipe_atual['equipe_email'] ?? ''); ?>" 
                          maxlength="100" placeholder="email@exemplo.com">
               </div>
           </div>
           
           <div class="form-section">
               <h3>Observações</h3>
               
               <div class="form-group">
                   <label for="equipe_observacoes">Observações Adicionais</label>
                   <textarea id="equipe_observacoes" name="equipe_observacoes" 
                             rows="4" maxlength="1000" 
                             placeholder="Informações adicionais sobre a equipe..."><?php echo htmlspecialchars($equipe_atual['equipe_observacoes'] ?? ''); ?></textarea>
               </div>
           </div>
           
           <!-- Seleção de atletas -->
           <?php if (!empty($atletas_disponiveis)): ?>
            <div class="form-section">
                <h3>Atletas da Equipe</h3>
                <p style="color: #666; margin-bottom: 15px;">
                    Selecione os atletas que farão parte desta equipe. Você pode buscar por nome e definir o número da camisa para cada atleta.
                </p>
                
                <div class="athletes-section">
                    <!-- Busca de atletas -->
                    <div class="athlete-search-container">
                        <div class="search-box">
                            <input type="text" id="athlete-search" placeholder="🔍 Digite o nome do atleta para buscar..." autocomplete="off">
                            <div id="search-results" class="search-results"></div>
                        </div>
                    </div>
                    
                    <!-- Atletas selecionados -->
                    <div class="selected-athletes-container">
                        <h4><i class="fas fa-users"></i> Atletas Selecionados (<span id="selected-count">0</span>)</h4>
                        <div id="selected-athletes" class="selected-athletes">
                            <div class="empty-selection">
                                <i class="fas fa-user-plus"></i>
                                <p>Nenhum atleta selecionado ainda</p>
                                <small>Use a busca acima para encontrar e adicionar atletas</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="form-section">
                <h3>Atletas da Equipe</h3>
                <div class="text-center" style="padding: 20px; background-color: #f8f9fa; border-radius: 8px;">
                    <i class="fas fa-user-slash" style="font-size: 2rem; color: #ddd; margin-bottom: 10px;"></i>
                    <p class="text-muted">Nenhum atleta ativo encontrado. <a href="esporte_atletas.php?acao=adicionar">Cadastre atletas</a> antes de criar equipes.</p>
                </div>
            </div>
            <?php endif; ?>
           
           <div class="form-actions">
               <button type="submit" class="btn btn-success">
                   <i class="fas fa-save"></i> 
                   <?php echo ($acao === 'editar') ? 'Atualizar' : 'Cadastrar'; ?> Equipe
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
           
           // Gerenciamento de seleção de atletas
           const athleteCheckboxes = document.querySelectorAll('input[name="atletas[]"]');
           const jerseyInputs = document.querySelectorAll('.jersey-number');
           
           // Habilitar/desabilitar campo de número quando checkbox é marcado/desmarcado
           athleteCheckboxes.forEach(checkbox => {
               const athleteId = checkbox.value;
               const jerseyInput = document.querySelector(`input[name="numero_${athleteId}"]`);
               
               if (jerseyInput) {
                   // Estado inicial
                   jerseyInput.disabled = !checkbox.checked;
                   
                   // Evento de mudança
                   checkbox.addEventListener('change', function() {
                       jerseyInput.disabled = !this.checked;
                       if (!this.checked) {
                           jerseyInput.value = '';
                       }
                   });
               }
           });
           
           // Validar números únicos de camisa
           jerseyInputs.forEach(input => {
               input.addEventListener('blur', function() {
                   const currentValue = this.value;
                   if (currentValue) {
                       let duplicate = false;
                       jerseyInputs.forEach(otherInput => {
                           if (otherInput !== this && otherInput.value === currentValue && !otherInput.disabled) {
                               duplicate = true;
                           }
                       });
                       
                       if (duplicate) {
                           alert('Este número de camisa já está sendo usado por outro atleta!');
                           this.focus();
                       }
                   }
               });
           });
           
           // Formatação do telefone
           const telefoneInput = document.getElementById('equipe_telefone');
           if (telefoneInput) {
               telefoneInput.addEventListener('input', function() {
                   let value = this.value.replace(/\D/g, '');
                   if (value.length >= 11) {
                       value = value.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
                   } else if (value.length >= 6) {
                       value = value.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3');
                   } else if (value.length >= 2) {
                       value = value.replace(/(\d{2})(\d{0,5})/, '($1) $2');
                   }
                   this.value = value;
               });
           }
       });
       // Sistema de busca e seleção de atletas
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('athlete-search');
            const searchResults = document.getElementById('search-results');
            const selectedAthletes = document.getElementById('selected-athletes');
            const selectedCount = document.getElementById('selected-count');
            
            let selectedAthletesData = [];
            let allAthletes = <?php echo json_encode($atletas_disponiveis); ?>;
            let searchTimeout;
            
            // Carregar atletas já selecionados (para edição)
            <?php if (!empty($atletas_equipe)): ?>
            const preSelectedAthletes = <?php echo json_encode($atletas_equipe); ?>;
            preSelectedAthletes.forEach(athlete => {
                const athleteData = {
                    atleta_id: athlete.atleta_id,
                    atleta_nome: athlete.atleta_nome,
                    atleta_modalidade_principal: athlete.atleta_modalidade_principal || 'Não informado',
                    atleta_categoria: athlete.atleta_categoria || 'Não informado'
                };
                selectedAthletesData.push(athleteData);
                addAthleteToSelected(athleteData, athlete.numero_camisa || '');
            });
            <?php endif; ?>
            
            // Busca em tempo real
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const query = this.value.trim();
                    
                    clearTimeout(searchTimeout);
                    
                    if (query.length < 2) {
                        searchResults.style.display = 'none';
                        return;
                    }
                    
                    searchTimeout = setTimeout(() => {
                        performSearch(query);
                    }, 300);
                });
                
                // Fechar resultados ao clicar fora
                document.addEventListener('click', function(e) {
                    if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                        searchResults.style.display = 'none';
                    }
                });
            }
            
            function performSearch(query) {
                const filteredAthletes = allAthletes.filter(athlete => 
                    athlete.atleta_nome.toLowerCase().includes(query.toLowerCase()) ||
                    (athlete.atleta_modalidade_principal && athlete.atleta_modalidade_principal.toLowerCase().includes(query.toLowerCase()))
                );
                
                displaySearchResults(filteredAthletes);
            }
            
            function displaySearchResults(athletes) {
                if (athletes.length === 0) {
                    searchResults.innerHTML = '<div class="no-results">Nenhum atleta encontrado</div>';
                } else {
                    let html = '';
                    athletes.forEach(athlete => {
                        const isSelected = selectedAthletesData.some(selected => selected.atleta_id === athlete.atleta_id);
                        const selectedClass = isSelected ? 'already-selected' : '';
                        const buttonText = isSelected ? 'Já selecionado' : 'Adicionar';
                        const buttonDisabled = isSelected ? 'disabled' : '';
                        
                        html += `
                            <div class="search-result-item ${selectedClass}" data-athlete-id="${athlete.atleta_id}">
                                <div class="athlete-info">
                                    <div class="athlete-name">${athlete.atleta_nome}</div>
                                    <div class="athlete-details">
                                        ${athlete.atleta_modalidade_principal || 'Modalidade não informada'} - 
                                        ${athlete.atleta_categoria || 'Categoria não informada'}
                                    </div>
                                </div>
                                <button class="add-athlete-btn" onclick="addAthlete(${athlete.atleta_id})" ${buttonDisabled}>
                                    ${buttonText}
                                </button>
                            </div>
                        `;
                    });
                    searchResults.innerHTML = html;
                }
                
                searchResults.style.display = 'block';
            }
            
            // Função global para adicionar atleta
            window.addAthlete = function(athleteId) {
                const athlete = allAthletes.find(a => a.atleta_id == athleteId);
                if (athlete && !selectedAthletesData.some(selected => selected.atleta_id === athlete.atleta_id)) {
                    selectedAthletesData.push(athlete);
                    addAthleteToSelected(athlete);
                    updateSelectedCount();
                    
                    // Atualizar resultados de busca
                    const query = searchInput.value.trim();
                    if (query.length >= 2) {
                        performSearch(query);
                    }
                }
            };
            
            function addAthleteToSelected(athlete, jerseyNumber = '') {
                const emptySelection = selectedAthletes.querySelector('.empty-selection');
                if (emptySelection) {
                    emptySelection.remove();
                }
                
                const athleteCard = document.createElement('div');
                athleteCard.className = 'selected-athlete-card';
                athleteCard.dataset.athleteId = athlete.atleta_id;
                
                athleteCard.innerHTML = `
                    <div class="athlete-card-header">
                        <div class="athlete-card-name">${athlete.atleta_nome}</div>
                        <button type="button" class="remove-athlete-btn" onclick="removeAthlete(${athlete.atleta_id})">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="athlete-card-details">
                        ${athlete.atleta_modalidade_principal || 'Modalidade não informada'} - 
                        ${athlete.atleta_categoria || 'Categoria não informada'}
                    </div>
                    <div class="jersey-input-group">
                        <label>Nº da camisa:</label>
                        <input type="number" name="numero_${athlete.atleta_id}" class="jersey-input" 
                            min="1" max="99" value="${jerseyNumber}" placeholder="Nº">
                        <input type="hidden" name="atletas[]" value="${athlete.atleta_id}">
                    </div>
                `;
                
                selectedAthletes.appendChild(athleteCard);
                updateSelectedCount();
            }
            
            // Função global para remover atleta
            window.removeAthlete = function(athleteId) {
                selectedAthletesData = selectedAthletesData.filter(athlete => athlete.atleta_id != athleteId);
                
                const athleteCard = selectedAthletes.querySelector(`[data-athlete-id="${athleteId}"]`);
                if (athleteCard) {
                    athleteCard.remove();
                }
                
                updateSelectedCount();
                
                // Se não há atletas selecionados, mostrar mensagem vazia
                if (selectedAthletesData.length === 0) {
                    selectedAthletes.innerHTML = `
                        <div class="empty-selection">
                            <i class="fas fa-user-plus"></i>
                            <p>Nenhum atleta selecionado ainda</p>
                            <small>Use a busca acima para encontrar e adicionar atletas</small>
                        </div>
                    `;
                }
                
                // Atualizar resultados de busca se houver
                const query = searchInput.value.trim();
                if (query.length >= 2) {
                    performSearch(query);
                }
            };
            
            function updateSelectedCount() {
                selectedCount.textContent = selectedAthletesData.length;
            }
            
            // Validação antes do envio do formulário
            const form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    // Validar números de camisa únicos
                    const jerseyInputs = selectedAthletes.querySelectorAll('.jersey-input');
                    const jerseyNumbers = [];
                    let hasError = false;
                    
                    jerseyInputs.forEach(input => {
                        const number = parseInt(input.value);
                        if (number && jerseyNumbers.includes(number)) {
                            alert('Números de camisa devem ser únicos! Número ' + number + ' está duplicado.');
                            input.focus();
                            hasError = true;
                            return false;
                        }
                        if (number) {
                            jerseyNumbers.push(number);
                        }
                    });
                    
                    if (hasError) {
                        e.preventDefault();
                    }
                });
            }
        });
   </script>
</body>
</html>
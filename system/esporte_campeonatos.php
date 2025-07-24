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
$campeonato_id = intval($_GET['id'] ?? 0);

// Mensagens e tratamento de erros
$mensagem = '';
$tipo_mensagem = '';

// Processar formulários
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar') {
    // Obter ID do campeonato do formulário
    $campeonato_id = intval($_POST['campeonato_id'] ?? 0);
    
    // Coletar e validar dados do formulário
    $dados = [
        'nome' => sanitizeInput($_POST['campeonato_nome'] ?? ''),
        'descricao' => sanitizeInput($_POST['campeonato_descricao'] ?? ''),
        'modalidade' => sanitizeInput($_POST['campeonato_modalidade'] ?? ''),
        'categoria' => sanitizeInput($_POST['campeonato_categoria'] ?? ''),
        'tipo' => sanitizeInput($_POST['campeonato_tipo'] ?? ''),
        'data_inicio' => sanitizeInput($_POST['campeonato_data_inicio'] ?? ''),
        'data_fim' => sanitizeInput($_POST['campeonato_data_fim'] ?? ''),
        'data_inscricoes_inicio' => sanitizeInput($_POST['campeonato_data_inscricoes_inicio'] ?? ''),
        'data_inscricoes_fim' => sanitizeInput($_POST['campeonato_data_inscricoes_fim'] ?? ''),
        'local' => sanitizeInput($_POST['campeonato_local'] ?? ''),
        'max_participantes' => intval($_POST['campeonato_max_participantes'] ?? 0),
        'taxa_inscricao' => floatval($_POST['campeonato_taxa_inscricao'] ?? 0.00),
        'premiacao' => sanitizeInput($_POST['campeonato_premiacao'] ?? ''),
        'observacoes' => sanitizeInput($_POST['campeonato_observacoes'] ?? ''),
        'status' => sanitizeInput($_POST['campeonato_status'] ?? 'PLANEJAMENTO')
    ];
    
    // Validação básica
    $erros = [];
    
    if (empty($dados['nome'])) {
        $erros[] = "Nome do campeonato é obrigatório.";
    }
    
    if (empty($dados['modalidade'])) {
        $erros[] = "Modalidade é obrigatória.";
    }
    
    if (empty($dados['categoria'])) {
        $erros[] = "Categoria é obrigatória.";
    }
    
    if (empty($dados['data_inicio'])) {
        $erros[] = "Data de início é obrigatória.";
    }
    
    if (empty($dados['data_fim'])) {
        $erros[] = "Data de fim é obrigatória.";
    }
    
    // Validar se data de fim é posterior à data de início
    if (!empty($dados['data_inicio']) && !empty($dados['data_fim'])) {
        if (strtotime($dados['data_fim']) < strtotime($dados['data_inicio'])) {
            $erros[] = "Data de fim deve ser posterior à data de início.";
        }
    }
    
    // Validar se data de fim das inscrições é anterior à data de início do campeonato
    if (!empty($dados['data_inscricoes_fim']) && !empty($dados['data_inicio'])) {
        if (strtotime($dados['data_inscricoes_fim']) > strtotime($dados['data_inicio'])) {
            $erros[] = "Data limite de inscrições deve ser anterior ao início do campeonato.";
        }
    }
    
    if (empty($erros)) {
        try {
            $conn->beginTransaction();
            
            if ($campeonato_id > 0) {
                // Atualizar
                $sql = "UPDATE tb_campeonatos SET 
                        campeonato_nome = :nome,
                        campeonato_descricao = :descricao,
                        campeonato_modalidade = :modalidade,
                        campeonato_categoria = :categoria,
                        campeonato_tipo = :tipo,
                        campeonato_data_inicio = :data_inicio,
                        campeonato_data_fim = :data_fim,
                        campeonato_data_inscricoes_inicio = :data_inscricoes_inicio,
                        campeonato_data_inscricoes_fim = :data_inscricoes_fim,
                        campeonato_local = :local,
                        campeonato_max_participantes = :max_participantes,
                        campeonato_taxa_inscricao = :taxa_inscricao,
                        campeonato_premiacao = :premiacao,
                        campeonato_observacoes = :observacoes,
                        campeonato_status = :status,
                        campeonato_data_modificacao = NOW()
                        WHERE campeonato_id = :campeonato_id";
                
                $stmt = $conn->prepare($sql);
                
                foreach ($dados as $key => $value) {
                    $stmt->bindValue(':' . $key, $value);
                }
                $stmt->bindValue(':campeonato_id', $campeonato_id, PDO::PARAM_INT);
                
                $acao_historico = "Campeonato atualizado";
            } else {
                // Inserir
                $sql = "INSERT INTO tb_campeonatos (
                        campeonato_nome, campeonato_descricao, campeonato_modalidade,
                        campeonato_categoria, campeonato_tipo, campeonato_data_inicio,
                        campeonato_data_fim, campeonato_data_inscricoes_inicio,
                        campeonato_data_inscricoes_fim, campeonato_local,
                        campeonato_max_participantes, campeonato_taxa_inscricao,
                        campeonato_premiacao, campeonato_observacoes, campeonato_status,
                        campeonato_cadastrado_por, campeonato_data_cadastro
                    ) VALUES (
                        :nome, :descricao, :modalidade,
                        :categoria, :tipo, :data_inicio,
                        :data_fim, :data_inscricoes_inicio,
                        :data_inscricoes_fim, :local,
                        :max_participantes, :taxa_inscricao,
                        :premiacao, :observacoes, :status,
                        :cadastrado_por, NOW()
                    )";
                
                $stmt = $conn->prepare($sql);
                
                foreach ($dados as $key => $value) {
                    $stmt->bindValue(':' . $key, $value);
                }
                $stmt->bindValue(':cadastrado_por', $usuario_id, PDO::PARAM_INT);
                
                $acao_historico = "Campeonato cadastrado";
            }
            
            $stmt->execute();
            
            if ($campeonato_id == 0) {
                $campeonato_id = $conn->lastInsertId();
            }
            
            // Registrar no histórico
            $stmt_hist = $conn->prepare("INSERT INTO tb_esporte_historico (historico_tipo, historico_referencia_id, historico_acao, historico_detalhes, historico_usuario_id) VALUES (?, ?, ?, ?, ?)");
            $stmt_hist->execute(['CAMPEONATO', $campeonato_id, $acao_historico, "Campeonato: " . $dados['nome'], $usuario_id]);
            
            $conn->commit();
            
            $mensagem = $acao_historico . " com sucesso!";
            $tipo_mensagem = "success";
            $acao = 'listar'; // Redirecionar para listagem
            
            // Limpar dados do formulário
            $campeonato_atual = null;
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $mensagem = "Erro ao salvar campeonato: " . $e->getMessage();
            $tipo_mensagem = "error";
            error_log("Erro ao salvar campeonato: " . $e->getMessage());
        }
    } else {
        $mensagem = implode("<br>", $erros);
        $tipo_mensagem = "error";
    }
}

// Buscar dados para edição
$campeonato_atual = null;
if ($acao === 'editar' && $campeonato_id > 0) {
    try {
        $stmt = $conn->prepare("SELECT * FROM tb_campeonatos WHERE campeonato_id = ?");
        $stmt->execute([$campeonato_id]);
        
        if ($stmt->rowCount() > 0) {
            $campeonato_atual = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $mensagem = "Campeonato não encontrado.";
            $tipo_mensagem = "error";
            $acao = 'listar';
        }
    } catch (PDOException $e) {
        $mensagem = "Erro ao buscar campeonato: " . $e->getMessage();
        $tipo_mensagem = "error";
        $acao = 'listar';
        error_log("Erro ao buscar campeonato: " . $e->getMessage());
    }
}

// Buscar dados para visualização
$campeonato_visualizar = null;
$estatisticas_campeonato = [];
if ($acao === 'visualizar' && $campeonato_id > 0) {
    try {
        // Buscar dados do campeonato
        $stmt = $conn->prepare("SELECT * FROM tb_campeonatos WHERE campeonato_id = ?");
        $stmt->execute([$campeonato_id]);
        
        if ($stmt->rowCount() > 0) {
            $campeonato_visualizar = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Buscar estatísticas do campeonato
            $stmt = $conn->prepare("SELECT COUNT(*) as total_equipes FROM tb_campeonato_equipes WHERE campeonato_id = ?");
            $stmt->execute([$campeonato_id]);
            $estatisticas_campeonato['total_equipes'] = $stmt->fetchColumn();
            
            // Total de atletas
            $stmt = $conn->prepare("
                SELECT COUNT(DISTINCT ea.atleta_id) as total_atletas 
                FROM tb_campeonato_equipe_atletas ea
                JOIN tb_campeonato_equipes e ON ea.equipe_id = e.equipe_id
                WHERE e.campeonato_id = ?
            ");
            $stmt->execute([$campeonato_id]);
            $estatisticas_campeonato['total_atletas'] = $stmt->fetchColumn();
            
            // Total de partidas
            $stmt = $conn->prepare("SELECT COUNT(*) as total_partidas FROM tb_campeonato_partidas WHERE campeonato_id = ?");
            $stmt->execute([$campeonato_id]);
            $estatisticas_campeonato['total_partidas'] = $stmt->fetchColumn();
            
            // Partidas finalizadas
            $stmt = $conn->prepare("SELECT COUNT(*) as partidas_finalizadas FROM tb_campeonato_partidas WHERE campeonato_id = ? AND status_partida = 'FINALIZADA'");
            $stmt->execute([$campeonato_id]);
            $estatisticas_campeonato['partidas_finalizadas'] = $stmt->fetchColumn();
            
        } else {
            $mensagem = "Campeonato não encontrado.";
            $tipo_mensagem = "error";
            $acao = 'listar';
        }
    } catch (PDOException $e) {
        $mensagem = "Erro ao buscar campeonato: " . $e->getMessage();
        $tipo_mensagem = "error";
        $acao = 'listar';
        error_log("Erro ao buscar campeonato: " . $e->getMessage());
    }
}

// Buscar lista de campeonatos para exibição
$campeonatos = [];
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
        'status' => sanitizeInput($_GET['filtro_status'] ?? ''),
        'tipo' => sanitizeInput($_GET['filtro_tipo'] ?? '')
    ];
    
    // Construir query com filtros
    $where_conditions = [];
    $params = [];
    
    foreach ($filtros as $key => $value) {
        if (!empty($value)) {
            switch ($key) {
                case 'nome':
                    $where_conditions[] = "campeonato_nome LIKE ?";
                    $params[] = "%{$value}%";
                    break;
                case 'modalidade':
                    $where_conditions[] = "campeonato_modalidade LIKE ?";
                    $params[] = "%{$value}%";
                    break;
                case 'categoria':
                    $where_conditions[] = "campeonato_categoria = ?";
                    $params[] = $value;
                    break;
                case 'status':
                    $where_conditions[] = "campeonato_status = ?";
                    $params[] = $value;
                    break;
                case 'tipo':
                    $where_conditions[] = "campeonato_tipo = ?";
                    $params[] = $value;
                    break;
            }
        }
    }
    
    $where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    try {
        // Contar total de registros
        $count_sql = "SELECT COUNT(*) as total FROM tb_campeonatos {$where_sql}";
        $stmt = $conn->prepare($count_sql);
        $stmt->execute($params);
        $total_registros = $stmt->fetch()['total'];
        
        // Buscar registros com paginação
        $sql = "SELECT c.*, 
                (SELECT COUNT(*) FROM tb_campeonato_equipes e WHERE e.campeonato_id = c.campeonato_id) as total_equipes
                FROM tb_campeonatos c {$where_sql} 
                ORDER BY c.campeonato_data_inicio DESC 
                LIMIT {$registros_por_pagina} OFFSET {$offset}";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        $campeonatos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $mensagem = "Erro ao buscar campeonatos: " . $e->getMessage();
        $tipo_mensagem = "error";
        error_log("Erro ao buscar campeonatos: " . $e->getMessage());
    }
    
    $total_paginas = ceil($total_registros / $registros_por_pagina);
}

// Funções específicas da página
function formatarData($data) {
    if (empty($data)) return '';
    $data_obj = new DateTime($data);
    return $data_obj->format('d/m/Y');
}

function formatarDataHora($data) {
    if (empty($data)) return '';
    $data_obj = new DateTime($data);
    return $data_obj->format('d/m/Y H:i');
}

function getStatusClass($status) {
    $classes = [
        'PLANEJAMENTO' => 'status-planejamento',
        'INSCRICOES_ABERTAS' => 'status-inscricoes',
        'INSCRICOES_FECHADAS' => 'status-fechadas',
        'EM_ANDAMENTO' => 'status-andamento',
        'FINALIZADO' => 'status-finalizado',
        'CANCELADO' => 'status-cancelado'
    ];
    return $classes[$status] ?? '';
}

function getStatusTexto($status) {
    $textos = [
        'PLANEJAMENTO' => 'Planejamento',
        'INSCRICOES_ABERTAS' => 'Inscrições Abertas',
        'INSCRICOES_FECHADAS' => 'Inscrições Fechadas',
        'EM_ANDAMENTO' => 'Em Andamento',
        'FINALIZADO' => 'Finalizado',
        'CANCELADO' => 'Cancelado'
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

$tipos_campeonato = ['INTERNO', 'MUNICIPAL', 'REGIONAL', 'ESTADUAL', 'NACIONAL'];

$status_campeonato = [
    'PLANEJAMENTO' => 'Planejamento',
    'INSCRICOES_ABERTAS' => 'Inscrições Abertas',
    'INSCRICOES_FECHADAS' => 'Inscrições Fechadas',
    'EM_ANDAMENTO' => 'Em Andamento',
    'FINALIZADO' => 'Finalizado',
    'CANCELADO' => 'Cancelado'
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
    <title>Campeonatos - Sistema da Prefeitura</title>
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

       .status-planejamento {
           background-color: #e9ecef;
           color: #495057;
       }

       .status-inscricoes {
           background-color: #d4edda;
           color: #155724;
       }

       .status-fechadas {
           background-color: #fff3cd;
           color: #856404;
       }

       .status-andamento {
           background-color: #cce5ff;
           color: #004085;
       }

       .status-finalizado {
           background-color: #d1ecf1;
           color: #0c5460;
       }

       .status-cancelado {
           background-color: #f8d7da;
           color: #721c24;
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

       .btn-manage {
           background-color: var(--secondary-color);
           color: white;
           font-weight: bold;
       }

       .btn-manage:hover {
           background-color: #45a049;
       }

       /* Dropdown menu */
       .dropdown {
           position: relative;
           display: inline-block;
       }

       .dropdown-menu {
           display: none;
           position: absolute;
           right: 0;
           background-color: white;
           min-width: 200px;
           box-shadow: 0 8px 16px rgba(0,0,0,0.2);
           border-radius: 4px;
           z-index: 1000;
           border: 1px solid #ddd;
       }

       .dropdown-menu.show {
           display: block;
       }

       .dropdown-item {
           color: #333;
           padding: 12px 16px;
           text-decoration: none;
           display: flex;
           align-items: center;
           transition: background-color 0.3s;
       }

       .dropdown-item:hover {
           background-color: #f8f9fa;
           text-decoration: none;
           color: var(--secondary-color);
       }

       .dropdown-item i {
           margin-right: 8px;
           width: 16px;
       }

       .dropdown-toggle::after {
           display: none;
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

       .btn-info {
           background-color: var(--info-color);
           color: white;
       }

       .btn-info:hover {
           background-color: #138496;
       }

       .btn-warning {
           background-color: var(--warning-color);
           color: #212529;
       }

       .btn-warning:hover {
           background-color: #e0a800;
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
           <i class="fas fa-trophy"></i>
           <?php 
           switch($acao) {
               case 'adicionar':
                   echo 'Criar Campeonato';
                   break;
               case 'editar':
                   echo 'Editar Campeonato';
                   break;
               case 'visualizar':
                   echo 'Visualizar Campeonato';
                   break;
               default:
                   echo 'Gerenciar Campeonatos';
           }
           ?>
       </h1>

       <!-- Breadcrumb -->
       <div class="breadcrumb">
           <a href="dashboard.php">Dashboard</a>
           <i class="fas fa-chevron-right"></i>
           <a href="esporte.php">Esporte</a>
           <i class="fas fa-chevron-right"></i>
           <span>Campeonatos</span>
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
                   <label for="filtro_nome">Nome do Campeonato:</label>
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
                       <?php foreach ($status_campeonato as $status_key => $status_text): ?>
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
               <i class="fas fa-plus"></i> Criar Campeonato
           </a>
       </div>

       <!-- Lista de campeonatos -->
       <div class="table-container">
           <table class="data-table">
               <thead>
                   <tr>
                       <th>Nome</th>
                       <th>Modalidade</th>
                       <th>Categoria</th>
                       <th>Período</th>
                       <th>Equipes</th>
                       <th>Status</th>
                       <th>Ações</th>
                   </tr>
               </thead>
               <tbody>
                   <?php if (empty($campeonatos)): ?>
                   <tr>
                       <td colspan="7" class="text-center">Nenhum campeonato encontrado.</td>
                   </tr>
                   <?php else: ?>
                   <?php foreach ($campeonatos as $campeonato): ?>
                   <tr>
                       <td>
                           <strong><?php echo htmlspecialchars($campeonato['campeonato_nome']); ?></strong>
                           <?php if (!empty($campeonato['campeonato_tipo'])): ?>
                           <br><small class="text-muted"><?php echo htmlspecialchars($campeonato['campeonato_tipo']); ?></small>
                           <?php endif; ?>
                       </td>
                       <td>
                           <small><?php echo htmlspecialchars($campeonato['campeonato_modalidade']); ?></small>
                       </td>
                       <td>
                           <small><?php echo htmlspecialchars($campeonato['campeonato_categoria']); ?></small>
                       </td>
                       <td>
                           <small>
                               <?php echo formatarData($campeonato['campeonato_data_inicio']); ?>
                               <?php if (!empty($campeonato['campeonato_data_fim'])): ?>
                               até<br><?php echo formatarData($campeonato['campeonato_data_fim']); ?>
                               <?php endif; ?>
                           </small>
                       </td>
                       <td>
                           <small>
                               <strong><?php echo $campeonato['total_equipes']; ?></strong> equipe(s)
                           </small>
                       </td>
                       <td>
                           <span class="status-badge <?php echo getStatusClass($campeonato['campeonato_status']); ?>">
                               <?php echo getStatusTexto($campeonato['campeonato_status']); ?>
                           </span>
                       </td>
                       <td>
                           <div class="actions">
                               <!-- BOTÃO PRINCIPAL - Gerenciar Equipes -->
                               <a href="campeonato_equipes.php?campeonato_id=<?php echo $campeonato['campeonato_id']; ?>" 
                                  class="btn-action btn-manage" title="Gerenciar Equipes">
                                   <i class="fas fa-users"></i>
                               </a>
                               
                               <!-- Visualizar -->
                               <a href="?acao=visualizar&id=<?php echo $campeonato['campeonato_id']; ?>" 
                                  class="btn-action btn-view" title="Visualizar">
                                   <i class="fas fa-eye"></i>
                               </a>
                               
                               <!-- Editar -->
                               <a href="?acao=editar&id=<?php echo $campeonato['campeonato_id']; ?>" 
                                  class="btn-action btn-edit" title="Editar">
                                   <i class="fas fa-edit"></i>
                               </a>
                               
                               <!-- Menu Dropdown com mais opções -->
                               <div class="dropdown">
                                   <button class="btn-action btn-info dropdown-toggle" type="button" 
                                           onclick="toggleDropdown(<?php echo $campeonato['campeonato_id']; ?>)">
                                       <i class="fas fa-ellipsis-v"></i>
                                   </button>
                                   <div class="dropdown-menu" id="dropdown-<?php echo $campeonato['campeonato_id']; ?>">
                                        <?php if ($campeonato['total_equipes'] >= 4): ?>
                                        <a href="campeonato_chaves.php?campeonato_id=<?php echo $campeonato['campeonato_id']; ?>" 
                                        class="dropdown-item">
                                            <i class="fas fa-sitemap"></i> Chaves/Grupos
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($campeonato['total_equipes'] >= 2): ?>
                                        <a href="campeonato_partidas.php?campeonato_id=<?php echo $campeonato['campeonato_id']; ?>" 
                                        class="dropdown-item">
                                            <i class="fas fa-calendar-alt"></i> Partidas
                                        </a>
                                        <a href="campeonato_classificacao.php?campeonato_id=<?php echo $campeonato['campeonato_id']; ?>" 
                                        class="dropdown-item">
                                            <i class="fas fa-trophy"></i> Classificação
                                        </a>
                                        <?php endif; ?>
                                    </div>
                               </div>
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

       <?php elseif ($acao === 'visualizar' && $campeonato_visualizar): ?>
       <!-- Visualização do campeonato -->
       <div class="view-container">
           <!-- Header -->
           <div class="view-header">
               <h2><?php echo htmlspecialchars($campeonato_visualizar['campeonato_nome']); ?></h2>
               <p><?php echo htmlspecialchars($campeonato_visualizar['campeonato_modalidade']); ?> - <?php echo htmlspecialchars($campeonato_visualizar['campeonato_categoria']); ?></p>
           </div>

           <!-- Estatísticas -->
           <div class="view-stats">
               <div class="stat-item">
                   <div class="stat-number"><?php echo $estatisticas_campeonato['total_equipes']; ?></div>
                   <div class="stat-label">Equipes</div>
               </div>
               <div class="stat-item">
                   <div class="stat-number"><?php echo $estatisticas_campeonato['total_atletas']; ?></div>
                   <div class="stat-label">Atletas</div>
               </div>
               <div class="stat-item">
                   <div class="stat-number"><?php echo $estatisticas_campeonato['partidas_finalizadas']; ?>/<?php echo $estatisticas_campeonato['total_partidas']; ?></div>
                   <div class="stat-label">Partidas</div>
               </div>
               <div class="stat-item">
                   <div class="stat-number">
                       <span class="status-badge <?php echo getStatusClass($campeonato_visualizar['campeonato_status']); ?>">
                           <?php echo getStatusTexto($campeonato_visualizar['campeonato_status']); ?>
                       </span>
                   </div>
                   <div class="stat-label">Status</div>
               </div>
           </div>

           <!-- Conteúdo -->
           <div class="view-content">
               <div class="info-grid">
                   <div class="info-item">
                       <div class="info-label">Tipo de Campeonato</div>
                       <div class="info-value"><?php echo htmlspecialchars($campeonato_visualizar['campeonato_tipo'] ?: 'Não informado'); ?></div>
                   </div>
                   
                   <div class="info-item">
                       <div class="info-label">Data de Início</div>
                       <div class="info-value"><?php echo formatarData($campeonato_visualizar['campeonato_data_inicio']); ?></div>
                   </div>
                   
                   <div class="info-item">
                       <div class="info-label">Data de Fim</div>
                       <div class="info-value"><?php echo formatarData($campeonato_visualizar['campeonato_data_fim']); ?></div>
                   </div>
                   
                   <div class="info-item">
                       <div class="info-label">Local</div>
                       <div class="info-value"><?php echo htmlspecialchars($campeonato_visualizar['campeonato_local'] ?: 'Não informado'); ?></div>
                   </div>
                   
                   <?php if (!empty($campeonato_visualizar['campeonato_data_inscricoes_inicio'])): ?>
                   <div class="info-item">
                       <div class="info-label">Inscrições de</div>
                       <div class="info-value">
                           <?php echo formatarData($campeonato_visualizar['campeonato_data_inscricoes_inicio']); ?>
                           <?php if (!empty($campeonato_visualizar['campeonato_data_inscricoes_fim'])): ?>
                           até <?php echo formatarData($campeonato_visualizar['campeonato_data_inscricoes_fim']); ?>
                           <?php endif; ?>
                       </div>
                   </div>
                   <?php endif; ?>
                   
                   <?php if ($campeonato_visualizar['campeonato_max_participantes'] > 0): ?>
                   <div class="info-item">
                       <div class="info-label">Máximo de Participantes</div>
                       <div class="info-value"><?php echo number_format($campeonato_visualizar['campeonato_max_participantes']); ?></div>
                   </div>
                   <?php endif; ?>
                   
                   <?php if ($campeonato_visualizar['campeonato_taxa_inscricao'] > 0): ?>
                   <div class="info-label">Taxa de Inscrição</div>
                       <div class="info-value">R$ <?php echo number_format($campeonato_visualizar['campeonato_taxa_inscricao'], 2, ',', '.'); ?></div>
                   </div>
                   <?php endif; ?>
               </div>

               <?php if (!empty($campeonato_visualizar['campeonato_descricao'])): ?>
               <div class="info-item" style="margin-bottom: 20px;">
                   <div class="info-label">Descrição</div>
                   <div class="info-value"><?php echo nl2br(htmlspecialchars($campeonato_visualizar['campeonato_descricao'])); ?></div>
               </div>
               <?php endif; ?>

               <?php if (!empty($campeonato_visualizar['campeonato_premiacao'])): ?>
               <div class="info-item" style="margin-bottom: 20px;">
                   <div class="info-label">Premiação</div>
                   <div class="info-value"><?php echo nl2br(htmlspecialchars($campeonato_visualizar['campeonato_premiacao'])); ?></div>
               </div>
               <?php endif; ?>

               <?php if (!empty($campeonato_visualizar['campeonato_observacoes'])): ?>
               <div class="info-item">
                   <div class="info-label">Observações</div>
                   <div class="info-value"><?php echo nl2br(htmlspecialchars($campeonato_visualizar['campeonato_observacoes'])); ?></div>
               </div>
               <?php endif; ?>
           </div>

           <!-- Ações -->
           <div class="view-actions">
                <a href="campeonato_equipes.php?campeonato_id=<?php echo $campeonato_visualizar['campeonato_id']; ?>" 
                class="btn btn-primary">
                    <i class="fas fa-users"></i> Gerenciar Equipes
                </a>
                
                <?php if ($estatisticas_campeonato['total_equipes'] >= 4): ?>
                <a href="campeonato_chaves.php?campeonato_id=<?php echo $campeonato_visualizar['campeonato_id']; ?>" 
                class="btn btn-info">
                    <i class="fas fa-sitemap"></i> Gerenciar Chaves
                </a>
                <?php endif; ?>
                
                <?php if ($estatisticas_campeonato['total_equipes'] >= 2): ?>
                <a href="campeonato_partidas.php?campeonato_id=<?php echo $campeonato_visualizar['campeonato_id']; ?>" 
                class="btn btn-warning">
                    <i class="fas fa-calendar-alt"></i> Ver Partidas
                </a>
                
                <a href="campeonato_classificacao.php?campeonato_id=<?php echo $campeonato_visualizar['campeonato_id']; ?>" 
                class="btn btn-success">
                    <i class="fas fa-trophy"></i> Ver Classificação
                </a>
                <?php endif; ?>
            </div>
       </div>

       <?php elseif ($acao === 'adicionar' || $acao === 'editar'): ?>
       <!-- Formulário de cadastro/edição -->
       <form method="POST" class="form-container">
           <input type="hidden" name="acao" value="salvar">
           <input type="hidden" name="campeonato_id" value="<?php echo $campeonato_id; ?>">
           
           <div class="form-section">
               <h3>Informações Básicas</h3>
               
               <div class="form-row">
                   <div class="form-group">
                       <label for="campeonato_nome">Nome do Campeonato *</label>
                       <input type="text" id="campeonato_nome" name="campeonato_nome" 
                              value="<?php echo htmlspecialchars($campeonato_atual['campeonato_nome'] ?? ''); ?>" 
                              required maxlength="255">
                   </div>
                   
                   <div class="form-group">
                       <label for="campeonato_tipo">Tipo de Campeonato</label>
                       <select id="campeonato_tipo" name="campeonato_tipo">
                           <option value="">Selecione...</option>
                           <?php foreach ($tipos_campeonato as $tipo): ?>
                           <option value="<?php echo $tipo; ?>" 
                                   <?php echo (($campeonato_atual['campeonato_tipo'] ?? '') === $tipo) ? 'selected' : ''; ?>>
                               <?php echo $tipo; ?>
                           </option>
                           <?php endforeach; ?>
                       </select>
                   </div>
               </div>
               
               <div class="form-group">
                   <label for="campeonato_descricao">Descrição</label>
                   <textarea id="campeonato_descricao" name="campeonato_descricao" 
                             rows="3" maxlength="1000"><?php echo htmlspecialchars($campeonato_atual['campeonato_descricao'] ?? ''); ?></textarea>
               </div>
           </div>
           
           <div class="form-section">
               <h3>Modalidade e Categoria</h3>
               
               <div class="form-row">
                   <div class="form-group">
                       <label for="campeonato_modalidade">Modalidade *</label>
                       <select id="campeonato_modalidade" name="campeonato_modalidade" required>
                           <option value="">Selecione...</option>
                           <?php foreach ($modalidades as $modalidade): ?>
                           <option value="<?php echo $modalidade; ?>" 
                                   <?php echo (($campeonato_atual['campeonato_modalidade'] ?? '') === $modalidade) ? 'selected' : ''; ?>>
                               <?php echo $modalidade; ?>
                           </option>
                           <?php endforeach; ?>
                       </select>
                   </div>
                   
                   <div class="form-group">
                       <label for="campeonato_categoria">Categoria *</label>
                       <select id="campeonato_categoria" name="campeonato_categoria" required>
                           <option value="">Selecione...</option>
                           <?php foreach ($categorias as $categoria): ?>
                           <option value="<?php echo $categoria; ?>" 
                                   <?php echo (($campeonato_atual['campeonato_categoria'] ?? '') === $categoria) ? 'selected' : ''; ?>>
                               <?php echo $categoria; ?>
                           </option>
                           <?php endforeach; ?>
                       </select>
                   </div>
               </div>
           </div>
           
           <div class="form-section">
               <h3>Datas e Período</h3>
               
               <div class="form-row">
                   <div class="form-group">
                       <label for="campeonato_data_inicio">Data de Início *</label>
                       <input type="date" id="campeonato_data_inicio" name="campeonato_data_inicio" 
                              value="<?php echo $campeonato_atual['campeonato_data_inicio'] ?? ''; ?>" required>
                   </div>
                   
                   <div class="form-group">
                       <label for="campeonato_data_fim">Data de Fim *</label>
                       <input type="date" id="campeonato_data_fim" name="campeonato_data_fim" 
                              value="<?php echo $campeonato_atual['campeonato_data_fim'] ?? ''; ?>" required>
                   </div>
               </div>
               
               <div class="form-row">
                   <div class="form-group">
                       <label for="campeonato_data_inscricoes_inicio">Início das Inscrições</label>
                       <input type="date" id="campeonato_data_inscricoes_inicio" name="campeonato_data_inscricoes_inicio" 
                              value="<?php echo $campeonato_atual['campeonato_data_inscricoes_inicio'] ?? ''; ?>">
                   </div>
                   
                   <div class="form-group">
                       <label for="campeonato_data_inscricoes_fim">Fim das Inscrições</label>
                       <input type="date" id="campeonato_data_inscricoes_fim" name="campeonato_data_inscricoes_fim" 
                              value="<?php echo $campeonato_atual['campeonato_data_inscricoes_fim'] ?? ''; ?>">
                   </div>
               </div>
           </div>
           
           <div class="form-section">
               <h3>Local e Participantes</h3>
               
               <div class="form-row">
                   <div class="form-group">
                       <label for="campeonato_local">Local de Realização</label>
                       <input type="text" id="campeonato_local" name="campeonato_local" 
                              value="<?php echo htmlspecialchars($campeonato_atual['campeonato_local'] ?? ''); ?>" 
                              maxlength="255" placeholder="Ex: Ginásio Municipal, Campo de Futebol...">
                   </div>
                   
                   <div class="form-group">
                       <label for="campeonato_max_participantes">Máximo de Participantes</label>
                       <input type="number" id="campeonato_max_participantes" name="campeonato_max_participantes" 
                              value="<?php echo $campeonato_atual['campeonato_max_participantes'] ?? ''; ?>" 
                              min="0" placeholder="0 = sem limite">
                   </div>
               </div>
           </div>
           
           <div class="form-section">
               <h3>Valores e Premiação</h3>
               
               <div class="form-row">
                   <div class="form-group">
                       <label for="campeonato_taxa_inscricao">Taxa de Inscrição (R$)</label>
                       <input type="number" id="campeonato_taxa_inscricao" name="campeonato_taxa_inscricao" 
                              value="<?php echo $campeonato_atual['campeonato_taxa_inscricao'] ?? '0.00'; ?>" 
                              min="0" step="0.01" placeholder="0.00">
                   </div>
                   
                   <div class="form-group">
                       <label for="campeonato_status">Status</label>
                       <select id="campeonato_status" name="campeonato_status">
                           <?php foreach ($status_campeonato as $status_key => $status_text): ?>
                           <option value="<?php echo $status_key; ?>" 
                                   <?php echo (($campeonato_atual['campeonato_status'] ?? 'PLANEJAMENTO') === $status_key) ? 'selected' : ''; ?>>
                               <?php echo $status_text; ?>
                           </option>
                           <?php endforeach; ?>
                       </select>
                   </div>
               </div>
               
               <div class="form-group">
                   <label for="campeonato_premiacao">Premiação</label>
                   <textarea id="campeonato_premiacao" name="campeonato_premiacao" 
                             rows="3" maxlength="1000" 
                             placeholder="Descreva a premiação para os vencedores..."><?php echo htmlspecialchars($campeonato_atual['campeonato_premiacao'] ?? ''); ?></textarea>
               </div>
           </div>
           
           <div class="form-section">
               <h3>Observações</h3>
               
               <div class="form-group">
                   <label for="campeonato_observacoes">Observações Adicionais</label>
                   <textarea id="campeonato_observacoes" name="campeonato_observacoes" 
                             rows="4" maxlength="2000" 
                             placeholder="Regulamento, requisitos especiais, informações adicionais..."><?php echo htmlspecialchars($campeonato_atual['campeonato_observacoes'] ?? ''); ?></textarea>
               </div>
           </div>
           
           <div class="form-actions">
               <button type="submit" class="btn btn-success">
                   <i class="fas fa-save"></i> 
                   <?php echo ($acao === 'editar') ? 'Atualizar' : 'Cadastrar'; ?> Campeonato
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
           
           // Validação de datas
           const dataInicio = document.getElementById('campeonato_data_inicio');
           const dataFim = document.getElementById('campeonato_data_fim');
           const dataInscricaoInicio = document.getElementById('campeonato_data_inscricoes_inicio');
           const dataInscricaoFim = document.getElementById('campeonato_data_inscricoes_fim');
           
           function validarDatas() {
               if (dataInicio && dataFim) {
                   if (dataInicio.value && dataFim.value) {
                       if (new Date(dataInicio.value) > new Date(dataFim.value)) {
                           dataFim.setCustomValidity('A data de fim deve ser posterior à data de início.');
                       } else {
                           dataFim.setCustomValidity('');
                       }
                   }
               }
               
               if (dataInscricaoFim && dataInicio) {
                   if (dataInscricaoFim.value && dataInicio.value) {
                       if (new Date(dataInscricaoFim.value) > new Date(dataInicio.value)) {
                           dataInscricaoFim.setCustomValidity('A data limite de inscrições deve ser anterior ao início do campeonato.');
                       } else {
                           dataInscricaoFim.setCustomValidity('');
                       }
                   }
               }
           }
           
           if (dataInicio) dataInicio.addEventListener('change', validarDatas);
           if (dataFim) dataFim.addEventListener('change', validarDatas);
           if (dataInscricaoFim) dataInscricaoFim.addEventListener('change', validarDatas);
           
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
           
           // Formatação da taxa de inscrição
           const taxaInput = document.getElementById('campeonato_taxa_inscricao');
           if (taxaInput) {
               taxaInput.addEventListener('blur', function() {
                   const value = parseFloat(this.value);
                   if (!isNaN(value)) {
                       this.value = value.toFixed(2);
                   }
               });
           }
       });

       // Função para toggle do dropdown
       function toggleDropdown(campeonatoId) {
           const dropdown = document.getElementById(`dropdown-${campeonatoId}`);
           
           // Fechar outros dropdowns
           document.querySelectorAll('.dropdown-menu').forEach(menu => {
               if (menu !== dropdown) {
                   menu.classList.remove('show');
               }
           });
           
           // Toggle do dropdown atual
           dropdown.classList.toggle('show');
       }

       // Fechar dropdown ao clicar fora
       document.addEventListener('click', function(event) {
           if (!event.target.closest('.dropdown')) {
               document.querySelectorAll('.dropdown-menu').forEach(menu => {
                   menu.classList.remove('show');
               });
           }
       });
   </script>
</body>
</html>
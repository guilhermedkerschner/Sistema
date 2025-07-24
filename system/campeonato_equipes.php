<?php
// campeonato_equipes.php
session_start();

if (!isset($_SESSION['usersystem_logado'])) {
    header("Location: ../acessdeniedrestrict.php"); 
    exit;
}

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
        $is_admin = ($usuario_nivel_id == 1);
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar dados do usuário: " . $e->getMessage());
}

// Verificar permissões
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

// Função para sanitizar dados
function sanitizeInput($data) {
    return trim(htmlspecialchars(stripslashes($data)));
}

// Obter ID do campeonato
$campeonato_id = intval($_GET['campeonato_id'] ?? 0);
$acao = sanitizeInput($_GET['acao'] ?? 'listar');
$equipe_id = intval($_GET['equipe_id'] ?? 0);

// Verificar se campeonato existe
$campeonato = null;
if ($campeonato_id > 0) {
    try {
        $stmt = $conn->prepare("SELECT * FROM tb_campeonatos WHERE campeonato_id = ?");
        $stmt->execute([$campeonato_id]);
        $campeonato = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$campeonato) {
            header("Location: esporte_campeonatos.php?erro=campeonato_nao_encontrado");
            exit;
        }
    } catch (PDOException $e) {
        header("Location: esporte_campeonatos.php?erro=erro_banco");
        exit;
    }
} else {
    header("Location: esporte_campeonatos.php");
    exit;
}

$mensagem = '';
$tipo_mensagem = '';

// Processar exclusão de equipe
if (isset($_GET['acao']) && $_GET['acao'] === 'excluir' && isset($_GET['equipe_id'])) {
    $equipe_id = intval($_GET['equipe_id']);
    
    if ($equipe_id > 0) {
        try {
            $conn->beginTransaction();
            
            // Primeiro remove todos os atletas da equipe
            $stmt = $conn->prepare("DELETE FROM tb_campeonato_equipe_atletas WHERE equipe_id = ?");
            $stmt->execute([$equipe_id]);
            
            // Depois remove a equipe
            $stmt = $conn->prepare("DELETE FROM tb_campeonato_equipes WHERE equipe_id = ? AND campeonato_id = ?");
            $stmt->execute([$equipe_id, $campeonato_id]);
            
            $conn->commit();
            
            $mensagem = "Equipe excluída com sucesso!";
            $tipo_mensagem = "success";
            
            // Redirecionar
            header("Location: ?campeonato_id={$campeonato_id}");
            exit;
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $mensagem = "Erro ao excluir equipe: " . $e->getMessage();
            $tipo_mensagem = "error";
        }
    }
}

// Processar remoção de atleta
if (isset($_GET['acao']) && $_GET['acao'] === 'remover_atleta') {
    $atleta_id = intval($_GET['atleta_id'] ?? 0);
    $equipe_id = intval($_GET['equipe_id'] ?? 0);
    
    if ($atleta_id > 0 && $equipe_id > 0) {
        try {
            $sql = "DELETE FROM tb_campeonato_equipe_atletas WHERE equipe_id = ? AND atleta_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$equipe_id, $atleta_id]);
            
            $mensagem = "Atleta removido da equipe com sucesso!";
            $tipo_mensagem = "success";
            
            // Redirecionar para evitar reenvio do formulário
            header("Location: ?campeonato_id={$campeonato_id}&acao=gerenciar&equipe_id={$equipe_id}");
            exit;
            
        } catch (PDOException $e) {
            $mensagem = "Erro ao remover atleta: " . $e->getMessage();
            $tipo_mensagem = "error";
        }
    }
}

// Processar formulários POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    if ($_POST['acao'] === 'salvar_equipe') {
        $dados = [
            'nome' => sanitizeInput($_POST['equipe_nome'] ?? ''),
            'responsavel' => sanitizeInput($_POST['equipe_responsavel'] ?? ''),
            'telefone' => sanitizeInput($_POST['equipe_telefone'] ?? ''),
            'observacoes' => sanitizeInput($_POST['equipe_observacoes'] ?? '')
        ];
        
        $equipe_id = intval($_POST['equipe_id'] ?? 0);
        
        // Validação
        $erros = [];
        if (empty($dados['nome'])) {
            $erros[] = "Nome da equipe é obrigatório.";
        }
        
        // Verificar se já existe equipe com mesmo nome neste campeonato
        if ($equipe_id > 0) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM tb_campeonato_equipes WHERE campeonato_id = ? AND equipe_nome = ? AND equipe_id != ?");
            $stmt->execute([$campeonato_id, $dados['nome'], $equipe_id]);
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM tb_campeonato_equipes WHERE campeonato_id = ? AND equipe_nome = ?");
            $stmt->execute([$campeonato_id, $dados['nome']]);
        }
        
        if ($stmt->fetchColumn() > 0) {
            $erros[] = "Já existe uma equipe com este nome neste campeonato.";
        }
        
        if (empty($erros)) {
            try {
                if ($equipe_id > 0) {
                    // Atualizar equipe existente
                    $sql = "UPDATE tb_campeonato_equipes SET 
                            equipe_nome = ?, 
                            equipe_responsavel = ?, 
                            equipe_telefone = ?, 
                            equipe_observacoes = ?
                            WHERE equipe_id = ? AND campeonato_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $dados['nome'], 
                        $dados['responsavel'], 
                        $dados['telefone'], 
                        $dados['observacoes'],
                        $equipe_id, 
                        $campeonato_id
                    ]);
                    $mensagem = "Equipe atualizada com sucesso!";
                } else {
                    // Inserir nova equipe - USANDO OS CAMPOS CORRETOS
                    $sql = "INSERT INTO tb_campeonato_equipes 
                            (campeonato_id, equipe_nome, equipe_responsavel, equipe_telefone, equipe_observacoes, equipe_data_inscricao, equipe_status, criado_por) 
                            VALUES (?, ?, ?, ?, ?, NOW(), 'INSCRITA', ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $campeonato_id, 
                        $dados['nome'], 
                        $dados['responsavel'], 
                        $dados['telefone'], 
                        $dados['observacoes'],
                        $usuario_id
                    ]);
                    $mensagem = "Equipe cadastrada com sucesso!";
                }
                $tipo_mensagem = "success";
                
                // Redirecionar para evitar reenvio
                header("Location: ?campeonato_id={$campeonato_id}");
                exit;
                
            } catch (PDOException $e) {
                $mensagem = "Erro ao salvar equipe: " . $e->getMessage();
                $tipo_mensagem = "error";
                error_log("Erro ao salvar equipe: " . $e->getMessage());
            }
        } else {
            $mensagem = implode("<br>", $erros);
            $tipo_mensagem = "error";
        }
    }
    
    if ($_POST['acao'] === 'adicionar_atleta') {
        $atleta_id = intval($_POST['atleta_id'] ?? 0);
        $numero_camisa = intval($_POST['numero_camisa'] ?? 0);
        $posicao = sanitizeInput($_POST['posicao'] ?? '');
        $capitao = sanitizeInput($_POST['capitao'] ?? 'NAO');
        $equipe_id = intval($_POST['equipe_id'] ?? 0);
        
        // Validação
        $erros = [];
        if ($atleta_id <= 0) {
            $erros[] = "Selecione um atleta.";
        }
        if ($numero_camisa <= 0 || $numero_camisa > 99) {
            $erros[] = "Número da camisa deve estar entre 1 e 99.";
        }
        
        if (empty($erros)) {
            try {
                // Verificar se atleta já está na equipe
                $stmt = $conn->prepare("SELECT COUNT(*) FROM tb_campeonato_equipe_atletas WHERE equipe_id = ? AND atleta_id = ?");
                $stmt->execute([$equipe_id, $atleta_id]);
                if ($stmt->fetchColumn() > 0) {
                    $erros[] = "Atleta já está cadastrado nesta equipe.";
                }
                
                // Verificar se número já está sendo usado
                $stmt = $conn->prepare("SELECT COUNT(*) FROM tb_campeonato_equipe_atletas WHERE equipe_id = ? AND numero_camisa = ?");
                $stmt->execute([$equipe_id, $numero_camisa]);
                if ($stmt->fetchColumn() > 0) {
                    $erros[] = "Número da camisa já está sendo usado.";
                }
                
                // Se for capitão, remover capitania dos outros
                if ($capitao === 'SIM') {
                    $stmt = $conn->prepare("UPDATE tb_campeonato_equipe_atletas SET capitao = 'NAO' WHERE equipe_id = ?");
                    $stmt->execute([$equipe_id]);
                }
                
                if (empty($erros)) {
                    $sql = "INSERT INTO tb_campeonato_equipe_atletas 
                            (equipe_id, atleta_id, numero_camisa, posicao, capitao) 
                            VALUES (?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$equipe_id, $atleta_id, $numero_camisa, $posicao, $capitao]);
                    $mensagem = "Atleta adicionado à equipe com sucesso!";
                    $tipo_mensagem = "success";
                    
                    // Redirecionar
                    header("Location: ?campeonato_id={$campeonato_id}&acao=gerenciar&equipe_id={$equipe_id}");
                    exit;
                }
            } catch (PDOException $e) {
                $mensagem = "Erro ao adicionar atleta: " . $e->getMessage();
                $tipo_mensagem = "error";
            }
        }
        
        if (!empty($erros)) {
            $mensagem = implode("<br>", $erros);
            $tipo_mensagem = "error";
        }
    }
    
    // Processar seleção múltipla de atletas
    if ($_POST['acao'] === 'salvar') {
        $atletas_selecionados = $_POST['atletas'] ?? [];
        
        if (!empty($atletas_selecionados)) {
            try {
                $conn->beginTransaction();
                
                // Remover atletas existentes da equipe
                $stmt = $conn->prepare("DELETE FROM tb_campeonato_equipe_atletas WHERE equipe_id = ?");
                $stmt->execute([$equipe_id]);
                
                // Adicionar atletas selecionados
                $stmt = $conn->prepare("INSERT INTO tb_campeonato_equipe_atletas (equipe_id, atleta_id, numero_camisa, posicao, capitao) VALUES (?, ?, ?, ?, ?)");
                
                foreach ($atletas_selecionados as $atleta_id_sel) {
                    $numero_camisa = intval($_POST['numero_' . $atleta_id_sel] ?? 0);
                    $posicao = sanitizeInput($_POST['posicao_' . $atleta_id_sel] ?? '');
                    $capitao = sanitizeInput($_POST['capitao_' . $atleta_id_sel] ?? 'NAO');
                    
                    $stmt->execute([$equipe_id, $atleta_id_sel, $numero_camisa > 0 ? $numero_camisa : null, $posicao, $capitao]);
                }
                
                $conn->commit();
                
                $mensagem = "Atletas adicionados à equipe com sucesso!";
                $tipo_mensagem = "success";
                
                // Redirecionar
                header("Location: ?campeonato_id={$campeonato_id}&acao=gerenciar&equipe_id={$equipe_id}");
                exit;
                
            } catch (PDOException $e) {
                $conn->rollBack();
                $mensagem = "Erro ao salvar atletas: " . $e->getMessage();
                $tipo_mensagem = "error";
                error_log("Erro ao salvar atletas: " . $e->getMessage());
            }
        }
    }
}

// Buscar equipes do campeonato
$equipes = [];
try {
    $sql = "SELECT e.*, 
            (SELECT COUNT(*) FROM tb_campeonato_equipe_atletas ea WHERE ea.equipe_id = e.equipe_id) as total_atletas
            FROM tb_campeonato_equipes e 
            WHERE e.campeonato_id = ? 
            ORDER BY e.equipe_nome";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$campeonato_id]);
    $equipes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar equipes: " . $e->getMessage());
}

// Buscar dados para edição
$equipe_atual = null;
if ($acao === 'editar' && $equipe_id > 0) {
    try {
        $stmt = $conn->prepare("SELECT * FROM tb_campeonato_equipes WHERE equipe_id = ? AND campeonato_id = ?");
        $stmt->execute([$equipe_id, $campeonato_id]);
        $equipe_atual = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$equipe_atual) {
            $mensagem = "Equipe não encontrada.";
            $tipo_mensagem = "error";
            header("Location: ?campeonato_id={$campeonato_id}");
            exit;
        }
    } catch (PDOException $e) {
        $mensagem = "Erro ao buscar equipe: " . $e->getMessage();
        $tipo_mensagem = "error";
        header("Location: ?campeonato_id={$campeonato_id}");
        exit;
    }
}

// Buscar atletas para gerenciar equipe
$atletas_equipe = [];
$atletas_disponiveis = [];
if ($acao === 'gerenciar' && $equipe_id > 0) {
    try {
        // Atletas da equipe
        $sql = "SELECT ea.*, a.atleta_nome 
                FROM tb_campeonato_equipe_atletas ea
                JOIN tb_atletas a ON ea.atleta_id = a.atleta_id
                WHERE ea.equipe_id = ?
                ORDER BY ea.numero_camisa";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$equipe_id]);
        $atletas_equipe = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Atletas disponíveis (não estão em nenhuma equipe deste campeonato)
        $sql = "SELECT a.atleta_id, a.atleta_nome, a.atleta_modalidade_principal, a.atleta_categoria
                FROM tb_atletas a 
                WHERE a.atleta_status = 'ATIVO' 
                AND a.atleta_id NOT IN (
                    SELECT DISTINCT ea.atleta_id 
                    FROM tb_campeonato_equipe_atletas ea
                    JOIN tb_campeonato_equipes e ON ea.equipe_id = e.equipe_id
                    WHERE e.campeonato_id = ?
                )
                ORDER BY a.atleta_nome";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$campeonato_id]);
        $atletas_disponiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Erro ao buscar atletas: " . $e->getMessage());
    }
} elseif ($acao === 'adicionar' || $acao === 'editar') {
    // Buscar atletas para formulário de edição/criação
    try {
        $sql = "SELECT a.atleta_id, a.atleta_nome, a.atleta_modalidade_principal, a.atleta_categoria
                FROM tb_atletas a 
                WHERE a.atleta_status = 'ATIVO'
                ORDER BY a.atleta_nome";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $atletas_disponiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Se estiver editando, buscar atletas já selecionados
        if ($acao === 'editar' && $equipe_id > 0) {
            $sql = "SELECT ea.*, a.atleta_nome, a.atleta_modalidade_principal, a.atleta_categoria
                    FROM tb_campeonato_equipe_atletas ea
                    JOIN tb_atletas a ON ea.atleta_id = a.atleta_id
                    WHERE ea.equipe_id = ?
                    ORDER BY ea.numero_camisa";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$equipe_id]);
            $atletas_equipe = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (PDOException $e) {
        error_log("Erro ao buscar atletas: " . $e->getMessage());
    }
}

// Configurar tema
$tema_cores = $menuManager->getThemeColors();
$titulo_sistema = $tema_cores['title'];
$cor_tema = $tema_cores['primary'];
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipes do Campeonato - Sistema da Prefeitura</title>
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

        .toggle-btn {
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
        }

        /* Estilos do Menu */
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

        .menu-text {
            flex: 1;
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

        .sidebar.collapsed {
            width: 70px;
        }

        .sidebar.collapsed .menu-text,
        .sidebar.collapsed .sidebar-header h3 {
            display: none;
        }

        /* Main content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: margin-left 0.3s;
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

        /* Campeonato info */
        .campeonato-info {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            border-left: 4px solid var(--secondary-color);
        }

        .campeonato-info h2 {
            color: var(--text-color);
            margin-bottom: 10px;
        }

        .campeonato-info p {
            color: #666;
            margin-bottom: 5px;
        }

        /* Alerts */
        .alert {
            padding: 12px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
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

        /* Destaque dos botões principais */
        .main-actions {
            background: linear-gradient(45deg, var(--success-color), #20c997);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .main-actions-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
        }

        .main-actions h3 {
            margin: 0;
            color: white;
        }

        .main-actions p {
            margin: 5px 0 0 0;
            opacity: 0.9;
        }

        .main-actions-buttons {
            display: flex;
            gap: 10px;
        }

        .btn-main {
            background: white;
            color: var(--success-color);
            font-weight: bold;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: all 0.3s;
        }

        .btn-main:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .btn-main i {
            margin-right: 8px;
        }

        .btn-secondary-main {
           background: rgba(255,255,255,0.2);
           color: white;
           border: 1px solid white;
       }

       .btn-secondary-main:hover {
           background: white;
           color: var(--success-color);
       }

       /* Cards */
       .equipes-grid {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
           gap: 20px;
           margin-bottom: 20px;
       }

       .equipe-card {
           background-color: white;
           border-radius: 8px;
           box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
           padding: 20px;
           transition: transform 0.3s;
       }

       .equipe-card:hover {
           transform: translateY(-2px);
           box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
       }

       .equipe-card h3 {
           color: var(--text-color);
           margin-bottom: 10px;
           display: flex;
           align-items: center;
           justify-content: space-between;
       }

       .equipe-info {
           margin-bottom: 15px;
       }

       .equipe-info p {
           margin-bottom: 5px;
           color: #666;
       }

       .equipe-actions {
           display: flex;
           gap: 8px;
           flex-wrap: wrap;
       }

       /* Buttons */
       .btn {
           padding: 8px 16px;
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

       .btn-info {
           background-color: var(--info-color);
           color: white;
       }

       .btn-info:hover {
           background-color: #138496;
       }

       .btn-danger {
           background-color: var(--danger-color);
           color: white;
       }

       .btn-danger:hover {
           background-color: #c82333;
       }

       .btn-sm {
           padding: 4px 8px;
           font-size: 12px;
       }

       .btn-lg {
           padding: 12px 24px;
           font-size: 16px;
       }

       /* Forms */
       .form-container {
           background-color: white;
           padding: 25px;
           border-radius: 8px;
           box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
           margin-bottom: 20px;
       }

       .form-row {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
           margin-top: 20px;
           padding-top: 20px;
           border-top: 1px solid #eee;
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

       /* Tables */
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

       .status-inscrita {
           background-color: #cce5ff;
           color: #004085;
       }

       .status-confirmada {
           background-color: #d4edda;
           color: #155724;
       }

       .text-center {
           text-align: center;
       }

       .text-muted {
           color: #6c757d;
       }

       .empty-state {
           text-align: center;
           padding: 60px 20px;
           background: white;
           border-radius: 12px;
           box-shadow: 0 2px 10px rgba(0,0,0,0.1);
       }

       .empty-state-icon {
           font-size: 5rem;
           color: #e9ecef;
           margin-bottom: 20px;
       }

       .empty-state h2 {
           color: var(--text-color);
           margin-bottom: 15px;
       }

       .empty-state p {
           color: #6c757d;
           margin-bottom: 30px;
           font-size: 1.1rem;
       }

       .btn-featured {
           display: inline-block;
           background: linear-gradient(45deg, var(--success-color), #20c997);
           color: white;
           padding: 15px 40px;
           border-radius: 50px;
           text-decoration: none;
           font-size: 1.2rem;
           font-weight: bold;
           box-shadow: 0 4px 15px rgba(40, 167, 69, 0.4);
           transform: scale(1);
           transition: transform 0.2s;
       }

       .btn-featured:hover {
           transform: scale(1.05);
           color: white;
           text-decoration: none;
       }

       .info-box {
           margin-top: 20px;
           padding: 15px;
           background: #f8f9fa;
           border-radius: 8px;
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

           .main-actions-content {
               flex-direction: column;
               gap: 15px;
               text-align: center;
           }

           .main-actions-buttons {
               flex-direction: column;
               width: 100%;
           }

           .equipes-grid {
               grid-template-columns: 1fr;
           }

           .form-row {
               grid-template-columns: 1fr;
           }

           .equipe-actions {
               flex-direction: column;
           }

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

       .mobile-toggle {
           display: none;
       }

       @media (max-width: 768px) {
           .mobile-toggle {
               display: block;
               background: none;
               border: none;
               font-size: 20px;
               cursor: pointer;
               color: var(--primary-color);
           }
       }
   </style>
</head>
<body>
   <!-- Menu lateral -->
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
           <i class="fas fa-users"></i>
           Equipes do Campeonato
       </h1>

       <!-- Breadcrumb -->
       <div class="breadcrumb">
           <a href="dashboard.php">Dashboard</a>
           <i class="fas fa-chevron-right"></i>
           <a href="esporte.php">Esporte</a>
           <i class="fas fa-chevron-right"></i>
           <a href="esporte_campeonatos.php">Campeonatos</a>
           <i class="fas fa-chevron-right"></i>
           <span>Equipes</span>
       </div>

       <!-- Informações do campeonato -->
       <div class="campeonato-info">
           <h2><?php echo htmlspecialchars($campeonato['campeonato_nome']); ?></h2>
           <p><strong>Modalidade:</strong> <?php echo htmlspecialchars($campeonato['campeonato_modalidade']); ?></p>
           <p><strong>Categoria:</strong> <?php echo htmlspecialchars($campeonato['campeonato_categoria']); ?></p>
           <p><strong>Status:</strong> <?php echo htmlspecialchars($campeonato['campeonato_status']); ?></p>
       </div>

       <!-- Botões principais destacados -->
       <div class="main-actions">
           <div class="main-actions-content">
               <div>
                   <h3>
                       <i class="fas fa-users"></i> Gerenciar Equipes
                   </h3>
                   <p>
                       <?php echo count($equipes); ?> equipe(s) cadastrada(s) 
                       <?php if (count($equipes) < 4): ?>
                       - <strong>Cadastre pelo menos 4 equipes para gerar as chaves</strong>
                       <?php endif; ?>
                   </p>
               </div>
               <div class="main-actions-buttons">
                   <a href="?campeonato_id=<?php echo $campeonato_id; ?>&acao=adicionar" class="btn-main">
                       <i class="fas fa-plus"></i> CADASTRAR EQUIPE
                   </a>
                   <?php if (count($equipes) >= 4): ?>
                   <a href="campeonato_chaves.php?campeonato_id=<?php echo $campeonato_id; ?>" 
                      class="btn-main btn-secondary-main">
                       <i class="fas fa-random"></i> Gerar Chaves
                   </a>
                   <?php endif; ?>
                   <?php if (count($equipes) >= 2): ?>
                   <a href="campeonato_partidas.php?campeonato_id=<?php echo $campeonato_id; ?>" 
                      class="btn-main btn-secondary-main">
                       <i class="fas fa-calendar-alt"></i> Ver Partidas
                   </a>
                   <?php endif; ?>
               </div>
           </div>
       </div>

       <!-- Mensagens -->
       <?php if ($mensagem): ?>
       <div class="alert alert-<?php echo $tipo_mensagem == 'success' ? 'success' : 'error'; ?>">
           <i class="fas fa-<?php echo $tipo_mensagem == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
           <?php echo $mensagem; ?>
       </div>
       <?php endif; ?>

       <?php if ($acao === 'listar'): ?>

       <!-- Lista de equipes -->
       <?php if (empty($equipes)): ?>
       <div class="empty-state">
           <div class="empty-state-icon">
               <i class="fas fa-users"></i>
           </div>
           <h2>Nenhuma equipe cadastrada</h2>
           <p>
               Este campeonato ainda não possui equipes.<br>
               Cadastre a primeira equipe para começar a organizar o torneio.
           </p>
           
           <a href="?campeonato_id=<?php echo $campeonato_id; ?>&acao=adicionar" class="btn-featured">
               <i class="fas fa-plus"></i> CADASTRAR PRIMEIRA EQUIPE
           </a>
           
           <div class="info-box">
               <i class="fas fa-info-circle"></i> 
               <strong>Dica:</strong> Você precisa de pelo menos 4 equipes para gerar as chaves do campeonato.
           </div>
       </div>
       <?php else: ?>
       <div class="equipes-grid">
           <?php foreach ($equipes as $equipe): ?>
           <div class="equipe-card">
               <h3>
                   <?php echo htmlspecialchars($equipe['equipe_nome']); ?>
                   <span class="status-badge status-<?php echo strtolower($equipe['equipe_status']); ?>">
                       <?php echo htmlspecialchars($equipe['equipe_status']); ?>
                   </span>
               </h3>
               
               <div class="equipe-info">
                   <?php if (!empty($equipe['equipe_responsavel'])): ?>
                   <p><strong>Responsável:</strong> <?php echo htmlspecialchars($equipe['equipe_responsavel']); ?></p>
                   <?php endif; ?>
                   
                   <?php if (!empty($equipe['equipe_telefone'])): ?>
                   <p><strong>Telefone:</strong> <?php echo htmlspecialchars($equipe['equipe_telefone']); ?></p>
                   <?php endif; ?>
                   
                   <p><strong>Atletas:</strong> <?php echo $equipe['total_atletas']; ?> cadastrados</p>
                   <p><strong>Data de Inscrição:</strong> <?php echo date('d/m/Y H:i', strtotime($equipe['equipe_data_inscricao'])); ?></p>
                   
                   <?php if (!empty($equipe['equipe_observacoes'])): ?>
                   <p><strong>Observações:</strong> <?php echo htmlspecialchars($equipe['equipe_observacoes']); ?></p>
                   <?php endif; ?>
               </div>
               
               <div class="equipe-actions">
                   <a href="?campeonato_id=<?php echo $campeonato_id; ?>&acao=gerenciar&equipe_id=<?php echo $equipe['equipe_id']; ?>" 
                      class="btn btn-primary btn-sm">
                       <i class="fas fa-users"></i> Gerenciar Atletas
                   </a>
                   <a href="?campeonato_id=<?php echo $campeonato_id; ?>&acao=editar&equipe_id=<?php echo $equipe['equipe_id']; ?>" 
                      class="btn btn-warning btn-sm">
                       <i class="fas fa-edit"></i> Editar
                   </a>
                   <a href="?campeonato_id=<?php echo $campeonato_id; ?>&acao=excluir&equipe_id=<?php echo $equipe['equipe_id']; ?>" 
                      class="btn btn-danger btn-sm"
                      onclick="return confirm('Tem certeza que deseja excluir esta equipe? Todos os atletas serão removidos.')">
                       <i class="fas fa-trash"></i> Excluir
                   </a>
               </div>
           </div>
           <?php endforeach; ?>
       </div>
       <?php endif; ?>

       <?php elseif ($acao === 'adicionar' || $acao === 'editar'): ?>
       <!-- Formulário de cadastro/edição de equipe -->
       <form method="POST" class="form-container">
           <input type="hidden" name="acao" value="salvar_equipe">
           <input type="hidden" name="equipe_id" value="<?php echo $equipe_id; ?>">
           
           <h3><?php echo ($acao === 'editar') ? 'Editar Equipe' : 'Cadastrar Nova Equipe'; ?></h3>
           
           <div class="form-row">
               <div class="form-group">
                   <label for="equipe_nome">Nome da Equipe *</label>
                   <input type="text" id="equipe_nome" name="equipe_nome" 
                          value="<?php echo htmlspecialchars($equipe_atual['equipe_nome'] ?? ''); ?>" 
                          required maxlength="255" placeholder="Ex: Tigres FC">
               </div>
               
               <div class="form-group">
                   <label for="equipe_responsavel">Responsável</label>
                   <input type="text" id="equipe_responsavel" name="equipe_responsavel" 
                          value="<?php echo htmlspecialchars($equipe_atual['equipe_responsavel'] ?? ''); ?>" 
                          maxlength="255" placeholder="Nome do técnico ou responsável">
               </div>
           </div>
           
           <div class="form-row">
               <div class="form-group">
                   <label for="equipe_telefone">Telefone</label>
                   <input type="text" id="equipe_telefone" name="equipe_telefone" 
                          value="<?php echo htmlspecialchars($equipe_atual['equipe_telefone'] ?? ''); ?>" 
                          maxlength="20" placeholder="(00) 00000-0000">
               </div>
           </div>
           
           <div class="form-group">
               <label for="equipe_observacoes">Observações</label>
               <textarea id="equipe_observacoes" name="equipe_observacoes" 
                         rows="3" maxlength="1000" 
                         placeholder="Informações adicionais sobre a equipe..."><?php echo htmlspecialchars($equipe_atual['equipe_observacoes'] ?? ''); ?></textarea>
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
               <a href="?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-secondary">
                   <i class="fas fa-times"></i> Cancelar
               </a>
           </div>
       </form>

       <?php elseif ($acao === 'gerenciar'): ?>
       <!-- Gerenciar atletas da equipe -->
       <?php
       $equipe_info = null;
       try {
           $stmt = $conn->prepare("SELECT * FROM tb_campeonato_equipes WHERE equipe_id = ?");
           $stmt->execute([$equipe_id]);
           $equipe_info = $stmt->fetch(PDO::FETCH_ASSOC);
       } catch (PDOException $e) {
           // handle error
       }
       ?>
       
       <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
           <h3>Gerenciar Atletas - <?php echo htmlspecialchars($equipe_info['equipe_nome'] ?? ''); ?></h3>
           <a href="?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-secondary">
               <i class="fas fa-arrow-left"></i> Voltar às Equipes
           </a>
       </div>

       <!-- Formulário para adicionar atleta -->
       <?php if (!empty($atletas_disponiveis)): ?>
       <form method="POST" class="form-container">
           <input type="hidden" name="acao" value="adicionar_atleta">
           <input type="hidden" name="equipe_id" value="<?php echo $equipe_id; ?>">
           
           <h4><i class="fas fa-user-plus"></i> Adicionar Atleta à Equipe</h4>
           
           <div class="form-row">
               <div class="form-group">
                   <label for="atleta_id">Atleta *</label>
                   <select id="atleta_id" name="atleta_id" required>
                       <option value="">Selecione um atleta...</option>
                       <?php foreach ($atletas_disponiveis as $atleta): ?>
                       <option value="<?php echo $atleta['atleta_id']; ?>">
                           <?php echo htmlspecialchars($atleta['atleta_nome']); ?>
                       </option>
                       <?php endforeach; ?>
                   </select>
               </div>
               
               <div class="form-group">
                   <label for="numero_camisa">Número da Camisa *</label>
                   <input type="number" id="numero_camisa" name="numero_camisa" 
                          required min="1" max="99" placeholder="1-99">
               </div>
               
               <div class="form-group">
                   <label for="posicao">Posição</label>
                   <input type="text" id="posicao" name="posicao" 
                          maxlength="50" placeholder="Ex: Atacante, Meio-campo...">
               </div>
               
               <div class="form-group">
                   <label for="capitao">Capitão</label>
                   <select id="capitao" name="capitao">
                       <option value="NAO">Não</option>
                       <option value="SIM">Sim</option>
                   </select>
               </div>
           </div>
           
           <div class="form-actions">
               <button type="submit" class="btn btn-success">
                   <i class="fas fa-plus"></i> Adicionar Atleta
               </button>
           </div>
       </form>
       <?php else: ?>
       <div class="form-container">
           <h4><i class="fas fa-info-circle"></i> Não há atletas disponíveis</h4>
           <p class="text-muted">Todos os atletas ativos já estão cadastrados em equipes deste campeonato.</p>
       </div>
       <?php endif; ?>

       <!-- Lista de atletas da equipe -->
       <?php if (!empty($atletas_equipe)): ?>
       <div class="table-container">
           <h4 style="padding: 20px 20px 0; margin: 0;">
               <i class="fas fa-list"></i> Atletas da Equipe (<?php echo count($atletas_equipe); ?>)
           </h4>
           <table class="data-table">
               <thead>
                   <tr>
                       <th>Nº</th>
                       <th>Atleta</th>
                       <th>Posição</th>
                       <th>Capitão</th>
                       <th>Ações</th>
                   </tr>
               </thead>
               <tbody>
                   <?php foreach ($atletas_equipe as $atleta): ?>
                   <tr>
                       <td>
                           <strong style="font-size: 1.2rem; color: var(--secondary-color);">
                               <?php echo $atleta['numero_camisa']; ?>
                           </strong>
                       </td>
                       <td><?php echo htmlspecialchars($atleta['atleta_nome']); ?></td>
                       <td><?php echo htmlspecialchars($atleta['posicao'] ?: 'Não informado'); ?></td>
                       <td>
                           <?php if ($atleta['capitao'] === 'SIM'): ?>
                           <span class="status-badge" style="background-color: #ffc107; color: #212529;">
                               <i class="fas fa-star"></i> Capitão
                           </span>
                           <?php else: ?>
                           <span class="text-muted">-</span>
                           <?php endif; ?>
                       </td>
                       <td>
                           <a href="?campeonato_id=<?php echo $campeonato_id; ?>&acao=remover_atleta&equipe_id=<?php echo $equipe_id; ?>&atleta_id=<?php echo $atleta['atleta_id']; ?>" 
                              class="btn btn-danger btn-sm"
                              onclick="return confirm('Tem certeza que deseja remover este atleta da equipe?')">
                               <i class="fas fa-trash"></i> Remover
                           </a>
                       </td>
                   </tr>
                   <?php endforeach; ?>
               </tbody>
           </table>
       </div>
       <?php else: ?>
       <div class="empty-state">
           <div style="font-size: 3rem; color: #ccc; margin-bottom: 15px;">
               <i class="fas fa-user-slash"></i>
           </div>
           <h4>Nenhum atleta cadastrado</h4>
           <p class="text-muted">Adicione atletas a esta equipe usando o formulário acima.</p>
       </div>
       <?php endif; ?>

       <?php endif; ?>
   </div>

   <script>
       document.addEventListener('DOMContentLoaded', function() {
           // Toggle do menu lateral
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
           
           // Máscara de telefone
           const telefoneInput = document.getElementById('equipe_telefone');
           if (telefoneInput) {
               telefoneInput.addEventListener('input', function(e) {
                   let value = e.target.value.replace(/\D/g, '');
                   value = value.replace(/^(\d{2})(\d)/, '($1) $2');
                   value = value.replace(/(\d{5})(\d)/, '$1-$2');
                   e.target.value = value;
               });
           }
           
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
       });

       // Sistema de busca e seleção de atletas
       document.addEventListener('DOMContentLoaded', function() {
           const searchInput = document.getElementById('athlete-search');
           const searchResults = document.getElementById('search-results');
           const selectedAthletes = document.getElementById('selected-athletes');
           const selectedCount = document.getElementById('selected-count');
           
           if (!searchInput || !searchResults || !selectedAthletes || !selectedCount) {
               return; // Não está na página de cadastro/edição
           }
           
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
           
           function performSearch(query) {
               const filteredAthletes = allAthletes.filter(athlete => 
                   athlete.atleta_nome.toLowerCase().includes(query.toLowerCase()) ||
                   (athlete.atleta_modalidade_principal && athlete.atleta_modalidade_principal.toLowerCase().includes(query.toLowerCase()))
               );
               
               displaySearchResults(filteredAthletes);
           }
           
           function displaySearchResults(athletes) {
               if (athletes.length === 0) {
                   searchResults.innerHTML = '<div class="no-results">Nenhum atleta encontrado para esta busca</div>';
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
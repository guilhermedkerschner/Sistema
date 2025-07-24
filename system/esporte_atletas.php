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

// Buscar informações do usuário logado
$usuario_id = $_SESSION['usersystem_id'];
$usuario_dados = [];

try {
    $stmt = $conn->prepare("
        SELECT 
            usuario_id,
            usuario_nome, 
            usuario_departamento, 
            usuario_nivel_id,
            usuario_email
        FROM tb_usuarios_sistema 
        WHERE usuario_id = :id
    ");
    $stmt->bindParam(':id', $usuario_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $usuario_dados = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        session_destroy();
        header("Location: ../acessdeniedrestrict.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar dados do usuário: " . $e->getMessage());
    header("Location: ../acessdeniedrestrict.php");
    exit;
}

// Verificar permissões de acesso ao módulo de esporte
$is_admin = ($usuario_dados['usuario_nivel_id'] == 1);
$usuario_departamento = strtoupper($usuario_dados['usuario_departamento'] ?? '');
$tem_permissao = $is_admin || $usuario_departamento === 'ESPORTE';

if (!$tem_permissao) {
    header("Location: dashboard.php?erro=acesso_negado");
    exit;
}

// Inicializar o MenuManager
$userSession = [
    'usuario_id' => $usuario_dados['usuario_id'],
    'usuario_nome' => $usuario_dados['usuario_nome'],
    'usuario_departamento' => $usuario_dados['usuario_departamento'],
    'usuario_nivel_id' => $usuario_dados['usuario_nivel_id'],
    'usuario_email' => $usuario_dados['usuario_email']
];

$menuManager = new MenuManager($userSession);
$themeColors = $menuManager->getThemeColors();

// Definir ação
$acao = $_GET['acao'] ?? 'listar';
$atleta_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// Variáveis para mensagens
$mensagem = "";
$tipo_mensagem = "";

// Função para sanitizar inputs
function sanitizeInput($data) {
    if (is_null($data) || $data === '') {
        return null;
    }
    return trim(htmlspecialchars(stripslashes($data)));
}

// Função para validar CPF
function validarCPF($cpf) {
    // Remove caracteres não numéricos
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    
    // Verifica se tem 11 dígitos
    if (strlen($cpf) != 11) {
        return false;
    }
    
    // Verifica se todos os dígitos são iguais
    if (preg_match('/(\d)\1{10}/', $cpf)) {
        return false;
    }
    
    // Calcula o primeiro dígito verificador
    $soma = 0;
    for ($i = 0; $i < 9; $i++) {
        $soma += intval($cpf[$i]) * (10 - $i);
    }
    $resto = $soma % 11;
    $digito1 = ($resto < 2) ? 0 : (11 - $resto);
    
    // Verifica o primeiro dígito
    if (intval($cpf[9]) != $digito1) {
        return false;
    }
    
    // Calcula o segundo dígito verificador
    $soma = 0;
    for ($i = 0; $i < 10; $i++) {
        $soma += intval($cpf[$i]) * (11 - $i);
    }
    $resto = $soma % 11;
    $digito2 = ($resto < 2) ? 0 : (11 - $resto);
    
    // Verifica o segundo dígito
    return intval($cpf[10]) == $digito2;
}

// Processamento de formulários
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        if ($acao === 'adicionar' || $acao === 'editar') {
            // Validar e sanitizar dados - CORRIGIDO para corresponder aos campos da tabela
            $nome = sanitizeInput($_POST['atleta_nome'] ?? '');
            $cpf = sanitizeInput($_POST['atleta_cpf'] ?? '');
            $rg = sanitizeInput($_POST['atleta_rg'] ?? '');
            $data_nascimento = sanitizeInput($_POST['atleta_data_nascimento'] ?? '');
            $genero = sanitizeInput($_POST['atleta_genero'] ?? '');
            $telefone = sanitizeInput($_POST['atleta_telefone'] ?? '');
            $celular = sanitizeInput($_POST['atleta_celular'] ?? ''); // ADICIONADO - existe na tabela
            $email = sanitizeInput($_POST['atleta_email'] ?? '');
            $endereco = sanitizeInput($_POST['atleta_endereco'] ?? '');
            $bairro = sanitizeInput($_POST['atleta_bairro'] ?? '');
            $cidade = sanitizeInput($_POST['atleta_cidade'] ?? ''); // ADICIONADO - existe na tabela
            $cep = sanitizeInput($_POST['atleta_cep'] ?? '');
            $modalidade_principal = sanitizeInput($_POST['atleta_modalidade_principal'] ?? '');
            $categoria = sanitizeInput($_POST['atleta_categoria'] ?? '');
            $status = sanitizeInput($_POST['atleta_status'] ?? '');
            $responsavel_nome = sanitizeInput($_POST['atleta_responsavel_nome'] ?? ''); // CORRIGIDO
            $responsavel_telefone = sanitizeInput($_POST['atleta_responsavel_telefone'] ?? ''); // CORRIGIDO
            $observacoes = sanitizeInput($_POST['atleta_observacoes'] ?? '');

            
            // Processar CPF
            $cpf_limpo = $cpf ? preg_replace('/[^0-9]/', '', $cpf) : null;
            
            // Validações
            if (empty($nome)) {
                throw new Exception("Nome do atleta é obrigatório.");
            }
            
            if (!empty($cpf) && !validarCPF($cpf)) {
                throw new Exception("CPF inválido.");
            }
            
            if (empty($data_nascimento)) {
                throw new Exception("Data de nascimento é obrigatória.");
            }
            
            if (empty($modalidade_principal)) {
                throw new Exception("Modalidade principal é obrigatória.");
            }
            
            if (empty($categoria)) {
                throw new Exception("Categoria é obrigatória.");
            }
            
            // Verificar se CPF já existe (apenas se fornecido)
            if (!empty($cpf)) {
                $sql_check = "SELECT atleta_id FROM tb_atletas WHERE atleta_cpf = :cpf";
                if ($acao === 'editar') {
                    $sql_check .= " AND atleta_id != :atleta_id";
                }
                
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->bindValue(':cpf', $cpf_limpo);
                if ($acao === 'editar') {
                    $stmt_check->bindValue(':atleta_id', $atleta_id);
                }
                $stmt_check->execute();
                
                if ($stmt_check->rowCount() > 0) {
                    throw new Exception("Já existe um atleta cadastrado com este CPF.");
                }
            }
            
            if ($acao === 'adicionar') {
                $sql = "INSERT INTO tb_atletas (
                    atleta_nome, atleta_cpf, atleta_rg, atleta_data_nascimento, 
                    atleta_genero, atleta_telefone, atleta_celular, atleta_email, 
                    atleta_endereco, atleta_bairro, atleta_cidade, atleta_cep, 
                    atleta_modalidade_principal, atleta_categoria, atleta_responsavel_nome, 
                    atleta_responsavel_telefone, atleta_observacoes, atleta_status, 
                    atleta_data_cadastro, atleta_cadastrado_por
                ) VALUES (
                    :nome, :cpf, :rg, :data_nascimento, :genero, :telefone, 
                    :celular, :email, :endereco, :bairro, :cidade, :cep, 
                    :modalidade_principal, :categoria, :responsavel_nome, 
                    :responsavel_telefone, :observacoes, :status, 
                    NOW(), :cadastrado_por
                )";
                
                $stmt = $conn->prepare($sql);
                
                $stmt->bindValue(':nome', $nome);
                $stmt->bindValue(':cpf', $cpf_limpo);
                $stmt->bindValue(':rg', $rg);
                $stmt->bindValue(':data_nascimento', $data_nascimento);
                $stmt->bindValue(':genero', $genero);
                $stmt->bindValue(':telefone', $telefone);
                $stmt->bindValue(':celular', $celular);
                $stmt->bindValue(':email', $email);
                $stmt->bindValue(':endereco', $endereco);
                $stmt->bindValue(':bairro', $bairro);
                $stmt->bindValue(':cidade', $cidade);
                $stmt->bindValue(':cep', $cep);
                $stmt->bindValue(':modalidade_principal', $modalidade_principal);
                $stmt->bindValue(':categoria', $categoria);
                $stmt->bindValue(':responsavel_nome', $responsavel_nome);
                $stmt->bindValue(':responsavel_telefone', $responsavel_telefone);
                $stmt->bindValue(':observacoes', $observacoes);
                $stmt->bindValue(':status', $status);
                $stmt->bindValue(':cadastrado_por', $usuario_id);
                
            } else {
                $sql = "UPDATE tb_atletas SET 
                    atleta_nome = :nome, atleta_cpf = :cpf, atleta_rg = :rg, 
                    atleta_data_nascimento = :data_nascimento, atleta_genero = :genero, 
                    atleta_telefone = :telefone, atleta_celular = :celular, atleta_email = :email, 
                    atleta_endereco = :endereco, atleta_bairro = :bairro, atleta_cidade = :cidade,
                    atleta_cep = :cep, atleta_modalidade_principal = :modalidade_principal, 
                    atleta_categoria = :categoria, atleta_status = :status, 
                    atleta_responsavel_nome = :responsavel_nome, 
                    atleta_responsavel_telefone = :responsavel_telefone, 
                    atleta_observacoes = :observacoes
                    WHERE atleta_id = :atleta_id";
                
                $stmt = $conn->prepare($sql);
                
                $stmt->bindValue(':nome', $nome);
                $stmt->bindValue(':cpf', $cpf_limpo);
                $stmt->bindValue(':rg', $rg);
                $stmt->bindValue(':data_nascimento', $data_nascimento);
                $stmt->bindValue(':genero', $genero);
                $stmt->bindValue(':telefone', $telefone);
                $stmt->bindValue(':celular', $celular);
                $stmt->bindValue(':email', $email);
                $stmt->bindValue(':endereco', $endereco);
                $stmt->bindValue(':bairro', $bairro);
                $stmt->bindValue(':cidade', $cidade);
                $stmt->bindValue(':cep', $cep);
                $stmt->bindValue(':modalidade_principal', $modalidade_principal);
                $stmt->bindValue(':categoria', $categoria);
                $stmt->bindValue(':responsavel_nome', $responsavel_nome);
                $stmt->bindValue(':responsavel_telefone', $responsavel_telefone);
                $stmt->bindValue(':observacoes', $observacoes);
                $stmt->bindValue(':status', $status);
                $stmt->bindValue(':atleta_id', $atleta_id);
            }
            
            $stmt->execute();
            
            $conn->commit();
            
            $mensagem = ($acao === 'adicionar') ? "Atleta cadastrado com sucesso!" : "Atleta atualizado com sucesso!";
            $tipo_mensagem = "success";
            
            // Redirecionar para listagem
            header("Location: esporte_atletas.php?mensagem=" . urlencode($mensagem) . "&tipo=success");
            exit;
        } elseif ($acao === 'excluir' && $atleta_id) {
            // Verificar se o atleta existe
            $stmt = $conn->prepare("SELECT atleta_nome FROM tb_atletas WHERE atleta_id = :id");
            $stmt->bindParam(':id', $atleta_id);
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                throw new Exception("Atleta não encontrado.");
            }
            
            $atleta = $stmt->fetch();
            
            // Excluir atleta
            $stmt = $conn->prepare("DELETE FROM tb_atletas WHERE atleta_id = :id");
            $stmt->bindParam(':id', $atleta_id);
            $stmt->execute();
            
            $conn->commit();
            
            $mensagem = "Atleta '{$atleta['atleta_nome']}' excluído com sucesso!";
            $tipo_mensagem = "success";
            
            header("Location: esporte_atletas.php?mensagem=" . urlencode($mensagem) . "&tipo=success");
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        $mensagem = $e->getMessage();
        $tipo_mensagem = "error";
    }
}

// Buscar atleta para edição
$atleta_atual = null;
if ($acao === 'editar' && $atleta_id) {
    try {
        $stmt = $conn->prepare("SELECT * FROM tb_atletas WHERE atleta_id = :id");
        $stmt->bindParam(':id', $atleta_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $atleta_atual = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $mensagem = "Atleta não encontrado.";
            $tipo_mensagem = "error";
            $acao = 'listar';
        }
    } catch (PDOException $e) {
        $mensagem = "Erro ao buscar dados do atleta.";
        $tipo_mensagem = "error";
        $acao = 'listar';
    }
}

// Buscar lista de atletas para listagem
$atletas = [];
$total_registros = 0;
$registros_por_pagina = 20;
$pagina_atual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$offset = ($pagina_atual - 1) * $registros_por_pagina;

// Filtros
$filtro_nome = isset($_GET['filtro_nome']) ? trim($_GET['filtro_nome']) : '';
$filtro_modalidade = isset($_GET['filtro_modalidade']) ? trim($_GET['filtro_modalidade']) : '';
$filtro_categoria = isset($_GET['filtro_categoria']) ? trim($_GET['filtro_categoria']) : '';
$filtro_status = isset($_GET['filtro_status']) ? trim($_GET['filtro_status']) : '';

if ($acao === 'listar') {
    try {
        // Construir WHERE clause
        $where_conditions = [];
        $params = [];
        
        if (!empty($filtro_nome)) {
            $where_conditions[] = "atleta_nome LIKE :nome";
            $params[':nome'] = "%{$filtro_nome}%";
        }
        
        if (!empty($filtro_modalidade)) {
            $where_conditions[] = "atleta_modalidade_principal = :modalidade";
            $params[':modalidade'] = $filtro_modalidade;
        }
        
        if (!empty($filtro_categoria)) {
            $where_conditions[] = "atleta_categoria = :categoria";
            $params[':categoria'] = $filtro_categoria;
        }
        
        if (!empty($filtro_status)) {
            $where_conditions[] = "atleta_status = :status";
            $params[':status'] = $filtro_status;
        }
        
        $where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
        
        // Contar total de registros
        $count_sql = "SELECT COUNT(*) as total FROM tb_atletas {$where_sql}";
        $stmt = $conn->prepare($count_sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $total_registros = $stmt->fetch()['total'];
        
        // Buscar registros com paginação
        $sql = "SELECT * FROM tb_atletas {$where_sql} ORDER BY atleta_nome ASC LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $atletas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $mensagem = "Erro ao buscar atletas: " . $e->getMessage();
        $tipo_mensagem = "error";
        error_log("Erro ao buscar atletas: " . $e->getMessage());
    }
}

$total_paginas = ceil($total_registros / $registros_por_pagina);

// Mensagens da URL
if (isset($_GET['mensagem'])) {
    $mensagem = $_GET['mensagem'];
    $tipo_mensagem = $_GET['tipo'] ?? 'info';
}

// Funções auxiliares
function calcularIdade($data_nascimento) {
    if (!$data_nascimento) return '';
    $data_nasc = new DateTime($data_nascimento);
    $hoje = new DateTime();
    return $hoje->diff($data_nasc)->y . ' anos';
}

function formatarCPF($cpf) {
    if (!$cpf) return '';
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) != 11) return $cpf;
    return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
}

function formatarTelefone($telefone) {
    if (!$telefone) return '';
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) == 11) {
        return '(' . substr($telefone, 0, 2) . ') ' . substr($telefone, 2, 5) . '-' . substr($telefone, 7, 4);
    } elseif (strlen($telefone) == 10) {
        return '(' . substr($telefone, 0, 2) . ') ' . substr($telefone, 2, 4) . '-' . substr($telefone, 6, 4);
    }
    return $telefone;
}

function getStatusClass($status) {
    $classes = [
        'ATIVO' => 'status-ativo',
        'INATIVO' => 'status-inativo',
        'SUSPENSO' => 'status-suspenso'
    ];
    return $classes[$status] ?? '';
}

// Listas para dropdowns
$modalidades = [
    'Futebol', 'Futsal', 'Vôlei', 'Basquete', 'Handebol', 'Tênis', 'Tênis de Mesa',
    'Natação', 'Atletismo', 'Judô', 'Karatê', 'Taekwondo', 'Capoeira', 'Xadrez',
    'Damas', 'Corrida', 'Ciclismo', 'Ginástica', 'Outros'
];

$categorias = ['INFANTIL', 'JUVENIL', 'JUNIOR', 'ADULTO', 'MASTER'];
$status_atleta = ['ATIVO', 'INATIVO', 'SUSPENSO'];
$generos = ['MASCULINO', 'FEMININO', 'OUTRO'];



?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atletas - Sistema da Prefeitura</title>
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
            --secondary-color: #4caf50;
            --text-color: #333;
            --light-color: #ecf0f1;
            --sidebar-width: 250px;
            --header-height: 60px;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
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
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .sidebar-header h3 {
            color: var(--secondary-color);
            font-weight: 600;
            font-size: 1.2rem;
        }

        .toggle-btn {
            background: none;
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            padding: 5px;
            border-radius: 5px;
            transition: background-color 0.3s;
        }

        .toggle-btn:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .menu {
            list-style: none;
            padding: 0;
        }

        .menu-separator {
            height: 1px;
            background-color: rgba(255, 255, 255, 0.1);
            margin: 10px 20px;
        }

        .menu-category {
            color: #bdc3c7;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 10px 20px 5px;
        }

        .menu-item {
            margin: 2px 0;
        }

        .menu-link {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #ecf0f1;
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }

        .menu-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
        }

        .menu-link.active {
            background-color: var(--secondary-color);
            border-left-color: var(--secondary-color);
        }

        .menu-icon {
            width: 20px;
            margin-right: 15px;
            text-align: center;
        }

        .menu-text {
            flex: 1;
        }

        .arrow {
            transition: transform 0.3s;
        }

        .menu-item.open .arrow {
            transform: rotate(90deg);
        }

        .submenu {
            list-style: none;
            padding: 0;
            background-color: rgba(0, 0, 0, 0.2);
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .menu-item.open .submenu {
            max-height: 500px;
        }

        .submenu-link {
            display: block;
            padding: 10px 20px 10px 55px;
            color: #bdc3c7;
            text-decoration: none;
            transition: all 0.3s;
            font-size: 0.9rem;
        }

        .submenu-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .submenu-link.active {
            background-color: var(--secondary-color);
            color: white;
        }

        /* Main content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .header {
            background-color: white;
            padding: 0 30px;
            height: var(--header-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header h2 {
            color: var(--primary-color);
            font-weight: 600;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: var(--primary-color);
        }

        .user-role {
            font-size: 0.8rem;
            color: #7f8c8d;
        }

        .admin-badge {
            background-color: var(--danger-color);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .department-badge {
            background-color: var(--secondary-color);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Page content */
        .page-content {
            flex: 1;
            padding: 30px;
        }

        .page-title {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-title i {
            color: var(--secondary-color);
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 30px;
            font-size: 0.9rem;
            color: #7f8c8d;
        }

        .breadcrumb a {
            color: var(--secondary-color);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .alert-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }

        .alert-info {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .card-header {
            background: linear-gradient(135deg, var(--secondary-color), #66bb6a);
            color: white;
            padding: 20px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 25px;
        }

        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--primary-color);
        }

        .form-group label.required::after {
            content: ' *';
            color: var(--danger-color);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .form-control:invalid {
            border-color: var(--danger-color);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        /* Buttons */
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 0.95rem;
        }

        .btn-primary {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #45a049;
            transform: translateY(-2px);
        }

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background-color: #219a52;
            transform: translateY(-2px);
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #c0392b;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #545b62;
            transform: translateY(-2px);
        }

        .btn-warning {
            background-color: var(--warning-color);
            color: white;
        }

        .btn-warning:hover {
            background-color: #d68910;
           transform: translateY(-2px);
       }

       .btn-info {
           background-color: var(--info-color);
           color: white;
       }

       .btn-info:hover {
           background-color: #138496;
           transform: translateY(-2px);
       }

       .btn-sm {
           padding: 8px 15px;
           font-size: 0.85rem;
       }

       /* Action buttons */
       .action-buttons {
           display: flex;
           gap: 10px;
           justify-content: flex-end;
           margin-top: 20px;
           flex-wrap: wrap;
       }

       /* Table */
       .table-container {
           background: white;
           border-radius: 15px;
           overflow: hidden;
           box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
       }

       .table {
           width: 100%;
           border-collapse: collapse;
           font-size: 0.9rem;
       }

       .table th,
       .table td {
           padding: 15px;
           text-align: left;
           border-bottom: 1px solid #e9ecef;
       }

       .table th {
           background-color: #f8f9fa;
           font-weight: 600;
           color: var(--primary-color);
           position: sticky;
           top: 0;
           z-index: 10;
       }

       .table tbody tr:hover {
           background-color: #f8f9fa;
       }

       .table-actions {
           display: flex;
           gap: 5px;
           justify-content: center;
       }

       /* Status badges */
       .status-badge {
           padding: 4px 8px;
           border-radius: 12px;
           font-size: 0.75rem;
           font-weight: 600;
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

       /* Filters */
       .filters-container {
           background: white;
           padding: 20px;
           border-radius: 15px;
           margin-bottom: 20px;
           box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
       }

       .filters-grid {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
           gap: 15px;
           align-items: end;
       }

       /* Pagination */
       .pagination {
           display: flex;
           justify-content: center;
           align-items: center;
           gap: 10px;
           margin-top: 20px;
           flex-wrap: wrap;
       }

       .pagination a,
       .pagination span {
           padding: 8px 12px;
           border: 1px solid #dee2e6;
           color: var(--secondary-color);
           text-decoration: none;
           border-radius: 5px;
           transition: all 0.3s;
       }

       .pagination a:hover {
           background-color: var(--secondary-color);
           color: white;
       }

       .pagination .current {
           background-color: var(--secondary-color);
           color: white;
           border-color: var(--secondary-color);
       }

       .pagination .disabled {
           color: #6c757d;
           cursor: not-allowed;
       }

       /* Modal styles */
       .modal {
           display: none;
           position: fixed;
           z-index: 1000;
           left: 0;
           top: 0;
           width: 100%;
           height: 100%;
           background-color: rgba(0, 0, 0, 0.5);
       }

       .modal-content {
           background-color: white;
           margin: 5% auto;
           padding: 0;
           border-radius: 15px;
           width: 90%;
           max-width: 500px;
           box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
       }

       .modal-header {
           background: linear-gradient(135deg, var(--danger-color), #e74c3c);
           color: white;
           padding: 20px;
           border-radius: 15px 15px 0 0;
       }

       .modal-title {
           margin: 0;
           display: flex;
           align-items: center;
           gap: 10px;
       }

       .modal-body {
           padding: 20px;
       }

       .modal-footer {
           padding: 20px;
           border-top: 1px solid #e9ecef;
           display: flex;
           gap: 10px;
           justify-content: flex-end;
       }

       .close {
           color: white;
           float: right;
           font-size: 24px;
           font-weight: bold;
           cursor: pointer;
           line-height: 1;
       }

       .close:hover {
           opacity: 0.7;
       }

       /* Mobile responsive */
       .mobile-toggle {
           display: none;
       }

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
               padding: 0 20px;
           }

           .page-content {
               padding: 20px;
           }

           .form-grid {
               grid-template-columns: 1fr;
           }

           .filters-grid {
               grid-template-columns: 1fr;
           }

           .table-container {
               overflow-x: auto;
           }

           .action-buttons {
               justify-content: center;
           }

           .modal-content {
               width: 95%;
               margin: 10% auto;
           }
       }

       @media (max-width: 480px) {
           .page-title {
               font-size: 1.5rem;
           }

           .card-header {
               padding: 15px 20px;
           }

           .card-body {
               padding: 20px;
           }

           .btn {
               padding: 10px 20px;
               font-size: 0.9rem;
           }
       }
   </style>
</head>
<body>
   <!-- Sidebar -->
   <div class="sidebar" id="sidebar">
       <div class="sidebar-header">
           <h3><?php echo $themeColors['title'] ?? 'Sistema Esporte'; ?></h3>
           <button class="toggle-btn">
               <i class="fas fa-bars"></i>
           </button>
       </div>
       
       <?php echo $menuManager->generateSidebar('esporte_atletas.php'); ?>
   </div>

   <!-- Main Content -->
   <div class="main-content" id="mainContent">
       <div class="header">
           <div>
               <button class="mobile-toggle">
                   <i class="fas fa-bars"></i>
               </button>
               <h2>Gestão de Atletas</h2>
           </div>
           <div class="user-info">
               <div style="width: 35px; height: 35px; border-radius: 50%; background-color: var(--secondary-color); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                   <?php echo strtoupper(substr($usuario_dados['usuario_nome'], 0, 1)); ?>
               </div>
               <div class="user-details">
                   <div class="user-name"><?php echo htmlspecialchars($usuario_dados['usuario_nome']); ?></div>
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

       <div class="page-content">
           <h1 class="page-title">
               <i class="fas fa-user-friends"></i>
               <?php 
               switch($acao) {
                   case 'adicionar':
                       echo 'Cadastrar Atleta';
                       break;
                   case 'editar':
                       echo 'Editar Atleta';
                       break;
                   default:
                       echo 'Gerenciar Atletas';
               }
               ?>
           </h1>

           <!-- Breadcrumb -->
           <div class="breadcrumb">
               <a href="dashboard.php">Dashboard</a>
               <i class="fas fa-chevron-right"></i>
               <a href="esporte.php">Esporte</a>
               <i class="fas fa-chevron-right"></i>
               <span>Atletas</span>
           </div>

           <!-- Mensagens -->
           <?php if ($mensagem): ?>
           <div class="alert alert-<?php echo $tipo_mensagem == 'success' ? 'success' : 'error'; ?>">
               <i class="fas fa-<?php echo $tipo_mensagem == 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
               <?php echo $mensagem; ?>
           </div>
           <?php endif; ?>

           <?php if ($acao === 'listar'): ?>
           <!-- Filtros -->
           <div class="filters-container">
               <form method="GET" action="">
                   <input type="hidden" name="acao" value="listar">
                   <div class="filters-grid">
                       <div class="form-group">
                           <label for="filtro_nome">Nome do Atleta</label>
                           <input type="text" class="form-control" id="filtro_nome" name="filtro_nome" 
                                  value="<?php echo htmlspecialchars($filtro_nome); ?>" placeholder="Digite o nome...">
                       </div>
                       <div class="form-group">
                           <label for="filtro_modalidade">Modalidade</label>
                           <select class="form-control" id="filtro_modalidade" name="filtro_modalidade">
                               <option value="">Todas as modalidades</option>
                               <?php foreach ($modalidades as $modalidade): ?>
                               <option value="<?php echo $modalidade; ?>" <?php echo $filtro_modalidade === $modalidade ? 'selected' : ''; ?>>
                                   <?php echo $modalidade; ?>
                               </option>
                               <?php endforeach; ?>
                           </select>
                       </div>
                       <div class="form-group">
                           <label for="filtro_categoria">Categoria</label>
                           <select class="form-control" id="filtro_categoria" name="filtro_categoria">
                               <option value="">Todas as categorias</option>
                               <?php foreach ($categorias as $categoria): ?>
                               <option value="<?php echo $categoria; ?>" <?php echo $filtro_categoria === $categoria ? 'selected' : ''; ?>>
                                   <?php echo $categoria; ?>
                               </option>
                               <?php endforeach; ?>
                           </select>
                       </div>
                       <div class="form-group">
                           <label for="filtro_status">Status</label>
                           <select class="form-control" id="filtro_status" name="filtro_status">
                               <option value="">Todos os status</option>
                               <?php foreach ($status_atleta as $status): ?>
                               <option value="<?php echo $status; ?>" <?php echo $filtro_status === $status ? 'selected' : ''; ?>>
                                   <?php echo $status; ?>
                               </option>
                               <?php endforeach; ?>
                           </select>
                       </div>
                       <div class="form-group">
                           <button type="submit" class="btn btn-primary">
                               <i class="fas fa-search"></i> Filtrar
                           </button>
                           <a href="esporte_atletas.php" class="btn btn-secondary">
                               <i class="fas fa-times"></i> Limpar
                           </a>
                       </div>
                   </div>
               </form>
           </div>

           <!-- Lista de Atletas -->
           <div class="card">
               <div class="card-header">
                   <div class="card-title">
                       <i class="fas fa-list"></i>
                       Lista de Atletas (<?php echo number_format($total_registros); ?> registros)
                   </div>
                   <a href="esporte_atletas.php?acao=adicionar" class="btn btn-success">
                       <i class="fas fa-plus"></i> Novo Atleta
                   </a>
               </div>
               <div class="card-body" style="padding: 0;">
                   <?php if (empty($atletas)): ?>
                   <div style="padding: 40px; text-align: center; color: #7f8c8d;">
                       <i class="fas fa-user-friends" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3;"></i>
                       <h3>Nenhum atleta encontrado</h3>
                       <p>Não há atletas cadastrados com os filtros selecionados.</p>
                       <a href="esporte_atletas.php?acao=adicionar" class="btn btn-primary">
                           <i class="fas fa-plus"></i> Cadastrar Primeiro Atleta
                       </a>
                   </div>
                   <?php else: ?>
                   <div class="table-container">
                       <table class="table">
                           <thead>
                               <tr>
                                   <th>Nome</th>
                                   <th>CPF</th>
                                   <th>Idade</th>
                                   <th>Modalidade</th>
                                   <th>Categoria</th>
                                   <th>Status</th>
                                   <th>Ações</th>
                               </tr>
                           </thead>
                           <tbody>
                               <?php foreach ($atletas as $atleta): ?>
                               <tr>
                                   <td>
                                       <strong><?php echo htmlspecialchars($atleta['atleta_nome']); ?></strong>
                                       <?php if ($atleta['atleta_email']): ?>
                                       <br><small style="color: #7f8c8d;">
                                           <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($atleta['atleta_email']); ?>
                                       </small>
                                       <?php endif; ?>
                                   </td>
                                   <td>
                                       <?php echo formatarCPF($atleta['atleta_cpf']); ?>
                                       <?php if ($atleta['atleta_telefone']): ?>
                                       <br><small style="color: #7f8c8d;">
                                           <i class="fas fa-phone"></i> <?php echo formatarTelefone($atleta['atleta_telefone']); ?>
                                       </small>
                                       <?php endif; ?>
                                   </td>
                                   <td><?php echo calcularIdade($atleta['atleta_data_nascimento']); ?></td>
                                   <td>
                                       <span style="font-weight: 600;"><?php echo htmlspecialchars($atleta['atleta_modalidade_principal']); ?></span>
                                   </td>
                                   <td>
                                       <span class="status-badge" style="background-color: var(--info-color); color: white;">
                                           <?php echo htmlspecialchars($atleta['atleta_categoria']); ?>
                                       </span>
                                   </td>
                                   <td>
                                       <span class="status-badge <?php echo getStatusClass($atleta['atleta_status']); ?>">
                                           <?php echo htmlspecialchars($atleta['atleta_status']); ?>
                                       </span>
                                   </td>
                                   <td>
                                       <div class="table-actions">
                                           <a href="esporte_atletas.php?acao=editar&id=<?php echo $atleta['atleta_id']; ?>" 
                                              class="btn btn-sm btn-warning" title="Editar">
                                               <i class="fas fa-edit"></i>
                                           </a>
                                           <button type="button" class="btn btn-sm btn-danger" 
                                                   onclick="confirmarExclusao(<?php echo $atleta['atleta_id']; ?>, '<?php echo addslashes($atleta['atleta_nome']); ?>')"
                                                   title="Excluir">
                                               <i class="fas fa-trash"></i>
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
                   <div class="pagination">
                       <?php if ($pagina_atual > 1): ?>
                       <a href="?acao=listar&pagina=<?php echo $pagina_atual - 1; ?>&filtro_nome=<?php echo urlencode($filtro_nome); ?>&filtro_modalidade=<?php echo urlencode($filtro_modalidade); ?>&filtro_categoria=<?php echo urlencode($filtro_categoria); ?>&filtro_status=<?php echo urlencode($filtro_status); ?>">
                           <i class="fas fa-chevron-left"></i> Anterior
                       </a>
                       <?php endif; ?>

                       <?php
                       $inicio = max(1, $pagina_atual - 2);
                       $fim = min($total_paginas, $pagina_atual + 2);
                       
                       for ($i = $inicio; $i <= $fim; $i++):
                       ?>
                       <?php if ($i == $pagina_atual): ?>
                       <span class="current"><?php echo $i; ?></span>
                       <?php else: ?>
                       <a href="?acao=listar&pagina=<?php echo $i; ?>&filtro_nome=<?php echo urlencode($filtro_nome); ?>&filtro_modalidade=<?php echo urlencode($filtro_modalidade); ?>&filtro_categoria=<?php echo urlencode($filtro_categoria); ?>&filtro_status=<?php echo urlencode($filtro_status); ?>">
                           <?php echo $i; ?>
                       </a>
                       <?php endif; ?>
                       <?php endfor; ?>

                       <?php if ($pagina_atual < $total_paginas): ?>
                       <a href="?acao=listar&pagina=<?php echo $pagina_atual + 1; ?>&filtro_nome=<?php echo urlencode($filtro_nome); ?>&filtro_modalidade=<?php echo urlencode($filtro_modalidade); ?>&filtro_categoria=<?php echo urlencode($filtro_categoria); ?>&filtro_status=<?php echo urlencode($filtro_status); ?>">
                           Próximo <i class="fas fa-chevron-right"></i>
                       </a>
                       <?php endif; ?>
                   </div>
                   <?php endif; ?>
                   <?php endif; ?>
               </div>
           </div>

           <?php elseif ($acao === 'adicionar' || $acao === 'editar'): ?>
           <!-- Formulário de Cadastro/Edição -->
           <div class="card">
               <div class="card-header">
                   <div class="card-title">
                       <i class="fas fa-<?php echo $acao === 'adicionar' ? 'plus' : 'edit'; ?>"></i>
                       <?php echo $acao === 'adicionar' ? 'Cadastrar Novo Atleta' : 'Editar Atleta'; ?>
                   </div>
               </div>
               <div class="card-body">
                   <form method="POST" action="">
                       <input type="hidden" name="acao" value="<?php echo $acao; ?>">
                       <?php if ($acao === 'editar'): ?>
                       <input type="hidden" name="atleta_id" value="<?php echo $atleta_id; ?>">
                       <?php endif; ?>

                       <!-- Dados Pessoais -->
                       <h3 style="color: var(--primary-color); margin-bottom: 20px; border-bottom: 2px solid var(--secondary-color); padding-bottom: 10px;">
                           <i class="fas fa-user"></i> Dados Pessoais
                       </h3>
                       
                       <div class="form-grid">
                           <div class="form-group">
                               <label for="atleta_nome" class="required">Nome Completo</label>
                               <input type="text" class="form-control" id="atleta_nome" name="atleta_nome" 
                                      value="<?php echo htmlspecialchars($atleta_atual['atleta_nome'] ?? ''); ?>" required>
                           </div>

                           <div class="form-group">
                               <label for="atleta_cpf">CPF</label>
                               <input type="text" class="form-control" id="atleta_cpf" name="atleta_cpf" 
                                      value="<?php echo formatarCPF($atleta_atual['atleta_cpf'] ?? ''); ?>" 
                                      placeholder="000.000.000-00" maxlength="14">
                           </div>

                           <div class="form-group">
                               <label for="atleta_rg">RG</label>
                               <input type="text" class="form-control" id="atleta_rg" name="atleta_rg" 
                                      value="<?php echo htmlspecialchars($atleta_atual['atleta_rg'] ?? ''); ?>">
                           </div>

                           <div class="form-group">
                               <label for="atleta_data_nascimento" class="required">Data de Nascimento</label>
                               <input type="date" class="form-control" id="atleta_data_nascimento" name="atleta_data_nascimento" 
                                      value="<?php echo $atleta_atual['atleta_data_nascimento'] ?? ''; ?>" required>
                           </div>

                           <div class="form-group">
                               <label for="atleta_genero" class="required">Gênero</label>
                               <select class="form-control" id="atleta_genero" name="atleta_genero" required>
                                   <option value="">Selecione...</option>
                                   <?php foreach ($generos as $genero): ?>
                                   <option value="<?php echo $genero; ?>" 
                                           <?php echo ($atleta_atual['atleta_genero'] ?? '') === $genero ? 'selected' : ''; ?>>
                                       <?php echo $genero; ?>
                                   </option>
                                   <?php endforeach; ?>
                               </select>
                           </div>

                           <div class="form-group">
                               <label for="atleta_telefone">Telefone</label>
                               <input type="text" class="form-control" id="atleta_telefone" name="atleta_telefone" 
                                      value="<?php echo htmlspecialchars($atleta_atual['atleta_telefone'] ?? ''); ?>" 
                                      placeholder="(00) 00000-0000">
                           </div>

                           <div class="form-group">
                                <label for="atleta_celular" class="required">Celular</label>
                                <input type="text" class="form-control" id="atleta_celular" name="atleta_celular" 
                                    value="<?php echo htmlspecialchars($atleta_atual['atleta_celular'] ?? ''); ?>" 
                                    placeholder="(00) 00000-0000" required>
                            </div>

                           <div class="form-group">
                               <label for="atleta_email">E-mail</label>
                               <input type="email" class="form-control" id="atleta_email" name="atleta_email" 
                                      value="<?php echo htmlspecialchars($atleta_atual['atleta_email'] ?? ''); ?>">
                           </div>
                       </div>

                       <!-- Endereço -->
                       <h3 style="color: var(--primary-color); margin: 30px 0 20px; border-bottom: 2px solid var(--secondary-color); padding-bottom: 10px;">
                           <i class="fas fa-map-marker-alt"></i> Endereço
                       </h3>
                       
                       <div class="form-grid">
                           <div class="form-group" style="grid-column: 1 / -1;">
                               <label for="atleta_endereco">Endereço Completo</label>
                               <input type="text" class="form-control" id="atleta_endereco" name="atleta_endereco" 
                                      value="<?php echo htmlspecialchars($atleta_atual['atleta_endereco'] ?? ''); ?>" 
                                      placeholder="Rua, número, complemento">
                           </div>

                           <div class="form-group">
                               <label for="atleta_bairro">Bairro</label>
                               <input type="text" class="form-control" id="atleta_bairro" name="atleta_bairro" 
                                      value="<?php echo htmlspecialchars($atleta_atual['atleta_bairro'] ?? ''); ?>">
                           </div>

                           <div class="form-group">
                               <label for="atleta_cep">CEP</label>
                               <input type="text" class="form-control" id="atleta_cep" name="atleta_cep" 
                                      value="<?php echo htmlspecialchars($atleta_atual['atleta_cep'] ?? ''); ?>" 
                                      placeholder="00000-000" maxlength="9">
                           </div>

                           <div class="form-group">
                                <label for="atleta_cidade">Cidade</label>
                                <input type="text" class="form-control" id="atleta_cidade" name="atleta_cidade" 
                                    value="<?php echo htmlspecialchars($atleta_atual['atleta_cidade'] ?? ''); ?>">
                            </div>
                       </div>

                       <!-- Dados Esportivos -->
                       <h3 style="color: var(--primary-color); margin: 30px 0 20px; border-bottom: 2px solid var(--secondary-color); padding-bottom: 10px;">
                           <i class="fas fa-running"></i> Dados Esportivos
                       </h3>
                       
                       <div class="form-grid">
                           <div class="form-group">
                               <label for="atleta_modalidade_principal" class="required">Modalidade Principal</label>
                               <select class="form-control" id="atleta_modalidade_principal" name="atleta_modalidade_principal" required>
                                   <option value="">Selecione a modalidade...</option>
                                   <?php foreach ($modalidades as $modalidade): ?>
                                   <option value="<?php echo $modalidade; ?>" 
                                           <?php echo ($atleta_atual['atleta_modalidade_principal'] ?? '') === $modalidade ? 'selected' : ''; ?>>
                                       <?php echo $modalidade; ?>
                                   </option>
                                   <?php endforeach; ?>
                               </select>
                           </div>

                           <div class="form-group">
                               <label for="atleta_categoria" class="required">Categoria</label>
                               <select class="form-control" id="atleta_categoria" name="atleta_categoria" required>
                                   <option value="">Selecione a categoria...</option>
                                   <?php foreach ($categorias as $categoria): ?>
                                   <option value="<?php echo $categoria; ?>" 
                                           <?php echo ($atleta_atual['atleta_categoria'] ?? '') === $categoria ? 'selected' : ''; ?>>
                                       <?php echo $categoria; ?>
                                   </option>
                                   <?php endforeach; ?>
                               </select>
                           </div>

                           <div class="form-group">
                               <label for="atleta_status" class="required">Status</label>
                               <select class="form-control" id="atleta_status" name="atleta_status" required>
                                   <?php foreach ($status_atleta as $status): ?>
                                   <option value="<?php echo $status; ?>" 
                                           <?php echo ($atleta_atual['atleta_status'] ?? 'ATIVO') === $status ? 'selected' : ''; ?>>
                                       <?php echo $status; ?>
                                   </option>
                                   <?php endforeach; ?>
                               </select>
                           </div>
                       </div>

                       <!-- Dados do Responsável (para menores) -->
                       <h3 style="color: var(--primary-color); margin: 30px 0 20px; border-bottom: 2px solid var(--secondary-color); padding-bottom: 10px;">
                           <i class="fas fa-user-shield"></i> Responsável Legal (se menor de idade)
                       </h3>
                       
                       <div class="form-grid">
                           <div class="form-group">
                               <label for="atleta_nome_responsavel">Nome do Responsável</label>
                               <input type="text" class="form-control" id="atleta_nome_responsavel" name="atleta_nome_responsavel" 
                                      value="<?php echo htmlspecialchars($atleta_atual['atleta_nome_responsavel'] ?? ''); ?>">
                           </div>

                           <div class="form-group">
                               <label for="atleta_telefone_responsavel">Telefone do Responsável</label>
                               <input type="text" class="form-control" id="atleta_telefone_responsavel" name="atleta_telefone_responsavel" 
                                      value="<?php echo htmlspecialchars($atleta_atual['atleta_telefone_responsavel'] ?? ''); ?>" 
                                      placeholder="(00) 00000-0000">
                           </div>
                       </div>

                       <!-- Observações -->
                       <div class="form-group">
                           <label for="atleta_observacoes">Observações</label>
                           <textarea class="form-control" id="atleta_observacoes" name="atleta_observacoes" rows="4" 
                                     placeholder="Informações adicionais sobre o atleta..."><?php echo htmlspecialchars($atleta_atual['atleta_observacoes'] ?? ''); ?></textarea>
                       </div>

                       <div class="action-buttons">
                           <a href="esporte_atletas.php" class="btn btn-secondary">
                               <i class="fas fa-times"></i> Cancelar
                           </a>
                           <button type="submit" class="btn btn-success">
                               <i class="fas fa-save"></i> <?php echo $acao === 'adicionar' ? 'Cadastrar Atleta' : 'Salvar Alterações'; ?>
                           </button>
                       </div>
                   </form>
               </div>
           </div>
           <?php endif; ?>
       </div>
   </div>

   <!-- Modal de Confirmação de Exclusão -->
   <div id="modalExclusao" class="modal">
       <div class="modal-content">
           <div class="modal-header">
               <h3 class="modal-title">
                   <i class="fas fa-exclamation-triangle"></i>
                   Confirmar Exclusão
               </h3>
               <span class="close" onclick="fecharModal()">&times;</span>
           </div>
           <div class="modal-body">
               <p>Tem certeza que deseja excluir o atleta <strong id="nomeAtleta"></strong>?</p>
               <p style="color: var(--danger-color); font-weight: 600;">
                   <i class="fas fa-warning"></i> Esta ação não pode ser desfeita!
               </p>
           </div>
           <div class="modal-footer">
               <button type="button" class="btn btn-secondary" onclick="fecharModal()">
                   <i class="fas fa-times"></i> Cancelar
               </button>
               <form id="formExclusao" method="POST" style="display: inline;">
                   <input type="hidden" name="acao" value="excluir">
                   <input type="hidden" name="atleta_id" id="atletaIdExclusao">
                   <button type="submit" class="btn btn-danger">
                       <i class="fas fa-trash"></i> Confirmar Exclusão
                   </button>
               </form>
           </div>
       </div>
   </div>

   <script>
       // Toggle sidebar para mobile
       document.addEventListener('DOMContentLoaded', function() {
           const sidebar = document.getElementById('sidebar');
           const mobileToggle = document.querySelector('.mobile-toggle');
           const toggleBtn = document.querySelector('.toggle-btn');

           // Toggle para mobile
           if (mobileToggle) {
               mobileToggle.addEventListener('click', function() {
                   sidebar.classList.toggle('show');
               });
           }

           // Toggle para desktop
           if (toggleBtn) {
               toggleBtn.addEventListener('click', function() {
                   sidebar.classList.toggle('collapsed');
               });
           }

           // Fechar sidebar ao clicar fora (mobile)
           document.addEventListener('click', function(e) {
               if (window.innerWidth <= 768) {
                   if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                       sidebar.classList.remove('show');
                   }
               }
           });

           // Submenu toggle
           const menuItems = document.querySelectorAll('.menu-link');
           menuItems.forEach(item => {
               item.addEventListener('click', function(e) {
                   const parentItem = this.closest('.menu-item');
                   const submenu = parentItem.querySelector('.submenu');
                   
                   if (submenu) {
                       e.preventDefault();
                       parentItem.classList.toggle('open');
                   }
               });
           });

           // Highlight active menu
           const currentPage = window.location.pathname.split('/').pop();
           const menuLinks = document.querySelectorAll('.menu-link, .submenu-link');
           
           menuLinks.forEach(link => {
               const href = link.getAttribute('href');
               if (href && href.includes(currentPage)) {
                   link.classList.add('active');
                   
                   const parentMenuItem = link.closest('.menu-item');
                   if (parentMenuItem && parentMenuItem.querySelector('.submenu')) {
                       parentMenuItem.classList.add('open');
                   }
               }
           });

           // Máscaras para campos
           aplicarMascaras();

           // Validação em tempo real
           aplicarValidacoes();

           // Auto preenchimento de responsável para menores
           verificarIdadeResponsavel();
       });

       // Função para aplicar máscaras nos campos
       function aplicarMascaras() {
           // Máscara CPF
           const cpfInput = document.getElementById('atleta_cpf');
           if (cpfInput) {
               cpfInput.addEventListener('input', function(e) {
                   let value = e.target.value.replace(/\D/g, '');
                   value = value.replace(/(\d{3})(\d)/, '$1.$2');
                   value = value.replace(/(\d{3})(\d)/, '$1.$2');
                   value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
                   e.target.value = value;
               });
           }

           // Máscara Telefone
           const telefoneInputs = document.querySelectorAll('#atleta_telefone, #atleta_telefone_responsavel');
           telefoneInputs.forEach(input => {
               input.addEventListener('input', function(e) {
                   let value = e.target.value.replace(/\D/g, '');
                   if (value.length <= 10) {
                       value = value.replace(/(\d{2})(\d)/, '($1) $2');
                       value = value.replace(/(\d{4})(\d)/, '$1-$2');
                   } else {
                       value = value.replace(/(\d{2})(\d)/, '($1) $2');
                       value = value.replace(/(\d{5})(\d)/, '$1-$2');
                   }
                   e.target.value = value;
               });
           });

           // Máscara CEP
           const cepInput = document.getElementById('atleta_cep');
           if (cepInput) {
               cepInput.addEventListener('input', function(e) {
                   let value = e.target.value.replace(/\D/g, '');
                   value = value.replace(/(\d{5})(\d)/, '$1-$2');
                   e.target.value = value;
               });
           }
       }

       // Função para aplicar validações
       function aplicarValidacoes() {
           // Validação CPF
           const cpfInput = document.getElementById('atleta_cpf');
           if (cpfInput) {
               cpfInput.addEventListener('blur', function() {
                   const cpf = this.value.replace(/\D/g, '');
                   if (cpf && !validarCPF(cpf)) {
                       this.style.borderColor = 'var(--danger-color)';
                       mostrarTooltip(this, 'CPF inválido');
                   } else {
                       this.style.borderColor = '';
                       removerTooltip(this);
                   }
               });
           }

            const celularInput = document.getElementById('atleta_celular');
            if (celularInput) {
                celularInput.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\D/g, '');
                    if (value.length <= 10) {
                        value = value.replace(/(\d{2})(\d)/, '($1) $2');
                        value = value.replace(/(\d{4})(\d)/, '$1-$2');
                    } else {
                        value = value.replace(/(\d{2})(\d)/, '($1) $2');
                        value = value.replace(/(\d{5})(\d)/, '$1-$2');
                    }
                    e.target.value = value;
                });
            }

           // Validação Email
           const emailInput = document.getElementById('atleta_email');
           if (emailInput) {
               emailInput.addEventListener('blur', function() {
                   const email = this.value;
                   if (email && !validarEmail(email)) {
                       this.style.borderColor = 'var(--danger-color)';
                       mostrarTooltip(this, 'E-mail inválido');
                   } else {
                       this.style.borderColor = '';
                       removerTooltip(this);
                   }
               });
           }
       }

       // Função para verificar idade e mostrar/ocultar campos do responsável
       function verificarIdadeResponsavel() {
           const dataNascInput = document.getElementById('atleta_data_nascimento');
           if (dataNascInput) {
               dataNascInput.addEventListener('change', function() {
                   const dataNasc = new Date(this.value);
                   const hoje = new Date();
                   const idade = hoje.getFullYear() - dataNasc.getFullYear();
                   const mesAtual = hoje.getMonth();
                   const mesNasc = dataNasc.getMonth();
                   
                   let idadeFinal = idade;
                   if (mesAtual < mesNasc || (mesAtual === mesNasc && hoje.getDate() < dataNasc.getDate())) {
                       idadeFinal--;
                   }

                   const responsavelInputs = document.querySelectorAll('#atleta_nome_responsavel, #atleta_telefone_responsavel');
                   const responsavelSection = responsavelInputs[0]?.closest('h3')?.nextElementSibling;

                   if (idadeFinal < 18) {
                       // Menor de idade - destacar campos do responsável
                       responsavelInputs.forEach(input => {
                           input.style.backgroundColor = '#fff3cd';
                           input.required = true;
                       });
                       if (responsavelSection) {
                           responsavelSection.style.backgroundColor = '#fff3cd';
                           responsavelSection.style.padding = '15px';
                           responsavelSection.style.borderRadius = '8px';
                           responsavelSection.style.border = '2px solid var(--warning-color)';
                       }
                   } else {
                       // Maior de idade - remover destaque
                       responsavelInputs.forEach(input => {
                           input.style.backgroundColor = '';
                           input.required = false;
                       });
                       if (responsavelSection) {
                           responsavelSection.style.backgroundColor = '';
                           responsavelSection.style.padding = '';
                           responsavelSection.style.borderRadius = '';
                           responsavelSection.style.border = '';
                       }
                   }
               });

               // Verificar na inicialização se está editando
               if (dataNascInput.value) {
                   dataNascInput.dispatchEvent(new Event('change'));
               }
           }
       }

       // Função para validar CPF
       function validarCPF(cpf) {
           cpf = cpf.replace(/\D/g, '');
           if (cpf.length !== 11) return false;
           if (/^(\d)\1{10}$/.test(cpf)) return false;
           
           let soma = 0;
           for (let i = 0; i < 9; i++) {
               soma += parseInt(cpf.charAt(i)) * (10 - i);
           }
           let resto = (soma * 10) % 11;
           if (resto === 10 || resto === 11) resto = 0;
           if (resto !== parseInt(cpf.charAt(9))) return false;
           
           soma = 0;
           for (let i = 0; i < 10; i++) {
               soma += parseInt(cpf.charAt(i)) * (11 - i);
           }
           resto = (soma * 10) % 11;
           if (resto === 10 || resto === 11) resto = 0;
           return resto === parseInt(cpf.charAt(10));
       }

       // Função para validar email
       function validarEmail(email) {
           const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
           return regex.test(email);
       }

       // Funções para tooltip de erro
       function mostrarTooltip(element, message) {
           removerTooltip(element);
           const tooltip = document.createElement('div');
           tooltip.className = 'error-tooltip';
           tooltip.textContent = message;
           tooltip.style.cssText = `
               position: absolute;
               background: var(--danger-color);
               color: white;
               padding: 5px 10px;
               border-radius: 4px;
               font-size: 0.8rem;
               z-index: 1000;
               margin-top: 5px;
               white-space: nowrap;
           `;
           element.parentNode.appendChild(tooltip);
           element.setAttribute('data-has-tooltip', 'true');
       }

       function removerTooltip(element) {
           if (element.getAttribute('data-has-tooltip')) {
               const tooltip = element.parentNode.querySelector('.error-tooltip');
               if (tooltip) {
                   tooltip.remove();
               }
               element.removeAttribute('data-has-tooltip');
           }
       }

       // Função para confirmar exclusão
       function confirmarExclusao(atletaId, nomeAtleta) {
           document.getElementById('atletaIdExclusao').value = atletaId;
           document.getElementById('nomeAtleta').textContent = nomeAtleta;
           document.getElementById('modalExclusao').style.display = 'block';
       }

       // Função para fechar modal
       function fecharModal() {
           document.getElementById('modalExclusao').style.display = 'none';
       }

       // Fechar modal ao clicar fora
       window.onclick = function(event) {
           const modal = document.getElementById('modalExclusao');
           if (event.target === modal) {
               fecharModal();
           }
       }

       // Auto-save em rascunho (opcional)
       function salvarRascunho() {
           const form = document.querySelector('form');
           if (form) {
               const formData = new FormData(form);
               const data = {};
               for (let [key, value] of formData.entries()) {
                   data[key] = value;
               }
               localStorage.setItem('atleta_rascunho', JSON.stringify(data));
           }
       }

       function carregarRascunho() {
           const rascunho = localStorage.getItem('atleta_rascunho');
           if (rascunho && document.querySelector('form')) {
               const data = JSON.parse(rascunho);
               for (let [key, value] of Object.entries(data)) {
                   const input = document.querySelector(`[name="${key}"]`);
                   if (input && !input.value) {
                       input.value = value;
                   }
               }
           }
       }

       function limparRascunho() {
           localStorage.removeItem('atleta_rascunho');
       }

       // Auto-save a cada 30 segundos
       if (document.querySelector('form')) {
           setInterval(salvarRascunho, 30000);
           
           // Carregar rascunho na inicialização (apenas para novo cadastro)
           if (window.location.search.includes('acao=adicionar')) {
               carregarRascunho();
           }
           
           // Limpar rascunho após envio
           document.querySelector('form').addEventListener('submit', limparRascunho);
       }

       // Busca por CEP (opcional - requer API)
       function buscarCEP() {
           const cepInput = document.getElementById('atleta_cep');
           if (cepInput) {
               cepInput.addEventListener('blur', function() {
                   const cep = this.value.replace(/\D/g, '');
                   if (cep.length === 8) {
                       fetch(`https://viacep.com.br/ws/${cep}/json/`)
                           .then(response => response.json())
                           .then(data => {
                               if (!data.erro) {
                                   const enderecoInput = document.getElementById('atleta_endereco');
                                   const bairroInput = document.getElementById('atleta_bairro');
                                   
                                   if (enderecoInput && !enderecoInput.value) {
                                       enderecoInput.value = data.logradouro || '';
                                   }
                                   if (bairroInput && !bairroInput.value) {
                                       bairroInput.value = data.bairro || '';
                                   }
                               }
                           })
                           .catch(error => {
                               console.log('Erro ao buscar CEP:', error);
                           });
                   }
               });
           }
       }

       // Inicializar busca por CEP
       buscarCEP();
   </script>
</body>
</html>
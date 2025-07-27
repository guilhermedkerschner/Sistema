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

// Configuração de módulos
$configuracao_modulos = [
    'produtores' => [
        'icone' => 'fas fa-user-tie',
        'titulo' => 'Produtores',
        'tabela' => 'tb_cad_produtores',
        'campos_busca' => ['cad_pro_nome', 'cad_pro_cpf'],
        'tem_filtros' => true,
        'filtros_especiais' => ['comunidade', 'status']
    ],
    'bancos' => [
        'icone' => 'fas fa-university',
        'titulo' => 'Bancos',
        'tabela' => 'tb_cad_bancos',
        'campos_busca' => ['ban_nome', 'ban_codigo'],
        'tem_filtros' => false,
        'filtros_especiais' => ['status']
    ],
    'comunidades' => [
        'icone' => 'fas fa-home',
        'titulo' => 'Comunidades',
        'tabela' => 'tb_cad_comunidades',
        'campos_busca' => [],
        'tem_filtros' => false
    ],
    'servicos' => [
        'icone' => 'fas fa-cogs',
        'titulo' => 'Serviços',
        'tabela' => 'tb_cad_servicos',
        'campos_busca' => ['ser_nome'],
        'tem_filtros' => false,
        'filtros_especiais' => ['secretaria', 'status']
    ],
    'maquinas' => [
        'icone' => 'fas fa-tractor',
        'titulo' => 'Máquinas',
        'tabela' => 'tb_cad_maquinas',
        'campos_busca' => ['maq_nome'],
        'tem_filtros' => false,
        'filtros_especiais' => ['disponibilidade', 'status']
    ],
    'veterinarios' => [
        'icone' => 'fas fa-user-md',
        'titulo' => 'Veterinários',
        'tabela' => 'tb_cad_veterinarios',
        'campos_busca' => ['vet_nome', 'vet_cpf', 'vet_crmv'],
        'tem_filtros' => false,
        'filtros_especiais' => ['status']
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
$aba_ativa = $_GET['aba'] ?? 'produtores';
$config_aba = $configuracao_modulos[$aba_ativa] ?? $configuracao_modulos['produtores'];

// Buscar dados auxiliares
$comunidades = [];
$bancos = [];
$secretarias = [];

try {
    // Comunidades
    $stmt = $conn->prepare("SELECT com_id, com_nome FROM tb_cad_comunidades WHERE com_status = 'ativo' ORDER BY com_nome");
    $stmt->execute();
    $comunidades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Bancos
    $stmt = $conn->prepare("SELECT ban_id, ban_codigo, ban_nome FROM tb_cad_bancos WHERE ban_status = 'ativo' ORDER BY ban_nome");
    $stmt->execute();
    $bancos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Secretarias
    $stmt = $conn->prepare("SELECT sec_id, sec_nome FROM tb_cad_secretarias WHERE sec_status = 'ativo' ORDER BY sec_nome");
    $stmt->execute();
    $secretarias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar dados auxiliares: " . $e->getMessage());
}

// Função para buscar dados de qualquer módulo
function buscarDadosModulo($conn, $modulo, $filtros = []) {
    $dados = [];
    
    switch ($modulo) {
        case 'produtores':
            $dados = buscarProdutores($conn, $filtros);
            break;
        case 'bancos':
            $dados = buscarBancos($conn, $filtros);
            break;
        case 'comunidades':
            $dados = buscarComunidades($conn, $filtros);
            break;
        case 'servicos':
            $dados = buscarServicos($conn, $filtros);
            break;
        case 'maquinas':
            $dados = buscarMaquinas($conn, $filtros);
            break;
        case 'veterinarios':
            $dados = buscarVeterinarios($conn, $filtros);
            break;
    }
    
    return $dados;
}

function buscarProdutores($conn, $filtros) {
    $sql = "
        SELECT p.*, c.com_nome, b.ban_nome, b.ban_codigo, u.usuario_nome as cadastrado_por
        FROM tb_cad_produtores p
        LEFT JOIN tb_cad_comunidades c ON p.cad_pro_comunidade_id = c.com_id
        LEFT JOIN tb_cad_bancos b ON p.cad_pro_banco_id = b.ban_id
        LEFT JOIN tb_usuarios_sistema u ON p.cad_pro_usuario_cadastro = u.usuario_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($filtros['busca'])) {
        $sql .= " AND (p.cad_pro_nome LIKE :busca OR p.cad_pro_cpf LIKE :busca_cpf)";
        $params[':busca'] = '%' . $filtros['busca'] . '%';
        $params[':busca_cpf'] = '%' . preg_replace('/\D/', '', $filtros['busca']) . '%';
    }
    
    if (!empty($filtros['comunidade'])) {
        $sql .= " AND p.cad_pro_comunidade_id = :comunidade";
        $params[':comunidade'] = $filtros['comunidade'];
    }
    
    if (!empty($filtros['status'])) {
        $sql .= " AND p.cad_pro_status = :status";
        $params[':status'] = $filtros['status'];
    } else {
        $sql .= " AND p.cad_pro_status = 'ativo'";
    }
    
    $sql .= " ORDER BY p.cad_pro_nome";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarBancos($conn, $filtros) {
    $sql = "
        SELECT b.*, 
               COALESCE(u.usuario_nome, 'Sistema') as cadastrado_por,
               COALESCE(b.ban_data_cadastro, NOW()) as ban_data_cadastro,
               COUNT(p.cad_pro_id) as total_produtores
        FROM tb_cad_bancos b
        LEFT JOIN tb_usuarios_sistema u ON b.ban_usuario_cadastro = u.usuario_id
        LEFT JOIN tb_cad_produtores p ON b.ban_id = p.cad_pro_banco_id AND p.cad_pro_status = 'ativo'
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($filtros['busca'])) {
        $sql .= " AND (b.ban_nome LIKE :busca OR b.ban_codigo LIKE :busca)";
        $params[':busca'] = '%' . $filtros['busca'] . '%';
    }
    
    if (!empty($filtros['status'])) {
        $sql .= " AND b.ban_status = :status";
        $params[':status'] = $filtros['status'];
    } else {
        $sql .= " AND COALESCE(b.ban_status, 'ativo') = 'ativo'";
    }
    
    $sql .= " GROUP BY b.ban_id ORDER BY b.ban_nome";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarComunidades($conn, $filtros) {
    $sql = "
        SELECT c.*, 
               COALESCE(u.usuario_nome, 'Sistema') as cadastrado_por,
               COUNT(p.cad_pro_id) as total_produtores
        FROM tb_cad_comunidades c
        LEFT JOIN tb_usuarios_sistema u ON c.com_usuario_cadastro = u.usuario_id
        LEFT JOIN tb_cad_produtores p ON c.com_id = p.cad_pro_comunidade_id AND p.cad_pro_status = 'ativo'
        WHERE c.com_status = 'ativo'
        GROUP BY c.com_id
        ORDER BY c.com_nome
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarServicos($conn, $filtros) {
    $sql = "
        SELECT s.*, sec.sec_nome, 
               COALESCE(u.usuario_nome, 'Sistema') as cadastrado_por
        FROM tb_cad_servicos s
        LEFT JOIN tb_cad_secretarias sec ON s.ser_secretaria_id = sec.sec_id
        LEFT JOIN tb_usuarios_sistema u ON s.ser_usuario_cadastro = u.usuario_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($filtros['busca'])) {
        $sql .= " AND s.ser_nome LIKE :busca";
        $params[':busca'] = '%' . $filtros['busca'] . '%';
    }
    
    if (!empty($filtros['secretaria'])) {
        $sql .= " AND s.ser_secretaria_id = :secretaria";
        $params[':secretaria'] = $filtros['secretaria'];
    }
    
    if (!empty($filtros['status'])) {
        $sql .= " AND s.ser_status = :status";
        $params[':status'] = $filtros['status'];
    } else {
        $sql .= " AND s.ser_status = 'ativo'";
    }
    
    $sql .= " ORDER BY s.ser_nome";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarMaquinas($conn, $filtros) {
    $sql = "
        SELECT m.*, 
               COALESCE(u.usuario_nome, 'Sistema') as cadastrado_por
        FROM tb_cad_maquinas m
        LEFT JOIN tb_usuarios_sistema u ON m.maq_usuario_cadastro = u.usuario_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($filtros['busca'])) {
        $sql .= " AND m.maq_nome LIKE :busca";
        $params[':busca'] = '%' . $filtros['busca'] . '%';
    }
    
    if (!empty($filtros['disponibilidade'])) {
        $sql .= " AND m.maq_disponibilidade = :disponibilidade";
        $params[':disponibilidade'] = $filtros['disponibilidade'];
    }
    
    if (!empty($filtros['status'])) {
        $sql .= " AND m.maq_status = :status";
        $params[':status'] = $filtros['status'];
    } else {
        $sql .= " AND m.maq_status = 'ativo'";
    }
    
    $sql .= " ORDER BY m.maq_nome";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarVeterinarios($conn, $filtros) {
    $sql = "
        SELECT v.*, 
               COALESCE(u.usuario_nome, 'Sistema') as cadastrado_por
        FROM tb_cad_veterinarios v
        LEFT JOIN tb_usuarios_sistema u ON v.vet_usuario_cadastro = u.usuario_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($filtros['busca'])) {
        $sql .= " AND (v.vet_nome LIKE :busca OR v.vet_cpf LIKE :busca OR v.vet_crmv LIKE :busca)";
        $params[':busca'] = '%' . $filtros['busca'] . '%';
    }
    
    if (!empty($filtros['status'])) {
        $sql .= " AND v.vet_status = :status";
        $params[':status'] = $filtros['status'];
    } else {
        $sql .= " AND v.vet_status = 'ativo'";
    }
    
    $sql .= " ORDER BY v.vet_nome";
    
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
    'comunidade' => $_GET['comunidade'] ?? '',
    'secretaria' => $_GET['secretaria'] ?? '',
    'disponibilidade' => $_GET['disponibilidade'] ?? '',
    'status' => $_GET['status'] ?? ''
];

// Buscar dados da aba ativa
$dados_aba = buscarDadosModulo($conn, $aba_ativa, $filtros);

// Mensagens de feedback
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
        /* Estilos base */
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

        .cadastros-container {
            padding: 30px;
            width: 100%;
            max-width: none;
            box-sizing: border-box;
        }

        /* Header da página */
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

        .filtros-grid {
            display: grid;
            gap: 20px;
            align-items: end;
        }

        .filtros-grid.produtores {
            grid-template-columns: 2fr 1fr 1fr auto;
        }

        .filtros-grid.bancos,
        .filtros-grid.servicos,
        .filtros-grid.maquinas,
        .filtros-grid.veterinarios {
            grid-template-columns: 2fr 1fr auto;
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
           border-color: #4169E1;
           background: white;
           box-shadow: 0 0 0 3px rgba(65, 105, 225, 0.1);
       }

       .btn-filtrar,
       .btn-limpar {
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
       }

       .btn-filtrar {
           background: #4169E1;
           color: white;
       }

       .btn-filtrar:hover {
           background: #3557c4;
       }

       .btn-limpar {
           background: #6b7280;
           color: white;
           margin-left: 10px;
       }

       .btn-limpar:hover {
           background: #4b5563;
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
           box-sizing: border-box;
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

       /* Lista de registros */
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
           background: #4169E1;
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

       .status-disponivel {
           background: #d1fae5;
           color: #065f46;
       }

       .status-manutencao {
           background: #fef3c7;
           color: #92400e;
       }

       .status-indisponivel {
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

       /* Responsividade */
       @media (max-width: 1200px) {
           .filtros-grid {
               grid-template-columns: 1fr !important;
               gap: 15px;
           }
           
           .filtros-header {
               flex-direction: column;
               align-items: flex-start;
               gap: 15px;
           }
       }

       @media (max-width: 768px) {
           .main-content {
               margin-left: 0;
           }
           
           .cadastros-container {
               padding: 20px;
           }
           
           .tab-item {
               min-width: auto;
               flex: 1 1 calc(50% - 4px);
               font-size: 12px;
               padding: 12px 15px;
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
               <?php foreach ($configuracao_modulos as $key => $config): ?>
                   <a href="?aba=<?= $key ?>" class="tab-item <?= $aba_ativa === $key ? 'active' : '' ?>">
                       <i class="<?= $config['icone'] ?>"></i>
                       <?= $config['titulo'] ?>
                   </a>
               <?php endforeach; ?>
           </div>

           <!-- Conteúdo Dinâmico -->
           <div class="tab-content active">
               
               <!-- Botão Cadastrar -->
               <button type="button" class="btn-cadastrar" onclick="toggleFormulario('<?= $aba_ativa ?>')">
                   <i class="fas fa-plus"></i>
                   Cadastrar Novo <?= rtrim($config_aba['titulo'], 's') ?>
               </button>

               <!-- Filtros de Pesquisa (se habilitado) -->
               <?php if ($config_aba['tem_filtros']): ?>
               <div class="filtros-container">
                   <div class="filtros-header">
                       <h3 class="filtros-title">
                           <i class="fas fa-search"></i>
                           Filtros de Pesquisa
                       </h3>
                   </div>
                   
                   <form method="GET" action="" id="formFiltros">
                       <input type="hidden" name="aba" value="<?= $aba_ativa ?>">
                       <div class="filtros-grid <?= $aba_ativa ?>">
                           
                           <?php if (!empty($config_aba['campos_busca'])): ?>
                           <div class="filtro-group">
                               <label for="busca">Buscar</label>
                               <input type="text" 
                                      id="busca" 
                                      name="busca" 
                                      class="filtro-input"
                                      placeholder="Digite para buscar..."
                                      value="<?= htmlspecialchars($filtros['busca']) ?>">
                           </div>
                           <?php endif; ?>
                           
                           <?php if (in_array('comunidade', $config_aba['filtros_especiais'] ?? [])): ?>
                           <div class="filtro-group">
                               <label for="comunidade">Comunidade</label>
                               <select id="comunidade" name="comunidade" class="filtro-select">
                                   <option value="">Todas as comunidades</option>
                                   <?php foreach ($comunidades as $comunidade): ?>
                                       <option value="<?= $comunidade['com_id'] ?>" 
                                               <?= $filtros['comunidade'] == $comunidade['com_id'] ? 'selected' : '' ?>>
                                           <?= htmlspecialchars($comunidade['com_nome']) ?>
                                       </option>
                                   <?php endforeach; ?>
                               </select>
                           </div>
                           <?php endif; ?>
                           
                           <?php if (in_array('secretaria', $config_aba['filtros_especiais'] ?? [])): ?>
                           <div class="filtro-group">
                               <label for="secretaria">Secretaria</label>
                               <select id="secretaria" name="secretaria" class="filtro-select">
                                   <option value="">Todas as secretarias</option>
                                   <?php foreach ($secretarias as $secretaria): ?>
                                       <option value="<?= $secretaria['sec_id'] ?>" 
                                               <?= $filtros['secretaria'] == $secretaria['sec_id'] ? 'selected' : '' ?>>
                                           <?= htmlspecialchars($secretaria['sec_nome']) ?>
                                       </option>
                                   <?php endforeach; ?>
                               </select>
                           </div>
                           <?php endif; ?>
                           
                           <?php if (in_array('disponibilidade', $config_aba['filtros_especiais'] ?? [])): ?>
                           <div class="filtro-group">
                               <label for="disponibilidade">Disponibilidade</label>
                               <select id="disponibilidade" name="disponibilidade" class="filtro-select">
                                   <option value="">Todas</option>
                                   <option value="disponivel" <?= $filtros['disponibilidade'] == 'disponivel' ? 'selected' : '' ?>>Disponível</option>
                                   <option value="manutencao" <?= $filtros['disponibilidade'] == 'manutencao' ? 'selected' : '' ?>>Manutenção</option>
                                   <option value="indisponivel" <?= $filtros['disponibilidade'] == 'indisponivel' ? 'selected' : '' ?>>Indisponível</option>
                               </select>
                           </div>
                           <?php endif; ?>
                           
                           <?php if (in_array('status', $config_aba['filtros_especiais'] ?? [])): ?>
                           <div class="filtro-group">
                               <label for="status">Status</label>
                               <select id="status" name="status" class="filtro-select">
                                   <option value="">Apenas ativos</option>
                                   <option value="ativo" <?= $filtros['status'] == 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                   <option value="inativo" <?= $filtros['status'] == 'inativo' ? 'selected' : '' ?>>Inativo</option>
                               </select>
                           </div>
                           <?php endif; ?>
                           
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
               <?php endif; ?>

               <!-- Formulários de Cadastro -->
               <?php include 'includes/formularios_cadastro.php'; ?>

               <!-- Tabelas de Listagem -->
               <?php include 'includes/tabelas_listagem.php'; ?>

           </div>
       </div>
   </div>

   <!-- JavaScript -->
   <script src="assets/js/main.js"></script>
   <script src="assets/js/cadastros.js"></script>
</body>
</html>
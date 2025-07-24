<?php
// Ativar exibição de erros para debug
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Inicia a sessão
session_start();

// Verifica se o usuário está logado no sistema administrativo
if (!isset($_SESSION['usersystem_logado'])) {
    header("Location: ../acessdeniedrestrict.php"); 
    exit;
}

// Inclui arquivo de configuração com conexão ao banco de dados
require_once "../lib/config.php";

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
$tem_permissao = $is_admin || strtoupper($usuario_departamento) === 'ASSISTENCIA_SOCIAL';

if (!$tem_permissao) {
    header("Location: dashboard.php?erro=acesso_negado");
    exit;
}

// Verificar se o ID foi fornecido
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: assistencia_habitacao.php?erro=id_invalido");
    exit;
}

$inscricao_id = (int)$_GET['id'];
$inscricao_atual = null;
$dependentes = [];
$comentarios = [];
$arquivos = [];

try {
    // Consulta a inscrição com informações do usuário cidadão
    $stmt = $conn->prepare("
        SELECT cs.*, cu.cad_usu_nome, cu.cad_usu_email
        FROM tb_cad_social cs 
        LEFT JOIN tb_cad_usuarios cu ON cs.cad_usu_id = cu.cad_usu_id 
        WHERE cs.cad_social_id = :id
    ");
    $stmt->bindParam(':id', $inscricao_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $inscricao_atual = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Busca os dependentes
        $stmt = $conn->prepare("SELECT * FROM tb_cad_social_dependentes WHERE cad_social_id = :id ORDER BY cad_social_dependente_data_nascimento");
        $stmt->bindParam(':id', $inscricao_id);
        $stmt->execute();
        $dependentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Busca o histórico/comentários
        $stmt = $conn->prepare("
            SELECT h.*, u.usuario_nome
            FROM tb_cad_social_historico h
            LEFT JOIN tb_usuarios_sistema u ON h.cad_social_hist_usuario = u.usuario_id
            WHERE h.cad_social_id = :id
            ORDER BY h.cad_social_hist_data DESC
        ");
        $stmt->bindParam(':id', $inscricao_id);
        $stmt->execute();
        $comentarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Busca os arquivos anexados pelo sistema
        $stmt = $conn->prepare("
            SELECT a.*, u.usuario_nome 
            FROM tb_cad_social_arquivos a
            LEFT JOIN tb_usuarios_sistema u ON a.cad_social_arq_usuario = u.usuario_id
            WHERE a.cad_social_id = :id
            ORDER BY a.cad_social_arq_data DESC
        ");
        $stmt->bindParam(':id', $inscricao_id);
        $stmt->execute();
        $arquivos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        header("Location: assistencia_habitacao.php?erro=inscricao_nao_encontrada");
        exit;
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar informações: " . $e->getMessage());
    header("Location: assistencia_habitacao.php?erro=erro_database");
    exit;
}

// Funções auxiliares
function formatarCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) != 11) return $cpf;
    return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
}

function formatarData($data) {
    return date('d/m/Y H:i', strtotime($data));
}

function formatarDataBR($data) {
    return date('d/m/Y', strtotime($data));
}

function formatarTelefone($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) == 10) {
        return '(' . substr($telefone, 0, 2) . ') ' . substr($telefone, 2, 4) . '-' . substr($telefone, 6);
    } elseif (strlen($telefone) == 11) {
        return '(' . substr($telefone, 0, 2) . ') ' . substr($telefone, 2, 5) . '-' . substr($telefone, 7);
    }
    return $telefone;
}

function formatarTamanhoArquivo($bytes) {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

function getStatusClass($status) {
    $classes = [
        'PENDENTE DE ANÁLISE' => 'status-pendente',
        'EM ANÁLISE' => 'status-analise',
        'DOCUMENTAÇÃO PENDENTE' => 'status-documentacao',
        'APROVADO' => 'status-aprovado',
        'REPROVADO' => 'status-reprovado',
        'CANCELADO' => 'status-cancelado',
        'EM ESPERA' => 'status-espera',
        'CONCLUÍDO' => 'status-concluido'
    ];
    return $classes[$status] ?? '';
}

// Definir tema baseado no usuário
$titulo_sistema = $is_admin ? 'Administração Geral' : 'Assistência Social';
$cor_tema = $is_admin ? '#e74c3c' : '#e91e63';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visualizar Cadastro Habitacional - Sistema</title>
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

        /* Sidebar */
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

        /* Cards */
        .card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, var(--secondary-color), #c2185b);
            color: white;
            padding: 15px 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-header i {
            margin-right: 10px;
        }

        .card-body {
            padding: 20px;
        }

        /* Status badges */
        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }

        .status-pendente {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
        }

        .status-analise {
            background: linear-gradient(135deg, #d1ecf1, #bee5eb);
            color: #0c5460;
        }

        .status-documentacao {
            background: linear-gradient(135deg, #ffd7a6, #ffcc80);
            color: #8b4513;
        }

        .status-aprovado {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
        }

        .status-reprovado {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
        }

        .status-cancelado {
            background: linear-gradient(135deg, #e2e3e5, #d6d8db);
            color: #383d41;
        }

        .status-espera {
            background: linear-gradient(135deg, #e7e3ff, #d4c5ff);
            color: #6f42c1;
        }

        .status-concluido {
            background: linear-gradient(135deg, #c8f7c5, #a8e6a3);
            color: #0f5132;
        }

        /* Info sections */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .info-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            border-left: 4px solid var(--secondary-color);
        }

        .info-section h4 {
            color: var(--primary-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }

        .info-section h4 i {
            margin-right: 8px;
            color: var(--secondary-color);
        }

        .info-item {
            margin-bottom: 12px;
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-weight: 600;
            color: #555;
            font-size: 0.9rem;
            margin-bottom: 4px;
        }

        .info-value {
            color: var(--text-color);
            word-wrap: break-word;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab {
            padding: 12px 20px;
            cursor: pointer;
            border: none;
            background: none;
            color: #666;
            font-weight: 500;
            transition: all 0.3s;
            position: relative;
        }

        .tab.active {
            color: var(--secondary-color);
        }

        .tab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background-color: var(--secondary-color);
        }

        .tab:hover {
            color: var(--secondary-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Dependentes */
        .dependente-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid var(--info-color);
        }

        .dependente-header {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }

        .dependente-header i {
            margin-right: 8px;
            color: var(--info-color);
        }

        /* Histórico */
        .historico-item {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            position: relative;
        }

        .historico-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .historico-acao {
            font-weight: 600;
            color: var(--primary-color);
        }

        .historico-data {
            font-size: 0.85rem;
            color: #666;
        }

        .historico-usuario {
            font-size: 0.85rem;
            color: var(--secondary-color);
            margin-bottom: 8px;
        }

        .historico-observacao {
            color: var(--text-color);
            line-height: 1.5;
        }

        /* Arquivos */
        .arquivo-item {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .arquivo-info {
            flex: 1;
        }

        .arquivo-nome {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .arquivo-meta {
            font-size: 0.85rem;
            color: #666;
        }

        .arquivo-actions {
            display: flex;
            gap: 10px;
        }

        /* Botões */
        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn i {
            margin-right: 6px;
        }

        .btn-primary {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #c2185b;
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

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #ddd;
        }

        /* Responsive */
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

            .info-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                overflow-x: auto;
                white-space: nowrap;
            }
        }

        .mobile-toggle {
            display: none;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3><?php echo $titulo_sistema; ?></h3>
            <button class="toggle-btn">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        
        <ul class="menu">
            <li class="menu-item">
                <a href="dashboard.php" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
                    <span class="menu-text">Dashboard</span>
                </a>
            </li>
            
            <?php if ($is_admin): ?>
            <!-- Menu completo para administradores -->
            <div class="menu-separator"></div>
            <div class="menu-category">Administração</div>
            
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-users-cog"></i></span>
                    <span class="menu-text">Gerenciar Usuários</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="lista_usuarios.php" class="submenu-link">Lista de Usuários</a></li>
                    <li><a href="adicionar_usuario.php" class="submenu-link">Adicionar Usuário</a></li>
                    <li><a href="permissoes.php" class="submenu-link">Permissões</a></li>
                </ul>
            </li>
            
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-chart-pie"></i></span>
                    <span class="menu-text">Relatórios Gerais</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Consolidado Geral</a></li>
                    <li><a href="#" class="submenu-link">Por Departamento</a></li>
                    <li><a href="#" class="submenu-link">Estatísticas</a></li>
                </ul>
            </li>
            
            <div class="menu-separator"></div>
            <div class="menu-category">Departamentos</div>
            
            <li class="menu-item open">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-hands-helping"></i></span>
                    <span class="menu-text">Assistência Social</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Atendimentos</a></li>
                    <li><a href="#" class="submenu-link">Benefícios</a></li>
                    <li><a href="assistencia_habitacao.php" class="submenu-link active">Programas Habitacionais</a></li>
                    <li><a href="#" class="submenu-link">Relatórios</a></li>
                </ul>
            </li>
            
            <?php else: ?>
            <!-- Menu específico do departamento para usuários normais -->
            <?php if (strtoupper($usuario_departamento) === 'ASSISTENCIA_SOCIAL'): ?>
            <div class="menu-separator"></div>
            <div class="menu-category">Assistência Social</div>
            
            <li class="menu-item open">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-hands-helping"></i></span>
                    <span class="menu-text">Assistência Social</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Atendimentos</a></li>
                    <li><a href="#" class="submenu-link">Benefícios</a></li>
                    <li><a href="assistencia_habitacao.php" class="submenu-link active">Programas Habitacionais</a></li>
                    <li><a href="#" class="submenu-link">Relatórios</a></li>
                </ul>
            </li>
            <?php endif; ?>
            <?php endif; ?>
            
            <div class="menu-separator"></div>
            
            <li class="menu-item">
                <a href="perfil.php" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-user-cog"></i></span>
                    <span class="menu-text">Meu Perfil</span>
                </a>
            </li>
            
            <li class="menu-item">
                <a href="../controller/logout_system.php" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span class="menu-text">Sair</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="header">
            <div>
                <button class="mobile-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Programas Habitacionais</h2>
            </div>
            <div class="user-info">
                <div style="width: 35px; height: 35px; border-radius: 50%; background-color: var(--secondary-color); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                    <?php echo strtoupper(substr($usuario_nome, 0, 1)); ?>
                </div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($usuario_nome); ?></div>
                    <div class="user-role">
                        <?php if ($is_admin): ?>
                        <span class="admin-badge">
                            <i class="fas fa-crown"></i> Administrador
                        </span>
                        <?php else: ?>
                        <span class="department-badge">
                            Assistência Social
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <h1 class="page-title">
            <i class="fas fa-eye"></i>
            Visualizar Cadastro Habitacional
        </h1>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="dashboard.php">Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <a href="assistencia_habitacao.php">Assistência Habitacional</a>
            <i class="fas fa-chevron-right"></i>
            <span>Visualizar Cadastro</span>
        </div>

        <!-- Informações Principais -->
        <div class="card">
            <div class="card-header">
                <div>
                    <i class="fas fa-info-circle"></i>
                    Informações Principais
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <span class="status-badge <?php echo getStatusClass($inscricao_atual['cad_social_status']); ?>">
                        <?php echo htmlspecialchars($inscricao_atual['cad_social_status']); ?>
                    </span>
                    <a href="assistencia_habitacao.php" class="btn btn-secondary btn-sm">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="info-grid">
                    <div class="info-section">
                        <h4><i class="fas fa-clipboard-list"></i> Dados do Protocolo</h4>
                        <div class="info-item">
                            <div class="info-label">Protocolo</div>
                            <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_protocolo']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Data de Cadastro</div>
                            <div class="info-value"><?php echo formatarData($inscricao_atual['cad_social_data_cadastro']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Programa de Interesse</div>
                            <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_programa_interesse'] ?? 'Não informado'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Cadastrado por</div>
                            <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_usu_nome'] ?? 'Sistema'); ?></div>
                        </div>
                    </div>

                    <div class="info-section">
                        <h4><i class="fas fa-user"></i> Dados Pessoais</h4>
                        <div class="info-item">
                            <div class="info-label">Nome Completo</div>
                            <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_nome']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">CPF</div>
                            <div class="info-value"><?php echo formatarCPF($inscricao_atual['cad_social_cpf']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">RG</div>
                            <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_rg'] ?? 'Não informado'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Data de Nascimento</div>
                            <div class="info-value"><?php echo formatarDataBR($inscricao_atual['cad_social_data_nascimento']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Gênero</div>
                            <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_genero']); ?></div>
                        </div>
                    </div>

                    <div class="info-section">
                        <h4><i class="fas fa-phone"></i> Contato</h4>
                        <div class="info-item">
                            <div class="info-label">E-mail</div>
                            <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_email']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Celular</div>
                            <div class="info-value"><?php echo formatarTelefone($inscricao_atual['cad_social_celular']); ?></div>
                        </div>
                        <?php if ($inscricao_atual['cad_social_telefone']): ?>
                        <div class="info-item">
                            <div class="info-label">Telefone</div>
                            <div class="info-value"><?php echo formatarTelefone($inscricao_atual['cad_social_telefone']); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <div class="info-label">Autoriza Notificações</div>
                            <div class="info-value"><?php echo $inscricao_atual['cad_social_autoriza_email'] ? 'Sim' : 'Não'; ?></div>
                        </div>
                    </div>

                    <div class="info-section">
                        <h4><i class="fas fa-home"></i> Endereço</h4>
                        <div class="info-item">
                            <div class="info-label">Endereço Completo</div>
                            <div class="info-value">
                                <?php echo htmlspecialchars($inscricao_atual['cad_social_rua']); ?>, 
                                <?php echo htmlspecialchars($inscricao_atual['cad_social_numero']); ?>
                                <?php echo $inscricao_atual['cad_social_complemento'] ? ', ' . htmlspecialchars($inscricao_atual['cad_social_complemento']) : ''; ?><br>
                                <?php echo htmlspecialchars($inscricao_atual['cad_social_bairro']); ?><br>
                                <?php echo htmlspecialchars($inscricao_atual['cad_social_cidade']); ?> - 
                                CEP: <?php echo substr($inscricao_atual['cad_social_cep'], 0, 5) . '-' . substr($inscricao_atual['cad_social_cep'], 5); ?>
                            </div>
                        </div>
                        <?php if ($inscricao_atual['cad_social_ponto_referencia']): ?>
                        <div class="info-item">
                            <div class="info-label">Ponto de Referência</div>
                            <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_ponto_referencia']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs de Informações Detalhadas -->
        <div class="card">
            <div class="card-header">
                <div>
                    <i class="fas fa-folder-open"></i>
                    Informações Detalhadas
                </div>
            </div>
            <div class="card-body">
                <div class="tabs">
                    <button class="tab active" onclick="showTab('informacoes-adicionais')">
                        <i class="fas fa-info-circle"></i> Informações Adicionais
                    </button>
                    <button class="tab" onclick="showTab('situacao-trabalho')">
                        <i class="fas fa-briefcase"></i> Situação Trabalhista
                    </button>
                    <button class="tab" onclick="showTab('moradia')">
                        <i class="fas fa-home"></i> Situação de Moradia
                    </button>
                    <?php if ($inscricao_atual['cad_social_estado_civil'] == 'CASADO(A)' || $inscricao_atual['cad_social_estado_civil'] == 'UNIÃO ESTÁVEL/AMASIADO(A)'): ?>
                    <button class="tab" onclick="showTab('conjuge')">
                        <i class="fas fa-user-friends"></i> Cônjuge
                    </button>
                    <?php endif; ?>
                    <button class="tab" onclick="showTab('dependentes')">
                        <i class="fas fa-users"></i> Dependentes (<?php echo count($dependentes); ?>)
                    </button>
                    <button class="tab" onclick="showTab('historico')">
                        <i class="fas fa-history"></i> Histórico (<?php echo count($comentarios); ?>)
                    </button>
                    <button class="tab" onclick="showTab('arquivos')">
                        <i class="fas fa-file-alt"></i> Arquivos
                    </button>
                </div>

                <!-- Tab: Informações Adicionais -->
                <div id="informacoes-adicionais" class="tab-content active">
                    <div class="info-grid">
                        <div class="info-section">
                            <h4><i class="fas fa-user-plus"></i> Informações Pessoais</h4>
                            <div class="info-item">
                                <div class="info-label">Estado Civil</div>
                                <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_estado_civil']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Escolaridade</div>
                                <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_escolaridade']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Possui Deficiência</div>
                                <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_deficiencia']); ?></div>
                            </div>
                            <?php if ($inscricao_atual['cad_social_deficiencia'] !== 'NÃO' && $inscricao_atual['cad_social_deficiencia_fisica_detalhe']): ?>
                            <div class="info-item">
                                <div class="info-label">Detalhes da Deficiência</div>
                                <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_deficiencia_fisica_detalhe']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Tab: Situação Trabalhista -->
                <div id="situacao-trabalho" class="tab-content">
                    <div class="info-grid">
                        <div class="info-section">
                            <h4><i class="fas fa-briefcase"></i> Situação de Trabalho</h4>
                            <div class="info-item">
                                <div class="info-label">Situação de Trabalho</div>
                                <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_situacao_trabalho']); ?></div>
                            </div>
                            <?php if ($inscricao_atual['cad_social_situacao_trabalho'] != 'DESEMPREGADO'): ?>
                            <div class="info-item">
                                <div class="info-label">Profissão</div>
                                <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_profissao'] ?? 'Não informado'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Empregador</div>
                                <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_empregador'] ?? 'Não informado'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Cargo</div>
                                <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_cargo'] ?? 'Não informado'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Tempo de Serviço</div>
                                <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_tempo_servico'] ?? 'Não informado'); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Tab: Situação de Moradia -->
                <div id="moradia" class="tab-content">
                    <div class="info-grid">
                        <div class="info-section">
                            <h4><i class="fas fa-home"></i> Situação Habitacional</h4>
                            <div class="info-item">
                                <div class="info-label">Tipo de Moradia</div>
                                <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_tipo_moradia']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Situação da Propriedade</div>
                                <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_situacao_propriedade']); ?></div>
                            </div>
                            <?php if ($inscricao_atual['cad_social_situacao_propriedade'] == 'ALUGADA' && $inscricao_atual['cad_social_valor_aluguel']): ?>
                            <div class="info-item">
                                <div class="info-label">Valor do Aluguel</div>
                                <div class="info-value">R$ <?php echo number_format($inscricao_atual['cad_social_valor_aluguel'], 2, ',', '.'); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Tab: Cônjuge -->
                <?php if ($inscricao_atual['cad_social_estado_civil'] == 'CASADO(A)' || $inscricao_atual['cad_social_estado_civil'] == 'UNIÃO ESTÁVEL/AMASIADO(A)'): ?>
                <div id="conjuge" class="tab-content">
                    <div class="info-grid">
                        <div class="info-section">
                            <h4><i class="fas fa-user-friends"></i> Informações do Cônjuge</h4>
                            <div class="info-item">
                                <div class="info-label">Nome do Cônjuge</div>
                                <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_conjuge_nome'] ?? 'Não informado'); ?></div>
                            </div>
                            <?php if ($inscricao_atual['cad_social_conjuge_cpf']): ?>
                            <div class="info-item">
                                <div class="info-label">CPF do Cônjuge</div>
                                <div class="info-value"><?php echo formatarCPF($inscricao_atual['cad_social_conjuge_cpf']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($inscricao_atual['cad_social_conjuge_data_nascimento']): ?>
                            <div class="info-item">
                                <div class="info-label">Data de Nascimento</div>
                                <div class="info-value"><?php echo formatarDataBR($inscricao_atual['cad_social_conjuge_data_nascimento']); ?></div>
                            </div>
                            <?php endif; ?>
                            <div class="info-item">
                                <div class="info-label">Cônjuge possui renda</div>
                                <div class="info-value"><?php echo htmlspecialchars($inscricao_atual['cad_social_conjuge_renda'] ?? 'Não informado'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab: Dependentes -->
                <div id="dependentes" class="tab-content">
                    <?php if (empty($dependentes)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h3>Nenhum dependente cadastrado</h3>
                        <p>Não há dependentes registrados para esta inscrição.</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($dependentes as $index => $dependente): ?>
                    <div class="dependente-card">
                        <div class="dependente-header">
                            <i class="fas fa-user"></i>
                            Dependente <?php echo $index + 1; ?>: <?php echo htmlspecialchars($dependente['cad_social_dependente_nome']); ?>
                        </div>
                        <div class="info-grid">
                            <div class="info-section">
                                <div class="info-item">
                                    <div class="info-label">Data de Nascimento</div>
                                    <div class="info-value"><?php echo formatarDataBR($dependente['cad_social_dependente_data_nascimento']); ?></div>
                                </div>
                                <?php if ($dependente['cad_social_dependente_cpf']): ?>
                                <div class="info-item">
                                    <div class="info-label">CPF</div>
                                    <div class="info-value"><?php echo formatarCPF($dependente['cad_social_dependente_cpf']); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="info-section">
                                <div class="info-item">
                                    <div class="info-label">Possui Deficiência</div>
                                    <div class="info-value"><?php echo htmlspecialchars($dependente['cad_social_dependente_deficiencia']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Possui Renda</div>
                                    <div class="info-value"><?php echo htmlspecialchars($dependente['cad_social_dependente_renda']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Tab: Histórico -->
                <div id="historico" class="tab-content">
                    <?php if (empty($comentarios)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h3>Nenhum histórico encontrado</h3>
                        <p>Não há registros de histórico para esta inscrição.</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($comentarios as $comentario): ?>
                    <div class="historico-item">
                        <div class="historico-header">
                            <div class="historico-acao"><?php echo htmlspecialchars($comentario['cad_social_hist_acao']); ?></div>
                            <div class="historico-data"><?php echo formatarData($comentario['cad_social_hist_data']); ?></div>
                        </div>
                        <div class="historico-usuario">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($comentario['usuario_nome'] ?? 'Sistema'); ?>
                        </div>
                        <?php if ($comentario['cad_social_hist_observacao']): ?>
                        <div class="historico-observacao">
                            <?php echo nl2br(htmlspecialchars($comentario['cad_social_hist_observacao'])); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Tab: Arquivos -->
                <div id="arquivos" class="tab-content">
                    <!-- Arquivos do Sistema -->
                    <h4 style="margin-bottom: 15px; color: var(--primary-color);">
                        <i class="fas fa-folder"></i> Arquivos do Sistema
                    </h4>
                    
                    <?php if (empty($arquivos)): ?>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; color: #666;">
                        <i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 10px; color: #ddd;"></i>
                        <p>Nenhum arquivo do sistema anexado.</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($arquivos as $arquivo): ?>
                    <div class="arquivo-item">
                        <div class="arquivo-info">
                            <div class="arquivo-nome">
                                <i class="fas fa-file"></i> <?php echo htmlspecialchars($arquivo['cad_social_arq_nome_original'] ?? $arquivo['cad_social_arq_nome']); ?>
                            </div>
                            <div class="arquivo-meta">
                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($arquivo['usuario_nome'] ?? 'Sistema'); ?></span>
                                <span style="margin-left: 15px;"><i class="fas fa-calendar"></i> <?php echo formatarData($arquivo['cad_social_arq_data']); ?></span>
                                <?php if (isset($arquivo['cad_social_arq_tamanho'])): ?>
                                <span style="margin-left: 15px;"><i class="fas fa-weight"></i> <?php echo formatarTamanhoArquivo($arquivo['cad_social_arq_tamanho']); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($arquivo['cad_social_arq_descricao']): ?>
                            <div style="margin-top: 5px; font-style: italic; color: #666;">
                                <?php echo htmlspecialchars($arquivo['cad_social_arq_descricao']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="arquivo-actions">
                            <a href="../uploads/habitacao/sistema/<?php echo htmlspecialchars($arquivo['cad_social_arq_nome']); ?>" 
                               class="btn btn-primary btn-sm" target="_blank" title="Visualizar">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="../uploads/habitacao/sistema/<?php echo htmlspecialchars($arquivo['cad_social_arq_nome']); ?>" 
                               class="btn btn-success btn-sm" download title="Baixar">
                                <i class="fas fa-download"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Arquivos do Cidadão -->
                    <h4 style="margin: 30px 0 15px 0; color: var(--primary-color);">
                        <i class="fas fa-folder-open"></i> Arquivos do Cidadão
                    </h4>
                    
                    <?php 
                    $arquivos_cidadao = [];
                    if ($inscricao_atual['cad_social_cpf_documento']) $arquivos_cidadao[] = ['nome' => 'Documento de CPF', 'arquivo' => $inscricao_atual['cad_social_cpf_documento'], 'icon' => 'fa-id-card'];
                    if ($inscricao_atual['cad_social_escolaridade_documento']) $arquivos_cidadao[] = ['nome' => 'Comprovante de Escolaridade', 'arquivo' => $inscricao_atual['cad_social_escolaridade_documento'], 'icon' => 'fa-graduation-cap'];
                    if ($inscricao_atual['cad_social_viuvo_documento']) $arquivos_cidadao[] = ['nome' => 'Certidão de Óbito', 'arquivo' => $inscricao_atual['cad_social_viuvo_documento'], 'icon' => 'fa-file-alt'];
                    if ($inscricao_atual['cad_social_laudo_deficiencia']) $arquivos_cidadao[] = ['nome' => 'Laudo de Deficiência', 'arquivo' => $inscricao_atual['cad_social_laudo_deficiencia'], 'icon' => 'fa-file-medical'];
                    if ($inscricao_atual['cad_social_conjuge_comprovante_renda']) $arquivos_cidadao[] = ['nome' => 'Comprovante de Renda do Cônjuge', 'arquivo' => $inscricao_atual['cad_social_conjuge_comprovante_renda'], 'icon' => 'fa-money-bill-wave'];
                    if ($inscricao_atual['cad_social_carteira_trabalho']) $arquivos_cidadao[] = ['nome' => 'Carteira de Trabalho', 'arquivo' => $inscricao_atual['cad_social_carteira_trabalho'], 'icon' => 'fa-id-badge'];
                    ?>
                    
                    <?php if (empty($arquivos_cidadao)): ?>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; text-align: center; color: #666;">
                        <i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 10px; color: #ddd;"></i>
                        <p>Nenhum arquivo do cidadão anexado.</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($arquivos_cidadao as $arquivo): ?>
                    <div class="arquivo-item">
                        <div class="arquivo-info">
                            <div class="arquivo-nome">
                                <i class="fas <?php echo $arquivo['icon']; ?>"></i> <?php echo $arquivo['nome']; ?>
                            </div>
                            <div class="arquivo-meta">
                                <span><i class="fas fa-user"></i> Cidadão</span>
                                <span style="margin-left: 15px;"><i class="fas fa-calendar"></i> <?php echo formatarData($inscricao_atual['cad_social_data_cadastro']); ?></span>
                            </div>
                        </div>
                        <div class="arquivo-actions">
                            <a href="../uploads/habitacao/<?php echo htmlspecialchars($arquivo['arquivo']); ?>" 
                               class="btn btn-primary btn-sm" target="_blank" title="Visualizar">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="../uploads/habitacao/<?php echo htmlspecialchars($arquivo['arquivo']); ?>" 
                               class="btn btn-success btn-sm" download title="Baixar">
                                <i class="fas fa-download"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Ações -->
        <div class="card">
            <div class="card-header">
                <div>
                    <i class="fas fa-cogs"></i>
                    Ações
                </div>
            </div>
            <div class="card-body">
                <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                    <a href="assistencia_habitacao.php?id=<?php echo $inscricao_id; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Gerenciar Cadastro
                    </a>
                    <button type="button" class="btn btn-info" onclick="window.print()">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                    <a href="assistencia_habitacao.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Voltar para Lista
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Inicialização
        document.addEventListener('DOMContentLoaded', function() {
            initializePage();
        });

        function initializePage() {
            // Toggle sidebar
            const toggleBtn = document.querySelector('.toggle-btn');
            const mobileToggle = document.querySelector('.mobile-toggle');
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            
            function toggleSidebar() {
                if (window.innerWidth <= 768) {
                    sidebar.classList.toggle('show');
                } else {
                    sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('expanded');
                }
            }
            
            if (toggleBtn) {
                toggleBtn.addEventListener('click', toggleSidebar);
            }
            
            if (mobileToggle) {
                mobileToggle.addEventListener('click', toggleSidebar);
            }

            // Submenu toggle
            const menuItems = document.querySelectorAll('.menu-item');
            menuItems.forEach(function(item) {
                const menuLink = item.querySelector('.menu-link');
                if (menuLink && menuLink.querySelector('.arrow')) {
                    menuLink.addEventListener('click', function(e) {
                        e.preventDefault();
                        item.classList.toggle('open');
                        menuItems.forEach(function(otherItem) {
                            if (otherItem !== item && otherItem.classList.contains('open')) {
                                otherItem.classList.remove('open');
                            }
                        });
                    });
                }
            });

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 768) {
                    const isClickInsideSidebar = sidebar.contains(e.target);
                    const isToggleBtn = e.target.closest('.mobile-toggle') || e.target.closest('.toggle-btn');
                    
                    if (!isClickInsideSidebar && !isToggleBtn && sidebar.classList.contains('show')) {
                        sidebar.classList.remove('show');
                    }
                }
            });
            
            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768) {
                    sidebar.classList.remove('show');
                }
            });
        }

        // Função para alternar entre as abas
        function showTab(tabId) {
            // Esconder todas as abas
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(function(content) {
                content.classList.remove('active');
            });

            // Remover classe active de todos os botões
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(function(tab) {
                tab.classList.remove('active');
            });

            // Mostrar a aba selecionada
            const selectedTab = document.getElementById(tabId);
            if (selectedTab) {
                selectedTab.classList.add('active');
            }

            // Adicionar classe active ao botão clicado
            const clickedButton = event.target.closest('.tab');
            if (clickedButton) {
                clickedButton.classList.add('active');
            }
        }

        // Função para copiar texto para área de transferência
        function copyToClipboard(text) {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function() {
                    showNotification('Texto copiado para a área de transferência!', 'success');
                });
            } else {
                // Fallback para navegadores mais antigos
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand('copy');
                    showNotification('Texto copiado para a área de transferência!', 'success');
                } catch (err) {
                    showNotification('Erro ao copiar texto', 'error');
                }
                document.body.removeChild(textArea);
            }
        }

        // Função para mostrar notificações
        function showNotification(message, type = 'info') {
            // Remove notificações existentes
            const existingNotifications = document.querySelectorAll('.notification');
            existingNotifications.forEach(notification => notification.remove());

            // Cria nova notificação
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 8px;
                color: white;
                z-index: 9999;
                opacity: 0;
                transform: translateX(100%);
                transition: all 0.3s ease;
                max-width: 300px;
                font-weight: 500;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            `;

            // Define cor baseada no tipo
            switch (type) {
                case 'success':
                    notification.style.backgroundColor = '#28a745';
                    break;
                case 'error':
                    notification.style.backgroundColor = '#dc3545';
                    break;
                case 'warning':
                    notification.style.backgroundColor = '#ffc107';
                    notification.style.color = '#212529';
                    break;
                default:
                    notification.style.backgroundColor = '#17a2b8';
            }

            notification.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span>${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" 
                            style="background: none; border: none; color: inherit; margin-left: 10px; cursor: pointer; font-size: 18px;">
                        ×
                    </button>
                </div>
            `;

            document.body.appendChild(notification);

            // Animar entrada
            setTimeout(() => {
                notification.style.opacity = '1';
                notification.style.transform = 'translateX(0)';
            }, 10);

            // Auto-remover após 5 segundos
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => notification.remove(), 300);
            }, 5000);
        }

        // Função para download de arquivo
        function downloadFile(url, filename) {
            const link = document.createElement('a');
            link.href = url;
            link.download = filename || 'arquivo';
            link.target = '_blank';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Adicionar event listeners para copiar informações
        document.addEventListener('DOMContentLoaded', function() {
            // Adicionar clique duplo para copiar protocolo
            const protocoloElement = document.querySelector('.info-value');
            if (protocoloElement) {
                protocoloElement.style.cursor = 'pointer';
                protocoloElement.title = 'Clique duplo para copiar';
                protocoloElement.addEventListener('dblclick', function() {
                    copyToClipboard(this.textContent);
                });
            }

            // Adicionar tooltips aos botões de arquivo
            const actionButtons = document.querySelectorAll('.arquivo-actions .btn');
            actionButtons.forEach(button => {
                button.addEventListener('mouseenter', function() {
                    const title = this.getAttribute('title');
                    if (title) {
                        this.setAttribute('data-original-title', title);
                    }
                });
            });
        });

        // Função para imprimir apenas o conteúdo relevante
        function printPage() {
            const printWindow = window.open('', '_blank');
            const printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Cadastro Habitacional - <?php echo htmlspecialchars($inscricao_atual['cad_social_protocolo']); ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                        .info-section { margin-bottom: 25px; }
                        .info-section h3 { color: #333; border-bottom: 1px solid #ccc; padding-bottom: 5px; }
                        .info-item { margin: 10px 0; }
                        .info-label { font-weight: bold; display: inline-block; width: 200px; }
                        .status-badge { padding: 5px 10px; border: 1px solid #333; }
                        @media print { body { margin: 0; } }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>Prefeitura Municipal de Santa Izabel do Oeste</h1>
                        <h2>Cadastro Habitacional</h2>
                        <p><strong>Protocolo:</strong> <?php echo htmlspecialchars($inscricao_atual['cad_social_protocolo']); ?></p>
                        <p><strong>Status:</strong> <?php echo htmlspecialchars($inscricao_atual['cad_social_status']); ?></p>
                    </div>
                    
                    <div class="info-section">
                        <h3>Dados Pessoais</h3>
                        <div class="info-item"><span class="info-label">Nome:</span> <?php echo htmlspecialchars($inscricao_atual['cad_social_nome']); ?></div>
                        <div class="info-item"><span class="info-label">CPF:</span> <?php echo formatarCPF($inscricao_atual['cad_social_cpf']); ?></div>
                        <div class="info-item"><span class="info-label">Data de Nascimento:</span> <?php echo formatarDataBR($inscricao_atual['cad_social_data_nascimento']); ?></div>
                        <div class="info-item"><span class="info-label">Estado Civil:</span> <?php echo htmlspecialchars($inscricao_atual['cad_social_estado_civil']); ?></div>
                        <div class="info-item"><span class="info-label">Programa:</span> <?php echo htmlspecialchars($inscricao_atual['cad_social_programa_interesse']); ?></div>
                    </div>
                    
                    <div class="info-section">
                        <h3>Contato</h3>
                        <div class="info-item"><span class="info-label">E-mail:</span> <?php echo htmlspecialchars($inscricao_atual['cad_social_email']); ?></div>
                        <div class="info-item"><span class="info-label">Celular:</span> <?php echo formatarTelefone($inscricao_atual['cad_social_celular']); ?></div>
                    </div>
                    
                    <div class="info-section">
                        <h3>Endereço</h3>
                        <div class="info-item">
                            <span class="info-label">Endereço:</span> 
                            <?php echo htmlspecialchars($inscricao_atual['cad_social_rua']); ?>, 
                            <?php echo htmlspecialchars($inscricao_atual['cad_social_numero']); ?>, 
                            <?php echo htmlspecialchars($inscricao_atual['cad_social_bairro']); ?>, 
                            <?php echo htmlspecialchars($inscricao_atual['cad_social_cidade']); ?>
                        </div>
                    </div>
                    
                    <div style="margin-top: 50px; text-align: center; font-size: 12px; color: #666;">
                        <p>Impresso em: <?php echo date('d/m/Y H:i:s'); ?></p>
                        <p>Sistema de Gerenciamento Habitacional - Prefeitura Municipal</p>
                    </div>
                </body>
                </html>
            `;
            
            printWindow.document.write(printContent);
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
            printWindow.close();
        }

        // Sobrescrever função de impressão padrão
        window.addEventListener('beforeprint', function() {
            // Esconder sidebar durante impressão
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) {
                sidebar.style.display = 'none';
            }
        });

        window.addEventListener('afterprint', function() {
            // Mostrar sidebar após impressão
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) {
                sidebar.style.display = 'block';
            }
        });

        // Atalhos de teclado
        document.addEventListener('keydown', function(e) {
            // Ctrl+P para imprimir
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printPage();
            }
            
            // ESC para voltar
            if (e.key === 'Escape') {
                window.location.href = 'assistencia_habitacao.php';
            }
            
            // Ctrl+1 a Ctrl+8 para alternar entre abas
            if (e.ctrlKey && e.key >= '1' && e.key <= '8') {
                e.preventDefault();
                const tabButtons = document.querySelectorAll('.tab');
                const tabIndex = parseInt(e.key) - 1;
                if (tabButtons[tabIndex]) {
                    tabButtons[tabIndex].click();
                }
            }
        });
    </script>

    <!-- Estilos para impressão -->
    <style media="print">
        .sidebar,
        .mobile-toggle,
        .btn,
        .tabs,
        .card-header {
            display: none !important;
        }
        
        .main-content {
            margin-left: 0 !important;
            padding: 0 !important;
        }
        
        .card {
            box-shadow: none !important;
            border: 1px solid #ccc !important;
            margin-bottom: 20px !important;
        }
        
        .card-body {
            padding: 15px !important;
        }
        
        .tab-content {
            display: block !important;
        }
        
        .page-title {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .status-badge {
            border: 1px solid #333 !important;
            color: #333 !important;
            background: transparent !important;
        }
        
        .info-section {
            break-inside: avoid;
        }
        
        .dependente-card,
        .historico-item,
        .arquivo-item {
            break-inside: avoid;
            border: 1px solid #ccc !important;
            margin-bottom: 15px !important;
        }
    </style>
</body>
</html>
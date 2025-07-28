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

// Verificar permissões de acesso
$is_admin = ($usuario_dados['usuario_nivel_id'] == 1);
$is_associacao = (strtoupper(trim($usuario_dados['usuario_nome'])) === "ASSOCIAÇÃO EMPRESARIAL DE SANTA IZABEL DO OESTE");
$tem_permissao = $is_admin || strtoupper($usuario_dados['usuario_departamento']) === 'ASSISTENCIA_SOCIAL' || $is_associacao;

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
    // Consulta a inscrição com informações do usuário cidadão incluindo a foto
    $stmt = $conn->prepare("
        SELECT cs.*, cu.cad_usu_nome, cu.cad_usu_email, cu.cad_usu_foto
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
        'EM ANÁLISE FINANCEIRA' => 'status-documentacao',
        'FINANCEIRO APROVADO' => 'status-aprovado',
        'FINANCEIRO REPROVADO' => 'status-reprovado',
        'CADASTRO REPROVADO' => 'status-cancelado',
        'EM FASE DE SELEÇÃO' => 'status-espera',
        'CONTEMPLADO' => 'status-aprovado'
    ];
    return $classes[$status] ?? '';
}

// Função para verificar se foto existe
function verificarFoto($caminhoFoto) {
    if (empty($caminhoFoto)) return false;
    $caminhoCompleto = "../uploads/fotos_usuarios/" . $caminhoFoto;
    return file_exists($caminhoCompleto);
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../../img/logo_eai.ico" type="imagem/x-icon">
    <title>Visualizar Cadastro Habitacional - Sistema da Prefeitura</title>
    
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

        .visualizacao-container {
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

        /* Breadcrumb */
        .breadcrumb {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            color: #666;
            font-size: 0.9rem;
        }

        .breadcrumb a {
            color: #667eea;
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
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, #667eea, #5a6fd8);
            color: white;
            padding: 20px 25px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-header i {
            margin-right: 10px;
        }

        .card-body {
            padding: 25px;
        }

        /* Foto de perfil */
        .profile-photo-container {
            text-align: center;
            margin-bottom: 25px;
        }

        .profile-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #667eea;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            margin-bottom: 15px;
        }

        .profile-photo-placeholder {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #5a6fd8);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            font-weight: bold;
            margin: 0 auto 15px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .profile-name {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .profile-protocol {
            font-size: 1rem;
            color: #667eea;
            font-weight: 500;
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

        /* Info sections */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .info-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            border-left: 4px solid #667eea;
        }

        .info-section h4 {
            color: #2c3e50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            font-size: 1.1rem;
        }

        .info-section h4 i {
            margin-right: 10px;
            color: #667eea;
        }

        .info-item {
            margin-bottom: 15px;
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-weight: 600;
            color: #555;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }

        .info-value {
            color: #2c3e50;
            word-wrap: break-word;
            line-height: 1.4;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 5px;
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
            border-radius: 8px 8px 0 0;
        }

        .tab.active {
            color: #667eea;
            background: #f8f9fa;
        }

        .tab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background-color: #667eea;
        }

        .tab:hover {
            color: #667eea;
            background: #f8f9fa;
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
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #17a2b8;
        }

        .dependente-header {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            font-size: 1.1rem;
        }

        .dependente-header i {
            margin-right: 10px;
            color: #17a2b8;
        }

        /* Histórico */
        .historico-item {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            position: relative;
        }

        .historico-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .historico-acao {
            font-weight: 600;
            color: #2c3e50;
            font-size: 1.1rem;
        }

        .historico-data {
            font-size: 0.9rem;
            color: #666;
        }

        .historico-usuario {
            font-size: 0.9rem;
            color: #667eea;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }

        .historico-usuario i {
            margin-right: 5px;
        }

        .historico-observacao {
            color: #2c3e50;
            line-height: 1.6;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 3px solid #667eea;
        }

        /* Arquivos */
        .arquivo-item {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .arquivo-info {
            flex: 1;
        }

        .arquivo-nome {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }

        .arquivo-nome i {
            margin-right: 8px;
            color: #667eea;
        }

        .arquivo-meta {
            font-size: 0.9rem;
            color: #666;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }

        .arquivo-actions {
            display: flex;
            gap: 10px;
        }

        /* Botões */
        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background-color: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background-color: #5a6fd8;
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
            background-color: #28a745;
            color: white;
        }

        .btn-success:hover {
            background-color: #218838;
        }

        .btn-info {
            background-color: #17a2b8;
            color: white;
        }

        .btn-info:hover {
            background-color: #138496;
        }

        .btn-sm {
            padding: 8px 12px;
            font-size: 0.8rem;
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 20px;
            color: #ddd;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: #2c3e50;
        }

        /* Ações */
        .acoes-container {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
        }

        /* Responsivo */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .visualizacao-container {
                padding: 20px;
            }
            
            .page-title {
                font-size: 1.8rem;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                overflow-x: auto;
                white-space: nowrap;
            }

            .card-header {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }

            .arquivo-item {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }

            .acoes-container {
                flex-direction: column;
            }
        }

        /* Estilos para impressão */
        @media print {
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
            
            .btn,
            .tabs,
            .card-header {
                display: none !important;
            }
            
            .status-badge {
                border: 1px solid #333 !important;
                color: #333 !important;
                background: transparent !important;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="visualizacao-container">
            <!-- Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-eye"></i>
                    Visualizar Cadastro Habitacional
                </h1>
                <p class="page-subtitle">
                    Detalhes completos do cadastro habitacional
                </p>
            </div>

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
                        <span class="status-badge <?= getStatusClass($inscricao_atual['cad_social_status']) ?>">
                            <?= htmlspecialchars($inscricao_atual['cad_social_status']) ?>
                        </span>
                        <a href="assistencia_habitacao.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Foto de Perfil e Nome -->
                    <div class="profile-photo-container">
                        <?php if (!empty($inscricao_atual['cad_usu_foto']) && verificarFoto(caminhoFoto: $inscricao_atual['cad_usu_foto'])): ?>
                            <img src="../uploads/fotos_usuarios/<?= htmlspecialchars($inscricao_atual['cad_usu_foto']) ?>" 
                                 alt="Foto de <?= htmlspecialchars($inscricao_atual['cad_social_nome']) ?>" 
                                 class="profile-photo">
                        <?php else: ?>
                            <div class="profile-photo-placeholder">
                                <?= strtoupper(substr($inscricao_atual['cad_social_nome'], 0, 2)) ?>
                            </div>
                        <?php endif; ?>
                        <div class="profile-name"><?= htmlspecialchars($inscricao_atual['cad_social_nome']) ?></div>
                        <div class="profile-protocol">Protocolo: <?= htmlspecialchars($inscricao_atual['cad_social_protocolo']) ?></div>
                    </div>

                    <div class="info-grid">
                        <div class="info-section">
                            <h4><i class="fas fa-clipboard-list"></i> Dados do Protocolo</h4>
                            <div class="info-item">
                                <div class="info-label">Protocolo</div>
                                <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_protocolo']) ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Data de Cadastro</div>
                                <div class="info-value"><?= formatarData($inscricao_atual['cad_social_data_cadastro']) ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Programa de Interesse</div>
                                <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_programa_interesse'] ?? 'Não informado') ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Cadastrado por</div>
                                <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_usu_nome'] ?? 'Sistema') ?></div>
                            </div>
                        </div>

                        <div class="info-section">
                            <h4><i class="fas fa-user"></i> Dados Pessoais</h4>
                            <div class="info-item">
                                <div class="info-label">Nome Completo</div>
                                <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_nome']) ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">CPF</div>
                                <div class="info-value"><?= formatarCPF($inscricao_atual['cad_social_cpf']) ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">RG</div>
                                <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_rg'] ?? 'Não informado') ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Data de Nascimento</div>
                                <div class="info-value"><?= formatarDataBR($inscricao_atual['cad_social_data_nascimento']) ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Gênero</div>
                                <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_genero']) ?></div>
                            </div>
                        </div>

                        <div class="info-section">
                            <h4><i class="fas fa-phone"></i> Contato</h4>
                            <div class="info-item">
                                <div class="info-label">E-mail</div>
                                <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_email']) ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Celular</div>
                                <div class="info-value"><?= formatarTelefone($inscricao_atual['cad_social_celular']) ?></div>
                           </div>
                           <?php if ($inscricao_atual['cad_social_telefone']): ?>
                           <div class="info-item">
                               <div class="info-label">Telefone</div>
                               <div class="info-value"><?= formatarTelefone($inscricao_atual['cad_social_telefone']) ?></div>
                           </div>
                           <?php endif; ?>
                           <div class="info-item">
                               <div class="info-label">Autoriza Notificações</div>
                               <div class="info-value"><?= $inscricao_atual['cad_social_autoriza_email'] ? 'Sim' : 'Não' ?></div>
                           </div>
                       </div>

                       <div class="info-section">
                           <h4><i class="fas fa-home"></i> Endereço</h4>
                           <div class="info-item">
                               <div class="info-label">Endereço Completo</div>
                               <div class="info-value">
                                   <?= htmlspecialchars($inscricao_atual['cad_social_rua']) ?>, 
                                   <?= htmlspecialchars($inscricao_atual['cad_social_numero']) ?>
                                   <?= $inscricao_atual['cad_social_complemento'] ? ', ' . htmlspecialchars($inscricao_atual['cad_social_complemento']) : '' ?><br>
                                   <?= htmlspecialchars($inscricao_atual['cad_social_bairro']) ?><br>
                                   <?= htmlspecialchars($inscricao_atual['cad_social_cidade']) ?> - 
                                   CEP: <?= substr($inscricao_atual['cad_social_cep'], 0, 5) . '-' . substr($inscricao_atual['cad_social_cep'], 5) ?>
                               </div>
                           </div>
                           <?php if ($inscricao_atual['cad_social_ponto_referencia']): ?>
                           <div class="info-item">
                               <div class="info-label">Ponto de Referência</div>
                               <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_ponto_referencia']) ?></div>
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
                           <i class="fas fa-users"></i> Dependentes (<?= count($dependentes) ?>)
                       </button>
                       <button class="tab" onclick="showTab('historico')">
                           <i class="fas fa-history"></i> Histórico (<?= count($comentarios) ?>)
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
                                   <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_estado_civil']) ?></div>
                               </div>
                               <div class="info-item">
                                   <div class="info-label">Escolaridade</div>
                                   <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_escolaridade']) ?></div>
                               </div>
                               <div class="info-item">
                                   <div class="info-label">Possui Deficiência</div>
                                   <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_deficiencia']) ?></div>
                               </div>
                               <?php if ($inscricao_atual['cad_social_deficiencia'] !== 'NÃO' && $inscricao_atual['cad_social_deficiencia_fisica_detalhe']): ?>
                               <div class="info-item">
                                   <div class="info-label">Detalhes da Deficiência</div>
                                   <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_deficiencia_fisica_detalhe']) ?></div>
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
                                   <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_situacao_trabalho']) ?></div>
                               </div>
                               <?php if ($inscricao_atual['cad_social_situacao_trabalho'] != 'DESEMPREGADO'): ?>
                               <div class="info-item">
                                   <div class="info-label">Profissão</div>
                                   <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_profissao'] ?? 'Não informado') ?></div>
                               </div>
                               <div class="info-item">
                                   <div class="info-label">Empregador</div>
                                   <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_empregador'] ?? 'Não informado') ?></div>
                               </div>
                               <div class="info-item">
                                   <div class="info-label">Cargo</div>
                                   <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_cargo'] ?? 'Não informado') ?></div>
                               </div>
                               <div class="info-item">
                                   <div class="info-label">Tempo de Serviço</div>
                                   <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_tempo_servico'] ?? 'Não informado') ?></div>
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
                                   <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_tipo_moradia']) ?></div>
                               </div>
                               <div class="info-item">
                                   <div class="info-label">Situação da Propriedade</div>
                                   <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_situacao_propriedade']) ?></div>
                               </div>
                               <?php if ($inscricao_atual['cad_social_situacao_propriedade'] == 'ALUGADA' && $inscricao_atual['cad_social_valor_aluguel']): ?>
                               <div class="info-item">
                                   <div class="info-label">Valor do Aluguel</div>
                                   <div class="info-value">R$ <?= number_format($inscricao_atual['cad_social_valor_aluguel'], 2, ',', '.') ?></div>
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
                                   <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_conjuge_nome'] ?? 'Não informado') ?></div>
                               </div>
                               <?php if ($inscricao_atual['cad_social_conjuge_cpf']): ?>
                               <div class="info-item">
                                   <div class="info-label">CPF do Cônjuge</div>
                                   <div class="info-value"><?= formatarCPF($inscricao_atual['cad_social_conjuge_cpf']) ?></div>
                               </div>
                               <?php endif; ?>
                               <?php if ($inscricao_atual['cad_social_conjuge_data_nascimento']): ?>
                               <div class="info-item">
                                   <div class="info-label">Data de Nascimento</div>
                                   <div class="info-value"><?= formatarDataBR($inscricao_atual['cad_social_conjuge_data_nascimento']) ?></div>
                               </div>
                               <?php endif; ?>
                               <div class="info-item">
                                   <div class="info-label">Cônjuge possui renda</div>
                                   <div class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_conjuge_renda'] ?? 'Não informado') ?></div>
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
                               Dependente <?= $index + 1 ?>: <?= htmlspecialchars($dependente['cad_social_dependente_nome']) ?>
                           </div>
                           <div class="info-grid">
                               <div class="info-section">
                                   <div class="info-item">
                                       <div class="info-label">Data de Nascimento</div>
                                       <div class="info-value"><?= formatarDataBR($dependente['cad_social_dependente_data_nascimento']) ?></div>
                                   </div>
                                   <?php if ($dependente['cad_social_dependente_cpf']): ?>
                                   <div class="info-item">
                                       <div class="info-label">CPF</div>
                                       <div class="info-value"><?= formatarCPF($dependente['cad_social_dependente_cpf']) ?></div>
                                   </div>
                                   <?php endif; ?>
                               </div>
                               <div class="info-section">
                                   <div class="info-item">
                                       <div class="info-label">Possui Deficiência</div>
                                       <div class="info-value"><?= htmlspecialchars($dependente['cad_social_dependente_deficiencia']) ?></div>
                                   </div>
                                   <div class="info-item">
                                       <div class="info-label">Possui Renda</div>
                                       <div class="info-value"><?= htmlspecialchars($dependente['cad_social_dependente_renda']) ?></div>
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
                               <div class="historico-acao"><?= htmlspecialchars($comentario['cad_social_hist_acao']) ?></div>
                               <div class="historico-data"><?= formatarData($comentario['cad_social_hist_data']) ?></div>
                           </div>
                           <div class="historico-usuario">
                               <i class="fas fa-user"></i> <?= htmlspecialchars($comentario['usuario_nome'] ?? 'Sistema') ?>
                           </div>
                           <?php if ($comentario['cad_social_hist_observacao']): ?>
                           <div class="historico-observacao">
                               <?= nl2br(htmlspecialchars($comentario['cad_social_hist_observacao'])) ?>
                           </div>
                           <?php endif; ?>
                       </div>
                       <?php endforeach; ?>
                       <?php endif; ?>
                   </div>

                   <!-- Tab: Arquivos -->
                   <div id="arquivos" class="tab-content">
                       <!-- Arquivos do Sistema -->
                       <h4 style="margin-bottom: 20px; color: #2c3e50;">
                           <i class="fas fa-folder"></i> Arquivos do Sistema
                       </h4>
                       
                       <?php if (empty($arquivos)): ?>
                       <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-bottom: 30px; text-align: center; color: #666;">
                           <i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 15px; color: #ddd;"></i>
                           <p>Nenhum arquivo do sistema anexado.</p>
                       </div>
                       <?php else: ?>
                       <?php foreach ($arquivos as $arquivo): ?>
                       <div class="arquivo-item">
                           <div class="arquivo-info">
                               <div class="arquivo-nome">
                                   <i class="fas fa-file"></i> <?= htmlspecialchars($arquivo['cad_social_arq_nome_original'] ?? $arquivo['cad_social_arq_nome']) ?>
                               </div>
                               <div class="arquivo-meta">
                                   <span><i class="fas fa-user"></i> <?= htmlspecialchars($arquivo['usuario_nome'] ?? 'Sistema') ?></span>
                                   <span><i class="fas fa-calendar"></i> <?= formatarData($arquivo['cad_social_arq_data']) ?></span>
                                   <?php if (isset($arquivo['cad_social_arq_tamanho'])): ?>
                                   <span><i class="fas fa-weight"></i> <?= formatarTamanhoArquivo($arquivo['cad_social_arq_tamanho']) ?></span>
                                   <?php endif; ?>
                               </div>
                               <?php if ($arquivo['cad_social_arq_descricao']): ?>
                               <div style="margin-top: 8px; font-style: italic; color: #666; font-size: 0.9rem;">
                                   <?= htmlspecialchars($arquivo['cad_social_arq_descricao']) ?>
                               </div>
                               <?php endif; ?>
                           </div>
                           <div class="arquivo-actions">
                               <a href="../uploads/habitacao/sistema/<?= htmlspecialchars($arquivo['cad_social_arq_nome']) ?>" 
                                  class="btn btn-primary btn-sm" target="_blank" title="Visualizar">
                                   <i class="fas fa-eye"></i>
                               </a>
                               <a href="../uploads/habitacao/sistema/<?= htmlspecialchars($arquivo['cad_social_arq_nome']) ?>" 
                                  class="btn btn-success btn-sm" download title="Baixar">
                                   <i class="fas fa-download"></i>
                               </a>
                           </div>
                       </div>
                       <?php endforeach; ?>
                       <?php endif; ?>

                       <!-- Arquivos do Cidadão -->
                       <h4 style="margin: 40px 0 20px 0; color: #2c3e50;">
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
                       <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; text-align: center; color: #666;">
                           <i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 15px; color: #ddd;"></i>
                           <p>Nenhum arquivo do cidadão anexado.</p>
                       </div>
                       <?php else: ?>
                       <?php foreach ($arquivos_cidadao as $arquivo): ?>
                       <div class="arquivo-item">
                           <div class="arquivo-info">
                               <div class="arquivo-nome">
                                   <i class="fas <?= $arquivo['icon'] ?>"></i> <?= $arquivo['nome'] ?>
                               </div>
                               <div class="arquivo-meta">
                                   <span><i class="fas fa-user"></i> Cidadão</span>
                                   <span><i class="fas fa-calendar"></i> <?= formatarData($inscricao_atual['cad_social_data_cadastro']) ?></span>
                               </div>
                           </div>
                           <div class="arquivo-actions">
                               <a href="../uploads/habitacao/<?= htmlspecialchars($arquivo['arquivo']) ?>" 
                                  class="btn btn-primary btn-sm" target="_blank" title="Visualizar">
                                   <i class="fas fa-eye"></i>
                               </a>
                               <a href="../uploads/habitacao/<?= htmlspecialchars($arquivo['arquivo']) ?>" 
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
                   <div class="acoes-container">
                       <a href="assistencia_habitacao.php?id=<?= $inscricao_id ?>" class="btn btn-primary">
                           <i class="fas fa-edit"></i> Gerenciar Cadastro
                       </a>
                       <button type="button" class="btn btn-info" onclick="window.print()">
                           <i class="fas fa-print"></i> Imprimir
                       </button>
                       <button type="button" class="btn btn-success" onclick="exportarPDF()">
                           <i class="fas fa-file-pdf"></i> Exportar PDF
                       </button>
                       <a href="assistencia_habitacao.php" class="btn btn-secondary">
                           <i class="fas fa-arrow-left"></i> Voltar para Lista
                       </a>
                   </div>
               </div>
           </div>
       </div>
   </div>

   <!-- JavaScript -->
   <script src="assets/js/main.js"></script>
   <script>
       // Inicialização
       document.addEventListener('DOMContentLoaded', function() {
           initializePage();
       });

       function initializePage() {
           // Ajustar layout inicial
           adjustMainContent();
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

       // Função para exportar PDF
       function exportarPDF() {
           showNotification('Funcionalidade de exportação PDF em desenvolvimento.', 'info');
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

       // Atalhos de teclado
       document.addEventListener('keydown', function(e) {
           // Ctrl+P para imprimir
           if (e.ctrlKey && e.key === 'p') {
               e.preventDefault();
               window.print();
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

       // Adicionar clique duplo para copiar protocolo
       document.addEventListener('DOMContentLoaded', function() {
        const protocoloElement = document.querySelector('.profile-protocol');
           if (protocoloElement) {
               protocoloElement.style.cursor = 'pointer';
               protocoloElement.title = 'Clique duplo para copiar protocolo';
               protocoloElement.addEventListener('dblclick', function() {
                   const protocolo = this.textContent.replace('Protocolo: ', '');
                   copyToClipboard(protocolo);
               });
           }

           // Adicionar funcionalidade de zoom para a foto
           const profilePhoto = document.querySelector('.profile-photo');
           if (profilePhoto) {
               profilePhoto.style.cursor = 'pointer';
               profilePhoto.title = 'Clique para ampliar';
               profilePhoto.addEventListener('click', function() {
                   showPhotoModal(this.src, this.alt);
               });
           }
       });

       // Modal para ampliar foto
       function showPhotoModal(src, alt) {
           const modal = document.createElement('div');
           modal.style.cssText = `
               position: fixed;
               top: 0;
               left: 0;
               width: 100%;
               height: 100%;
               background: rgba(0, 0, 0, 0.8);
               display: flex;
               align-items: center;
               justify-content: center;
               z-index: 9999;
               cursor: pointer;
           `;

           const img = document.createElement('img');
           img.src = src;
           img.alt = alt;
           img.style.cssText = `
               max-width: 90%;
               max-height: 90%;
               border-radius: 10px;
               box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
           `;

           modal.appendChild(img);
           document.body.appendChild(modal);

           // Fechar ao clicar
           modal.addEventListener('click', function() {
               document.body.removeChild(modal);
           });

           // Fechar com ESC
           const closeOnEsc = function(e) {
               if (e.key === 'Escape') {
                   document.body.removeChild(modal);
                   document.removeEventListener('keydown', closeOnEsc);
               }
           };
           document.addEventListener('keydown', closeOnEsc);
       }

       // Função para imprimir com foto
       function printWithPhoto() {
           const printContent = `
               <!DOCTYPE html>
               <html>
               <head>
                   <title>Cadastro Habitacional - <?= htmlspecialchars($inscricao_atual['cad_social_protocolo']) ?></title>
                   <style>
                       body { 
                           font-family: Arial, sans-serif; 
                           margin: 20px; 
                           line-height: 1.4;
                       }
                       .header { 
                           text-align: center; 
                           margin-bottom: 30px; 
                           border-bottom: 2px solid #333; 
                           padding-bottom: 20px; 
                       }
                       .profile-section {
                           text-align: center;
                           margin-bottom: 30px;
                       }
                       .profile-photo-print {
                           width: 100px;
                           height: 100px;
                           border-radius: 50%;
                           object-fit: cover;
                           border: 2px solid #333;
                           margin-bottom: 15px;
                       }
                       .profile-name-print {
                           font-size: 1.2rem;
                           font-weight: bold;
                           margin-bottom: 5px;
                       }
                       .profile-protocol-print {
                           font-size: 1rem;
                           color: #666;
                       }
                       .info-section { 
                           margin-bottom: 25px; 
                           page-break-inside: avoid;
                       }
                       .info-section h3 { 
                           color: #333; 
                           border-bottom: 1px solid #ccc; 
                           padding-bottom: 5px; 
                           margin-bottom: 15px;
                       }
                       .info-item { 
                           margin: 8px 0; 
                           display: flex;
                       }
                       .info-label { 
                           font-weight: bold; 
                           display: inline-block; 
                           width: 200px; 
                           margin-right: 10px;
                       }
                       .info-value {
                           flex: 1;
                       }
                       .status-badge { 
                           padding: 5px 10px; 
                           border: 1px solid #333; 
                           display: inline-block;
                           margin-bottom: 10px;
                       }
                       .footer {
                           margin-top: 50px; 
                           text-align: center; 
                           font-size: 12px; 
                           color: #666;
                           border-top: 1px solid #ccc;
                           padding-top: 15px;
                       }
                       @media print { 
                           body { margin: 0; }
                           .header { page-break-after: avoid; }
                       }
                   </style>
               </head>
               <body>
                   <div class="header">
                       <h1>Prefeitura Municipal de Santa Izabel do Oeste</h1>
                       <h2>Secretaria de Assistência Social</h2>
                       <h3>Cadastro Habitacional</h3>
                   </div>
                   
                   <div class="profile-section">
                       <?php if (!empty($inscricao_atual['cad_usu_foto']) && verificarFoto($inscricao_atual['cad_usu_foto'])): ?>
                       <img src="../uploads/fotos_usuarios/<?= htmlspecialchars($inscricao_atual['cad_usu_foto']) ?>" 
                            alt="Foto de <?= htmlspecialchars($inscricao_atual['cad_social_nome']) ?>" 
                            class="profile-photo-print">
                       <?php endif; ?>
                       <div class="profile-name-print"><?= htmlspecialchars($inscricao_atual['cad_social_nome']) ?></div>
                       <div class="profile-protocol-print">Protocolo: <?= htmlspecialchars($inscricao_atual['cad_social_protocolo']) ?></div>
                       <div class="status-badge">Status: <?= htmlspecialchars($inscricao_atual['cad_social_status']) ?></div>
                   </div>
                   
                   <div class="info-section">
                       <h3>Dados Pessoais</h3>
                       <div class="info-item">
                           <span class="info-label">Nome Completo:</span>
                           <span class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_nome']) ?></span>
                       </div>
                       <div class="info-item">
                           <span class="info-label">CPF:</span>
                           <span class="info-value"><?= formatarCPF($inscricao_atual['cad_social_cpf']) ?></span>
                       </div>
                       <div class="info-item">
                           <span class="info-label">Data de Nascimento:</span>
                           <span class="info-value"><?= formatarDataBR($inscricao_atual['cad_social_data_nascimento']) ?></span>
                       </div>
                       <div class="info-item">
                           <span class="info-label">Estado Civil:</span>
                           <span class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_estado_civil']) ?></span>
                       </div>
                       <div class="info-item">
                           <span class="info-label">Gênero:</span>
                           <span class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_genero']) ?></span>
                       </div>
                   </div>
                   
                   <div class="info-section">
                       <h3>Contato</h3>
                       <div class="info-item">
                           <span class="info-label">E-mail:</span>
                           <span class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_email']) ?></span>
                       </div>
                       <div class="info-item">
                           <span class="info-label">Celular:</span>
                           <span class="info-value"><?= formatarTelefone($inscricao_atual['cad_social_celular']) ?></span>
                       </div>
                       <?php if ($inscricao_atual['cad_social_telefone']): ?>
                       <div class="info-item">
                           <span class="info-label">Telefone:</span>
                           <span class="info-value"><?= formatarTelefone($inscricao_atual['cad_social_telefone']) ?></span>
                       </div>
                       <?php endif; ?>
                   </div>
                   
                   <div class="info-section">
                       <h3>Endereço</h3>
                       <div class="info-item">
                           <span class="info-label">Endereço:</span>
                           <span class="info-value">
                               <?= htmlspecialchars($inscricao_atual['cad_social_rua']) ?>, 
                               <?= htmlspecialchars($inscricao_atual['cad_social_numero']) ?>
                               <?= $inscricao_atual['cad_social_complemento'] ? ', ' . htmlspecialchars($inscricao_atual['cad_social_complemento']) : '' ?><br>
                               <?= htmlspecialchars($inscricao_atual['cad_social_bairro']) ?><br>
                               <?= htmlspecialchars($inscricao_atual['cad_social_cidade']) ?> - 
                               CEP: <?= substr($inscricao_atual['cad_social_cep'], 0, 5) . '-' . substr($inscricao_atual['cad_social_cep'], 5) ?>
                           </span>
                       </div>
                   </div>
                   
                   <div class="info-section">
                       <h3>Programa Habitacional</h3>
                       <div class="info-item">
                           <span class="info-label">Programa de Interesse:</span>
                           <span class="info-value"><?= htmlspecialchars($inscricao_atual['cad_social_programa_interesse']) ?></span>
                       </div>
                       <div class="info-item">
                           <span class="info-label">Data de Cadastro:</span>
                           <span class="info-value"><?= formatarData($inscricao_atual['cad_social_data_cadastro']) ?></span>
                       </div>
                       <div class="info-item">
                           <span class="info-label">Cadastrado por:</span>
                           <span class="info-value"><?= htmlspecialchars($inscricao_atual['cad_usu_nome'] ?? 'Sistema') ?></span>
                       </div>
                   </div>
                   
                   <?php if (!empty($dependentes)): ?>
                   <div class="info-section">
                       <h3>Dependentes (<?= count($dependentes) ?>)</h3>
                       <?php foreach ($dependentes as $index => $dependente): ?>
                       <div style="margin-bottom: 15px; padding: 10px; border: 1px solid #ccc; border-radius: 5px;">
                           <div class="info-item">
                               <span class="info-label">Nome:</span>
                               <span class="info-value"><?= htmlspecialchars($dependente['cad_social_dependente_nome']) ?></span>
                           </div>
                           <div class="info-item">
                               <span class="info-label">Data de Nascimento:</span>
                               <span class="info-value"><?= formatarDataBR($dependente['cad_social_dependente_data_nascimento']) ?></span>
                           </div>
                       </div>
                       <?php endforeach; ?>
                   </div>
                   <?php endif; ?>
                   
                   <div class="footer">
                       <p><strong>Data de Impressão:</strong> <?= date('d/m/Y H:i:s') ?></p>
                       <p>Sistema de Gerenciamento Habitacional - Prefeitura Municipal de Santa Izabel do Oeste</p>
                       <p>Este documento foi gerado automaticamente pelo sistema e contém informações confidenciais.</p>
                   </div>
               </body>
               </html>
           `;
           
           const printWindow = window.open('', '_blank');
           printWindow.document.write(printContent);
           printWindow.document.close();
           printWindow.focus();
           printWindow.print();
           printWindow.close();
       }

       // Sobrescrever função de impressão padrão para incluir foto
       window.addEventListener('beforeprint', function() {
           printWithPhoto();
       });
   </script>
</body>
</html>
<?php
/**
 * Arquivo: listar_produtores.php
 * Descrição: Lista todos os produtores cadastrados
 */

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

// Inicializar dados do usuário (mesmo código do template)
$usuario_id = $_SESSION['usersystem_id'];
$usuario_dados = [];

try {
    $stmt = $conn->prepare("
        SELECT 
            usuario_id, usuario_nome, usuario_departamento, usuario_nivel_id,
            usuario_email, usuario_telefone, usuario_status, usuario_data_criacao,
            usuario_ultimo_acesso
        FROM tb_usuarios_sistema 
        WHERE usuario_id = :id
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
$availableModules = $menuManager->getAvailableModules();

// Parâmetros de busca e paginação
$busca = $_GET['busca'] ?? '';
$pagina = (int)($_GET['pagina'] ?? 1);
$registros_por_pagina = 20;
$offset = ($pagina - 1) * $registros_por_pagina;

// Construir query de busca
$where_conditions = ["p.cad_pro_status = 'ativo'"];
$params = [];

if (!empty($busca)) {
    $where_conditions[] = "(p.cad_pro_nome LIKE :busca OR p.cad_pro_cpf LIKE :busca)";
    $params[':busca'] = "%$busca%";
}

$where_clause = implode(' AND ', $where_conditions);

// Buscar produtores
try {
    // Contar total de registros
    $count_sql = "
        SELECT COUNT(*) as total
        FROM tb_cad_produtores p
        WHERE $where_clause
    ";
    $count_stmt = $conn->prepare($count_sql);
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_registros = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Buscar dados
    $sql = "
        SELECT 
            p.*,
            c.com_nome,
            b.ban_nome,
            b.ban_codigo,
            u.usuario_nome as cadastrado_por
        FROM tb_cad_produtores p
        LEFT JOIN tb_cad_comunidades c ON p.cad_pro_comunidade_id = c.com_id
        LEFT JOIN tb_cad_bancos b ON p.cad_pro_banco_id = b.ban_id
        LEFT JOIN tb_usuarios_sistema u ON p.cad_pro_usuario_cadastro = u.usuario_id
        WHERE $where_clause
        ORDER BY p.cad_pro_nome
        LIMIT :offset, :limit
    ";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
    $stmt->execute();
    
    $produtores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Erro ao buscar produtores: " . $e->getMessage());
    $produtores = [];
    $total_registros = 0;
}

$total_paginas = ceil($total_registros / $registros_por_pagina);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../../img/logo_eai.ico" type="imagem/x-icon">
    <title>Lista de Produtores - Sistema da Prefeitura</title>
    
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
        .lista-container {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .lista-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .lista-title {
            font-size: 2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .btn-novo {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid white;
            padding: 12px 25px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-novo:hover {
            background: white;
            color: #667eea;
        }

        /* Filtros */
        .filtros-container {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .filtros-row {
            display: flex;
            gap: 20px;
            align-items: end;
        }

        .filtro-group {
            flex: 1;
        }

        .filtro-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }

        .filtro-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
        }

        .btn-filtrar {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Tabela */
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
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
            padding: 20px 15px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }

        tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            padding: 6px 12px;
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

        .acoes {
            display: flex;
            gap: 8px;
        }

        .btn-acao {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
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
        }

        /* Paginação */
        .paginacao {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 25px;
            background: white;
            border-radius: 15px;
            margin-top: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .pag-btn {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            background: white;
            color: #374151;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .pag-btn:hover,
        .pag-btn.active {
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
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .lista-header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .filtros-row {
                flex-direction: column;
            }
            
            .table-responsive {
                font-size: 14px;
            }
            
            th, td {
                padding: 10px 8px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="lista-container">
            <!-- Header -->
            <div class="lista-header">
                <h1 class="lista-title">
                    <i class="fas fa-user-tie"></i>
                    Lista de Produtores
                </h1>
                <a href="cadastros.php?aba=produtores" class="btn-novo">
                    <i class="fas fa-plus"></i>
                    Novo Produtor
                </a>
            </div>

            <!-- Filtros -->
            <div class="filtros-container">
                <form method="GET" class="filtros-row">
                    <div class="filtro-group">
                        <label>Buscar por Nome ou CPF</label>
                        <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>" 
                               placeholder="Digite o nome ou CPF...">
                    </div>
                    <button type="submit" class="btn-filtrar">
                        <i class="fas fa-search"></i>
                        Buscar
                    </button>
                </form>
            </div>

            <!-- Tabela -->
            <div class="table-container">
                <?php if (count($produtores) > 0): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>CPF</th>
                                    <th>Telefone</th>
                                    <th>Comunidade</th>
                                    <th>Banco</th>
                                    <th>Status</th>
                                    <th>Cadastrado em</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($produtores as $produtor): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($produtor['cad_pro_nome']) ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            Titular: <?= htmlspecialchars($produtor['cad_pro_titular_nome']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $produtor['cad_pro_cpf']) ?>
                                    </td>
                                    <td><?= htmlspecialchars($produtor['cad_pro_telefone']) ?></td>
                                    <td><?= htmlspecialchars($produtor['com_nome'] ?? 'Não informada') ?></td>
                                    <td>
                                        <?= $produtor['ban_codigo'] ?> - <?= htmlspecialchars($produtor['ban_nome']) ?>
                                        <br>
                                        <small class="text-muted">
                                            Ag: <?= $produtor['cad_pro_agencia'] ?> 
                                            Conta: <?= $produtor['cad_pro_conta'] ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= $produtor['cad_pro_status'] ?>">
                                            <?= ucfirst($produtor['cad_pro_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= date('d/m/Y H:i', strtotime($produtor['cad_pro_data_cadastro'])) ?>
                                        <br>
                                        <small class="text-muted">
                                            por <?= htmlspecialchars($produtor['cadastrado_por']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="acoes">
                                            <button class="btn-acao btn-editar" 
                                                    onclick="editarProdutor(<?= $produtor['cad_pro_id'] ?>)"
                                                    title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn-acao btn-excluir" 
                                                    onclick="excluirProdutor(<?= $produtor['cad_pro_id'] ?>, '<?= htmlspecialchars($produtor['cad_pro_nome']) ?>')"
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
                <?php else: ?>
                    <div class="sem-registros">
                        <i class="fas fa-user-times"></i>
                        <h3>Nenhum produtor encontrado</h3>
                        <p>
                            <?php if (!empty($busca)): ?>
                                Não foram encontrados produtores com os critérios de busca informados.
                            <?php else: ?>
                                Ainda não há produtores cadastrados no sistema.
                            <?php endif; ?>
                        </p>
                        <a href="cadastros.php?aba=produtores" class="btn-novo" style="margin-top: 20px;">
                            <i class="fas fa-plus"></i>
                            Cadastrar Primeiro Produtor
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Paginação -->
            <?php if ($total_paginas > 1): ?>
            <div class="paginacao">
                <?php if ($pagina > 1): ?>
                    <a href="?pagina=<?= $pagina - 1 ?>&busca=<?= urlencode($busca) ?>" class="pag-btn">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php endif; ?>

                <?php for ($i = max(1, $pagina - 2); $i <= min($total_paginas, $pagina + 2); $i++): ?>
                    <a href="?pagina=<?= $i ?>&busca=<?= urlencode($busca) ?>" 
                       class="pag-btn <?= $i == $pagina ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($pagina < $total_paginas): ?>
                    <a href="?pagina=<?= $pagina + 1 ?>&busca=<?= urlencode($busca) ?>" class="pag-btn">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="assets/js/main.js"></script>
    <script>
        function editarProdutor(id) {
            // Redirecionar para página de edição ou abrir modal
            window.location.href = `editar_produtor.php?id=${id}`;
        }

        function excluirProdutor(id, nome) {
            if (confirm(`Tem certeza que deseja excluir o produtor "${nome}"?\n\nEsta ação não pode ser desfeita.`)) {
                // Fazer requisição AJAX para excluir
                fetch('controller/excluir_produtor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id: id })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Produtor excluído com sucesso!');
                        location.reload();
                    } else {
                        alert('Erro ao excluir produtor: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Erro interno do sistema.');
                    console.error('Error:', error);
                });
            }
        }
    </script>
</body>
</html>
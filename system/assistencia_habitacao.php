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

// Buscar informações do usuário logado
$usuario_id = $_SESSION['usersystem_id'];
$usuario_nome = $_SESSION['usersystem_nome'] ?? 'Usuário';
$usuario_departamento = null;
$usuario_nivel_id = null;
$is_admin = false;

// NOVA FUNÇÃO: Verificar se é usuário da Associação Empresarial
function isAssociacaoEmpresarial($nome_usuario) {
    return strtoupper(trim($nome_usuario)) === "ASSOCIAÇÃO EMPRESARIAL DE SANTA IZABEL DO OESTE";
}

// Verificar se é usuário da Associação Empresarial
$is_associacao = isAssociacaoEmpresarial($usuario_nome);

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
        
        // Atualizar verificação da associação com o nome do banco
        $is_associacao = isAssociacaoEmpresarial($usuario_nome);
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar dados do usuário: " . $e->getMessage());
}

// Verificar permissões de acesso
// MODIFICADO: Adicionar permissão para usuários da Associação Empresarial
$tem_permissao = $is_admin || strtoupper($usuario_departamento) === 'ASSISTENCIA_SOCIAL' || $is_associacao;

if (!$tem_permissao) {
    header("Location: dashboard.php?erro=acesso_negado");
    exit;
}

// Definição de variáveis
$mensagem = "";
$tipo_mensagem = "";
$is_exibir_modal = false;
$inscricao_atual = null;
$dependentes = [];
$comentarios = [];
$arquivos = [];

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
                
                // NOVO: Verificar se usuário da associação pode alterar status
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

                // Buscar o ID da solicitação para registrar no histórico
                $stmt_sol_id = $conn->prepare("SELECT solicitacao_id FROM tb_solicitacoes WHERE protocolo = :protocolo LIMIT 1");
                $stmt_sol_id->bindParam(':protocolo', $inscricao_data['cad_social_protocolo']);
                $stmt_sol_id->execute();

                if ($stmt_sol_id->rowCount() > 0) {
                    $solicitacao_data = $stmt_sol_id->fetch(PDO::FETCH_ASSOC);
                    $solicitacao_id = $solicitacao_data['solicitacao_id'];
                    
                    // NOVO: Inserir registro no histórico de solicitações
                    $stmt_hist_sol = $conn->prepare("
                        INSERT INTO tb_solicitacoes_historico 
                        (solicitacao_id, status_anterior, status_novo, detalhes, usuario_operacao, data_operacao) 
                        VALUES 
                        (:solicitacao_id, :status_anterior, :status_novo, :detalhes, :usuario_operacao, NOW())
                    ");
                    
                    $detalhes_historico = "Status alterado de '{$status_anterior}' para '{$novo_status}'" . 
                                        (!empty($observacao) ? ". Observação: {$observacao}" : "");
                    
                    $stmt_hist_sol->bindParam(':solicitacao_id', $solicitacao_id);
                    $stmt_hist_sol->bindParam(':status_anterior', $status_anterior);
                    $stmt_hist_sol->bindParam(':status_novo', $novo_status);
                    $stmt_hist_sol->bindParam(':detalhes', $detalhes_historico);
                    $stmt_hist_sol->bindParam(':usuario_operacao', $_SESSION['usersystem_id']);
                    $stmt_hist_sol->execute();
                }

                // Registrar no histórico da tb_cad_social
                $stmt = $conn->prepare("INSERT INTO tb_cad_social_historico (cad_social_id, cad_social_hist_acao, cad_social_hist_observacao, cad_social_hist_usuario, cad_social_hist_data) VALUES (:inscricao_id, :acao, :observacao, :usuario, NOW())");
                $acao_hist = "Alteração de status: {$status_anterior} → {$novo_status}";
                $stmt->bindParam(':inscricao_id', $inscricao_id);
                $stmt->bindParam(':acao', $acao_hist);
                $stmt->bindParam(':observacao', $observacao);
                $stmt->bindParam(':usuario', $usuario_id);
                $stmt->execute();

                $conn->commit();

                // Log da atividade
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

// Processamento de upload de arquivos
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['acao']) && $_POST['acao'] == 'anexar_arquivo') {
    $inscricao_id = filter_input(INPUT_POST, 'inscricao_id', FILTER_VALIDATE_INT);
    $descricao_arquivo = sanitizeInput($_POST['descricao_arquivo'] ?? '');
    
    if (isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] == 0) {
        $arquivo = $_FILES['arquivo'];
        
        // Validações
        $tipos_permitidos = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $tamanho_maximo = 10 * 1024 * 1024; // 10MB
        
        if (!in_array($arquivo['type'], $tipos_permitidos)) {
            $mensagem = "Tipo de arquivo não permitido. Apenas PDF, JPG, PNG, DOC e DOCX são aceitos.";
            $tipo_mensagem = "error";
        } elseif ($arquivo['size'] > $tamanho_maximo) {
            $mensagem = "O arquivo excede o tamanho máximo permitido (10MB).";
            $tipo_mensagem = "error";
        } else {
            // Gerar nome único para o arquivo
            $extensao = pathinfo($arquivo['name'], PATHINFO_EXTENSION);
            $nome_arquivo = "HABSYS_" . $inscricao_id . "_" . date('Ymd_His') . "_" . uniqid() . ".{$extensao}";
            
            $upload_dir = "../uploads/habitacao/sistema/";
            
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $caminho_completo = $upload_dir . $nome_arquivo;
            
            if (move_uploaded_file($arquivo['tmp_name'], $caminho_completo)) {
                try {
                    $conn->beginTransaction();
                    
                    // Registrar arquivo no banco
                    $stmt = $conn->prepare("INSERT INTO tb_cad_social_arquivos (cad_social_id, cad_social_arq_nome, cad_social_arq_nome_original, cad_social_arq_descricao, cad_social_arq_tamanho, cad_social_arq_tipo, cad_social_arq_usuario, cad_social_arq_data) VALUES (:inscricao_id, :nome_arquivo, :nome_original, :descricao, :tamanho, :tipo, :usuario, NOW())");
                    $stmt->bindParam(':inscricao_id', $inscricao_id);
                    $stmt->bindParam(':nome_arquivo', $nome_arquivo);
                    $stmt->bindParam(':nome_original', $arquivo['name']);
                    $stmt->bindParam(':descricao', $descricao_arquivo);
                    $stmt->bindParam(':tamanho', $arquivo['size']);
                    $stmt->bindParam(':tipo', $arquivo['type']);
                    $stmt->bindParam(':usuario', $usuario_id);
                    $stmt->execute();
                    
                    // Registrar no histórico
                    $stmt = $conn->prepare("INSERT INTO tb_cad_social_historico (cad_social_id, cad_social_hist_acao, cad_social_hist_observacao, cad_social_hist_usuario, cad_social_hist_data) VALUES (:inscricao_id, :acao, :observacao, :usuario, NOW())");
                    $acao = "Arquivo anexado";
                    $observacao = "Arquivo: {$arquivo['name']} - {$descricao_arquivo}";
                    $stmt->bindParam(':inscricao_id', $inscricao_id);
                    $stmt->bindParam(':acao', $acao);
                    $stmt->bindParam(':observacao', $observacao);
                    $stmt->bindParam(':usuario', $usuario_id);
                    $stmt->execute();
                    
                    $conn->commit();
                    
                    logActivity($conn, 'Arquivo Anexado', $arquivo['name'], $usuario_id, $inscricao_id);
                    
                    $mensagem = "Arquivo anexado com sucesso!";
                    $tipo_mensagem = "success";
                } catch (PDOException $e) {
                    $conn->rollBack();
                    unlink($caminho_completo); // Remove arquivo se houve erro no banco
                    $mensagem = "Erro ao registrar arquivo: " . $e->getMessage();
                    $tipo_mensagem = "error";
                }
            } else {
                $mensagem = "Erro ao fazer upload do arquivo.";
                $tipo_mensagem = "error";
            }
        }
    } else {
        $mensagem = "Nenhum arquivo foi selecionado ou ocorreu um erro no upload.";
        $tipo_mensagem = "error";
    }
}

// Buscar inscrição específica para exibir detalhes
if (isset($_GET['id'])) {
    $inscricao_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($inscricao_id) {
        $is_exibir_modal = true;
        
        try {
            // Consulta a inscrição
            $stmt = $conn->prepare("SELECT cs.*, cu.cad_usu_nome FROM tb_cad_social cs LEFT JOIN tb_cad_usuarios cu ON cs.cad_usu_id = cu.cad_usu_id WHERE cs.cad_social_id = :id");
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
                $stmt = $conn->prepare("SELECT h.*, u.usuario_nome FROM tb_cad_social_historico h LEFT JOIN tb_usuarios_sistema u ON h.cad_social_hist_usuario = u.usuario_id WHERE h.cad_social_id = :id ORDER BY h.cad_social_hist_data DESC");
                $stmt->bindParam(':id', $inscricao_id);
                $stmt->execute();
                $comentarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Busca os arquivos anexados
                $stmt = $conn->prepare("SELECT a.*, u.usuario_nome FROM tb_cad_social_arquivos a LEFT JOIN tb_usuarios_sistema u ON a.cad_social_arq_usuario = u.usuario_id WHERE a.cad_social_id = :id ORDER BY a.cad_social_arq_data DESC");
                $stmt->bindParam(':id', $inscricao_id);
                $stmt->execute();
                $arquivos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $mensagem = "Inscrição não encontrada.";
                $tipo_mensagem = "error";
            }
        } catch (PDOException $e) {
            $mensagem = "Erro ao buscar informações: " . $e->getMessage();
            $tipo_mensagem = "error";
        }
    }
}

// Parâmetros de paginação e filtros
$registros_por_pagina = 15;
$pagina_atual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina_atual - 1) * $registros_por_pagina;

// MODIFICADO: Filtros de busca com lógica especial para Associação Empresarial
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
            LIMIT :offset, :limit";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $registros_por_pagina, PDO::PARAM_INT);
    $stmt->execute();
    
    $inscricoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $mensagem = "Erro ao buscar inscrições: " . $e->getMessage();
    $tipo_mensagem = "error";
    error_log("Erro na consulta de inscrições: " . $e->getMessage());
}

$total_paginas = ceil($total_registros / $registros_por_pagina);

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

// Lista de programas habitacionais
$programas_habitacionais = [
    'HABITASIO',
];

// Funções auxiliares
function formatarCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) != 11) return $cpf;
    return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
}

function formatarData($data) {
    return date('d/m/Y H:i', strtotime($data));
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

// MODIFICADO: Definir tema baseado no usuário
if ($is_associacao) {
    $titulo_sistema = 'Associação Empresarial - Análise Financeira';
    $cor_tema = '#4a90e2';
} elseif ($is_admin) {
    $titulo_sistema = 'Administração Geral';
    $cor_tema = '#e74c3c';
} else {
    $titulo_sistema = 'Assistência Social';
    $cor_tema = '#e91e63';
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciamento Habitacional - Sistema da Prefeitura</title>
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

        /* Sidebar - Mesmo padrão dos outros arquivos */
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

        /* Alertas */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            border: none;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .alert i {
            margin-right: 12px;
            font-size: 1.2rem;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
        }

        .alert-error {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
        }

        .alert-warning {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
        }

        .alert-info {
            background: linear-gradient(135deg, #d1ecf1, #bee5eb);
            color: #0c5460;
        }

        /* Cards de Estatísticas */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.15);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--secondary-color), #3498db);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--secondary-color);
            margin-bottom: 8px;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 2rem;
            color: rgba(0, 0, 0, 0.1);
        }

        /* Filtros */
        .filters-container {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
        }

        .filters-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 20px;
        }

        .filters-title {
            font-size: 1.2rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
        }

        .filters-title i {
            margin-right: 10px;
            color: var(--secondary-color);
        }

        .filters-toggle {
            background: none;
            border: none;
            color: var(--secondary-color);
            cursor: pointer;
            font-size: 1.1rem;
            padding: 5px;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        .filters-toggle:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }

        .filters-content {
            max-height: 1000px;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .filters-content.collapsed {
            max-height: 0;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-weight: 500;
            margin-bottom: 5px;
            color: var(--text-color);
            font-size: 0.9rem;
        }

        .filter-group input,
        .filter-group select {
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 2px rgba(233, 30, 99, 0.1);
            outline: none;
        }

        .filters-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
            min-width: 100px;
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

        .btn-warning {
            background-color: var(--warning-color);
            color: #212529;
        }

        .btn-warning:hover {
            background-color: #e0a800;
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
        }

        .btn-info {
            background-color: var(--info-color);
            color: white;
        }

        .btn-info:hover {
            background-color: #138496;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Tabela */
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 25px;
        }

        .table-header {
            padding: 20px 25px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        }

        .table-title {
            font-size: 1.2rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
            font-weight: 600;
        }

        .table-title i {
            margin-right: 10px;
            color: var(--secondary-color);
        }

        .table-info {
            color: #666;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .table th,
        .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }

        .table th {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            font-weight: 600;
            color: var(--text-color);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table tbody tr {
            transition: background-color 0.3s;
        }

        .table tbody tr:hover {
            background-color: #f8f9fa;
        }

        /* Status badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
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

        /* Actions */
        .actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .btn-action {
            padding: 6px 10px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: all 0.3s;
            min-width: auto;
        }

        .btn-view {
            background-color: var(--info-color);
            color: white;
        }

        .btn-view:hover {
            background-color: #138496;
        }

        .btn-edit {
            background-color: var(--warning-color);
            color: #212529;
        }

        .btn-edit:hover {
            background-color: #e0a800;
        }

        .btn-comment {
            background-color: #6c757d;
            color: white;
        }

        .btn-comment:hover {
            background-color: #545b62;
        }

        .btn-attach {
            background-color: #17a2b8;
            color: white;
        }

        .btn-attach:hover {
            background-color: #138496;
        }

        /* Paginação */
        .pagination-container {
            padding: 20px 25px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
        }

        .pagination-info {
            color: #666;
            font-size: 0.9rem;
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
            border-radius: 6px;
            font-size: 0.9rem;
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

        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
            background: linear-gradient(135deg, var(--secondary-color), #c2185b);
            color: white;
        }

        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .modal-title i {
            margin-right: 10px;
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
            color: var(--text-color);
        }

        .form-group label.required::after {
            content: "*";
            color: var(--danger-color);
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
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 2px rgba(233, 30, 99, 0.1);
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
            border-top: 4px solid var(--secondary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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

            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .filters-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .table-container {
                overflow-x: auto;
            }

            .actions {
                flex-direction: column;
            }

            .modal {
                max-width: 95vw;
                max-height: 95vh;
            }

            .modal-body {
                padding: 20px;
                max-height: 60vh;
            }
        }

        .mobile-toggle {
            display: none;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #ddd;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: var(--text-color);
        }

        .empty-state p {
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .btn-info {
            background-color: #17a2b8;
            color: white;
        }

        .btn-info:hover {
            background-color: #138496;
        }

        /* Estilo para destacar o botão principal */
        .btn-view {
            background-color: var(--secondary-color);
            color: white;
            font-weight: 600;
        }

        .btn-view:hover {
            background-color: #c2185b;
            transform: scale(1.05);
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
            
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-leaf"></i></span>
                    <span class="menu-text">Agricultura</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Projetos</a></li>
                    <li><a href="#" class="submenu-link">Programas</a></li>
                    <li><a href="#" class="submenu-link">Relatórios</a></li>
                </ul>
            </li>
            
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
            
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-palette"></i></span>
                    <span class="menu-text">Cultura e Turismo</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Eventos</a></li>
                    <li><a href="#" class="submenu-link">Pontos Turísticos</a></li>
                    <li><a href="#" class="submenu-link">Relatórios</a></li>
                </ul>
            </li>
            
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-graduation-cap"></i></span>
                    <span class="menu-text">Educação</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Escolas</a></li>
                    <li><a href="#" class="submenu-link">Professores</a></li>
                    <li><a href="#" class="submenu-link">Alunos</a></li>
                    <li><a href="#" class="submenu-link">Relatórios</a></li>
                </ul>
            </li>
            
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-running"></i></span>
                    <span class="menu-text">Esporte</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Eventos</a></li>
                    <li><a href="#" class="submenu-link">Equipamentos</a></li>
                    <li><a href="#" class="submenu-link">Relatórios</a></li>
                </ul>
            </li>
            
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-money-bill-wave"></i></span>
                    <span class="menu-text">Fazenda</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Orçamento</a></li>
                    <li><a href="#" class="submenu-link">Receitas</a></li>
                    <li><a href="#" class="submenu-link">Despesas</a></li>
                    <li><a href="#" class="submenu-link">Relatórios</a></li>
                </ul>
            </li>
            
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-search"></i></span>
                    <span class="menu-text">Fiscalização</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Denúncias</a></li>
                    <li><a href="#" class="submenu-link">Fiscalizações</a></li>
                    <li><a href="#" class="submenu-link">Relatórios</a></li>
                </ul>
            </li>
            
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-tree"></i></span>
                    <span class="menu-text">Meio Ambiente</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Licenciamentos</a></li>
                    <li><a href="#" class="submenu-link">Projetos</a></li>
                    <li><a href="#" class="submenu-link">Relatórios</a></li>
                </ul>
            </li>
            
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-hard-hat"></i></span>
                    <span class="menu-text">Obras</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Projetos</a></li>
                    <li><a href="#" class="submenu-link">Licitações</a></li>
                    <li><a href="#" class="submenu-link">Andamento</a></li>
                    <li><a href="#" class="submenu-link">Relatórios</a></li>
                </ul>
            </li>
            
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-truck"></i></span>
                    <span class="menu-text">Rodoviário</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Frota</a></li>
                    <li><a href="#" class="submenu-link">Manutenção</a></li>
                    <li><a href="#" class="submenu-link">Relatórios</a></li>
                </ul>
            </li>
            
            <li class="menu-item">
                <a href="#" class="menu-link">
                    <span class="menu-icon"><i class="fas fa-city"></i></span>
                    <span class="menu-text">Serviços Urbanos</span>
                    <span class="arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu">
                    <li><a href="#" class="submenu-link">Solicitações</a></li>
                    <li><a href="#" class="submenu-link">Manutenções</a></li>
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
                <h2>Gerenciamento Habitacional</h2>
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
            <i class="fas fa-home"></i>
            Cadastros Habitacionais
        </h1>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="dashboard.php">Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <span>Assistência Habitacional</span>
        </div>

        <!-- Mensagens -->
        <?php if ($mensagem): ?>
        <div class="alert alert-<?php echo $tipo_mensagem == 'success' ? 'success' : 'error'; ?>">
            <i class="fas fa-<?php echo $tipo_mensagem == 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
            <?php echo $mensagem; ?>
        </div>
        <?php endif; ?>

        <!-- Estatísticas -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($estatisticas['total']); ?></div>
                <div class="stat-label">Total de Cadastros</div>
                <i class="fas fa-home stat-icon"></i>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($estatisticas['pendentes']); ?></div>
                <div class="stat-label">EM ANÁLISE</div>
                <i class="fas fa-clock stat-icon"></i>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($estatisticas['em_analise_fin']); ?></div>
                <div class="stat-label">EM ANALISE FINANCEIRA</div>
                <i class="fas fa-search stat-icon"></i>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($estatisticas['fase_selecao']); ?></div>
                <div class="stat-label">EM FASE DE SELEÇÃO</div>
                <i class="fas fa-check-circle stat-icon"></i>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($estatisticas['contemplados']); ?></div>
                <div class="stat-label">CONTEMPLADOS</div>
                <i class="fas fa-times-circle stat-icon"></i>
            </div>
        </div>

        <!-- Filtros -->
        <div class="filters-container">
            <div class="filters-header">
                <div class="filters-title">
                    <i class="fas fa-filter"></i>
                    Filtros de Busca
                </div>
                <button class="filters-toggle" onclick="toggleFilters()">
                    <i class="fas fa-chevron-down" id="filtersChevron"></i>
                </button>
            </div>
            
            <div class="filters-content" id="filtersContent">
                <form method="GET" action="" id="filtersForm">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label for="filtro_protocolo">Protocolo</label>
                            <input type="text" id="filtro_protocolo" name="filtro_protocolo" 
                                   value="<?php echo htmlspecialchars($filtros['protocolo']); ?>" 
                                   placeholder="Digite o protocolo...">
                        </div>
                        
                        <div class="filter-group">
                            <label for="filtro_cpf">CPF</label>
                            <input type="text" id="filtro_cpf" name="filtro_cpf" 
                                   value="<?php echo htmlspecialchars($filtros['cpf']); ?>" 
                                   placeholder="000.000.000-00" maxlength="14">
                        </div>
                        
                        <div class="filter-group">
                            <label for="filtro_nome">Nome</label>
                            <input type="text" id="filtro_nome" name="filtro_nome" 
                                   value="<?php echo htmlspecialchars($filtros['nome']); ?>" 
                                   placeholder="Digite o nome...">
                        </div>
                        
                        <div class="filter-group">
                            <label for="filtro_status">Status</label>
                            <select id="filtro_status" name="filtro_status">
                                <option value="">Todos os Status</option>
                                <?php foreach ($is_associacao ? 'EM ANÁLISE FINANCEIRA' : $lista_status as $status): ?>
                                <option value="<?php echo $status; ?>" 
                                        <?php echo ($filtros['status'] == $status) ? 'selected' : ''; ?>>
                                    <?php echo $status; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="filtro_programa">Programa</label>
                            <select id="filtro_programa" name="filtro_programa">
                                <option value="">Todos os Programas</option>
                                <?php foreach ($programas_habitacionais as $programa): ?>
                                <option value="<?php echo $programa; ?>" 
                                        <?php echo ($filtros['programa'] == $programa) ? 'selected' : ''; ?>>
                                    <?php echo $programa; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="filtro_data_inicio">Data Início</label>
                            <input type="date" id="filtro_data_inicio" name="filtro_data_inicio" 
                                   value="<?php echo htmlspecialchars($filtros['data_inicio']); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="filtro_data_fim">Data Fim</label>
                            <input type="date" id="filtro_data_fim" name="filtro_data_fim" 
                                   value="<?php echo htmlspecialchars($filtros['data_fim']); ?>">
                        </div>
                    </div>
                    
                    <div class="filters-actions">
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

        <!-- Tabela de Cadastros -->
        <div class="table-container">
            <div class="table-header">
                <div class="table-title">
                    <i class="fas fa-list"></i>
                    Cadastros Habitacionais
                </div>
                <div class="table-info">
                    <i class="fas fa-info-circle"></i>
                    <?php echo number_format($total_registros); ?> registro(s) encontrado(s)
                </div>
            </div>
            
            <?php if (count($inscricoes) > 0): ?>
            <div class="table-responsive">
                <table class="table">
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
                                <strong><?php echo htmlspecialchars($inscricao['cad_social_protocolo']); ?></strong>
                            </td>
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($inscricao['cad_social_nome']); ?></strong>
                                    <?php if ($inscricao['cad_usu_nome']): ?>
                                    <br><small class="text-muted">Cadastrado por: <?php echo htmlspecialchars($inscricao['cad_usu_nome']); ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo formatarCPF($inscricao['cad_social_cpf']); ?></td>
                            <td>
                                <small><?php echo htmlspecialchars($inscricao['cad_social_programa_interesse'] ?? 'Não informado'); ?></small>
                            </td>
                            <td>
                                <span class="status-badge <?php echo getStatusClass($inscricao['cad_social_status']); ?>">
                                    <?php echo htmlspecialchars($inscricao['cad_social_status']); ?>
                                </span>
                            </td>
                            <td>
                                <small><?php echo formatarData($inscricao['cad_social_data_cadastro']); ?></small>
                            </td>
                            <td>
                                <div class="actions">
                                    <!-- Botão para a nova tela de visualização -->
                                    <a href="visualizar_cadastro_habitacao.php?id=<?php echo $inscricao['cad_social_id']; ?>" 
                                    class="btn-action btn-view" title="Ver Detalhes Completos">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <!-- Botão para visualização rápida (modal atual) -->
                                    <a href="assistencia.php?id=<?php echo $inscricao['cad_social_id']; ?>" 
                                    class="btn-action btn-info" title="Visualização Rápida">
                                        <i class="fas fa-search"></i>
                                    </a>
                                    
                                    <button type="button" class="btn-action btn-edit" 
                                            title="Alterar Status"
                                            onclick="openStatusModal(<?php echo $inscricao['cad_social_id']; ?>, '<?php echo htmlspecialchars($inscricao['cad_social_status']); ?>')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn-action btn-comment" 
                                            title="Adicionar Comentário"
                                            onclick="openCommentModal(<?php echo $inscricao['cad_social_id']; ?>)">
                                        <i class="fas fa-comment"></i>
                                    </button>
                                    <button type="button" class="btn-action btn-attach" 
                                            title="Anexar Arquivo"
                                            onclick="openAttachModal(<?php echo $inscricao['cad_social_id']; ?>)">
                                        <i class="fas fa-paperclip"></i>
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
                    Mostrando <?php echo (($pagina_atual - 1) * $registros_por_pagina) + 1; ?> a 
                    <?php echo min($pagina_atual * $registros_por_pagina, $total_registros); ?> de 
                    <?php echo number_format($total_registros); ?> registros
                </div>
                
                <div class="pagination">
                    <?php if ($pagina_atual > 1): ?>
                    <a href="?pagina=1<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                    <a href="?pagina=<?php echo $pagina_atual - 1; ?><?php echo '&' . http_build_query(array_diff_key($_GET, ['pagina' => ''])); ?>">
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
                    <a href="?pagina=<?php echo $i; ?><?php echo '&' . http_build_query(array_diff_key($_GET, ['pagina' => ''])); ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($pagina_atual < $total_paginas): ?>
                    <a href="?pagina=<?php echo $pagina_atual + 1; ?><?php echo '&' . http_build_query(array_diff_key($_GET, ['pagina' => ''])); ?>">
                        <i class="fas fa-angle-right"></i>
                    </a>
                    <a href="?pagina=<?php echo $total_paginas; ?><?php echo '&' . http_build_query(array_diff_key($_GET, ['pagina' => ''])); ?>">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-search"></i>
                <h3>Nenhum cadastro encontrado</h3>
                <p>Não há cadastros habitacionais que correspondam aos filtros aplicados.</p>
                <div>
                    <a href="assistencia_habitacao.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpar Filtros
                    </a>
                </div>
            </div>
            <?php endif; ?>
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
                            <option value="<?php echo $status; ?>"><?php echo $status; ?></option>
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

    <!-- Modal de Anexar Arquivo -->
    <div class="modal-overlay" id="attachModal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-paperclip"></i>
                    Anexar Arquivo
                </div>
                <button class="modal-close" onclick="closeModal('attachModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="attachForm" enctype="multipart/form-data">
                    <input type="hidden" name="acao" value="anexar_arquivo">
                    <input type="hidden" id="attach_inscricao_id" name="inscricao_id">
                    
                    <div class="form-group">
                        <label for="arquivo" class="required">Selecionar Arquivo</label>
                        <input type="file" id="arquivo" name="arquivo" required
                               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        <small style="color: #666; font-size: 0.8rem;">
                            Formatos: PDF, JPG, PNG, DOC, DOCX (máx. 10MB)
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="descricao_arquivo">Descrição</label>
                        <textarea id="descricao_arquivo" name="descricao_arquivo" rows="3"
                                placeholder="Descreva o conteúdo do arquivo..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('attachModal')">
                    Cancelar
                </button>
                <button type="button" class="btn btn-primary" onclick="attachFile()">
                    <i class="fas fa-upload"></i> Enviar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal de Detalhes (se necessário) -->
    <?php if ($is_exibir_modal && $inscricao_atual): ?>
    <div class="modal-overlay show" id="detailsModal">
        <div class="modal" style="max-width: 1000px;">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-info-circle"></i>
                    Detalhes da Inscrição - <?php echo htmlspecialchars($inscricao_atual['cad_social_protocolo']); ?>
                </div>
                <button class="modal-close" onclick="closeModal('detailsModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <!-- Conteúdo detalhado da inscrição aqui -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                    <div>
                        <h4>Dados Pessoais</h4>
                        <p><strong>Nome:</strong> <?php echo htmlspecialchars($inscricao_atual['cad_social_nome']); ?></p>
                        <p><strong>CPF:</strong> <?php echo formatarCPF($inscricao_atual['cad_social_cpf']); ?></p>
                        <p><strong>Data Nascimento:</strong> <?php echo date('d/m/Y', strtotime($inscricao_atual['cad_social_data_nascimento'])); ?></p>
                        <p><strong>Estado Civil:</strong> <?php echo htmlspecialchars($inscricao_atual['cad_social_estado_civil']); ?></p>
                    </div>
                    <div>
                        <h4>Contato</h4>
                        <p><strong>Telefone:</strong> <?php echo htmlspecialchars($inscricao_atual['cad_social_telefone'] ?? 'Não informado'); ?></p>
                        <p><strong>Celular:</strong> <?php echo htmlspecialchars($inscricao_atual['cad_social_celular']); ?></p>
                        <p><strong>E-mail:</strong> <?php echo htmlspecialchars($inscricao_atual['cad_social_email']); ?></p>
                    </div>
                    <div>
                        <h4>Programa</h4>
                        <p><strong>Programa:</strong> <?php echo htmlspecialchars($inscricao_atual['cad_social_programa_interesse']); ?></p>
                        <p><strong>Status:</strong> 
                            <span class="status-badge <?php echo getStatusClass($inscricao_atual['cad_social_status']); ?>">
                                <?php echo htmlspecialchars($inscricao_atual['cad_social_status']); ?>
                            </span>
                        </p>
                    </div>
                </div>
                
                <?php if (count($dependentes) > 0): ?>
                <div style="margin-top: 20px;">
                    <h4>Dependentes (<?php echo count($dependentes); ?>)</h4>
                    <?php foreach ($dependentes as $dependente): ?>
                    <div style="background: #f8f9fa; padding: 10px; margin: 10px 0; border-radius: 6px;">
                        <strong><?php echo htmlspecialchars($dependente['cad_social_dependente_nome']); ?></strong>
                        - <?php echo date('d/m/Y', strtotime($dependente['cad_social_dependente_data_nascimento'])); ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('detailsModal')">
                    Fechar
                </button>
                <button type="button" class="btn btn-primary" 
                        onclick="openStatusModal(<?php echo $inscricao_atual['cad_social_id']; ?>, '<?php echo htmlspecialchars($inscricao_atual['cad_social_status']); ?>')">
                    <i class="fas fa-edit"></i> Alterar Status
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Variáveis globais
        let currentInscricaoId = null;

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

            // CPF mask
            const cpfInput = document.getElementById('filtro_cpf');
            if (cpfInput) {
                cpfInput.addEventListener('input', function() {
                    this.value = formatCPF(this.value);
                });
            }

            // Auto-hide alerts
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-20px)';
                    setTimeout(function() {
                        alert.style.display = 'none';
                    }, 300);
                });
            }, 5000);

            // Close modal on outside click
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('modal-overlay')) {
                    const modalId = e.target.id;
                    closeModal(modalId);
                }
            });
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

        function openAttachModal(inscricaoId) {
            currentInscricaoId = inscricaoId;
            document.getElementById('attach_inscricao_id').value = inscricaoId;
            openModal('attachModal');
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

        function attachFile() {
            const form = document.getElementById('attachForm');
            const formData = new FormData(form);

            showLoading();
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                hideLoading();
                // Refresh page to show new file
                location.reload();
            })
            .catch(error => {
                hideLoading();
                showAlert('Erro ao anexar arquivo. Tente novamente.', 'error');
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

            // Insert after page title
            const pageTitle = document.querySelector('.page-title');
            pageTitle.parentNode.insertBefore(alert, pageTitle.nextSibling);

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
    </script>
</body>
</html>
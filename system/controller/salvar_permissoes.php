<?php
/**
 * Arquivo: salvar_permissoes.php
 * Descrição: Processa e salva as permissões de usuário
 * 
 * Parte do sistema de administração da Prefeitura
 */

// Headers para resposta JSON
header('Content-Type: application/json; charset=utf-8');

// Inicia a sessão
session_start();

// Verificar se o usuário está logado e é administrador
if (!isset($_SESSION['usersystem_logado'])) {
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado.']);
    exit;
}

// Incluir arquivo de configuração com conexão ao banco de dados
require_once "../lib/config.php";

// Verificar se é administrador
$usuario_logado_id = $_SESSION['usersystem_id'];
$is_admin = false;

try {
    $stmt = $conn->prepare("SELECT usuario_nivel_id FROM tb_usuarios_sistema WHERE usuario_id = :id");
    $stmt->bindParam(':id', $usuario_logado_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        $is_admin = ($usuario['usuario_nivel_id'] == 1);
    }
} catch (PDOException $e) {
    error_log("Erro ao verificar permissões: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do sistema.']);
    exit;
}

if (!$is_admin) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado. Apenas administradores podem gerenciar permissões.']);
    exit;
}

// Verificar se o método é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método de requisição inválido.']);
    exit;
}

// Função para sanitizar inputs
function sanitize($data) {
    if (is_null($data) || $data === '') {
        return null;
    }
    
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Capturar dados do formulário
$usuario_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];

// Validações
if ($usuario_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID do usuário inválido.']);
    exit;
}

// Verificar se o usuário existe
try {
    $stmt = $conn->prepare("SELECT usuario_id, usuario_nome FROM tb_usuarios_sistema WHERE usuario_id = :id");
    $stmt->bindParam(':id', $usuario_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Usuário não encontrado.']);
        exit;
    }
    
    $usuario_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Erro ao verificar usuário: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro ao verificar usuário.']);
    exit;
}

// Criar tabela de permissões se não existir
try {
    $create_table_sql = "
        CREATE TABLE IF NOT EXISTS tb_permissoes_usuario (
            permissao_id INT PRIMARY KEY AUTO_INCREMENT,
            usuario_id INT NOT NULL,
            modulo VARCHAR(50) NOT NULL,
            acao VARCHAR(50) NOT NULL,
            ativo BOOLEAN DEFAULT TRUE,
            data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            data_modificacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            criado_por INT,
            FOREIGN KEY (usuario_id) REFERENCES tb_usuarios_sistema(usuario_id) ON DELETE CASCADE,
            FOREIGN KEY (criado_por) REFERENCES tb_usuarios_sistema(usuario_id),
            UNIQUE KEY unique_permission (usuario_id, modulo, acao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $conn->exec($create_table_sql);
    
} catch (PDOException $e) {
    error_log("Erro ao criar tabela de permissões: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro ao preparar sistema de permissões.']);
    exit;
}

try {
    // Iniciar transação
    $conn->beginTransaction();
    
    // Primeiro, desativar todas as permissões existentes do usuário
    $stmt = $conn->prepare("UPDATE tb_permissoes_usuario SET ativo = FALSE WHERE usuario_id = :usuario_id");
    $stmt->bindParam(':usuario_id', $usuario_id);
    $stmt->execute();
    
    // Contador de permissões processadas
    $permissoes_salvas = 0;
    
    // Processar as novas permissões
    if (!empty($permissions) && is_array($permissions)) {
        foreach ($permissions as $modulo => $acoes) {
            if (is_array($acoes)) {
                foreach ($acoes as $acao => $valor) {
                    // Sanitizar dados
                    $modulo_clean = sanitize($modulo);
                    $acao_clean = sanitize($acao);
                    
                    if (!empty($modulo_clean) && !empty($acao_clean)) {
                        // Verificar se a permissão já existe
                        $check_stmt = $conn->prepare("
                            SELECT permissao_id FROM tb_permissoes_usuario 
                            WHERE usuario_id = :usuario_id AND modulo = :modulo AND acao = :acao
                        ");
                        $check_stmt->bindParam(':usuario_id', $usuario_id);
                        $check_stmt->bindParam(':modulo', $modulo_clean);
                        $check_stmt->bindParam(':acao', $acao_clean);
                        $check_stmt->execute();
                        
                        if ($check_stmt->rowCount() > 0) {
                            // Atualizar permissão existente
                            $update_stmt = $conn->prepare("
                                UPDATE tb_permissoes_usuario 
                                SET ativo = TRUE, data_modificacao = NOW() 
                                WHERE usuario_id = :usuario_id AND modulo = :modulo AND acao = :acao
                            ");
                            $update_stmt->bindParam(':usuario_id', $usuario_id);
                            $update_stmt->bindParam(':modulo', $modulo_clean);
                            $update_stmt->bindParam(':acao', $acao_clean);
                            $update_stmt->execute();
                        } else {
                            // Inserir nova permissão
                            $insert_stmt = $conn->prepare("
                                INSERT INTO tb_permissoes_usuario (usuario_id, modulo, acao, ativo, criado_por) 
                                VALUES (:usuario_id, :modulo, :acao, TRUE, :criado_por)
                            ");
                            $insert_stmt->bindParam(':usuario_id', $usuario_id);
                            $insert_stmt->bindParam(':modulo', $modulo_clean);
                            $insert_stmt->bindParam(':acao', $acao_clean);
                            $insert_stmt->bindParam(':criado_por', $usuario_logado_id);
                            $insert_stmt->execute();
                        }
                        
                        $permissoes_salvas++;
                    }
                }
            }
        }
    }
    
    // Remover permissões que ficaram inativas (limpeza)
    $cleanup_stmt = $conn->prepare("DELETE FROM tb_permissoes_usuario WHERE usuario_id = :usuario_id AND ativo = FALSE");
    $cleanup_stmt->bindParam(':usuario_id', $usuario_id);
    $cleanup_stmt->execute();
    $permissoes_removidas = $cleanup_stmt->rowCount();
    
    // Commit da transação
    $conn->commit();
    
    // Log da ação
    error_log("Permissões atualizadas - Usuário ID: {$usuario_id}, Permissões salvas: {$permissoes_salvas}, Removidas: {$permissoes_removidas}, Por: {$usuario_logado_id}");
    
    // Resposta de sucesso
    echo json_encode([
        'success' => true,
        'message' => "Permissões do usuário '{$usuario_info['usuario_nome']}' foram atualizadas com sucesso!",
        'data' => [
            'usuario_id' => $usuario_id,
            'usuario_nome' => $usuario_info['usuario_nome'],
            'permissoes_salvas' => $permissoes_salvas,
            'permissoes_removidas' => $permissoes_removidas,
            'data_atualizacao' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (PDOException $e) {
    // Rollback em caso de erro
    $conn->rollBack();
    
    // Log do erro
    error_log("Erro ao salvar permissões - Usuário ID: {$usuario_id}, Erro: " . $e->getMessage());
    
    // Verificar se é erro de constraint (usuário não existe)
    if ($e->getCode() == 23000) {
        echo json_encode(['success' => false, 'message' => 'Erro de integridade: Usuário pode não existir mais.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro ao salvar permissões no banco de dados.']);
    }
    
} catch (Exception $e) {
    // Rollback em caso de erro geral
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Log do erro
    error_log("Erro geral ao salvar permissões: " . $e->getMessage());
    
    echo json_encode(['success' => false, 'message' => 'Erro interno do sistema.']);
}
?>
<?php
/**
 * Arquivo: excluir_usuario.php
 * Descrição: Exclui um usuário do sistema
 * 
 * Parte do sistema de gerenciamento da Prefeitura
 */

// Desativar exibição de erros para evitar corromper a saída JSON
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Inicia a sessão
session_start();

// Define o cabeçalho para JSON
header('Content-Type: application/json; charset=utf-8');

// Verifica se o usuário está logado e é administrador
if (!isset($_SESSION['usersystem_logado']) || $_SESSION['usersystem_nivel'] !== 'Administrador') {
    echo json_encode([
        'success' => false,
        'message' => 'Acesso negado. Apenas administradores podem excluir usuários.'
    ]);
    exit;
}

// Incluir arquivo de configuração com conexão ao banco de dados
require_once "../database/conect.php";

// Verificar se o método é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Método de requisição inválido.'
    ]);
    exit;
}

try {
    // Verificar conexão com o banco
    if (!isset($conn) || !$conn) {
        throw new Exception("Erro na conexão com o banco de dados.");
    }
    
    // Obter dados da requisição JSON
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['user_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'ID do usuário não informado.'
        ]);
        exit;
    }
    
    $user_id = (int)$input['user_id'];
    
    // Verificar se o usuário existe
    $stmt = $conn->prepare("SELECT usuario_nome, usuario_nivel_id FROM tb_usuarios_sistema WHERE usuario_id = :user_id");
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Usuário não encontrado.'
        ]);
        exit;
    }
    
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Não permitir excluir administradores
    if ($usuario['usuario_nivel_id'] === '1') {
        echo json_encode([
            'success' => false,
            'message' => 'Não é possível excluir usuários administradores.'
        ]);
        exit;
    }
    
    // Não permitir que o usuário exclua a si mesmo
    if ($user_id == $_SESSION['usersystem_id']) {
        echo json_encode([
            'success' => false,
            'message' => 'Você não pode excluir sua própria conta.'
        ]);
        exit;
    }
    
    // Verificar se o usuário tem registros associados (opcional)
    // Aqui você pode verificar se o usuário tem dados em outras tabelas
    // Por exemplo: solicitações, relatórios, etc.
    
    // Excluir usuário
    $stmt = $conn->prepare("DELETE FROM tb_usuarios_sistema WHERE usuario_id = :user_id");
    $stmt->bindParam(':user_id', $user_id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Usuário excluído com sucesso!'
        ]);
        
    } else {
        throw new Exception("Erro ao excluir usuário.");
    }
    
} catch (PDOException $e) {
    // Log do erro
    error_log("Erro ao excluir usuário: " . $e->getMessage());
    
    // Verificar se é erro de constraint (usuário tem registros associados)
    if ($e->getCode() == '23000') {
        echo json_encode([
            'success' => false,
            'message' => 'Não é possível excluir este usuário pois ele possui registros associados no sistema.'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Erro interno do servidor. Tente novamente mais tarde.'
        ]);
    }
    
} catch (Exception $e) {
    // Log do erro
    error_log("Erro ao excluir usuário: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

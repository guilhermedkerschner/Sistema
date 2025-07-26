<?php
/**
 * Arquivo: controller/excluir_produtor.php
 * Descrição: Exclui um produtor do sistema
 */

// Headers para resposta JSON
header('Content-Type: application/json; charset=utf-8');

// Inicia a sessão
session_start();

// Verificar se o usuário está logado
if (!isset($_SESSION['usersystem_logado'])) {
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado.']);
    exit;
}

// Incluir arquivo de configuração - CAMINHO CORRIGIDO
require_once "../../database/conect.php";

// Verificar se é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método inválido.']);
    exit;
}

try {
    // Verificar conexão com o banco
    if (!isset($conn) || !$conn) {
        throw new Exception("Erro na conexão com o banco de dados.");
    }

    // Obter dados da requisição
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['id']) || !is_numeric($input['id'])) {
        echo json_encode(['success' => false, 'message' => 'ID inválido.']);
        exit;
    }
    
    $produtor_id = (int)$input['id'];
    
    // Verificar se o produtor existe
    $stmt = $conn->prepare("SELECT cad_pro_nome FROM tb_cad_produtores WHERE cad_pro_id = :id");
    $stmt->bindParam(':id', $produtor_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Produtor não encontrado.']);
        exit;
    }
    
    // Em vez de excluir fisicamente, vamos marcar como inativo
    $stmt = $conn->prepare("
        UPDATE tb_cad_produtores 
        SET cad_pro_status = 'inativo',
            cad_pro_data_atualizacao = NOW()
        WHERE cad_pro_id = :id
    ");
    $stmt->bindParam(':id', $produtor_id);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Produtor excluído com sucesso.']);
    
} catch (PDOException $e) {
    error_log("Erro ao excluir produtor: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do sistema.']);
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}
?>
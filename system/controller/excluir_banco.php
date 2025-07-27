<?php
session_start();

// Verificar se o usuário está logado
if (!isset($_SESSION['usersystem_logado'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

require_once "../../database/conect.php";

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $banco_id = $input['id'] ?? null;
    
    if (!$banco_id || !is_numeric($banco_id)) {
        echo json_encode(['success' => false, 'message' => 'ID do banco inválido']);
        exit;
    }
    
    // Verificar se o banco existe
    $stmt = $conn->prepare("SELECT ban_nome FROM tb_cad_bancos WHERE ban_id = :id AND ban_status = 'ativo'");
    $stmt->bindParam(':id', $banco_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Banco não encontrado']);
        exit;
    }
    
    $banco = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Verificar se há produtores vinculados ao banco
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tb_cad_produtores WHERE cad_pro_banco_id = :banco_id AND cad_pro_status = 'ativo'");
    $stmt->bindParam(':banco_id', $banco_id);
    $stmt->execute();
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($resultado['total'] > 0) {
        echo json_encode(['success' => false, 'message' => 'Não é possível excluir: há ' . $resultado['total'] . ' produtores vinculados a este banco']);
        exit;
    }
    
    // Marcar como inativo (soft delete)
    $stmt = $conn->prepare("UPDATE tb_cad_bancos SET ban_status = 'inativo' WHERE ban_id = :id");
    $stmt->bindParam(':id', $banco_id);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Banco "' . $banco['ban_nome'] . '" excluído com sucesso']);
    
} catch (PDOException $e) {
    error_log("Erro ao excluir banco: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do sistema']);
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro inesperado']);
}
?>
<?php
session_start();

if (!isset($_SESSION['usersystem_logado'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

require_once "../../database/conect.php";

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $comunidade_id = $input['id'] ?? null;
    
    if (!$comunidade_id || !is_numeric($comunidade_id)) {
        echo json_encode(['success' => false, 'message' => 'ID da comunidade inválido']);
        exit;
    }
    
    // Verificar se a comunidade existe
    $stmt = $conn->prepare("SELECT com_nome FROM tb_cad_comunidades WHERE com_id = :id AND com_status = 'ativo'");
    $stmt->bindParam(':id', $comunidade_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Comunidade não encontrada']);
        exit;
    }
    
    $comunidade = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Verificar se há produtores vinculados à comunidade
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tb_cad_produtores WHERE cad_pro_comunidade_id = :comunidade_id AND cad_pro_status = 'ativo'");
    $stmt->bindParam(':comunidade_id', $comunidade_id);
    $stmt->execute();
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($resultado['total'] > 0) {
        echo json_encode(['success' => false, 'message' => 'Não é possível excluir: há ' . $resultado['total'] . ' produtores vinculados a esta comunidade']);
        exit;
    }
    
    // Marcar como inativo (soft delete)
    $stmt = $conn->prepare("UPDATE tb_cad_comunidades SET com_status = 'inativo' WHERE com_id = :id");
    $stmt->bindParam(':id', $comunidade_id);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Comunidade "' . $comunidade['com_nome'] . '" excluída com sucesso']);
    
} catch (PDOException $e) {
    error_log("Erro ao excluir comunidade: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do sistema']);
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro inesperado']);
}
?>
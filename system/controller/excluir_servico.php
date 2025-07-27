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
    $servico_id = $input['id'] ?? null;
    
    if (!$servico_id || !is_numeric($servico_id)) {
        echo json_encode(['success' => false, 'message' => 'ID do serviço inválido']);
        exit;
    }
    
    // Verificar se o serviço existe
    $stmt = $conn->prepare("SELECT ser_nome FROM tb_cad_servicos WHERE ser_id = :id AND ser_status = 'ativo'");
    $stmt->bindParam(':id', $servico_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Serviço não encontrado']);
        exit;
    }
    
    $servico = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Marcar como inativo (soft delete)
    $stmt = $conn->prepare("UPDATE tb_cad_servicos SET ser_status = 'inativo' WHERE ser_id = :id");
    $stmt->bindParam(':id', $servico_id);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Serviço "' . $servico['ser_nome'] . '" excluído com sucesso']);
    
} catch (PDOException $e) {
    error_log("Erro ao excluir serviço: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do sistema']);
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro inesperado']);
}
?>
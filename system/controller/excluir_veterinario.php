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
    $veterinario_id = $input['id'] ?? null;
    
    if (!$veterinario_id || !is_numeric($veterinario_id)) {
        echo json_encode(['success' => false, 'message' => 'ID do veterinário inválido']);
        exit;
    }
    
    // Verificar se o veterinário existe
    $stmt = $conn->prepare("SELECT vet_nome FROM tb_cad_veterinarios WHERE vet_id = :id AND vet_status = 'ativo'");
    $stmt->bindParam(':id', $veterinario_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Veterinário não encontrado']);
        exit;
    }
    
    $veterinario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Aqui você pode adicionar verificações se há atendimentos/consultas pendentes
    // Por exemplo:
    // $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tb_atendimentos WHERE vet_id = :veterinario_id AND status = 'agendado'");
    // $stmt->bindParam(':veterinario_id', $veterinario_id);
    // $stmt->execute();
    // $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    // 
    // if ($resultado['total'] > 0) {
    //     echo json_encode(['success' => false, 'message' => 'Não é possível excluir: há atendimentos agendados para este veterinário']);
    //     exit;
    // }
    
    // Marcar como inativo (soft delete)
    $stmt = $conn->prepare("UPDATE tb_cad_veterinarios SET vet_status = 'inativo' WHERE vet_id = :id");
    $stmt->bindParam(':id', $veterinario_id);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Veterinário "' . $veterinario['vet_nome'] . '" excluído com sucesso']);
    
} catch (PDOException $e) {
    error_log("Erro ao excluir veterinário: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do sistema']);
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro inesperado']);
}
?>
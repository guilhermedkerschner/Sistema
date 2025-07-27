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
    $maquina_id = $input['id'] ?? null;
    
    if (!$maquina_id || !is_numeric($maquina_id)) {
        echo json_encode(['success' => false, 'message' => 'ID da máquina inválido']);
        exit;
    }
    
    // Verificar se a máquina existe
    $stmt = $conn->prepare("SELECT maq_nome FROM tb_cad_maquinas WHERE maq_id = :id AND maq_status = 'ativo'");
    $stmt->bindParam(':id', $maquina_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Máquina não encontrada']);
        exit;
    }
    
    $maquina = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Aqui você pode adicionar verificações se há agendamentos/usos pendentes da máquina
    // Por exemplo:
    // $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tb_agendamentos WHERE maq_id = :maquina_id AND status = 'ativo'");
    // $stmt->bindParam(':maquina_id', $maquina_id);
    // $stmt->execute();
    // $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    // 
    // if ($resultado['total'] > 0) {
    //     echo json_encode(['success' => false, 'message' => 'Não é possível excluir: há agendamentos pendentes para esta máquina']);
    //     exit;
    // }
    
    // Marcar como inativo (soft delete)
    $stmt = $conn->prepare("UPDATE tb_cad_maquinas SET maq_status = 'inativo' WHERE maq_id = :id");
    $stmt->bindParam(':id', $maquina_id);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Máquina "' . $maquina['maq_nome'] . '" excluída com sucesso']);
    
} catch (PDOException $e) {
    error_log("Erro ao excluir máquina: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do sistema']);
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro inesperado']);
}
?>
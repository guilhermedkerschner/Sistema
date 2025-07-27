<?php
session_start();

if (!isset($_SESSION['usersystem_logado'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

require_once "../../lib/config.php";

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $cpf = preg_replace('/\D/', '', $input['cpf'] ?? '');
    
    if (empty($cpf) || strlen($cpf) !== 11) {
        echo json_encode(['existe' => false]);
        exit;
    }
    
    // Verificar em produtores
    $stmt = $conn->prepare("
        SELECT cad_pro_nome as nome, 'produtor' as tipo
        FROM tb_cad_produtores 
        WHERE cad_pro_cpf = :cpf 
        AND cad_pro_status = 'ativo'
        LIMIT 1
    ");
    
    $stmt->bindParam(':cpf', $cpf);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $registro = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'existe' => true,
            'nome' => $registro['nome'],
            'tipo' => 'Produtor'
        ]);
        exit;
    }
    
    // Verificar em veterinários
    $stmt = $conn->prepare("
        SELECT vet_nome as nome, 'veterinario' as tipo
        FROM tb_cad_veterinarios 
        WHERE vet_cpf = :cpf 
        AND vet_status = 'ativo'
        LIMIT 1
    ");
    
    $stmt->bindParam(':cpf', $cpf);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $registro = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'existe' => true,
            'nome' => $registro['nome'],
            'tipo' => 'Veterinário'
        ]);
        exit;
    }
    
    echo json_encode(['existe' => false]);
    
} catch (PDOException $e) {
    error_log("Erro ao verificar CPF: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}
?>
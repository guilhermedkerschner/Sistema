<?php
header('Content-Type: application/json');
session_start();

// Verificar se o usuário está logado
if (!isset($_SESSION['usersystem_logado'])) {
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado']);
    exit;
}

require_once "../../lib/config.php";

try {
    $estatisticas = [
        'atletas_ativos' => 0,
        'campeonatos_ativos' => 0,
        'espacos_ativos' => 0,
        'modalidades_ativas' => 0
    ];

    // Total de atletas ativos
    $stmt = $conn->query("SELECT COUNT(*) as total FROM tb_atletas WHERE atleta_status = 'ATIVO'");
    if ($stmt) {
        $result = $stmt->fetch();
        $estatisticas['atletas_ativos'] = (int)($result['total'] ?? 0);
    }
    
    // Total de modalidades distintas
    $stmt = $conn->query("SELECT COUNT(DISTINCT atleta_modalidade_principal) as total FROM tb_atletas WHERE atleta_status = 'ATIVO'");
    if ($stmt) {
        $result = $stmt->fetch();
        $estatisticas['modalidades_ativas'] = (int)($result['total'] ?? 0);
    }
    
    // Verificar outras tabelas se existirem
    try {
        $stmt = $conn->query("SELECT COUNT(*) as total FROM tb_campeonatos WHERE status = 'ATIVO'");
        if ($stmt) {
            $result = $stmt->fetch();
            $estatisticas['campeonatos_ativos'] = (int)($result['total'] ?? 0);
        }
    } catch (PDOException $e) {
        $estatisticas['campeonatos_ativos'] = 0;
    }
    
    try {
        $stmt = $conn->query("SELECT COUNT(*) as total FROM tb_espacos_esportivos WHERE status = 'ATIVO'");
        if ($stmt) {
            $result = $stmt->fetch();
            $estatisticas['espacos_ativos'] = (int)($result['total'] ?? 0);
        }
    } catch (PDOException $e) {
        $estatisticas['espacos_ativos'] = 0;
    }

    echo json_encode([
        'success' => true,
        'atletas_ativos' => $estatisticas['atletas_ativos'],
        'campeonatos_ativos' => $estatisticas['campeonatos_ativos'],
        'espacos_ativos' => $estatisticas['espacos_ativos'],
        'modalidades_ativas' => $estatisticas['modalidades_ativas']
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao buscar estatísticas: ' . $e->getMessage()
    ]);
}
?>
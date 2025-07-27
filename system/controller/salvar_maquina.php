<?php
session_start();

if (!isset($_SESSION['usersystem_logado'])) {
    $_SESSION['erro_cadastro'] = "Acesso negado.";
    header("Location: ../agricultura_cadastros.php?aba=maquinas&erro=1");
    exit;
}

require_once "../../database/conect.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['erro_cadastro'] = "Método não permitido.";
    header("Location: ../agricultura_cadastros.php?aba=maquinas&erro=1");
    exit;
}

try {
    function sanitize($data) {
        return htmlspecialchars(strip_tags(trim($data)));
    }

    $nome = sanitize($_POST['maquina_nome'] ?? '');
    $valor_hora = !empty($_POST['maquina_valor_hora']) ? floatval($_POST['maquina_valor_hora']) : 0;
    $disponibilidade = $_POST['maquina_disponibilidade'] ?? '';
    $observacoes = sanitize($_POST['maquina_observacoes'] ?? '');
    
    $erros = [];
    
    if (empty($nome)) {
        $erros[] = "Nome da máquina é obrigatório";
    }
    
    if ($valor_hora < 0) {
        $erros[] = "Valor por hora deve ser maior ou igual a zero";
    }
    
    if (!in_array($disponibilidade, ['disponivel', 'manutencao', 'indisponivel'])) {
        $erros[] = "Disponibilidade inválida";
    }
    
    // Verificar se nome já está cadastrado
    $stmt = $conn->prepare("SELECT maq_id FROM tb_cad_maquinas WHERE maq_nome = :nome AND maq_status = 'ativo'");
    $stmt->bindParam(':nome', $nome);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $erros[] = "Máquina já cadastrada no sistema";
    }
    
    if (!empty($erros)) {
        $_SESSION['erro_cadastro'] = implode(', ', $erros);
        $_SESSION['dados_form_maquina'] = $_POST;
        header("Location: ../agricultura_cadastros.php?aba=maquinas&erro=1");
        exit;
    }
    
    $stmt = $conn->prepare("
        INSERT INTO tb_cad_maquinas (
            maq_nome,
            maq_valor_hora,
            maq_disponibilidade,
            maq_observacoes,
            maq_usuario_cadastro
        ) VALUES (
            :nome,
            :valor_hora,
            :disponibilidade,
            :observacoes,
            :usuario_cadastro
        )
    ");
    
    $stmt->bindParam(':nome', $nome);
    $stmt->bindParam(':valor_hora', $valor_hora);
    $stmt->bindParam(':disponibilidade', $disponibilidade);
    $stmt->bindParam(':observacoes', $observacoes);
    $stmt->bindParam(':usuario_cadastro', $_SESSION['usersystem_id']);
    
    $stmt->execute();
    
    $_SESSION['sucesso_cadastro'] = "Máquina cadastrada com sucesso!";
    header("Location: ../agricultura_cadastros.php?aba=maquinas&sucesso=1");
    exit;
    
} catch (PDOException $e) {
    error_log("Erro ao cadastrar máquina: " . $e->getMessage());
    $_SESSION['erro_cadastro'] = "Erro interno do sistema. Tente novamente.";
    $_SESSION['dados_form_maquina'] = $_POST;
    header("Location: ../agricultura_cadastros.php?aba=maquinas&erro=1");
    exit;
}
?>
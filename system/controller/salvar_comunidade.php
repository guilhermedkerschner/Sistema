<?php
session_start();

if (!isset($_SESSION['usersystem_logado'])) {
    $_SESSION['erro_cadastro'] = "Acesso negado.";
    header("Location: ../agricultura_cadastros.php?aba=comunidades&erro=1");
    exit;
}

require_once "../../database/conect.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['erro_cadastro'] = "Método não permitido.";
    header("Location: ../agricultura_cadastros.php?aba=comunidades&erro=1");
    exit;
}

try {
    function sanitize($data) {
        return htmlspecialchars(strip_tags(trim($data)));
    }

    $nome = sanitize($_POST['comunidade_nome'] ?? '');
    $descricao = sanitize($_POST['comunidade_descricao'] ?? '');
    
    $erros = [];
    
    if (empty($nome)) {
        $erros[] = "Nome da comunidade é obrigatório";
    }
    
    // Verificar se nome já está cadastrado
    $stmt = $conn->prepare("SELECT com_id FROM tb_cad_comunidades WHERE com_nome = :nome AND com_status = 'ativo'");
    $stmt->bindParam(':nome', $nome);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $erros[] = "Nome de comunidade já cadastrado no sistema";
    }
    
    if (!empty($erros)) {
        $_SESSION['erro_cadastro'] = implode(', ', $erros);
        $_SESSION['dados_form_comunidade'] = $_POST;
        header("Location: ../agricultura_cadastros.php?aba=comunidades&erro=1");
        exit;
    }
    
    $stmt = $conn->prepare("
        INSERT INTO tb_cad_comunidades (
            com_nome,
            com_descricao,
            com_usuario_cadastro
        ) VALUES (
            :nome,
            :descricao,
            :usuario_cadastro
        )
    ");
    
    $stmt->bindParam(':nome', $nome);
    $stmt->bindParam(':descricao', $descricao);
    $stmt->bindParam(':usuario_cadastro', $_SESSION['usersystem_id']);
    
    $stmt->execute();
    
    $_SESSION['sucesso_cadastro'] = "Comunidade cadastrada com sucesso!";
    header("Location: ../agricultura_cadastros.php?aba=comunidades&sucesso=1");
    exit;
    
} catch (PDOException $e) {
    error_log("Erro ao cadastrar comunidade: " . $e->getMessage());
    $_SESSION['erro_cadastro'] = "Erro interno do sistema. Tente novamente.";
    $_SESSION['dados_form_comunidade'] = $_POST;
    header("Location: ../agricultura_cadastros.php?aba=comunidades&erro=1");
    exit;
}
?>
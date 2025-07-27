<?php
session_start();

if (!isset($_SESSION['usersystem_logado'])) {
    $_SESSION['erro_cadastro'] = "Acesso negado.";
    header("Location: ../agricultura_cadastros.php?aba=servicos&erro=1");
    exit;
}

require_once "../../database/conect.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['erro_cadastro'] = "Método não permitido.";
    header("Location: ../agricultura_cadastros.php?aba=servicos&erro=1");
    exit;
}

try {
    function sanitize($data) {
        return htmlspecialchars(strip_tags(trim($data)));
    }

    $nome = sanitize($_POST['servico_nome'] ?? '');
    $secretaria_id = !empty($_POST['servico_secretaria_id']) ? (int)$_POST['servico_secretaria_id'] : null;
    
    $erros = [];
    
    if (empty($nome)) {
        $erros[] = "Nome do serviço é obrigatório";
    }
    
    if (empty($secretaria_id)) {
        $erros[] = "Secretaria é obrigatória";
    }
    
    // Verificar se nome já está cadastrado na mesma secretaria
    $stmt = $conn->prepare("SELECT ser_id FROM tb_cad_servicos WHERE ser_nome = :nome AND ser_secretaria_id = :secretaria AND ser_status = 'ativo'");
    $stmt->bindParam(':nome', $nome);
    $stmt->bindParam(':secretaria', $secretaria_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $erros[] = "Serviço já cadastrado para esta secretaria";
    }
    
    if (!empty($erros)) {
        $_SESSION['erro_cadastro'] = implode(', ', $erros);
        $_SESSION['dados_form_servico'] = $_POST;
        header("Location: ../agricultura_cadastros.php?aba=servicos&erro=1");
        exit;
    }
    
    $stmt = $conn->prepare("
        INSERT INTO tb_cad_servicos (
            ser_nome,
            ser_secretaria_id,
            ser_usuario_cadastro
        ) VALUES (
            :nome,
            :secretaria_id,
            :usuario_cadastro
        )
    ");
    
    $stmt->bindParam(':nome', $nome);
    $stmt->bindParam(':secretaria_id', $secretaria_id);
    $stmt->bindParam(':usuario_cadastro', $_SESSION['usersystem_id']);
    
    $stmt->execute();
    
    $_SESSION['sucesso_cadastro'] = "Serviço cadastrado com sucesso!";
    header("Location: ../agricultura_cadastros.php?aba=servicos&sucesso=1");
    exit;
    
} catch (PDOException $e) {
    error_log("Erro ao cadastrar serviço: " . $e->getMessage());
    $_SESSION['erro_cadastro'] = "Erro interno do sistema. Tente novamente.";
    $_SESSION['dados_form_servico'] = $_POST;
    header("Location: ../agricultura_cadastros.php?aba=servicos&erro=1");
    exit;
}
?>
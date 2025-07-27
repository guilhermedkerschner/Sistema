<?php
session_start();

// Verificar se o usuário está logado
if (!isset($_SESSION['usersystem_logado'])) {
    $_SESSION['erro_cadastro'] = "Acesso negado.";
    header("Location: ../agricultura_cadastros.php?aba=bancos&erro=1");
    exit;
}

require_once "../../database/conect.php";

// Verificar se é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['erro_cadastro'] = "Método não permitido.";
    header("Location: ../agricultura_cadastros.php?aba=bancos&erro=1");
    exit;
}

try {
    // Função para sanitizar dados
    function sanitize($data) {
        return htmlspecialchars(strip_tags(trim($data)));
    }

    // Capturar e sanitizar dados
    $codigo = sanitize($_POST['banco_codigo'] ?? '');
    $nome = sanitize($_POST['banco_nome'] ?? '');
    
    // Validações
    $erros = [];
    
    if (empty($codigo)) {
        $erros[] = "Código do banco é obrigatório";
    }
    
    if (empty($nome)) {
        $erros[] = "Nome do banco é obrigatório";
    }
    
    // Verificar se código já está cadastrado
    $stmt = $conn->prepare("SELECT ban_id FROM tb_cad_bancos WHERE ban_codigo = :codigo AND ban_status = 'ativo'");
    $stmt->bindParam(':codigo', $codigo);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $erros[] = "Código de banco já cadastrado no sistema";
    }
    
    // Verificar se nome já está cadastrado
    $stmt = $conn->prepare("SELECT ban_id FROM tb_cad_bancos WHERE ban_nome = :nome AND ban_status = 'ativo'");
    $stmt->bindParam(':nome', $nome);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $erros[] = "Nome de banco já cadastrado no sistema";
    }
    
    // Se houver erros, redirecionar com mensagem
    if (!empty($erros)) {
        $_SESSION['erro_cadastro'] = implode(', ', $erros);
        $_SESSION['dados_form_banco'] = $_POST;
        header("Location: ../agricultura_cadastros.php?aba=bancos&erro=1");
        exit;
    }
    
    // Inserir no banco de dados
    $stmt = $conn->prepare("
        INSERT INTO tb_cad_bancos (
            ban_codigo,
            ban_nome,
            ban_usuario_cadastro
        ) VALUES (
            :codigo,
            :nome,
            :usuario_cadastro
        )
    ");
    
    $stmt->bindParam(':codigo', $codigo);
    $stmt->bindParam(':nome', $nome);
    $stmt->bindParam(':usuario_cadastro', $_SESSION['usersystem_id']);
    
    $stmt->execute();
    
    $_SESSION['sucesso_cadastro'] = "Banco cadastrado com sucesso!";
    header("Location: ../agricultura_cadastros.php?aba=bancos&sucesso=1");
    exit;
    
} catch (PDOException $e) {
    error_log("Erro ao cadastrar banco: " . $e->getMessage());
    $_SESSION['erro_cadastro'] = "Erro interno do sistema. Tente novamente.";
    $_SESSION['dados_form_banco'] = $_POST;
    header("Location: ../agricultura_cadastros.php?aba=bancos&erro=1");
    exit;
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
    $_SESSION['erro_cadastro'] = "Erro: " . $e->getMessage();
    $_SESSION['dados_form_banco'] = $_POST;
    header("Location: ../agricultura_cadastros.php?aba=bancos&erro=1");
    exit;
}
?>
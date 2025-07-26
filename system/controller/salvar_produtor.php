<?php
/**
 * Arquivo: controller/salvar_produtor.php
 * Descrição: Processa o cadastro de produtores
 */

// Inicia a sessão
session_start();

// Verificar se o usuário está logado
if (!isset($_SESSION['usersystem_logado'])) {
    header("Location: ../../acessdeniedrestrict.php");
    exit;
}

// Incluir arquivo de configuração - CAMINHO CORRIGIDO
require_once "../../database/conect.php";

// Verificar se é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../agricultura_cadastros.php");
    exit;
}

// Função para sanitizar dados
function sanitize($data) {
    if (is_null($data) || $data === '') {
        return null;
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Função para validar CPF
function validarCPF($cpf) {
    $cpf = preg_replace('/[^0-9]/is', '', $cpf);
    
    if (strlen($cpf) != 11) {
        return false;
    }
    
    if (preg_match('/(\d)\1{10}/', $cpf)) {
        return false;
    }
    
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) {
            return false;
        }
    }
    return true;
}

try {
    // Verificar conexão com o banco
    if (!isset($conn) || !$conn) {
        throw new Exception("Erro na conexão com o banco de dados.");
    }

    // Capturar dados do formulário
    $nome = sanitize($_POST['nome'] ?? '');
    $cpf = preg_replace('/[^0-9]/', '', $_POST['cpf'] ?? '');
    $telefone = sanitize($_POST['telefone'] ?? '');
    $comunidade_id = !empty($_POST['comunidade_id']) ? (int)$_POST['comunidade_id'] : null;
    
    // Dados bancários
    $titular_nome = sanitize($_POST['titular_nome'] ?? '');
    $titular_cpf = preg_replace('/[^0-9]/', '', $_POST['titular_cpf'] ?? '');
    $titular_telefone = sanitize($_POST['titular_telefone'] ?? '');
    $banco_id = !empty($_POST['banco_id']) ? (int)$_POST['banco_id'] : null;
    $agencia = sanitize($_POST['agencia'] ?? '');
    $conta = sanitize($_POST['conta'] ?? '');
    $tipo_conta = $_POST['tipo_conta'] ?? '';
    
    // Validações
    $erros = [];
    
    if (empty($nome)) {
        $erros[] = "Nome é obrigatório";
    }
    
    if (empty($cpf) || !validarCPF($cpf)) {
        $erros[] = "CPF inválido";
    }
    
    if (empty($titular_nome)) {
        $erros[] = "Nome do titular é obrigatório";
    }
    
    if (empty($titular_cpf) || !validarCPF($titular_cpf)) {
        $erros[] = "CPF do titular inválido";
    }
    
    if (empty($banco_id)) {
        $erros[] = "Banco é obrigatório";
    }
    
    if (empty($agencia)) {
        $erros[] = "Agência é obrigatória";
    }
    
    if (empty($conta)) {
        $erros[] = "Conta é obrigatória";
    }
    
    if (!in_array($tipo_conta, ['corrente', 'poupanca'])) {
        $erros[] = "Tipo de conta inválido";
    }
    
    // Verificar se CPF já está cadastrado
    $stmt = $conn->prepare("SELECT cad_pro_id FROM tb_cad_produtores WHERE cad_pro_cpf = :cpf AND cad_pro_status = 'ativo'");
    $stmt->bindParam(':cpf', $cpf);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $erros[] = "CPF já cadastrado no sistema";
    }
    
    // Se houver erros, redirecionar com mensagem
    if (!empty($erros)) {
        $_SESSION['erro_cadastro'] = implode(', ', $erros);
        $_SESSION['dados_form'] = $_POST;
        header("Location: ../agricultura_cadastros.php?aba=produtores&erro=1");
        exit;
    }
    
    // Inserir no banco de dados
    $stmt = $conn->prepare("
        INSERT INTO tb_cad_produtores (
            cad_pro_nome,
            cad_pro_cpf,
            cad_pro_telefone,
            cad_pro_comunidade_id,
            cad_pro_titular_nome,
            cad_pro_titular_cpf,
            cad_pro_titular_telefone,
            cad_pro_banco_id,
            cad_pro_agencia,
            cad_pro_conta,
            cad_pro_tipo_conta,
            cad_pro_usuario_cadastro
        ) VALUES (
            :nome,
            :cpf,
            :telefone,
            :comunidade_id,
            :titular_nome,
            :titular_cpf,
            :titular_telefone,
            :banco_id,
            :agencia,
            :conta,
            :tipo_conta,
            :usuario_cadastro
        )
    ");
    
    $stmt->bindParam(':nome', $nome);
    $stmt->bindParam(':cpf', $cpf);
    $stmt->bindParam(':telefone', $telefone);
    $stmt->bindParam(':comunidade_id', $comunidade_id);
    $stmt->bindParam(':titular_nome', $titular_nome);
    $stmt->bindParam(':titular_cpf', $titular_cpf);
    $stmt->bindParam(':titular_telefone', $titular_telefone);
    $stmt->bindParam(':banco_id', $banco_id);
    $stmt->bindParam(':agencia', $agencia);
    $stmt->bindParam(':conta', $conta);
    $stmt->bindParam(':tipo_conta', $tipo_conta);
    $stmt->bindParam(':usuario_cadastro', $_SESSION['usersystem_id']);
    
    $stmt->execute();
    
    $_SESSION['sucesso_cadastro'] = "Produtor cadastrado com sucesso!";
    header("Location: ../agricultura_cadastros.php?aba=produtores&sucesso=1");
    exit;
    
} catch (PDOException $e) {
    error_log("Erro ao cadastrar produtor: " . $e->getMessage());
    $_SESSION['erro_cadastro'] = "Erro interno do sistema. Tente novamente.";
    $_SESSION['dados_form'] = $_POST;
    header("Location: ../agricultura_cadastros.php?aba=produtores&erro=1");
    exit;
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
    $_SESSION['erro_cadastro'] = "Erro: " . $e->getMessage();
    $_SESSION['dados_form'] = $_POST;
    header("Location: ../agricultura_cadastros.php?aba=produtores&erro=1");
    exit;
}
?>
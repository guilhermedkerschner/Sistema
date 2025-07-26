<?php
/**
 * Arquivo: controller/atualizar_produtor.php
 * Descrição: Processa a atualização de produtores
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

    // Capturar ID do produtor
    $produtor_id = $_POST['produtor_id'] ?? null;
    if (!$produtor_id || !is_numeric($produtor_id)) {
        throw new Exception("ID do produtor inválido.");
    }

    // Verificar se o produtor existe
    $stmt = $conn->prepare("SELECT cad_pro_id FROM tb_cad_produtores WHERE cad_pro_id = :id AND cad_pro_status = 'ativo'");
    $stmt->bindParam(':id', $produtor_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        throw new Exception("Produtor não encontrado.");
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
    
    // Verificar se CPF já está cadastrado em outro produtor
    $stmt = $conn->prepare("SELECT cad_pro_id FROM tb_cad_produtores WHERE cad_pro_cpf = :cpf AND cad_pro_status = 'ativo' AND cad_pro_id != :id");
    $stmt->bindParam(':cpf', $cpf);
    $stmt->bindParam(':id', $produtor_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $erros[] = "CPF já cadastrado para outro produtor";
    }
    
    // Se houver erros, redirecionar com mensagem
    if (!empty($erros)) {
        $_SESSION['erro_edicao'] = implode(', ', $erros);
        header("Location: ../editar_produtor.php?id=$produtor_id&erro=1");
        exit;
    }
    
    // Atualizar no banco de dados
    $stmt = $conn->prepare("
        UPDATE tb_cad_produtores SET
            cad_pro_nome = :nome,
            cad_pro_cpf = :cpf,
            cad_pro_telefone = :telefone,
            cad_pro_comunidade_id = :comunidade_id,
            cad_pro_titular_nome = :titular_nome,
            cad_pro_titular_cpf = :titular_cpf,
            cad_pro_titular_telefone = :titular_telefone,
            cad_pro_banco_id = :banco_id,
            cad_pro_agencia = :agencia,
            cad_pro_conta = :conta,
            cad_pro_tipo_conta = :tipo_conta,
            cad_pro_data_atualizacao = NOW()
        WHERE cad_pro_id = :id
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
    $stmt->bindParam(':id', $produtor_id);
    
    $stmt->execute();
    
    $_SESSION['sucesso_edicao'] = "Dados do produtor atualizados com sucesso!";
    header("Location: ../editar_produtor.php?id=$produtor_id&sucesso=1");
    exit;
    
} catch (PDOException $e) {
    error_log("Erro ao atualizar produtor: " . $e->getMessage());
    $_SESSION['erro_edicao'] = "Erro interno do sistema. Tente novamente.";
    $produtor_id = $_POST['produtor_id'] ?? '';
    header("Location: ../editar_produtor.php?id=$produtor_id&erro=1");
    exit;
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
    $_SESSION['erro_edicao'] = "Erro: " . $e->getMessage();
    $produtor_id = $_POST['produtor_id'] ?? '';
    header("Location: ../editar_produtor.php?id=$produtor_id&erro=1");
    exit;
}
?>
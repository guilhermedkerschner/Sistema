<?php
session_start();

if (!isset($_SESSION['usersystem_logado'])) {
    $_SESSION['erro_cadastro'] = "Acesso negado.";
    header("Location: ../agricultura_cadastros.php?aba=veterinarios&erro=1");
    exit;
}

require_once "../../database/conect.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['erro_cadastro'] = "Método não permitido.";
    header("Location: ../agricultura_cadastros.php?aba=veterinarios&erro=1");
    exit;
}

try {
    function sanitize($data) {
        return htmlspecialchars(strip_tags(trim($data)));
    }

    function validarCPF($cpf) {
        $cpf = preg_replace('/[^0-9]/', '', $cpf);
        
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

    $nome = sanitize($_POST['veterinario_nome'] ?? '');
    $cpf = preg_replace('/[^0-9]/', '', $_POST['veterinario_cpf'] ?? '');
    $crmv = sanitize($_POST['veterinario_crmv'] ?? '');
    $telefone = sanitize($_POST['veterinario_telefone'] ?? '');
    
    $erros = [];
    
    if (empty($nome)) {
        $erros[] = "Nome é obrigatório";
    }
    
    if (empty($cpf) || !validarCPF($cpf)) {
        $erros[] = "CPF inválido";
    }
    
    if (empty($crmv)) {
        $erros[] = "CRMV é obrigatório";
    }
    
    // Verificar se CPF já está cadastrado
    $stmt = $conn->prepare("SELECT vet_id FROM tb_cad_veterinarios WHERE vet_cpf = :cpf AND vet_status = 'ativo'");
    $stmt->bindParam(':cpf', $cpf);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $erros[] = "CPF já cadastrado no sistema";
    }
    
    // Verificar se CRMV já está cadastrado
    $stmt = $conn->prepare("SELECT vet_id FROM tb_cad_veterinarios WHERE vet_crmv = :crmv AND vet_status = 'ativo'");
    $stmt->bindParam(':crmv', $crmv);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $erros[] = "CRMV já cadastrado no sistema";
    }
    
    if (!empty($erros)) {
        $_SESSION['erro_cadastro'] = implode(', ', $erros);
        $_SESSION['dados_form_veterinario'] = $_POST;
        header("Location: ../agricultura_cadastros.php?aba=veterinarios&erro=1");
        exit;
    }
    
    $stmt = $conn->prepare("
        INSERT INTO tb_cad_veterinarios (
            vet_nome,
            vet_cpf,
            vet_crmv,
            vet_telefone,
            vet_usuario_cadastro
        ) VALUES (
            :nome,
            :cpf,
            :crmv,
            :telefone,
            :usuario_cadastro
        )
    ");
    
    $stmt->bindParam(':nome', $nome);
    $stmt->bindParam(':cpf', $cpf);
    $stmt->bindParam(':crmv', $crmv);
    $stmt->bindParam(':telefone', $telefone);
    $stmt->bindParam(':usuario_cadastro', $_SESSION['usersystem_id']);
    
    $stmt->execute();
    
    $_SESSION['sucesso_cadastro'] = "Veterinário cadastrado com sucesso!";
    header("Location: ../agricultura_cadastros.php?aba=veterinarios&sucesso=1");
    exit;
    
} catch (PDOException $e) {
    error_log("Erro ao cadastrar veterinário: " . $e->getMessage());
    $_SESSION['erro_cadastro'] = "Erro interno do sistema. Tente novamente.";
    $_SESSION['dados_form_veterinario'] = $_POST;
    header("Location: ../agricultura_cadastros.php?aba=veterinarios&erro=1");
    exit;
}
?>
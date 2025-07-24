<?php
/**
 * Arquivo: processar_cadastro_usuario.php
 * Descrição: Processa o cadastro de novos usuários do sistema
 * 
 * Parte do sistema de gerenciamento da Prefeitura
 */

// Desativar exibição de erros para evitar corromper a saída JSON
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Inicia a sessão
session_start();

// Define o cabeçalho para JSON
header('Content-Type: application/json; charset=utf-8');

// Verifica se o usuário está logado e é administrador
if (!isset($_SESSION['usersystem_logado']) || $_SESSION['usersystem_nivel'] !== 'Administrador') {
    echo json_encode([
        'success' => false,
        'message' => 'Acesso negado. Apenas administradores podem cadastrar usuários.'
    ]);
    exit;
}

// Incluir arquivo de configuração com conexão ao banco de dados
require_once "../../database/conect.php";

// Verificar se o formulário foi enviado via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Método de requisição inválido.'
    ]);
    exit;
}

// Função para sanitizar inputs
function sanitize($data) {
    if (is_null($data) || $data === '') {
        return null;
    }
    
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Função para validar CPF
function validarCPF($cpf) {
    // Extrai somente os números
    $cpf = preg_replace('/[^0-9]/is', '', $cpf);
    
    // Verifica se foi informado todos os dígitos corretamente
    if (strlen($cpf) != 11) {
        return false;
    }
    
    // Verifica se foi informada uma sequência de dígitos repetidos
    if (preg_match('/(\d)\1{10}/', $cpf)) {
        return false;
    }
    
    // Faz o cálculo para validar o CPF
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

// Função para validar email
function validarEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Função para validar força da senha
function validarSenha($senha) {
    if (strlen($senha) < 8) {
        return 'A senha deve ter pelo menos 8 caracteres.';
    }
    
    if (!preg_match('/[a-z]/', $senha)) {
        return 'A senha deve conter pelo menos uma letra minúscula.';
    }
    
    if (!preg_match('/[A-Z]/', $senha)) {
        return 'A senha deve conter pelo menos uma letra maiúscula.';
    }
    
    if (!preg_match('/[0-9]/', $senha)) {
        return 'A senha deve conter pelo menos um número.';
    }
    
    return true;
}

try {
    // Verificar conexão com o banco
    if (!isset($conn) || !$conn) {
        throw new Exception("Erro na conexão com o banco de dados.");
    }
    
    // Capturar e sanitizar dados do formulário
    $nome = sanitize($_POST['nome'] ?? '');
    $cpf = sanitize($_POST['cpf'] ?? '');
    $telefone = sanitize($_POST['telefone'] ?? '');
    $login = sanitize($_POST['login'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $confirmar_senha = $_POST['confirmar_senha'] ?? '';
    $nivel_acesso = sanitize($_POST['nivel_acesso'] ?? '');
    $setor = sanitize($_POST['setor'] ?? '');
    $observacoes = sanitize($_POST['observacoes'] ?? '');
    
    // Validações
    $erros = [];
    
    // Validar campos obrigatórios
    if (empty($nome)) {
        $erros[] = 'O nome é obrigatório.';
    }
    
    if (empty($cpf)) {
        $erros[] = 'O CPF é obrigatório.';
    } elseif (!validarCPF($cpf)) {
        $erros[] = 'CPF inválido.';
    }
    
    if (empty($login)) {
        $erros[] = 'O login é obrigatório.';
    } elseif (strlen($login) < 3) {
        $erros[] = 'O login deve ter pelo menos 3 caracteres.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $login)) {
        $erros[] = 'O login deve conter apenas letras, números e underscore.';
    }
    
    if (empty($email)) {
        $erros[] = 'O e-mail é obrigatório.';
    } elseif (!validarEmail($email)) {
        $erros[] = 'E-mail inválido.';
    }
    
    if (empty($senha)) {
        $erros[] = 'A senha é obrigatória.';
    } else {
        $validacao_senha = validarSenha($senha);
        if ($validacao_senha !== true) {
            $erros[] = $validacao_senha;
        }
    }
    
    if ($senha !== $confirmar_senha) {
        $erros[] = 'As senhas não coincidem.';
    }
    
    if (empty($nivel_acesso)) {
        $erros[] = 'O nível de acesso é obrigatório.';
    } elseif (!in_array($nivel_acesso, ['1', '2', '3', '4'])) {
        $erros[] = 'Nível de acesso inválido.';
    }
    
    // Se houver erros, retornar
    if (!empty($erros)) {
        echo json_encode([
            'success' => false,
            'message' => implode(' ', $erros)
        ]);
        exit;
    }
    
    // Limpar CPF e telefone (manter apenas números)
    $cpf_limpo = preg_replace('/[^0-9]/', '', $cpf);
    $telefone_limpo = !empty($telefone) ? preg_replace('/[^0-9]/', '', $telefone) : null;
    
    // Verificar se login já existe
    $stmt = $conn->prepare("SELECT usuario_id FROM tb_usuarios_sistema WHERE usuario_login = :login");
    $stmt->bindParam(':login', $login);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Este login já está sendo usado por outro usuário.'
        ]);
        exit;
    }
    
    // Verificar se CPF já existe
    $stmt = $conn->prepare("SELECT usuario_id FROM tb_usuarios_sistema WHERE usuario_cpf = :cpf");
    $stmt->bindParam(':cpf', $cpf_limpo);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Este CPF já está cadastrado no sistema.'
        ]);
        exit;
    }
    
    // Verificar se e-mail já existe
    $stmt = $conn->prepare("SELECT usuario_id FROM tb_usuarios_sistema WHERE usuario_email = :email");
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Este e-mail já está cadastrado no sistema.'
        ]);
        exit;
    }
    
    // Criptografar a senha
    $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
    
    // Inserir usuário no banco de dados
    $sql = "INSERT INTO tb_usuarios_sistema (
        usuario_nome, usuario_cpf, usuario_telefone, usuario_login, usuario_email, usuario_senha,
        usuario_nivel_id, usuario_departamento, usuario_observacao, usuario_status,
        usuario_data_criacao, usuario_cadastrado_por
    ) VALUES (
        :nome, :cpf, :telefone, :login, :email, :senha,
        :nivel, :setor, :observacoes, 'ativo',
        NOW(), :cadastrado_por
    )";
    
    $stmt = $conn->prepare($sql);
    
    // Binding de parâmetros
    $stmt->bindParam(':nome', $nome);
    $stmt->bindParam(':cpf', $cpf_limpo);
    $stmt->bindParam(':telefone', $telefone_limpo);
    $stmt->bindParam(':login', $login);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':senha', $senha_hash);
    $stmt->bindParam(':nivel', $nivel_acesso);
    $stmt->bindParam(':setor', $setor);
    $stmt->bindParam(':observacoes', $observacoes);
    $stmt->bindParam(':cadastrado_por', $_SESSION['usersystem_id']);
    
    // Executar a inserção
    if ($stmt->execute()) {
        $usuario_id = $conn->lastInsertId();
        
               
        echo json_encode([
            'success' => true,
            'message' => 'Usuário cadastrado com sucesso!'
        ]);
        
    } else {
        throw new Exception("Erro ao inserir usuário no banco de dados.");
    }
    
} catch (PDOException $e) {
    // Log do erro
    error_log("Erro no cadastro de usuário: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno do servidor. Tente novamente mais tarde.'
    ]);
    
} catch (Exception $e) {
    // Log do erro
    error_log("Erro no cadastro de usuário: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
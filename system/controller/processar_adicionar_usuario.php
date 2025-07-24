<?php
/**
 * Arquivo: processar_adicionar_usuario.php
 * Descrição: Processa o formulário de adição de usuário
 * 
 * Parte do sistema de administração da Prefeitura
 */

// Inicia a sessão
session_start();

// Verificar se o usuário está logado e é administrador
if (!isset($_SESSION['usersystem_logado'])) {
    header("Location: ../acessdeniedrestrict.php");
    exit;
}

// Incluir arquivo de configuração com conexão ao banco de dados
require_once "../../database/conect.php";

// Verificar se é administrador
$usuario_id = $_SESSION['usersystem_id'];
$is_admin = false;

try {
    $stmt = $conn->prepare("SELECT usuario_nivel_id FROM tb_usuarios_sistema WHERE usuario_id = :id");
    $stmt->bindParam(':id', $usuario_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        $is_admin = ($usuario['usuario_nivel_id'] == 1);
    }
} catch (PDOException $e) {
    error_log("Erro ao verificar permissões: " . $e->getMessage());
}

if (!$is_admin) {
    header("Location: ../system/dashboard.php");
    exit;
}

// Verificar se o formulário foi enviado via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../adicionar_usuario.php");
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

// Função para validar senha forte
function validarSenhaForte($senha) {
    // Pelo menos 8 caracteres, 1 maiúscula, 1 minúscula, 1 número, 1 especial
    return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*])[A-Za-z\d!@#$%^&*]{8,}$/', $senha);
}

// Capturar dados do formulário
$nome = sanitize($_POST['nome'] ?? '');
$email = sanitize($_POST['email'] ?? '');
$login = sanitize($_POST['login'] ?? '');
$departamento = sanitize($_POST['departamento'] ?? '');
$nivel = (int)($_POST['nivel'] ?? 2);
$status = sanitize($_POST['status'] ?? 'ativo');
$senha = $_POST['senha'] ?? '';
$confirmar_senha = $_POST['confirmar_senha'] ?? '';
$observacoes = sanitize($_POST['observacoes'] ?? '');

// Armazenar dados do formulário na sessão para redirecionamento em caso de erro
$_SESSION['dados_form_usuario'] = [
    'nome' => $nome,
    'email' => $email,
    'login' => $login,
    'departamento' => $departamento,
    'nivel' => $nivel,
    'status' => $status,
    'observacoes' => $observacoes
];

// Array para armazenar erros
$erros = [];

// Validações
if (empty($nome)) {
    $erros[] = "O nome é obrigatório.";
} elseif (strlen($nome) > 255) {
    $erros[] = "O nome deve ter no máximo 255 caracteres.";
}

if (empty($email)) {
    $erros[] = "O e-mail é obrigatório.";
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $erros[] = "O e-mail informado não é válido.";
} elseif (strlen($email) > 255) {
    $erros[] = "O e-mail deve ter no máximo 255 caracteres.";
}

if (empty($login)) {
    $erros[] = "O login é obrigatório.";
} elseif (strlen($login) > 100) {
    $erros[] = "O login deve ter no máximo 100 caracteres.";
} elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
    $erros[] = "O login deve conter apenas letras, números, pontos, hífens e sublinhados.";
}

if (empty($departamento)) {
    $erros[] = "O departamento é obrigatório.";
}

if (!in_array($nivel, [1, 2, 3, 4])) {
    $erros[] = "Nível de acesso inválido.";
}

if (!in_array($status, ['ativo', 'inativo'])) {
    $erros[] = "Status inválido.";
}

if (empty($senha)) {
    $erros[] = "A senha é obrigatória.";
} elseif (strlen($senha) < 8) {
    $erros[] = "A senha deve ter pelo menos 8 caracteres.";
} elseif (!validarSenhaForte($senha)) {
    $erros[] = "A senha deve conter pelo menos uma letra maiúscula, uma minúscula, um número e um caractere especial.";
}

if ($senha !== $confirmar_senha) {
    $erros[] = "As senhas não coincidem.";
}

if ($observacoes && strlen($observacoes) > 500) {
    $erros[] = "As observações devem ter no máximo 500 caracteres.";
}

// Se houver erros, redirecionar de volta para o formulário
if (!empty($erros)) {
    $_SESSION['erro_usuario'] = implode("<br>", $erros);
    header("Location: ../adicionar_usuario.php");
    exit;
}

try {
    // Verificar se o login já existe
    $stmt = $conn->prepare("SELECT usuario_id FROM tb_usuarios_sistema WHERE usuario_login = :login");
    $stmt->bindParam(':login', $login);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $_SESSION['erro_usuario'] = "Este login já está sendo usado por outro usuário.";
        header("Location: ../adicionar_usuario.php");
        exit;
    }
    
    // Verificar se o e-mail já existe
    $stmt = $conn->prepare("SELECT usuario_id FROM tb_usuarios_sistema WHERE usuario_email = :email");
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $_SESSION['erro_usuario'] = "Este e-mail já está sendo usado por outro usuário.";
        header("Location: ../adicionar_usuario.php");
        exit;
    }
    
    // Criptografar a senha
    $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
    
    // Data e hora atual
    $data_cadastro = date('Y-m-d H:i:s');
    
    // Inserir usuário no banco de dados
    $sql = "INSERT INTO tb_usuarios_sistema (
        usuario_nome, 
        usuario_login, 
        usuario_email, 
        usuario_senha, 
        usuario_departamento, 
        usuario_nivel_id, 
        usuario_status, 
        usuario_observacao,
        usuario_data_criacao,
        usuario_cadastrado_por
    ) VALUES (
        :nome, 
        :login, 
        :email, 
        :senha, 
        :departamento, 
        :nivel, 
        :status, 
        :observacoes,
        :data_cad,
        :criado_por
    )";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':nome', $nome);
    $stmt->bindParam(':login', $login);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':senha', $senha_hash);
    $stmt->bindParam(':departamento', $departamento);
    $stmt->bindParam(':nivel', $nivel);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':observacoes', $observacoes);
    $stmt->bindParam(':data_cad', $data_cadastro);
    $stmt->bindParam(':criado_por', $usuario_id);
    
    if ($stmt->execute()) {
        // Limpar dados do formulário da sessão
        unset($_SESSION['dados_form_usuario']);
        
        // Definir mensagem de sucesso
        $_SESSION['sucesso_usuario'] = "Usuário '{$nome}' criado com sucesso!";
        
        // Redirecionar para a lista de usuários
        header("Location: ../lista_usuarios.php");
        exit;
    } else {
        throw new Exception("Erro ao inserir usuário no banco de dados.");
    }
    
} catch (PDOException $e) {
    // Registrar erro no log
    error_log("Erro ao criar usuário: " . $e->getMessage());
    
    // Verificar se é erro de chave duplicada
    if ($e->getCode() == 23000) {
        if (strpos($e->getMessage(), 'usuario_login') !== false) {
            $_SESSION['erro_usuario'] = "Este login já está sendo usado por outro usuário.";
        } elseif (strpos($e->getMessage(), 'usuario_email') !== false) {
            $_SESSION['erro_usuario'] = "Este e-mail já está sendo usado por outro usuário.";
        } else {
            $_SESSION['erro_usuario'] = "Erro: dados duplicados encontrados.";
        }
    } else {
        $_SESSION['erro_usuario'] = "Erro ao criar usuário. Por favor, tente novamente mais tarde.";
    }
    
    header("Location: ../adicionar_usuario.php");
    exit;
    
} catch (Exception $e) {
    // Registrar erro no log
    error_log("Erro geral ao criar usuário: " . $e->getMessage());
    
    $_SESSION['erro_usuario'] = "Erro interno do sistema. Por favor, tente novamente mais tarde.";
    header("Location: ../adicionar_usuario.php");
    exit;
}
?>
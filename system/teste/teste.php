<?php
// Ativar exibição de erros para debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "1. Teste iniciado...<br>";

// Testar sessão
session_start();
echo "2. Sessão iniciada...<br>";

// Testar se usuário está logado
if (!isset($_SESSION['usersystem_logado'])) {
    echo "3. ERRO: Usuário não está logado<br>";
    echo "Sessão atual: ";
    print_r($_SESSION);
    exit;
}
echo "3. Usuário está logado: " . $_SESSION['usersystem_nome'] . "<br>";

// Testar arquivo de config
echo "4. Tentando incluir config...<br>";
try {
    require_once "../../database/conect.php";
    echo "5. Config incluído com sucesso<br>";
} catch (Exception $e) {
    echo "5. ERRO ao incluir config: " . $e->getMessage() . "<br>";
    exit;
}

// Testar conexão com banco
echo "6. Testando conexão com banco...<br>";
if (!isset($conn)) {
    echo "7. ERRO: Variável \$conn não existe<br>";
    exit;
}

try {
    $stmt = $conn->prepare("SELECT 1");
    $stmt->execute();
    echo "7. Conexão com banco OK<br>";
} catch (PDOException $e) {
    echo "7. ERRO na conexão: " . $e->getMessage() . "<br>";
    exit;
}

// Testar se tabelas existem
echo "8. Verificando tabelas...<br>";
$tabelas = ['tb_cad_bancos', 'tb_cad_comunidades', 'tb_cad_produtores'];
foreach ($tabelas as $tabela) {
    try {
        $stmt = $conn->prepare("SHOW TABLES LIKE '$tabela'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            echo "- Tabela $tabela: EXISTS<br>";
        } else {
            echo "- Tabela $tabela: NOT EXISTS<br>";
        }
    } catch (PDOException $e) {
        echo "- Erro ao verificar $tabela: " . $e->getMessage() . "<br>";
    }
}

echo "9. Teste concluído com sucesso!<br>";
?>
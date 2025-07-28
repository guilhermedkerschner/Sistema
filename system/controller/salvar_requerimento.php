<?php
session_start();

if (!isset($_SESSION['usersystem_logado'])) {
    $_SESSION['erro_requerimento'] = "Usuário não autenticado.";
    header("Location: ../requerimentos.php");
    exit;
}

require_once "../../database/conect.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['erro_requerimento'] = "Método inválido.";
    header("Location: ../requerimentos.php");
    exit;
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

try {
    // Capturar dados do formulário
    $tipo = $_POST['tipo'] ?? '';
    $produtor_id = !empty($_POST['produtor_id']) ? (int)$_POST['produtor_id'] : null;
    $servico_id = !empty($_POST['servico_id']) ? (int)$_POST['servico_id'] : null;
    $data_solicitacao = $_POST['data_solicitacao'] ?? '';
    $descricao = sanitize($_POST['descricao'] ?? '');
    
    // Validações
    $erros = [];
    
    if (!in_array($tipo, ['agricultura', 'meio_ambiente', 'vacinas', 'exames'])) {
        $erros[] = "Tipo de requerimento inválido";
    }
    
    if (empty($produtor_id)) {
        $erros[] = "Produtor é obrigatório";
    }
    
    if (empty($servico_id)) {
        $erros[] = "Serviço é obrigatório";
    }
    
    if (empty($data_solicitacao)) {
        $erros[] = "Data da solicitação é obrigatória";
    }
    
    if (empty($descricao)) {
        $erros[] = "Descrição é obrigatória";
    }
    
    // Verificar se a data não é futura além de 30 dias
    $data_limite = date('Y-m-d', strtotime('+30 days'));
    if ($data_solicitacao > $data_limite) {
        $erros[] = "Data da solicitação não pode ser superior a 30 dias";
    }
    
    // Verificar se produtor existe e está ativo
    if (!empty($produtor_id)) {
        $stmt = $conn->prepare("SELECT cad_pro_id, cad_pro_nome FROM tb_cad_produtores WHERE cad_pro_id = :id AND cad_pro_status = 'ativo'");
        $stmt->bindParam(':id', $produtor_id);
        $stmt->execute();
        
        if ($stmt->rowCount() === 0) {
            $erros[] = "Produtor não encontrado ou inativo";
        }
    }
    
    // Verificar se serviço existe e está ativo
    if (!empty($servico_id)) {
        $stmt = $conn->prepare("SELECT ser_id, ser_nome FROM tb_cad_servicos WHERE ser_id = :id AND ser_status = 'ativo'");
        $stmt->bindParam(':id', $servico_id);
        $stmt->execute();
        
        if ($stmt->rowCount() === 0) {
            $erros[] = "Serviço não encontrado ou inativo";
        }
    }
    
    // Se houver erros, redirecionar com mensagem
    if (!empty($erros)) {
        $_SESSION['erro_requerimento'] = implode(', ', $erros);
        $_SESSION['dados_form_requerimento'] = $_POST;
        header("Location: ../agricultura_requerimentos.php?aba=" . $tipo);
        exit;
    }
    
    // Função para gerar número do requerimento
    function gerarNumeroRequerimento($conn, $tipo) {
        $ano = date('Y');
        $prefixos = [
            'agricultura' => 'AGR',
            'meio_ambiente' => 'AMB',
            'vacinas' => 'VAC',
            'exames' => 'EXA'
        ];
        
        $prefixo = $prefixos[$tipo] . $ano;
        
        // Buscar o último número sequencial do ano para este tipo
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total 
            FROM tb_requerimentos 
            WHERE req_numero LIKE :prefixo AND YEAR(req_data_criacao) = :ano
        ");
        $stmt->bindValue(':prefixo', $prefixo . '%');
        $stmt->bindValue(':ano', $ano);
        $stmt->execute();
        
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $sequencial = str_pad($total + 1, 4, '0', STR_PAD_LEFT);
        
        return $prefixo . $sequencial;
    }
    
    $numero_requerimento = gerarNumeroRequerimento($conn, $tipo);
    
    // Verificar se o número já existe (proteção contra duplicatas)
    $stmt = $conn->prepare("SELECT req_id FROM tb_requerimentos WHERE req_numero = :numero");
    $stmt->bindParam(':numero', $numero_requerimento);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        // Se existir, gerar um novo número com timestamp
        $numero_requerimento = $numero_requerimento . '_' . time();
    }
    
    // Inserir no banco de dados
    $stmt = $conn->prepare("
        INSERT INTO tb_requerimentos (
            req_numero,
            req_tipo,
            req_produtor_id,
            req_servico_id,
            req_data_solicitacao,
            req_descricao,
            req_status,
            req_usuario_cadastro,
            req_data_criacao
        ) VALUES (
            :numero,
            :tipo,
            :produtor_id,
            :servico_id,
            :data_solicitacao,
            :descricao,
            'agendado',
            :usuario_cadastro,
            NOW()
        )
    ");
    
    $stmt->bindParam(':numero', $numero_requerimento);
    $stmt->bindParam(':tipo', $tipo);
    $stmt->bindParam(':produtor_id', $produtor_id);
    $stmt->bindParam(':servico_id', $servico_id);
    $stmt->bindParam(':data_solicitacao', $data_solicitacao);
    $stmt->bindParam(':descricao', $descricao);
    $stmt->bindParam(':usuario_cadastro', $_SESSION['usersystem_id']);
    
    $stmt->execute();
    
    // Limpar dados do formulário da sessão
    unset($_SESSION['dados_form_requerimento']);
    
    $_SESSION['sucesso_requerimento'] = "Requerimento {$numero_requerimento} cadastrado com sucesso!";
    header("Location: ../agricultura_requerimentos.php?aba=" . $tipo);
    
} catch (PDOException $e) {
    error_log("Erro ao salvar requerimento: " . $e->getMessage());
    $_SESSION['erro_requerimento'] = "Erro interno do sistema. Tente novamente.";
    $_SESSION['dados_form_requerimento'] = $_POST;
    header("Location: ../agricultura_requerimentos.php?aba=" . ($tipo ?? 'agricultura'));
} catch (Exception $e) {
    error_log("Erro geral ao salvar requerimento: " . $e->getMessage());
    $_SESSION['erro_requerimento'] = "Erro inesperado. Tente novamente.";
    $_SESSION['dados_form_requerimento'] = $_POST;
    header("Location: ../agricultura_requerimentos.php?aba=" . ($tipo ?? 'agricultura'));
}
?>
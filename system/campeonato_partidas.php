<?php
// Inicia a sessão
session_start();

// Verificar se o usuário está logado
if (!isset($_SESSION['usersystem_logado'])) {
    header("Location: ../acessdeniedrestrict.php"); 
    exit;
}

// Incluir dependências
require_once "../lib/config.php";
require_once "./core/MenuManager.php";

// Buscar informações do usuário logado
$usuario_id = $_SESSION['usersystem_id'];
$usuario_dados = [];

try {
    $stmt = $conn->prepare("
        SELECT 
            usuario_id,
            usuario_nome, 
            usuario_departamento, 
            usuario_nivel_id,
            usuario_email
        FROM tb_usuarios_sistema 
        WHERE usuario_id = :id
    ");
    $stmt->bindParam(':id', $usuario_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $usuario_dados = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        session_destroy();
        header("Location: ../acessdeniedrestrict.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar dados do usuário: " . $e->getMessage());
    header("Location: ../acessdeniedrestrict.php");
    exit;
}

// Verificar permissões de acesso ao módulo de esporte
$is_admin = ($usuario_dados['usuario_nivel_id'] == 1);
$usuario_departamento = strtoupper($usuario_dados['usuario_departamento'] ?? '');
$tem_permissao = $is_admin || $usuario_departamento === 'ESPORTE';

if (!$tem_permissao) {
    header("Location: dashboard.php?erro=acesso_negado");
    exit;
}

// Inicializar o MenuManager
$userSession = [
    'usuario_id' => $usuario_dados['usuario_id'],
    'usuario_nome' => $usuario_dados['usuario_nome'],
    'usuario_departamento' => $usuario_dados['usuario_departamento'],
    'usuario_nivel_id' => $usuario_dados['usuario_nivel_id'],
    'usuario_email' => $usuario_dados['usuario_email']
];

$menuManager = new MenuManager($userSession);
$themeColors = $menuManager->getThemeColors();

// Obter parâmetros
$campeonato_id = isset($_GET['campeonato_id']) ? (int)$_GET['campeonato_id'] : 0;
$acao = $_GET['acao'] ?? 'listar';

// Para súmula, o parâmetro é partida_id, para outras ações é id
if ($acao === 'sumula') {
    $partida_id = isset($_GET['partida_id']) ? (int)$_GET['partida_id'] : 0;
} else {
    $partida_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
}

// DEBUG para súmula
if ($acao === 'sumula') {
    error_log("=== DEBUG SÚMULA ===");
    error_log("Ação: " . $acao);
    error_log("partida_id do GET: " . $partida_id);
    error_log("partida_id do parâmetro: " . ($_GET['partida_id'] ?? 'não definido'));
    error_log("URL completa: " . $_SERVER['REQUEST_URI']);
}

if (!$campeonato_id) {
    header("Location: esporte_campeonatos.php?mensagem=" . urlencode("Campeonato não especificado.") . "&tipo=error");
    exit;
}

// Variáveis para mensagens
$mensagem = "";
$tipo_mensagem = "";

// Função para sanitizar inputs
function sanitizeInput($data) {
    if (is_null($data) || $data === '') {
        return null;
    }
    return trim(htmlspecialchars(stripslashes($data)));
}

// Buscar dados do campeonato
$campeonato = null;
try {
    $stmt = $conn->prepare("SELECT * FROM tb_campeonatos WHERE campeonato_id = :id");
    $stmt->bindValue(':id', $campeonato_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $campeonato = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        header("Location: esporte_campeonatos.php?mensagem=" . urlencode("Campeonato não encontrado.") . "&tipo=error");
        exit;
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar campeonato: " . $e->getMessage());
    header("Location: esporte_campeonatos.php?mensagem=" . urlencode("Erro ao buscar campeonato.") . "&tipo=error");
    exit;
}

// Buscar dados do campeonato
$campeonato = null;
try {
    $stmt = $conn->prepare("SELECT * FROM tb_campeonatos WHERE campeonato_id = :id");
    $stmt->bindValue(':id', $campeonato_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $campeonato = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        header("Location: esporte_campeonatos.php?mensagem=" . urlencode("Campeonato não encontrado.") . "&tipo=error");
        exit;
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar campeonato: " . $e->getMessage());
    header("Location: esporte_campeonatos.php?mensagem=" . urlencode("Erro ao buscar campeonato.") . "&tipo=error");
    exit;
}

// ADICIONE A FUNÇÃO AQUI - ANTES DO PROCESSAMENTO POST
function criarTabelasEventos($conn) {
    try {
        // Tabela de gols
        $conn->exec("
            CREATE TABLE IF NOT EXISTS tb_campeonato_gols (
                gol_id INT AUTO_INCREMENT PRIMARY KEY,
                partida_id INT NOT NULL,
                atleta_id INT NOT NULL,
                minuto_gol INT NOT NULL,
                tipo_gol ENUM('NORMAL', 'PENALTI', 'FALTA', 'CONTRA') DEFAULT 'NORMAL',
                data_evento DATETIME DEFAULT CURRENT_TIMESTAMP,
                observacoes TEXT,
                INDEX idx_partida (partida_id),
                INDEX idx_atleta (atleta_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Tabela de cartões
        $conn->exec("
            CREATE TABLE IF NOT EXISTS tb_campeonato_cartoes (
                cartao_id INT AUTO_INCREMENT PRIMARY KEY,
                partida_id INT NOT NULL,
                atleta_id INT NOT NULL,
                minuto_cartao INT NOT NULL,
                tipo_cartao ENUM('AMARELO', 'VERMELHO') NOT NULL,
                motivo_cartao VARCHAR(255),
                data_evento DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_partida (partida_id),
                INDEX idx_atleta (atleta_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Tabela de substituições
        $conn->exec("
            CREATE TABLE IF NOT EXISTS tb_campeonato_substituicoes (
                substituicao_id INT AUTO_INCREMENT PRIMARY KEY,
                partida_id INT NOT NULL,
                atleta_sai_id INT NOT NULL,
                atleta_entra_id INT NOT NULL,
                minuto_substituicao INT NOT NULL,
                motivo_substituicao ENUM('TATICA', 'LESAO', 'DISCIPLINAR') DEFAULT 'TATICA',
                data_evento DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_partida (partida_id),
                INDEX idx_atleta_sai (atleta_sai_id),
                INDEX idx_atleta_entra (atleta_entra_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        error_log("Tabelas de eventos criadas/verificadas com sucesso");
        return true;
        
    } catch (PDOException $e) {
        error_log("Erro ao criar tabelas de eventos: " . $e->getMessage());
        return false;
    }
}

// Buscar configurações do campeonato
$config_campeonato = [
    'pontos_vitoria' => 3,
    'pontos_empate' => 1,
    'pontos_derrota' => 0,
    'permite_empate' => 1,
    'tempo_partida' => 90
];

// Processamento de formulários
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Pegar a ação do POST
    $acao_post = $_POST['acao'] ?? '';
    
    // DEBUG: Log dos dados recebidos
    if ($acao_post === 'agendar_partida') {
        error_log("AGENDAMENTO - Dados recebidos:");
        error_log("acao_post: " . $acao_post);
        error_log("partida_id: " . $partida_id);
        error_log("POST: " . print_r($_POST, true));
    }
    
    try {
        // Verificar se já existe uma transação ativa antes de iniciar uma nova
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
        }
        
        if ($acao_post === 'atualizar_resultado' && $partida_id) {
            // Atualizar resultado da partida
            $gols_equipe1 = (int)$_POST['gols_equipe1'];
            $gols_equipe2 = (int)$_POST['gols_equipe2'];
            $penaltis_equipe1 = !empty($_POST['penaltis_equipe1']) ? (int)$_POST['penaltis_equipe1'] : null;
            $penaltis_equipe2 = !empty($_POST['penaltis_equipe2']) ? (int)$_POST['penaltis_equipe2'] : null;
            $observacoes = sanitizeInput($_POST['observacoes_partida']);
            $arbitro_principal = sanitizeInput($_POST['arbitro_principal']);
            $publico_presente = !empty($_POST['publico_presente']) ? (int)$_POST['publico_presente'] : null;
            $tempo_jogo = !empty($_POST['tempo_jogo']) ? (int)$_POST['tempo_jogo'] : $config_campeonato['tempo_partida'];
            
            $stmt = $conn->prepare("
                UPDATE tb_campeonato_partidas SET 
                    gols_equipe1 = :gols_equipe1,
                    gols_equipe2 = :gols_equipe2,
                    penaltis_equipe1 = :penaltis_equipe1,
                    penaltis_equipe2 = :penaltis_equipe2,
                    observacoes_partida = :observacoes,
                    arbitro_principal = :arbitro_principal,
                    publico_presente = :publico_presente,
                    tempo_jogo = :tempo_jogo,
                    status_partida = 'FINALIZADA'
                WHERE partida_id = :partida_id AND campeonato_id = :campeonato_id
            ");
            
            $stmt->bindValue(':gols_equipe1', $gols_equipe1);
            $stmt->bindValue(':gols_equipe2', $gols_equipe2);
            $stmt->bindValue(':penaltis_equipe1', $penaltis_equipe1);
            $stmt->bindValue(':penaltis_equipe2', $penaltis_equipe2);
            $stmt->bindValue(':observacoes', $observacoes);
            $stmt->bindValue(':arbitro_principal', $arbitro_principal);
            $stmt->bindValue(':publico_presente', $publico_presente);
            $stmt->bindValue(':tempo_jogo', $tempo_jogo);
            $stmt->bindValue(':partida_id', $partida_id);
            $stmt->bindValue(':campeonato_id', $campeonato_id);
            
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                if ($conn->inTransaction()) {
                    $conn->commit();
                }
                $mensagem = "Resultado da partida atualizado com sucesso!";
                $tipo_mensagem = "success";
            } else {
                throw new Exception("Nenhuma partida foi atualizada. Verifique se a partida existe.");
            }
            
        } elseif ($acao_post === 'agendar_partida' && $partida_id) {
            // Agendar data e local da partida
            $partida_data = sanitizeInput($_POST['partida_data']);
            $local_partida = sanitizeInput($_POST['local_partida']);
            $arbitro_principal = sanitizeInput($_POST['arbitro_principal']);
            $arbitro_auxiliar1 = sanitizeInput($_POST['arbitro_auxiliar1']);
            $arbitro_auxiliar2 = sanitizeInput($_POST['arbitro_auxiliar2']);
            
            if (empty($partida_data)) {
                throw new Exception("Data da partida é obrigatória.");
            }
            
            // Verificar se as colunas existem
            $colunas_extras = "";
            $valores_extras = "";
            $params_extras = [];
            
            // Verificar se a coluna arbitro_auxiliar1 existe
            $stmt_check = $conn->query("SHOW COLUMNS FROM tb_campeonato_partidas LIKE 'arbitro_auxiliar1'");
            if ($stmt_check->rowCount() > 0) {
                $colunas_extras .= ", arbitro_auxiliar1 = :arbitro_auxiliar1, arbitro_auxiliar2 = :arbitro_auxiliar2";
                $params_extras[':arbitro_auxiliar1'] = $arbitro_auxiliar1;
                $params_extras[':arbitro_auxiliar2'] = $arbitro_auxiliar2;
            }
            
            $sql = "
                UPDATE tb_campeonato_partidas SET 
                    partida_data = :partida_data,
                    local_partida = :local_partida,
                    arbitro_principal = :arbitro_principal
                    $colunas_extras
                WHERE partida_id = :partida_id AND campeonato_id = :campeonato_id
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':partida_data', $partida_data);
            $stmt->bindValue(':local_partida', $local_partida);
            $stmt->bindValue(':arbitro_principal', $arbitro_principal);
            $stmt->bindValue(':partida_id', $partida_id);
            $stmt->bindValue(':campeonato_id', $campeonato_id);
            
            // Adicionar parâmetros extras se existirem
            foreach ($params_extras as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            
            if ($conn->inTransaction()) {
                $conn->commit();
            }
            $mensagem = "Partida agendada com sucesso!";
            $tipo_mensagem = "success";
            
        } elseif ($acao_post === 'cancelar_partida' && $partida_id) {
            // Cancelar partida
            $motivo_cancelamento = sanitizeInput($_POST['motivo_cancelamento']);
            
            if (empty($motivo_cancelamento)) {
                throw new Exception("Motivo do cancelamento é obrigatório.");
            }
            
            $stmt = $conn->prepare("
                UPDATE tb_campeonato_partidas SET 
                    status_partida = 'CANCELADA',
                    observacoes_partida = CONCAT(COALESCE(observacoes_partida, ''), '\n\nCANCELADA: ', :motivo)
                WHERE partida_id = :partida_id AND campeonato_id = :campeonato_id
            ");
            
            $stmt->bindValue(':motivo', $motivo_cancelamento);
            $stmt->bindValue(':partida_id', $partida_id);
            $stmt->bindValue(':campeonato_id', $campeonato_id);
            
            $stmt->execute();
            
            if ($conn->inTransaction()) {
                $conn->commit();
            }
            $mensagem = "Partida cancelada com sucesso!";
            $tipo_mensagem = "success";
            
        } elseif ($acao_post === 'adicionar_gol') {
            $partida_id_post = (int)$_POST['partida_id'];
            $atleta_id = (int)$_POST['atleta_id'];
            $minuto = (int)$_POST['minuto'];
            $tipo_gol = sanitizeInput($_POST['tipo_gol']) ?: 'NORMAL';
            
            // Verificar se as tabelas de eventos existem, se não, criar
            criarTabelasEventos($conn);
            
            $stmt = $conn->prepare("
                INSERT INTO tb_campeonato_gols 
                (partida_id, atleta_id, minuto_gol, tipo_gol, data_evento) 
                VALUES (:partida_id, :atleta_id, :minuto, :tipo_gol, NOW())
            ");
            $stmt->bindValue(':partida_id', $partida_id_post);
            $stmt->bindValue(':atleta_id', $atleta_id);
            $stmt->bindValue(':minuto', $minuto);
            $stmt->bindValue(':tipo_gol', $tipo_gol);
            $stmt->execute();
            
            if ($conn->inTransaction()) {
                $conn->commit();
            }
            $mensagem = "Gol adicionado com sucesso!";
            $tipo_mensagem = "success";
            
        } elseif ($acao_post === 'adicionar_cartao') {
            $partida_id_post = (int)$_POST['partida_id'];
            $atleta_id = (int)$_POST['atleta_id'];
            $minuto = (int)$_POST['minuto'];
            $tipo_cartao = sanitizeInput($_POST['tipo_cartao']);
            $motivo = sanitizeInput($_POST['motivo']);
            
            criarTabelasEventos($conn);
            
            $stmt = $conn->prepare("
                INSERT INTO tb_campeonato_cartoes 
                (partida_id, atleta_id, minuto_cartao, tipo_cartao, motivo_cartao, data_evento) 
                VALUES (:partida_id, :atleta_id, :minuto, :tipo_cartao, :motivo, NOW())
            ");
            $stmt->bindValue(':partida_id', $partida_id_post);
            $stmt->bindValue(':atleta_id', $atleta_id);
            $stmt->bindValue(':minuto', $minuto);
            $stmt->bindValue(':tipo_cartao', $tipo_cartao);
            $stmt->bindValue(':motivo', $motivo);
            $stmt->execute();
            
            if ($conn->inTransaction()) {
                $conn->commit();
            }
            $mensagem = "Cartão adicionado com sucesso!";
            $tipo_mensagem = "success";
            
        } elseif ($acao_post === 'adicionar_substituicao') {
            $partida_id_post = (int)$_POST['partida_id'];
            $atleta_sai = (int)$_POST['atleta_sai'];
            $atleta_entra = (int)$_POST['atleta_entra'];
            $minuto = (int)$_POST['minuto'];
            $motivo = sanitizeInput($_POST['motivo']) ?: 'TATICA';
            
            criarTabelasEventos($conn);
            
            $stmt = $conn->prepare("
                INSERT INTO tb_campeonato_substituicoes 
                (partida_id, atleta_sai_id, atleta_entra_id, minuto_substituicao, motivo_substituicao, data_evento) 
                VALUES (:partida_id, :atleta_sai, :atleta_entra, :minuto, :motivo, NOW())
            ");
            $stmt->bindValue(':partida_id', $partida_id_post);
            $stmt->bindValue(':atleta_sai', $atleta_sai);
            $stmt->bindValue(':atleta_entra', $atleta_entra);
            $stmt->bindValue(':minuto', $minuto);
            $stmt->bindValue(':motivo', $motivo);
            $stmt->execute();
            
            if ($conn->inTransaction()) {
                $conn->commit();
            }
            $mensagem = "Substituição adicionada com sucesso!";
            $tipo_mensagem = "success";
            
        } elseif ($acao_post === 'excluir_gol') {
            $gol_id = (int)$_POST['gol_id'];
            
            $stmt = $conn->prepare("DELETE FROM tb_campeonato_gols WHERE gol_id = :gol_id");
            $stmt->bindValue(':gol_id', $gol_id);
            $stmt->execute();
            
            if ($conn->inTransaction()) {
                $conn->commit();
            }
            $mensagem = "Gol removido com sucesso!";
            $tipo_mensagem = "success";
            
        } elseif ($acao_post === 'excluir_cartao') {
            $cartao_id = (int)$_POST['cartao_id'];
            
            $stmt = $conn->prepare("DELETE FROM tb_campeonato_cartoes WHERE cartao_id = :cartao_id");
            $stmt->bindValue(':cartao_id', $cartao_id);
            $stmt->execute();
            
            if ($conn->inTransaction()) {
                $conn->commit();
            }
            $mensagem = "Cartão removido com sucesso!";
            $tipo_mensagem = "success";
            
        } elseif ($acao_post === 'excluir_substituicao') {
            $substituicao_id = (int)$_POST['substituicao_id'];
            
            $stmt = $conn->prepare("DELETE FROM tb_campeonato_substituicoes WHERE substituicao_id = :substituicao_id");
            $stmt->bindValue(':substituicao_id', $substituicao_id);
            $stmt->execute();
            
            if ($conn->inTransaction()) {
                $conn->commit();
            }
            $mensagem = "Substituição removida com sucesso!";
            $tipo_mensagem = "success";

        } elseif ($acao_post === 'iniciar_partida') {
            $partida_id_post = (int)$_POST['partida_id'];
            
            // Verificar se a coluna data_inicio existe
            $stmt_check = $conn->query("SHOW COLUMNS FROM tb_campeonato_partidas LIKE 'data_inicio'");
            if ($stmt_check->rowCount() > 0) {
                $sql = "UPDATE tb_campeonato_partidas SET status_partida = 'EM_ANDAMENTO', data_inicio = NOW() WHERE partida_id = :partida_id";
            } else {
                $sql = "UPDATE tb_campeonato_partidas SET status_partida = 'EM_ANDAMENTO' WHERE partida_id = :partida_id";
            }
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':partida_id', $partida_id_post);
            $stmt->execute();
            
            if ($conn->inTransaction()) {
                $conn->commit();
            }
            $mensagem = "Partida iniciada com sucesso!";
            $tipo_mensagem = "success";
            
        } elseif ($acao_post === 'finalizar_partida') {
            $partida_id_post = (int)$_POST['partida_id'];
            $gols_equipe1 = (int)$_POST['gols_equipe1'];
            $gols_equipe2 = (int)$_POST['gols_equipe2'];
            $observacoes = sanitizeInput($_POST['observacoes']);
            
            // Verificar se a coluna data_fim existe
            $stmt_check = $conn->query("SHOW COLUMNS FROM tb_campeonato_partidas LIKE 'data_fim'");
            if ($stmt_check->rowCount() > 0) {
                $sql = "
                    UPDATE tb_campeonato_partidas 
                    SET status_partida = 'FINALIZADA',
                        gols_equipe1 = :gols_equipe1,
                        gols_equipe2 = :gols_equipe2,
                        observacoes_partida = CONCAT(COALESCE(observacoes_partida, ''), '\n\n', :observacoes),
                        data_fim = NOW()
                    WHERE partida_id = :partida_id
                ";
            } else {
                $sql = "
                    UPDATE tb_campeonato_partidas 
                    SET status_partida = 'FINALIZADA',
                        gols_equipe1 = :gols_equipe1,
                        gols_equipe2 = :gols_equipe2,
                        observacoes_partida = CONCAT(COALESCE(observacoes_partida, ''), '\n\n', :observacoes)
                    WHERE partida_id = :partida_id
                ";
            }
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':gols_equipe1', $gols_equipe1);
            $stmt->bindValue(':gols_equipe2', $gols_equipe2);
            $stmt->bindValue(':observacoes', $observacoes);
            $stmt->bindValue(':partida_id', $partida_id_post);
            $stmt->execute();
            
            if ($conn->inTransaction()) {
                $conn->commit();
            }
            $mensagem = "Partida finalizada com sucesso!";
            $tipo_mensagem = "success";
        }
        
    } catch (Exception $e) {
        // Só faz rollback se a transação estiver ativa
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $mensagem = $e->getMessage();
        $tipo_mensagem = "error";
        error_log("Erro ao processar partida: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
    }
}

// Buscar partida específica para edição
$partida_atual = null;
if (($acao === 'resultado' || $acao === 'agendar' || $acao === 'visualizar' || $acao === 'sumula') && $partida_id > 0) {
    try {
        $stmt = $conn->prepare("
            SELECT p.*, 
                   e1.equipe_nome as equipe1_nome,
                   e2.equipe_nome as equipe2_nome,
                   c.fase, c.chave_nome
            FROM tb_campeonato_partidas p
            LEFT JOIN tb_campeonato_equipes e1 ON p.equipe1_id = e1.equipe_id
            LEFT JOIN tb_campeonato_equipes e2 ON p.equipe2_id = e2.equipe_id
            LEFT JOIN tb_campeonato_chaves c ON p.chave_id = c.chave_id
            WHERE p.partida_id = :id AND p.campeonato_id = :campeonato_id
        ");
        $stmt->bindValue(':id', $partida_id);
        $stmt->bindValue(':campeonato_id', $campeonato_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $partida_atual = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $mensagem = "Partida não encontrada.";
            $tipo_mensagem = "error";
            $acao = 'listar';
        }
    } catch (PDOException $e) {
        $mensagem = "Erro ao buscar dados da partida.";
        $tipo_mensagem = "error";
        $acao = 'listar';
        error_log("Erro ao buscar partida: " . $e->getMessage());
    }
}
// Se for súmula, buscar eventos da partida
$eventos_partida = ['gols' => [], 'cartoes' => [], 'substituicoes' => []];
$atletas_equipe1 = [];
$atletas_equipe2 = [];

if ($acao === 'sumula' && $partida_id > 0) {
    try {
        // Buscar atletas das equipes
        if ($acao === 'sumula' && $partida_id > 0 && $partida_atual) {
            try {
                // Buscar atletas das equipes
                // Equipe 1
                $stmt = $conn->prepare("
                    SELECT ea.*, a.atleta_nome 
                    FROM tb_campeonato_equipe_atletas ea
                    JOIN tb_atletas a ON ea.atleta_id = a.atleta_id
                    WHERE ea.equipe_id = :equipe_id
                    ORDER BY ea.numero_camisa
                ");
                $stmt->bindValue(':equipe_id', $partida_atual['equipe1_id']);
                $stmt->execute();
                $atletas_equipe1 = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Equipe 2
                $stmt->bindValue(':equipe_id', $partida_atual['equipe2_id']);
                $stmt->execute();
                $atletas_equipe2 = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Criar tabelas se não existirem
                criarTabelasEventos($conn);
                
                // Buscar eventos existentes
                // Gols
                $stmt = $conn->prepare("
                    SELECT g.*, a.atleta_nome 
                    FROM tb_campeonato_gols g
                    JOIN tb_atletas a ON g.atleta_id = a.atleta_id
                    WHERE g.partida_id = :partida_id
                    ORDER BY g.minuto_gol
                ");
                $stmt->bindValue(':partida_id', $partida_id);
                $stmt->execute();
                $eventos_partida['gols'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Cartões
                $stmt = $conn->prepare("
                    SELECT c.*, a.atleta_nome 
                    FROM tb_campeonato_cartoes c
                    JOIN tb_atletas a ON c.atleta_id = a.atleta_id
                    WHERE c.partida_id = :partida_id
                    ORDER BY c.minuto_cartao
                ");
                $stmt->bindValue(':partida_id', $partida_id);
                $stmt->execute();
                $eventos_partida['cartoes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Substituições
                $stmt = $conn->prepare("
                    SELECT s.*, 
                        a1.atleta_nome as atleta_sai_nome,
                        a2.atleta_nome as atleta_entra_nome
                    FROM tb_campeonato_substituicoes s
                    JOIN tb_atletas a1 ON s.atleta_sai_id = a1.atleta_id
                    JOIN tb_atletas a2 ON s.atleta_entra_id = a2.atleta_id
                    WHERE s.partida_id = :partida_id
                    ORDER BY s.minuto_substituicao
                ");
                $stmt->bindValue(':partida_id', $partida_id);
                $stmt->execute();
                $eventos_partida['substituicoes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
            } catch (PDOException $e) {
                error_log("Erro ao buscar eventos da partida: " . $e->getMessage());
            }
        }
        
    } catch (PDOException $e) {
        error_log("Erro ao buscar eventos da partida: " . $e->getMessage());
    }
}

// Buscar lista de partidas
$partidas = [];
$estatisticas = [
    'total_partidas' => 0,
    'agendadas' => 0,
    'finalizadas' => 0,
    'canceladas' => 0,
    'em_andamento' => 0
];

// Filtros
$filtro_status = $_GET['filtro_status'] ?? '';
$filtro_fase = $_GET['filtro_fase'] ?? '';
$filtro_equipe = $_GET['filtro_equipe'] ?? '';

if ($acao === 'listar') {
    try {
        // Construir WHERE clause
        $where_conditions = ['p.campeonato_id = :campeonato_id'];
        $params = [':campeonato_id' => $campeonato_id];
        
        if (!empty($filtro_status)) {
            $where_conditions[] = "p.status_partida = :status";
            $params[':status'] = $filtro_status;
        }
        
        if (!empty($filtro_fase)) {
            $where_conditions[] = "c.fase = :fase";
            $params[':fase'] = $filtro_fase;
        }
        
        if (!empty($filtro_equipe)) {
            $where_conditions[] = "(e1.equipe_nome LIKE :equipe OR e2.equipe_nome LIKE :equipe)";
            $params[':equipe'] = "%{$filtro_equipe}%";
        }
        
        $where_sql = "WHERE " . implode(" AND ", $where_conditions);
        
        // Buscar partidas
        $sql = "
            SELECT p.*, 
                   e1.equipe_nome as equipe1_nome,
                   e2.equipe_nome as equipe2_nome,
                   c.fase, c.chave_nome, c.chave_numero
            FROM tb_campeonato_partidas p
            LEFT JOIN tb_campeonato_equipes e1 ON p.equipe1_id = e1.equipe_id
            LEFT JOIN tb_campeonato_equipes e2 ON p.equipe2_id = e2.equipe_id
            LEFT JOIN tb_campeonato_chaves c ON p.chave_id = c.chave_id
            {$where_sql}
            ORDER BY c.fase ASC, c.chave_numero ASC, p.partida_data ASC, p.partida_id ASC
        ";
        
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        
        $partidas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calcular estatísticas
        $estatisticas['total_partidas'] = count($partidas);
        foreach ($partidas as $partida) {
            $status = strtolower($partida['status_partida']);
            if (isset($estatisticas[$status])) {
                $estatisticas[$status]++;
            }
        }
        
    } catch (PDOException $e) {
        $mensagem = "Erro ao buscar partidas: " . $e->getMessage();
        $tipo_mensagem = "error";
        error_log("Erro ao buscar partidas: " . $e->getMessage());
    }
}

// Mensagens da URL
if (isset($_GET['mensagem'])) {
    $mensagem = $_GET['mensagem'];
    $tipo_mensagem = $_GET['tipo'] ?? 'info';
}

// Funções auxiliares
function formatarData($data) {
    if (!$data) return '';
    $data_obj = new DateTime($data);
    return $data_obj->format('d/m/Y');
}

function formatarDataHora($data) {
    if (!$data) return '';
    $data_obj = new DateTime($data);
    return $data_obj->format('d/m/Y H:i');
}

function getStatusClass($status) {
    $classes = [
        'AGENDADA' => 'status-agendada',
        'EM_ANDAMENTO' => 'status-andamento',
        'FINALIZADA' => 'status-finalizada',
        'CANCELADA' => 'status-cancelada',
        'ADIADA' => 'status-adiada'
    ];
    return $classes[$status] ?? '';
}

function getNomeFase($fase) {
    $nomes = [
        1 => 'Primeira Fase',
        2 => 'Quartas de Final',
        3 => 'Semifinais',
        4 => 'Final'
    ];
    return $nomes[$fase] ?? "Fase $fase";
}

// Buscar equipes para filtro
$equipes_filtro = [];
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT equipe_nome 
        FROM tb_campeonato_equipes 
        WHERE campeonato_id = :campeonato_id 
        ORDER BY equipe_nome
    ");
    $stmt->bindValue(':campeonato_id', $campeonato_id);
    $stmt->execute();
    $equipes_filtro = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Erro ao buscar equipes para filtro: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partidas do Campeonato - <?php echo htmlspecialchars($campeonato['campeonato_nome']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        :root {
            --primary-color: #2c3e50;
            --secondary-color: #4caf50;
            --text-color: #333;
            --light-color: #ecf0f1;
            --sidebar-width: 250px;
            --header-height: 60px;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #17a2b8;
        }

        body {
            display: flex;
            min-height: 100vh;
            background-color: #f5f7fa;
        }

        /* Sidebar styles */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--primary-color);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .sidebar-header h3 {
            color: var(--secondary-color);
            font-weight: 600;
            font-size: 1.2rem;
        }

        .toggle-btn {
            background: none;
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            padding: 5px;
            border-radius: 5px;
            transition: background-color 0.3s;
        }

        .toggle-btn:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .menu {
            list-style: none;
            padding: 0;
        }

        .menu-separator {
            height: 1px;
            background-color: rgba(255, 255, 255, 0.1);
            margin: 10px 20px;
        }

        .menu-category {
            color: #bdc3c7;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 10px 20px 5px;
        }

        .menu-item {
            margin: 2px 0;
        }

        .menu-link {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #ecf0f1;
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }

        .menu-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
        }

        .menu-link.active {
            background-color: var(--secondary-color);
            border-left-color: var(--secondary-color);
        }

        .menu-icon {
            width: 20px;
            margin-right: 15px;
            text-align: center;
        }

        .menu-text {
            flex: 1;
        }

        .arrow {
            transition: transform 0.3s;
        }

        .menu-item.open .arrow {
            transform: rotate(90deg);
        }

        .submenu {
            list-style: none;
            padding: 0;
            background-color: rgba(0, 0, 0, 0.2);
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .menu-item.open .submenu {
            max-height: 500px;
        }

        .submenu-link {
            display: block;
            padding: 10px 20px 10px 55px;
            color: #bdc3c7;
            text-decoration: none;
            transition: all 0.3s;
            font-size: 0.9rem;
        }

        .submenu-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .submenu-link.active {
            background-color: var(--secondary-color);
            color: white;
        }

        /* Main content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .header {
            background-color: white;
            padding: 0 30px;
            height: var(--header-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header h2 {
            color: var(--primary-color);
            font-weight: 600;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: var(--primary-color);
        }

        .user-role {
            font-size: 0.8rem;
            color: #7f8c8d;
        }

        .admin-badge {
            background-color: var(--danger-color);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .department-badge {
            background-color: var(--secondary-color);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Page content */
        .page-content {
            flex: 1;
            padding: 30px;
        }

        .page-title {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-title i {
            color: var(--secondary-color);
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 30px;
            font-size: 0.9rem;
            color: #7f8c8d;
        }

        .breadcrumb a {
            color: var(--secondary-color);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        /* Campeonato Info */
        .campeonato-info {
            background: linear-gradient(135deg, var(--secondary-color), #66bb6a);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .campeonato-info h2 {
            font-size: 1.8rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .campeonato-info p {
            margin-bottom: 8px;
            opacity: 0.9;
        }

        /* Stats */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--secondary-color);
            display: block;
            margin-bottom: 8px;
        }

        .stat-label {
            color: #6c757d;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .alert-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }

        .alert-info {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .card-header {
            background: linear-gradient(135deg, var(--secondary-color), #66bb6a);
            color: white;
            padding: 20px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 25px;
        }

        /* Filters */
        .filters-container {
            background: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .filters-grid {
            display: grid;
           grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
           gap: 15px;
           align-items: end;
       }

       .form-group {
           margin-bottom: 0;
       }

       .form-group label {
           display: block;
           margin-bottom: 8px;
           font-weight: 600;
           color: var(--primary-color);
       }

       .form-control {
           width: 100%;
           padding: 12px 15px;
           border: 2px solid #e9ecef;
           border-radius: 8px;
           font-size: 1rem;
           transition: border-color 0.3s;
       }

       .form-control:focus {
           outline: none;
           border-color: var(--secondary-color);
           box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
       }

       /* Buttons */
       .btn {
           padding: 12px 25px;
           border: none;
           border-radius: 8px;
           text-decoration: none;
           font-weight: 600;
           transition: all 0.3s;
           display: inline-flex;
           align-items: center;
           gap: 8px;
           cursor: pointer;
           font-size: 0.95rem;
       }

       .btn-primary {
           background-color: var(--secondary-color);
           color: white;
       }

       .btn-primary:hover {
           background-color: #45a049;
           transform: translateY(-2px);
       }

       .btn-success {
           background-color: var(--success-color);
           color: white;
       }

       .btn-success:hover {
           background-color: #219a52;
           transform: translateY(-2px);
       }

       .btn-danger {
           background-color: var(--danger-color);
           color: white;
       }

       .btn-danger:hover {
           background-color: #c0392b;
           transform: translateY(-2px);
       }

       .btn-warning {
           background-color: var(--warning-color);
           color: white;
       }

       .btn-warning:hover {
           background-color: #d68910;
           transform: translateY(-2px);
       }

       .btn-secondary {
           background-color: #6c757d;
           color: white;
       }

       .btn-secondary:hover {
           background-color: #545b62;
           transform: translateY(-2px);
       }

       .btn-info {
           background-color: var(--info-color);
           color: white;
       }

       .btn-info:hover {
           background-color: #138496;
           transform: translateY(-2px);
       }

       .btn-sm {
           padding: 8px 15px;
           font-size: 0.85rem;
       }

       /* Table */
       .table-container {
           background: white;
           border-radius: 15px;
           overflow: hidden;
           box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
       }

       .table {
           width: 100%;
           border-collapse: collapse;
           font-size: 0.9rem;
       }

       .table th,
       .table td {
           padding: 15px;
           text-align: left;
           border-bottom: 1px solid #e9ecef;
       }

       .table th {
           background-color: #f8f9fa;
           font-weight: 600;
           color: var(--primary-color);
           position: sticky;
           top: 0;
           z-index: 10;
       }

       .table tbody tr:hover {
           background-color: #f8f9fa;
       }

       .table-actions {
           display: flex;
           gap: 5px;
           justify-content: center;
       }

       /* Status badges */
       .status-badge {
           padding: 4px 8px;
           border-radius: 12px;
           font-size: 0.75rem;
           font-weight: 600;
           text-transform: uppercase;
       }

       .status-agendada {
           background-color: #fff3cd;
           color: #856404;
       }

       .status-andamento {
           background-color: #d4edda;
           color: #155724;
       }

       .status-finalizada {
           background-color: #d1ecf1;
           color: #0c5460;
       }

       .status-cancelada {
           background-color: #f8d7da;
           color: #721c24;
       }

       .status-adiada {
           background-color: #e2e3e5;
           color: #6c757d;
       }

       /* Partida card */
       .partida-card {
           background: white;
           border-radius: 12px;
           padding: 20px;
           margin-bottom: 15px;
           box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
           transition: all 0.3s;
       }

       .partida-card:hover {
           transform: translateY(-2px);
           box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
       }

       .partida-header {
           display: flex;
           justify-content: space-between;
           align-items: center;
           margin-bottom: 15px;
           padding-bottom: 15px;
           border-bottom: 1px solid #e9ecef;
       }

       .partida-info {
           display: flex;
           align-items: center;
           gap: 15px;
       }

       .fase-badge {
           background: var(--info-color);
           color: white;
           padding: 4px 8px;
           border-radius: 8px;
           font-size: 0.8rem;
           font-weight: 600;
       }

       .partida-body {
           display: flex;
           justify-content: space-between;
           align-items: center;
       }

       .equipes-confronto {
           display: flex;
           align-items: center;
           gap: 20px;
           flex: 1;
       }

       .equipe {
           text-align: center;
           flex: 1;
       }

       .equipe-nome {
           font-weight: 600;
           font-size: 1.1rem;
           color: var(--primary-color);
           margin-bottom: 5px;
       }

       .placar {
           font-size: 2rem;
           font-weight: bold;
           color: var(--secondary-color);
       }

       .vs {
           font-size: 1.5rem;
           font-weight: bold;
           color: #6c757d;
       }

       .partida-details {
           text-align: right;
           min-width: 200px;
       }

       .data-partida {
           font-weight: 600;
           color: var(--primary-color);
           margin-bottom: 5px;
       }

       .local-partida {
           color: #6c757d;
           font-size: 0.9rem;
           margin-bottom: 10px;
       }

       /* Forms */
       .form-grid {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
           gap: 20px;
           margin-bottom: 20px;
       }

       .form-group label.required::after {
           content: ' *';
           color: var(--danger-color);
       }

       textarea.form-control {
           resize: vertical;
           min-height: 100px;
       }

       /* Action buttons */
       .action-buttons {
           display: flex;
           gap: 10px;
           justify-content: flex-end;
           margin-top: 20px;
           flex-wrap: wrap;
       }

       /* Modal styles */
       .modal {
           display: none;
           position: fixed;
           z-index: 1000;
           left: 0;
           top: 0;
           width: 100%;
           height: 100%;
           background-color: rgba(0, 0, 0, 0.5);
       }

       .modal-content {
           background-color: white;
           margin: 2% auto;
           padding: 0;
           border-radius: 15px;
           width: 90%;
           max-width: 800px;
           box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
           max-height: 90vh;
           overflow-y: auto;
       }

       .modal-header {
           background: linear-gradient(135deg, var(--secondary-color), #66bb6a);
           color: white;
           padding: 20px;
           border-radius: 15px 15px 0 0;
       }

       .modal-title {
           margin: 0;
           display: flex;
           align-items: center;
           gap: 10px;
       }

       .modal-body {
           padding: 20px;
       }

       .modal-footer {
           padding: 20px;
           border-top: 1px solid #e9ecef;
           display: flex;
           gap: 10px;
           justify-content: flex-end;
       }

       .close {
           color: white;
           float: right;
           font-size: 24px;
           font-weight: bold;
           cursor: pointer;
           line-height: 1;
       }

       .close:hover {
           opacity: 0.7;
       }

       /* Empty state */
       .empty-state {
           text-align: center;
           padding: 60px 20px;
           color: #6c757d;
       }

       .empty-state-icon {
           font-size: 4rem;
           margin-bottom: 20px;
           opacity: 0.3;
       }

       .empty-state h3 {
           font-size: 1.5rem;
           margin-bottom: 15px;
           color: var(--primary-color);
       }

       .empty-state p {
           font-size: 1rem;
           line-height: 1.6;
           margin-bottom: 30px;
       }

       /* Mobile responsive */
       .mobile-toggle {
           display: none;
       }

       @media (max-width: 768px) {
           .sidebar {
               transform: translateX(-100%);
           }
           
           .sidebar.show {
               transform: translateX(0);
           }
           
           .main-content {
               margin-left: 0;
           }
           
           .mobile-toggle {
               display: block;
               background: none;
               border: none;
               font-size: 20px;
               cursor: pointer;
               color: var(--primary-color);
           }

           .header {
               padding: 0 20px;
           }

           .page-content {
               padding: 20px;
           }

           .stats-container {
               grid-template-columns: repeat(2, 1fr);
           }

           .filters-grid {
               grid-template-columns: 1fr;
           }

           .form-grid {
               grid-template-columns: 1fr;
           }

           .partida-body {
               flex-direction: column;
               gap: 15px;
           }

           .partida-details {
               text-align: center;
               min-width: auto;
           }

           .equipes-confronto {
               justify-content: center;
           }

           .action-buttons {
               justify-content: center;
           }

           .modal-content {
               width: 95%;
               margin: 5% auto;
           }
       }

       @media (max-width: 480px) {
           .page-title {
               font-size: 1.5rem;
           }

           .campeonato-info {
               padding: 20px;
           }

           .stats-container {
               grid-template-columns: 1fr;
           }

           .equipes-confronto {
               flex-direction: column;
               gap: 10px;
           }

           .vs {
               transform: rotate(90deg);
           }
       }

       /* Tabs de Eventos */
        .tabs-container {
            margin-top: 30px;
        }

        .tabs-nav {
            display: flex;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 5px;
        }

        .tab-btn {
            padding: 12px 20px;
            border: none;
            background: transparent;
            color: #6c757d;
            font-weight: 500;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab-btn:hover {
            color: var(--secondary-color);
            background-color: #f8f9fa;
        }

        .tab-btn.active {
            color: var(--secondary-color);
            border-bottom-color: var(--secondary-color);
            background-color: #f8f9fa;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Eventos */
        .eventos-lista {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            max-height: 400px;
            overflow-y: auto;
        }

        .evento-item {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }

        .evento-item:hover {
            transform: translateX(5px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .evento-item:last-child {
            margin-bottom: 0;
        }

        .evento-info {
            flex: 1;
        }

        .evento-principal {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .evento-tempo {
            background: var(--secondary-color);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
            margin-right: 10px;
        }

        .evento-tipo {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 10px;
        }

        .evento-tipo.amarelo {
            background-color: #fff3cd;
            color: #856404;
        }

        .evento-tipo.vermelho {
            background-color: #f8d7da;
            color: #721c24;
        }

        .evento-motivo {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 5px;
            font-style: italic;
        }

        /* Formulário de Eventos */
        .form-add-evento {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            min-width: 280px;
            max-width: 320px;
        }

        .form-add-evento h5 {
            color: var(--primary-color);
            margin-bottom: 20px;
            text-align: center;
            font-weight: 600;
        }

        .form-evento .form-group {
            margin-bottom: 15px;
        }

        .form-evento .form-group label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
            display: block;
        }

        .form-evento .form-control {
            font-size: 0.9rem;
            padding: 8px 12px;
        }

        .form-evento .btn {
            width: 100%;
            margin-top: 10px;
        }

        /* Escalação */
        .escalacao-lista {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            max-height: 500px;
            overflow-y: auto;
        }

        .atleta-escalacao {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s;
        }

        .atleta-escalacao:hover {
            transform: translateX(3px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .atleta-escalacao:last-child {
            margin-bottom: 0;
        }

        .numero-camisa {
            background: var(--secondary-color);
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .atleta-nome {
            font-weight: 500;
            color: var(--primary-color);
            flex: 1;
        }

        /* Estados especiais */
        .partida-iniciada {
            border-left: 4px solid var(--success-color);
        }

        .partida-finalizada {
            border-left: 4px solid var(--info-color);
        }

        /* Responsive para súmula */
        @media (max-width: 768px) {
            .tabs-nav {
                justify-content: center;
            }
            
            .tab-btn {
                flex: 1;
                min-width: 0;
                font-size: 0.8rem;
                padding: 10px 8px;
                text-align: center;
            }
            
            .tab-btn i {
                display: none;
            }
            
            .form-add-evento {
                min-width: auto;
                max-width: none;
                margin-top: 20px;
            }
            
            .evento-item {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            .evento-info {
                text-align: center;
            }
            
            .atleta-escalacao {
                justify-content: center;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .tabs-nav {
                flex-direction: column;
            }
            
            .tab-btn {
                border-bottom: none;
                border-left: 3px solid transparent;
                justify-content: center;
            }
            
            .tab-btn.active {
                border-bottom: none;
                border-left-color: var(--secondary-color);
            }
        }

        /* Animações */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .evento-item {
            animation: slideIn 0.3s ease-out;
        }

        /* Cores para tipos de eventos */
        .evento-tipo {
            background-color: #e9ecef;
            color: #495057;
        }

        .evento-tipo[data-tipo="PENALTI"] {
            background-color: #fff3cd;
            color: #856404;
        }

        .evento-tipo[data-tipo="FALTA"] {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        .evento-tipo[data-tipo="CONTRA"] {
            background-color: #f8d7da;
            color: #721c24;
        }

        /* Melhorias visuais */
        .form-add-evento {
            position: sticky;
            top: 20px;
        }

        .eventos-lista::-webkit-scrollbar {
            width: 6px;
        }

        .eventos-lista::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .eventos-lista::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        .eventos-lista::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* CSS para impressão */
        @media print {
            /* Ocultar elementos que não devem ser impressos */
            .sidebar,
            .header,
            .breadcrumb,
            .action-buttons,
            .form-add-evento,
            .btn,
            .tabs-nav,
            .mobile-toggle {
                display: none !important;
            }
            
            /* Ajustar layout para impressão */
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .page-content {
                padding: 20px !important;
            }
            
            /* Mostrar todas as tabs na impressão */
            .tab-content {
                display: block !important;
                page-break-inside: avoid;
                margin-bottom: 30px;
            }
            
            /* Estilo da página impressa */
            body {
                font-size: 12pt;
                line-height: 1.4;
                color: #000;
                background: white;
            }
            
            .card {
                box-shadow: none;
                border: 1px solid #000;
                margin-bottom: 20px;
                page-break-inside: avoid;
            }
            
            .card-header {
                background: #f0f0f0 !important;
                color: #000 !important;
                border-bottom: 2px solid #000;
            }
            
            /* Cabeçalho da súmula */
            .sumula-header {
                text-align: center;
                border-bottom: 2px solid #000;
                padding-bottom: 20px;
                margin-bottom: 30px;
            }
            
            .sumula-header h1 {
                font-size: 18pt;
                font-weight: bold;
                margin-bottom: 10px;
            }
            
            .sumula-header h2 {
                font-size: 16pt;
                margin-bottom: 5px;
            }
            
            /* Informações da partida */
            .info-partida-print {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-bottom: 30px;
                font-size: 11pt;
            }
            
            /* Placar destaque */
            .placar-print {
                text-align: center;
                font-size: 24pt;
                font-weight: bold;
                margin: 20px 0;
                padding: 15px;
                border: 3px solid #000;
            }
            
            /* Eventos da partida */
            .eventos-print {
                margin-bottom: 25px;
            }
            
            .eventos-print h3 {
                font-size: 14pt;
                font-weight: bold;
                border-bottom: 1px solid #000;
                padding-bottom: 5px;
                margin-bottom: 15px;
            }
            
            .evento-item {
                border: 1px solid #ccc;
                margin-bottom: 5px;
                padding: 8px;
                font-size: 10pt;
            }
            
            /* Escalação */
            .escalacao-print {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 30px;
                margin-top: 30px;
            }
            
            .escalacao-print h3 {
                font-size: 14pt;
                font-weight: bold;
                text-align: center;
                border-bottom: 2px solid #000;
                padding-bottom: 5px;
                margin-bottom: 15px;
            }
            
            .atleta-print {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 5px;
                border-bottom: 1px solid #ccc;
                font-size: 10pt;
            }
            
            .numero-print {
                width: 25px;
                height: 25px;
                border: 2px solid #000;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: bold;
                font-size: 9pt;
            }
            
            /* Assinaturas */
            .assinaturas-print {
                margin-top: 50px;
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                gap: 40px;
                text-align: center;
            }
            
            .assinatura-item {
                border-top: 1px solid #000;
                padding-top: 5px;
                font-size: 10pt;
            }
            
            /* Quebras de página */
            .page-break {
                page-break-before: always;
            }
            
            /* Ocultar botões de eventos */
            .btn {
                display: none !important;
            }
        }

        /* CSS específico para a versão de impressão */
        .print-only {
            display: none;
        }

        @media print {
            .print-only {
                display: block !important;
            }
                
            .no-print {
                display: none !important;
            }
        }
   </style>
</head>
<body>
   <!-- Sidebar -->
   <div class="sidebar" id="sidebar">
       <div class="sidebar-header">
           <h3><?php echo $themeColors['title'] ?? 'Sistema Esporte'; ?></h3>
           <button class="toggle-btn">
               <i class="fas fa-bars"></i>
           </button>
       </div>
       
       <?php echo $menuManager->generateSidebar('campeonato_partidas.php'); ?>
   </div>

   <!-- Main Content -->
   <div class="main-content" id="mainContent">
       <div class="header">
           <div>
               <button class="mobile-toggle">
                   <i class="fas fa-bars"></i>
               </button>
               <h2>Partidas do Campeonato</h2>
           </div>
           <div class="user-info">
               <div style="width: 35px; height: 35px; border-radius: 50%; background-color: var(--secondary-color); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                   <?php echo strtoupper(substr($usuario_dados['usuario_nome'], 0, 1)); ?>
               </div>
               <div class="user-details">
                   <div class="user-name"><?php echo htmlspecialchars($usuario_dados['usuario_nome']); ?></div>
                   <div class="user-role">
                       <?php if ($is_admin): ?>
                       <span class="admin-badge">
                           <i class="fas fa-crown"></i> Administrador
                       </span>
                       <?php else: ?>
                       <span class="department-badge">
                           <i class="fas fa-running"></i> Esporte
                       </span>
                       <?php endif; ?>
                   </div>
               </div>
           </div>
       </div>

       <div class="page-content">
           <h1 class="page-title">
               <i class="fas fa-calendar-alt"></i>
               <?php 
               switch($acao) {
                   case 'resultado':
                       echo 'Registrar Resultado';
                       break;
                   case 'agendar':
                       echo 'Agendar Partida';
                       break;
                   case 'visualizar':
                       echo 'Detalhes da Partida';
                       break;
                   default:
                       echo 'Partidas do Campeonato';
               }
               ?>
           </h1>

           <!-- Breadcrumb -->
           <div class="breadcrumb">
               <a href="dashboard.php">Dashboard</a>
               <i class="fas fa-chevron-right"></i>
               <a href="esporte.php">Esporte</a>
               <i class="fas fa-chevron-right"></i>
               <a href="esporte_campeonatos.php">Campeonatos</a>
               <i class="fas fa-chevron-right"></i>
               <span>Partidas</span>
           </div>

           <!-- Informações do campeonato -->
           <div class="campeonato-info">
               <h2>
                   <i class="fas fa-trophy"></i>
                   <?php echo htmlspecialchars($campeonato['campeonato_nome']); ?>
               </h2>
               <p><strong>Modalidade:</strong> <?php echo htmlspecialchars($campeonato['campeonato_modalidade']); ?></p>
               <p><strong>Categoria:</strong> <?php echo htmlspecialchars($campeonato['campeonato_categoria']); ?></p>
               <p><strong>Status:</strong> <?php echo htmlspecialchars($campeonato['campeonato_status']); ?></p>
           </div>

           <!-- Mensagens -->
           <?php if ($mensagem): ?>
           <div class="alert alert-<?php echo $tipo_mensagem == 'success' ? 'success' : ($tipo_mensagem == 'warning' ? 'warning' : 'error'); ?>">
               <i class="fas fa-<?php echo $tipo_mensagem == 'success' ? 'check-circle' : ($tipo_mensagem == 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
               <?php echo $mensagem; ?>
           </div>
           <?php endif; ?>

           <?php if ($acao === 'listar'): ?>
           
           <!-- Estatísticas -->
           <div class="stats-container">
               <div class="stat-card">
                   <span class="stat-number"><?php echo $estatisticas['total_partidas']; ?></span>
                   <span class="stat-label">Total de Partidas</span>
               </div>
               <div class="stat-card">
                   <span class="stat-number"><?php echo $estatisticas['agendadas']; ?></span>
                   <span class="stat-label">Agendadas</span>
               </div>
               <div class="stat-card">
                   <span class="stat-number"><?php echo $estatisticas['finalizadas']; ?></span>
                   <span class="stat-label">Finalizadas</span>
               </div>
               <div class="stat-card">
                   <span class="stat-number"><?php echo $estatisticas['em_andamento']; ?></span>
                   <span class="stat-label">Em Andamento</span>
               </div>
               <div class="stat-card">
                   <span class="stat-number"><?php echo $estatisticas['canceladas']; ?></span>
                   <span class="stat-label">Canceladas</span>
               </div>
           </div>

           <!-- Filtros -->
           <div class="filters-container">
               <form method="GET" action="">
                   <input type="hidden" name="campeonato_id" value="<?php echo $campeonato_id; ?>">
                   <input type="hidden" name="acao" value="listar">
                   <div class="filters-grid">
                       <div class="form-group">
                           <label for="filtro_status">Status</label>
                           <select class="form-control" id="filtro_status" name="filtro_status">
                               <option value="">Todos os status</option>
                               <option value="AGENDADA" <?php echo $filtro_status === 'AGENDADA' ? 'selected' : ''; ?>>Agendada</option>
                               <option value="EM_ANDAMENTO" <?php echo $filtro_status === 'EM_ANDAMENTO' ? 'selected' : ''; ?>>Em Andamento</option>
                               <option value="FINALIZADA" <?php echo $filtro_status === 'FINALIZADA' ? 'selected' : ''; ?>>Finalizada</option>
                               <option value="CANCELADA" <?php echo $filtro_status === 'CANCELADA' ? 'selected' : ''; ?>>Cancelada</option>
                               <option value="ADIADA" <?php echo $filtro_status === 'ADIADA' ? 'selected' : ''; ?>>Adiada</option>
                           </select>
                       </div>
                       <div class="form-group">
                           <label for="filtro_fase">Fase</label>
                           <select class="form-control" id="filtro_fase" name="filtro_fase">
                               <option value="">Todas as fases</option>
                               <option value="1" <?php echo $filtro_fase === '1' ? 'selected' : ''; ?>>Primeira Fase</option>
                               <option value="2" <?php echo $filtro_fase === '2' ? 'selected' : ''; ?>>Quartas de Final</option>
                               <option value="3" <?php echo $filtro_fase === '3' ? 'selected' : ''; ?>>Semifinais</option>
                               <option value="4" <?php echo $filtro_fase === '4' ? 'selected' : ''; ?>>Final</option>
                           </select>
                       </div>
                       <div class="form-group">
                           <label for="filtro_equipe">Equipe</label>
                           <select class="form-control" id="filtro_equipe" name="filtro_equipe">
                               <option value="">Todas as equipes</option>
                               <?php foreach ($equipes_filtro as $equipe): ?>
                               <option value="<?php echo htmlspecialchars($equipe); ?>" <?php echo $filtro_equipe === $equipe ? 'selected' : ''; ?>>
                                   <?php echo htmlspecialchars($equipe); ?>
                               </option>
                               <?php endforeach; ?>
                           </select>
                       </div>
                       <div class="form-group">
                           <button type="submit" class="btn btn-primary">
                               <i class="fas fa-search"></i> Filtrar
                           </button>
                           <a href="?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-secondary">
                               <i class="fas fa-times"></i> Limpar
                           </a>
                       </div>
                   </div>
               </form>
           </div>

           <!-- Lista de Partidas -->
           <?php if (empty($partidas)): ?>
           <div class="empty-state">
               <div class="empty-state-icon">
                   <i class="fas fa-calendar-times"></i>
               </div>
               <h3>Nenhuma partida encontrada</h3>
               <p>
                   Não há partidas cadastradas para este campeonato com os filtros selecionados.<br>
                   As partidas são geradas automaticamente quando as chaves são criadas.
               </p>
               
               <a href="campeonato_chaves.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-primary">
                   <i class="fas fa-sitemap"></i> Gerenciar Chaves
               </a>
           </div>
           <?php else: ?>
           <div class="card">
               <div class="card-header">
                   <div class="card-title">
                       <i class="fas fa-list"></i>
                       Lista de Partidas (<?php echo count($partidas); ?> partidas)
                   </div>
               </div>
               <div class="card-body" style="padding: 0;">
                   <?php 
                    // Organizar partidas por fase e chave
                    $partidas_organizadas = [];
                    foreach ($partidas as $partida) {
                        $fase = $partida['fase'];
                        $chave_numero = $partida['chave_numero'] ?? 1;
                        $chave_nome = $partida['chave_nome'] ?? "Chave $chave_numero";
                        
                        if (!isset($partidas_organizadas[$fase])) {
                            $partidas_organizadas[$fase] = [];
                        }
                        if (!isset($partidas_organizadas[$fase][$chave_numero])) {
                            $partidas_organizadas[$fase][$chave_numero] = [
                                'nome' => $chave_nome,
                                'partidas' => []
                            ];
                        }
                        
                        $partidas_organizadas[$fase][$chave_numero]['partidas'][] = $partida;
                    }

                    foreach ($partidas_organizadas as $fase => $chaves_da_fase): 
                    ?>
                    <!-- Cabeçalho da Fase -->
                    <div style="background: #f8f9fa; padding: 15px 25px; border-bottom: 1px solid #e9ecef; font-weight: 600; color: var(--primary-color); margin-top: 20px;">
                        <i class="fas fa-layer-group"></i>
                        <?php echo getNomeFase($fase); ?>
                    </div>

                    <?php foreach ($chaves_da_fase as $chave_numero => $dados_chave): ?>
                    <!-- Cabeçalho da Chave -->
                    <div style="background: #e3f2fd; padding: 12px 25px; border-bottom: 1px solid #bbdefb; font-weight: 500; color: var(--info-color); font-size: 0.95rem;">
                        <i class="fas fa-sitemap"></i>
                        <?php echo htmlspecialchars($dados_chave['nome']); ?>
                        <span style="color: #6c757d; font-weight: normal;">(<?php echo count($dados_chave['partidas']); ?> partidas)</span>
                    </div>

                    <?php foreach ($dados_chave['partidas'] as $partida): ?>
                    <div class="partida-card" style="margin: 0; border-radius: 0; box-shadow: none; border-bottom: 1px solid #e9ecef;">
                        <div class="partida-body">
                            <div class="equipes-confronto">
                                <div class="equipe">
                                    <div class="equipe-nome"><?php echo htmlspecialchars($partida['equipe1_nome']); ?></div>
                                    <?php if ($partida['status_partida'] === 'FINALIZADA'): ?>
                                    <div class="placar"><?php echo $partida['gols_equipe1'] ?? '0'; ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="vs">VS</div>
                                
                                <div class="equipe">
                                    <div class="equipe-nome"><?php echo htmlspecialchars($partida['equipe2_nome']); ?></div>
                                    <?php if ($partida['status_partida'] === 'FINALIZADA'): ?>
                                    <div class="placar"><?php echo $partida['gols_equipe2'] ?? '0'; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="partida-details">
                                <div class="data-partida">
                                    <?php if (!empty($partida['partida_data'])): ?>
                                    <?php echo formatarDataHora($partida['partida_data']); ?>
                                    <?php else: ?>
                                    <span style="color: #dc3545;">Data não definida</span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!empty($partida['local_partida'])): ?>
                                <div class="local-partida">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($partida['local_partida']); ?>
                                </div>
                                <?php endif; ?>
                                
                                <div style="margin-bottom: 15px;">
                                    <span class="status-badge <?php echo getStatusClass($partida['status_partida']); ?>">
                                        <?php echo $partida['status_partida']; ?>
                                    </span>
                                </div>
                                
                                <div class="table-actions">
                                    <?php if ($partida['status_partida'] === 'AGENDADA'): ?>
                                    <a href="?campeonato_id=<?php echo $campeonato_id; ?>&acao=agendar&id=<?php echo $partida['partida_id']; ?>" 
                                    class="btn btn-sm btn-info" title="Agendar">
                                        <i class="fas fa-calendar-plus"></i>
                                    </a>
                                    <a href="?campeonato_id=<?php echo $campeonato_id; ?>&acao=resultado&id=<?php echo $partida['partida_id']; ?>" 
                                    class="btn btn-sm btn-success" title="Registrar Resultado">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <!-- Botão Súmula -->
                                    <?php if (in_array($partida['status_partida'], ['AGENDADA', 'EM_ANDAMENTO', 'FINALIZADA'])): ?>
                                    <a href="?campeonato_id=<?php echo $campeonato_id; ?>&acao=sumula&partida_id=<?php echo $partida['partida_id']; ?>" 
                                    class="btn btn-sm btn-warning" title="Gerenciar Súmula">
                                        <i class="fas fa-clipboard-list"></i>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($partida['status_partida'] === 'FINALIZADA'): ?>
                                    <a href="?campeonato_id=<?php echo $campeonato_id; ?>&acao=visualizar&id=<?php echo $partida['partida_id']; ?>" 
                                    class="btn btn-sm btn-primary" title="Ver Detalhes">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($partida['status_partida'], ['AGENDADA', 'EM_ANDAMENTO'])): ?>
                                    <button type="button" class="btn btn-sm btn-danger" 
                                            onclick="cancelarPartida(<?php echo $partida['partida_id']; ?>)"
                                            title="Cancelar">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php endforeach; ?>
                    <?php endforeach; ?>
               </div>
           </div>
           <?php endif; ?>

           <?php elseif ($acao === 'resultado' && $partida_atual): ?>
           <!-- Formulário para registrar resultado -->
           <div class="card">
               <div class="card-header">
                   <div class="card-title">
                       <i class="fas fa-edit"></i>
                       Registrar Resultado da Partida
                   </div>
               </div>
               <div class="card-body">
                   <!-- Info da partida -->
                   <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px;">
                       <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                           <h3 style="color: var(--primary-color); margin: 0;">
                               <?php echo htmlspecialchars($partida_atual['equipe1_nome']); ?>
                               <span style="color: #6c757d; font-weight: normal;"> vs </span>
                               <?php echo htmlspecialchars($partida_atual['equipe2_nome']); ?>
                           </h3>
                           <span class="fase-badge"><?php echo getNomeFase($partida_atual['fase']); ?></span>
                       </div>
                       
                       <?php if (!empty($partida_atual['chave_nome'])): ?>
                       <p><strong>Chave:</strong> <?php echo htmlspecialchars($partida_atual['chave_nome']); ?></p>
                       <?php endif; ?>
                       
                       <?php if (!empty($partida_atual['partida_data'])): ?>
                       <p><strong>Data:</strong> <?php echo formatarDataHora($partida_atual['partida_data']); ?></p>
                       <?php endif; ?>
                       
                       <?php if (!empty($partida_atual['local_partida'])): ?>
                       <p><strong>Local:</strong> <?php echo htmlspecialchars($partida_atual['local_partida']); ?></p>
                       <?php endif; ?>
                   </div>
                   
                   <form method="POST" action="">
                       <input type="hidden" name="acao" value="atualizar_resultado">
                       
                       <div class="form-grid">
                           <div class="form-group">
                               <label for="gols_equipe1" class="required">Gols - <?php echo htmlspecialchars($partida_atual['equipe1_nome']); ?></label>
                               <input type="number" class="form-control" id="gols_equipe1" name="gols_equipe1" 
                                      value="<?php echo $partida_atual['gols_equipe1'] ?? '0'; ?>" min="0" required>
                           </div>

                           <div class="form-group">
                               <label for="gols_equipe2" class="required">Gols - <?php echo htmlspecialchars($partida_atual['equipe2_nome']); ?></label>
                               <input type="number" class="form-control" id="gols_equipe2" name="gols_equipe2" 
                                      value="<?php echo $partida_atual['gols_equipe2'] ?? '0'; ?>" min="0" required>
                           </div>
                       </div>

                       <?php if (!$config_campeonato['permite_empate']): ?>
                       <!-- Campos de pênaltis se não permite empate -->
                       <div id="penaltis-section" style="display: none;">
                           <h4 style="color: var(--primary-color); margin: 20px 0 15px;">Disputa de Pênaltis</h4>
                           <div class="form-grid">
                               <div class="form-group">
                                   <label for="penaltis_equipe1">Pênaltis - <?php echo htmlspecialchars($partida_atual['equipe1_nome']); ?></label>
                                   <input type="number" class="form-control" id="penaltis_equipe1" name="penaltis_equipe1" 
                                          value="<?php echo $partida_atual['penaltis_equipe1'] ?? ''; ?>" min="0">
                               </div>

                               <div class="form-group">
                                   <label for="penaltis_equipe2">Pênaltis - <?php echo htmlspecialchars($partida_atual['equipe2_nome']); ?></label>
                                   <input type="number" class="form-control" id="penaltis_equipe2" name="penaltis_equipe2" 
                                          value="<?php echo $partida_atual['penaltis_equipe2'] ?? ''; ?>" min="0">
                               </div>
                           </div>
                       </div>
                       <?php endif; ?>

                       <div class="form-grid">
                           <div class="form-group">
                               <label for="arbitro_principal">Árbitro Principal</label>
                               <input type="text" class="form-control" id="arbitro_principal" name="arbitro_principal" 
                                      value="<?php echo htmlspecialchars($partida_atual['arbitro_principal'] ?? ''); ?>" 
                                      placeholder="Nome do árbitro principal">
                           </div>

                           <div class="form-group">
                               <label for="publico_presente">Público Presente</label>
                               <input type="number" class="form-control" id="publico_presente" name="publico_presente" 
                                      value="<?php echo $partida_atual['publico_presente'] ?? ''; ?>" min="0" 
                                      placeholder="Número de pessoas">
                           </div>

                           <div class="form-group">
                               <label for="tempo_jogo">Tempo de Jogo (minutos)</label>
                               <input type="number" class="form-control" id="tempo_jogo" name="tempo_jogo" 
                                      value="<?php echo $partida_atual['tempo_jogo'] ?? $config_campeonato['tempo_partida']; ?>" 
                                      min="1" placeholder="90">
                           </div>
                       </div>

                       <div class="form-group">
                           <label for="observacoes_partida">Observações da Partida</label>
                           <textarea class="form-control" id="observacoes_partida" name="observacoes_partida" rows="4" 
                                     placeholder="Observações sobre a partida, eventos importantes, etc."><?php echo htmlspecialchars($partida_atual['observacoes_partida'] ?? ''); ?></textarea>
                       </div>

                       <div class="action-buttons">
                           <a href="?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-secondary">
                               <i class="fas fa-times"></i> Cancelar
                           </a>
                           <button type="submit" class="btn btn-success">
                               <i class="fas fa-save"></i> Registrar Resultado
                           </button>
                       </div>
                   </form>
               </div>
           </div>

           <?php elseif ($acao === 'agendar' && $partida_atual): ?>
           <!-- Formulário para agendar partida -->
           <div class="card">
               <div class="card-header">
                   <div class="card-title">
                       <i class="fas fa-calendar-plus"></i>
                       Agendar Partida
                   </div>
               </div>
               <div class="card-body">
                   <!-- Info da partida -->
                   <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px;">
                       <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                           <h3 style="color: var(--primary-color); margin: 0;">
                               <?php echo htmlspecialchars($partida_atual['equipe1_nome']); ?>
                               <span style="color: #6c757d; font-weight: normal;"> vs </span>
                               <?php echo htmlspecialchars($partida_atual['equipe2_nome']); ?>
                           </h3>
                           <span class="fase-badge"><?php echo getNomeFase($partida_atual['fase']); ?></span>
                       </div>
                       
                       <?php if (!empty($partida_atual['chave_nome'])): ?>
                       <p><strong>Chave:</strong> <?php echo htmlspecialchars($partida_atual['chave_nome']); ?></p>
                       <?php endif; ?>
                       
                       <p><strong>Status:</strong> 
                           <span class="status-badge <?php echo getStatusClass($partida_atual['status_partida']); ?>">
                               <?php echo $partida_atual['status_partida']; ?>
                           </span>
                       </p>
                   </div>
                   
                   <form method="POST" action="">
                       <input type="hidden" name="acao" value="agendar_partida">
                       
                       <div class="form-grid">
                           <div class="form-group">
                               <label for="partida_data" class="required">Data e Hora da Partida</label>
                               <input type="datetime-local" class="form-control" id="partida_data" name="partida_data" 
                                      value="<?php echo $partida_atual['partida_data'] ? date('Y-m-d\TH:i', strtotime($partida_atual['partida_data'])) : ''; ?>" 
                                      required>
                           </div>

                           <div class="form-group">
                               <label for="local_partida">Local da Partida</label>
                               <input type="text" class="form-control" id="local_partida" name="local_partida" 
                                      value="<?php echo htmlspecialchars($partida_atual['local_partida'] ?? ''); ?>" 
                                      placeholder="Ex: Estádio Municipal, Campo do Centro">
                           </div>
                       </div>

                       <h4 style="color: var(--primary-color); margin: 30px 0 15px;">Arbitragem</h4>
                       <div class="form-grid">
                           <div class="form-group">
                               <label for="arbitro_principal">Árbitro Principal</label>
                               <input type="text" class="form-control" id="arbitro_principal" name="arbitro_principal" 
                                      value="<?php echo htmlspecialchars($partida_atual['arbitro_principal'] ?? ''); ?>" 
                                      placeholder="Nome do árbitro principal">
                           </div>

                           <div class="form-group">
                               <label for="arbitro_auxiliar1">1º Árbitro Auxiliar</label>
                               <input type="text" class="form-control" id="arbitro_auxiliar1" name="arbitro_auxiliar1" 
                                      value="<?php echo htmlspecialchars($partida_atual['arbitro_auxiliar1'] ?? ''); ?>" 
                                      placeholder="Nome do primeiro auxiliar">
                           </div>

                           <div class="form-group">
                               <label for="arbitro_auxiliar2">2º Árbitro Auxiliar</label>
                               <input type="text" class="form-control" id="arbitro_auxiliar2" name="arbitro_auxiliar2" 
                                      value="<?php echo htmlspecialchars($partida_atual['arbitro_auxiliar2'] ?? ''); ?>" 
                                      placeholder="Nome do segundo auxiliar">
                           </div>
                       </div>

                       <div class="action-buttons">
                           <a href="?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-secondary">
                               <i class="fas fa-times"></i> Cancelar
                           </a>
                           <button type="submit" class="btn btn-success">
                               <i class="fas fa-save"></i> Agendar Partida
                           </button>
                       </div>
                   </form>
               </div>
           </div>

           <?php elseif ($acao === 'visualizar' && $partida_atual): ?>

           <!-- Visualização detalhada da partida -->
           <div class="card">
               <div class="card-header">
                   <div class="card-title">
                       <i class="fas fa-eye"></i>
                       Detalhes da Partida
                   </div>
               </div>
               <div class="card-body">
                   <!-- Resultado principal -->
                   <div style="text-align: center; background: linear-gradient(135deg, var(--secondary-color), #66bb6a); color: white; padding: 30px; border-radius: 15px; margin-bottom: 30px;">
                       <div style="font-size: 1.2rem; margin-bottom: 15px; opacity: 0.9;">
                           <?php echo getNomeFase($partida_atual['fase']); ?>
                           <?php if (!empty($partida_atual['chave_nome'])): ?>
                           - <?php echo htmlspecialchars($partida_atual['chave_nome']); ?>
                           <?php endif; ?>
                       </div>
                       
                       <div style="display: flex; justify-content: space-between; align-items: center; max-width: 600px; margin: 0 auto;">
                           <div style="text-align: center; flex: 1;">
                               <h2 style="font-size: 1.8rem; margin-bottom: 10px;"><?php echo htmlspecialchars($partida_atual['equipe1_nome']); ?></h2>
                               <div style="font-size: 4rem; font-weight: bold; line-height: 1;">
                                   <?php echo $partida_atual['gols_equipe1'] ?? '0'; ?>
                               </div>
                           </div>
                           
                           <div style="font-size: 2rem; font-weight: bold; margin: 0 30px; opacity: 0.8;">VS</div>
                           
                           <div style="text-align: center; flex: 1;">
                               <h2 style="font-size: 1.8rem; margin-bottom: 10px;"><?php echo htmlspecialchars($partida_atual['equipe2_nome']); ?></h2>
                               <div style="font-size: 4rem; font-weight: bold; line-height: 1;">
                                   <?php echo $partida_atual['gols_equipe2'] ?? '0'; ?>
                               </div>
                           </div>
                       </div>
                       
                       <?php if (!empty($partida_atual['penaltis_equipe1']) || !empty($partida_atual['penaltis_equipe2'])): ?>
                       <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.3);">
                           <div style="font-size: 1rem; margin-bottom: 10px; opacity: 0.9;">Pênaltis</div>
                           <div style="font-size: 1.5rem;">
                               <?php echo $partida_atual['penaltis_equipe1'] ?? '0'; ?> - <?php echo $partida_atual['penaltis_equipe2'] ?? '0'; ?>
                           </div>
                       </div>
                       <?php endif; ?>
                       
                       <div style="margin-top: 20px;">
                           <span class="status-badge" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3);">
                               <?php echo $partida_atual['status_partida']; ?>
                           </span>
                       </div>
                   </div>

                   <!-- Informações da partida -->
                   <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px;">
                       <div>
                           <h4 style="color: var(--primary-color); margin-bottom: 15px; border-bottom: 2px solid var(--secondary-color); padding-bottom: 5px;">
                               <i class="fas fa-info-circle"></i> Informações Gerais
                           </h4>
                           
                           <?php if (!empty($partida_atual['partida_data'])): ?>
                           <p><strong>Data/Hora:</strong> <?php echo formatarDataHora($partida_atual['partida_data']); ?></p>
                           <?php endif; ?>
                           
                           <?php if (!empty($partida_atual['local_partida'])): ?>
                           <p><strong>Local:</strong> <?php echo htmlspecialchars($partida_atual['local_partida']); ?></p>
                           <?php endif; ?>
                           
                           <?php if (!empty($partida_atual['tempo_jogo'])): ?>
                           <p><strong>Tempo de Jogo:</strong> <?php echo $partida_atual['tempo_jogo']; ?> minutos</p>
                           <?php endif; ?>
                           
                           <?php if (!empty($partida_atual['publico_presente'])): ?>
                           <p><strong>Público:</strong> <?php echo number_format($partida_atual['publico_presente']); ?> pessoas</p>
                           <?php endif; ?>
                       </div>
                       
                       <div>
                           <h4 style="color: var(--primary-color); margin-bottom: 15px; border-bottom: 2px solid var(--secondary-color); padding-bottom: 5px;">
                               <i class="fas fa-user-tie"></i> Arbitragem
                           </h4>
                           
                           <?php if (!empty($partida_atual['arbitro_principal'])): ?>
                           <p><strong>Árbitro Principal:</strong> <?php echo htmlspecialchars($partida_atual['arbitro_principal']); ?></p>
                           <?php endif; ?>
                           
                           <?php if (!empty($partida_atual['arbitro_auxiliar1'])): ?>
                           <p><strong>1º Auxiliar:</strong> <?php echo htmlspecialchars($partida_atual['arbitro_auxiliar1']); ?></p>
                           <?php endif; ?>
                           
                           <?php if (!empty($partida_atual['arbitro_auxiliar2'])): ?>
                           <p><strong>2º Auxiliar:</strong> <?php echo htmlspecialchars($partida_atual['arbitro_auxiliar2']); ?></p>
                           <?php endif; ?>
                           
                           <?php if (empty($partida_atual['arbitro_principal']) && empty($partida_atual['arbitro_auxiliar1']) && empty($partida_atual['arbitro_auxiliar2'])): ?>
                           <p style="color: #6c757d; font-style: italic;">Arbitragem não informada</p>
                           <?php endif; ?>
                       </div>
                   </div>

                   <?php if (!empty($partida_atual['observacoes_partida'])): ?>
                   <div style="margin-top: 30px;">
                       <h4 style="color: var(--primary-color); margin-bottom: 15px; border-bottom: 2px solid var(--secondary-color); padding-bottom: 5px;">
                           <i class="fas fa-sticky-note"></i> Observações
                       </h4>
                       <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; line-height: 1.6;">
                           <?php echo nl2br(htmlspecialchars($partida_atual['observacoes_partida'])); ?>
                       </div>
                   </div>
                   <?php endif; ?>

                   <div class="action-buttons">
                       <a href="?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-secondary">
                           <i class="fas fa-arrow-left"></i> Voltar às Partidas
                       </a>
                       
                       <?php if ($partida_atual['status_partida'] === 'FINALIZADA'): ?>
                       <a href="?campeonato_id=<?php echo $campeonato_id; ?>&acao=resultado&id=<?php echo $partida_atual['partida_id']; ?>" class="btn btn-warning">
                           <i class="fas fa-edit"></i> Editar Resultado
                       </a>
                       <?php endif; ?>
                   </div>
               </div>
           </div>

            <?php elseif ($acao === 'sumula' && $partida_atual): ?>

            <!-- Gerenciamento de Súmula -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-clipboard-list"></i>
                        Súmula da Partida
                    </div>
                </div>
                <div class="card-body">
                    <!-- Info da partida -->
                    <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h3 style="color: var(--primary-color); margin: 0;">
                                <?php echo htmlspecialchars($partida_atual['equipe1_nome']); ?>
                                <span style="color: #6c757d; font-weight: normal;"> vs </span>
                                <?php echo htmlspecialchars($partida_atual['equipe2_nome']); ?>
                            </h3>
                            <span class="fase-badge"><?php echo getNomeFase($partida_atual['fase']); ?></span>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <?php if (!empty($partida_atual['partida_data'])): ?>
                            <p><strong>Data:</strong> <?php echo formatarDataHora($partida_atual['partida_data']); ?></p>
                            <?php endif; ?>
                            
                            <?php if (!empty($partida_atual['local_partida'])): ?>
                            <p><strong>Local:</strong> <?php echo htmlspecialchars($partida_atual['local_partida']); ?></p>
                            <?php endif; ?>
                            
                            <p><strong>Status:</strong> 
                                <span class="status-badge <?php echo getStatusClass($partida_atual['status_partida']); ?>">
                                    <?php echo $partida_atual['status_partida']; ?>
                                </span>
                            </p>
                            
                            <p><strong>Placar:</strong> 
                                <span style="font-weight: bold; color: var(--secondary-color);">
                                    <?php echo ($partida_atual['gols_equipe1'] ?? '0') . ' x ' . ($partida_atual['gols_equipe2'] ?? '0'); ?>
                                </span>
                            </p>
                        </div>
                    </div>

                    <!-- Controles da Partida -->
                    <?php if ($partida_atual['status_partida'] === 'AGENDADA'): ?>
                    <div style="background: #e3f2fd; padding: 20px; border-radius: 8px; margin-bottom: 30px; text-align: center;">
                        <h4 style="color: var(--info-color); margin-bottom: 15px;">
                            <i class="fas fa-play-circle"></i> Iniciar Partida
                        </h4>
                        <p style="margin-bottom: 20px;">A partida ainda não foi iniciada. Clique no botão abaixo para começar a registrar os eventos.</p>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="acao" value="iniciar_partida">
                            <input type="hidden" name="partida_id" value="<?php echo $partida_atual['partida_id']; ?>">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-play"></i> Iniciar Partida
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- Tabs de Eventos -->
                    <div class="tabs-container">
                        <div class="tabs-nav">
                            <button class="tab-btn active" onclick="mostrarTab('gols')">
                                <i class="fas fa-futbol"></i> Gols
                            </button>
                            <button class="tab-btn" onclick="mostrarTab('cartoes')">
                                <i class="fas fa-square"></i> Cartões
                            </button>
                            <button class="tab-btn" onclick="mostrarTab('substituicoes')">
                                <i class="fas fa-exchange-alt"></i> Substituições
                            </button>
                            <button class="tab-btn" onclick="mostrarTab('escalacao')">
                                <i class="fas fa-users"></i> Escalação
                            </button>
                        </div>

                        <!-- Tab Gols -->
                        <div id="tab-gols" class="tab-content active">
                            <div style="display: grid; grid-template-columns: 1fr auto; gap: 30px; align-items: start;">
                                <!-- Lista de Gols -->
                                <div>
                                    <h4 style="color: var(--primary-color); margin-bottom: 20px;">
                                        <i class="fas fa-futbol"></i> Gols da Partida
                                    </h4>
                                    
                                    <?php if (empty($eventos_partida['gols'])): ?>
                                    <div style="text-align: center; padding: 40px; color: #6c757d; background: #f8f9fa; border-radius: 8px;">
                                        <i class="fas fa-futbol" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3;"></i>
                                        <p>Nenhum gol registrado ainda</p>
                                    </div>
                                    <?php else: ?>
                                    <div class="eventos-lista">
                                        <?php foreach ($eventos_partida['gols'] as $gol): ?>
                                        <div class="evento-item">
                                            <div class="evento-info">
                                                <div class="evento-principal">
                                                    <strong><?php echo htmlspecialchars($gol['atleta_nome']); ?></strong>
                                                    <span class="evento-tipo">
                                                        <?php 
                                                        $tipos_gol = [
                                                            'NORMAL' => 'Gol',
                                                            'PENALTI' => 'Pênalti',
                                                            'FALTA' => 'Falta',
                                                            'CONTRA' => 'Contra'
                                                        ];
                                                        echo $tipos_gol[$gol['tipo_gol']] ?? 'Gol';
                                                        ?>
                                                    </span>
                                                </div>
                                                <div class="evento-tempo"><?php echo $gol['minuto_gol']; ?>'</div>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="excluirEvento('gol', <?php echo $gol['gol_id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Formulário Adicionar Gol -->
                                <?php if (in_array($partida_atual['status_partida'], ['EM_ANDAMENTO', 'AGENDADA'])): ?>
                                <div class="form-add-evento">
                                    <h5>Adicionar Gol</h5>
                                    <form method="POST" class="form-evento">
                                        <input type="hidden" name="acao" value="adicionar_gol">
                                        <input type="hidden" name="partida_id" value="<?php echo $partida_atual['partida_id']; ?>">
                                        
                                        <div class="form-group">
                                            <label for="atleta_gol">Atleta</label>
                                            <select class="form-control" name="atleta_id" required>
                                                <option value="">Selecione o atleta</option>
                                                <optgroup label="<?php echo htmlspecialchars($partida_atual['equipe1_nome']); ?>">
                                                    <?php foreach ($atletas_equipe1 as $atleta): ?>
                                                    <option value="<?php echo $atleta['atleta_id']; ?>">
                                                        #<?php echo $atleta['numero_camisa']; ?> - <?php echo htmlspecialchars($atleta['atleta_nome']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                                <optgroup label="<?php echo htmlspecialchars($partida_atual['equipe2_nome']); ?>">
                                                    <?php foreach ($atletas_equipe2 as $atleta): ?>
                                                    <option value="<?php echo $atleta['atleta_id']; ?>">
                                                        #<?php echo $atleta['numero_camisa']; ?> - <?php echo htmlspecialchars($atleta['atleta_nome']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="minuto_gol">Minuto</label>
                                            <input type="number" class="form-control" name="minuto" min="1" max="120" required>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="tipo_gol">Tipo do Gol</label>
                                            <select class="form-control" name="tipo_gol">
                                                <option value="NORMAL">Gol Normal</option>
                                                <option value="PENALTI">Pênalti</option>
                                                <option value="FALTA">Falta</option>
                                                <option value="CONTRA">Gol Contra</option>
                                            </select>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="fas fa-plus"></i> Adicionar Gol
                                        </button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Tab Cartões -->
                        <div id="tab-cartoes" class="tab-content">
                            <div style="display: grid; grid-template-columns: 1fr auto; gap: 30px; align-items: start;">
                                <!-- Lista de Cartões -->
                                <div>
                                    <h4 style="color: var(--primary-color); margin-bottom: 20px;">
                                        <i class="fas fa-square"></i> Cartões da Partida
                                    </h4>
                                    
                                    <?php if (empty($eventos_partida['cartoes'])): ?>
                                    <div style="text-align: center; padding: 40px; color: #6c757d; background: #f8f9fa; border-radius: 8px;">
                                        <i class="fas fa-square" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3;"></i>
                                        <p>Nenhum cartão registrado ainda</p>
                                    </div>
                                    <?php else: ?>
                                    <div class="eventos-lista">
                                        <?php foreach ($eventos_partida['cartoes'] as $cartao): ?>
                                        <div class="evento-item">
                                            <div class="evento-info">
                                                <div class="evento-principal">
                                                    <strong><?php echo htmlspecialchars($cartao['atleta_nome']); ?></strong>
                                                    <span class="evento-tipo <?php echo strtolower($cartao['tipo_cartao']); ?>">
                                                        <?php echo $cartao['tipo_cartao']; ?>
                                                    </span>
                                                </div>
                                                <div class="evento-tempo"><?php echo $cartao['minuto_cartao']; ?>'</div>
                                                <?php if (!empty($cartao['motivo_cartao'])): ?>
                                                <div class="evento-motivo"><?php echo htmlspecialchars($cartao['motivo_cartao']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="excluirEvento('cartao', <?php echo $cartao['cartao_id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Formulário Adicionar Cartão -->
                                <?php if (in_array($partida_atual['status_partida'], ['EM_ANDAMENTO', 'AGENDADA'])): ?>
                                <div class="form-add-evento">
                                    <h5>Adicionar Cartão</h5>
                                    <form method="POST" class="form-evento">
                                        <input type="hidden" name="acao" value="adicionar_cartao">
                                        <input type="hidden" name="partida_id" value="<?php echo $partida_atual['partida_id']; ?>">
                                        
                                        <div class="form-group">
                                            <label for="atleta_cartao">Atleta</label>
                                            <select class="form-control" name="atleta_id" required>
                                                <option value="">Selecione o atleta</option>
                                                <optgroup label="<?php echo htmlspecialchars($partida_atual['equipe1_nome']); ?>">
                                                    <?php foreach ($atletas_equipe1 as $atleta): ?>
                                                    <option value="<?php echo $atleta['atleta_id']; ?>">
                                                        #<?php echo $atleta['numero_camisa']; ?> - <?php echo htmlspecialchars($atleta['atleta_nome']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                                <optgroup label="<?php echo htmlspecialchars($partida_atual['equipe2_nome']); ?>">
                                                    <?php foreach ($atletas_equipe2 as $atleta): ?>
                                                    <option value="<?php echo $atleta['atleta_id']; ?>">
                                                        #<?php echo $atleta['numero_camisa']; ?> - <?php echo htmlspecialchars($atleta['atleta_nome']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="minuto_cartao">Minuto</label>
                                            <input type="number" class="form-control" name="minuto" min="1" max="120" required>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="tipo_cartao">Tipo do Cartão</label>
                                            <select class="form-control" name="tipo_cartao" required>
                                                <option value="">Selecione</option>
                                                <option value="AMARELO">Cartão Amarelo</option>
                                                <option value="VERMELHO">Cartão Vermelho</option>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="motivo_cartao">Motivo</label>
                                            <input type="text" class="form-control" name="motivo" placeholder="Ex: Falta dura, conduta antidesportiva...">
                                        </div>
                                        
                                        <button type="submit" class="btn btn-warning btn-sm">
                                            <i class="fas fa-plus"></i> Adicionar Cartão
                                        </button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Tab Substituições -->
                        <div id="tab-substituicoes" class="tab-content">
                            <div style="display: grid; grid-template-columns: 1fr auto; gap: 30px; align-items: start;">
                                <!-- Lista de Substituições -->
                                <div>
                                    <h4 style="color: var(--primary-color); margin-bottom: 20px;">
                                        <i class="fas fa-exchange-alt"></i> Substituições da Partida
                                    </h4>
                                    
                                    <?php if (empty($eventos_partida['substituicoes'])): ?>
                                    <div style="text-align: center; padding: 40px; color: #6c757d; background: #f8f9fa; border-radius: 8px;">
                                        <i class="fas fa-exchange-alt" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3;"></i>
                                        <p>Nenhuma substituição registrada ainda</p>
                                    </div>
                                    <?php else: ?>
                                    <div class="eventos-lista">
                                        <?php foreach ($eventos_partida['substituicoes'] as $sub): ?>
                                        <div class="evento-item">
                                            <div class="evento-info">
                                                <div class="evento-principal">
                                                    <strong>Saiu:</strong> <?php echo htmlspecialchars($sub['atleta_sai_nome']); ?>
                                                    <br>
                                                    <strong>Entrou:</strong> <?php echo htmlspecialchars($sub['atleta_entra_nome']); ?>
                                                </div>
                                                <div class="evento-tempo"><?php echo $sub['minuto_substituicao']; ?>'</div>
                                                <div class="evento-motivo"><?php echo $sub['motivo_substituicao']; ?></div>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="excluirEvento('substituicao', <?php echo $sub['substituicao_id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Formulário Adicionar Substituição -->
                                <?php if (in_array($partida_atual['status_partida'], ['EM_ANDAMENTO', 'AGENDADA'])): ?>
                                <div class="form-add-evento">
                                    <h5>Adicionar Substituição</h5>
                                    <form method="POST" class="form-evento">
                                        <input type="hidden" name="acao" value="adicionar_substituicao">
                                        <input type="hidden" name="partida_id" value="<?php echo $partida_atual['partida_id']; ?>">
                                        
                                        <div class="form-group">
                                            <label for="atleta_sai">Atleta que Sai</label>
                                            <select class="form-control" name="atleta_sai" required>
                                                <option value="">Selecione</option>
                                                <optgroup label="<?php echo htmlspecialchars($partida_atual['equipe1_nome']); ?>">
                                                    <?php foreach ($atletas_equipe1 as $atleta): ?>
                                                    <option value="<?php echo $atleta['atleta_id']; ?>">
                                                        #<?php echo $atleta['numero_camisa']; ?> - <?php echo htmlspecialchars($atleta['atleta_nome']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                                <optgroup label="<?php echo htmlspecialchars($partida_atual['equipe2_nome']); ?>">
                                                    <?php foreach ($atletas_equipe2 as $atleta): ?>
                                                    <option value="<?php echo $atleta['atleta_id']; ?>">
                                                        #<?php echo $atleta['numero_camisa']; ?> - <?php echo htmlspecialchars($atleta['atleta_nome']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="atleta_entra">Atleta que Entra</label>
                                            <select class="form-control" name="atleta_entra" required>
                                                <option value="">Selecione</option>
                                                <optgroup label="<?php echo htmlspecialchars($partida_atual['equipe1_nome']); ?>">
                                                    <?php foreach ($atletas_equipe1 as $atleta): ?>
                                                    <option value="<?php echo $atleta['atleta_id']; ?>">
                                                        #<?php echo $atleta['numero_camisa']; ?> - <?php echo htmlspecialchars($atleta['atleta_nome']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                                <optgroup label="<?php echo htmlspecialchars($partida_atual['equipe2_nome']); ?>">
                                                    <?php foreach ($atletas_equipe2 as $atleta): ?>
                                                    <option value="<?php echo $atleta['atleta_id']; ?>">
                                                        #<?php echo $atleta['numero_camisa']; ?> - <?php echo htmlspecialchars($atleta['atleta_nome']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="minuto_sub">Minuto</label>
                                            <input type="number" class="form-control" name="minuto" min="1" max="120" required>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="motivo_sub">Motivo</label>
                                            <select class="form-control" name="motivo">
                                                <option value="TATICA">Tática</option>
                                                <option value="LESAO">Lesão</option>
                                                <option value="DISCIPLINAR">Disciplinar</option>
                                            </select>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-info btn-sm">
                                            <i class="fas fa-plus"></i> Adicionar Substituição
                                        </button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Tab Escalação -->
                        <div id="tab-escalacao" class="tab-content">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                                <!-- Equipe 1 -->
                                <div>
                                    <h4 style="color: var(--primary-color); margin-bottom: 20px;">
                                        <i class="fas fa-users"></i> <?php echo htmlspecialchars($partida_atual['equipe1_nome']); ?>
                                    </h4>
                                    
                                    <?php if (empty($atletas_equipe1)): ?>
                                    <p style="color: #6c757d; font-style: italic;">Nenhum atleta escalado</p>
                                    <?php else: ?>
                                    <div class="escalacao-lista">
                                        <?php foreach ($atletas_equipe1 as $atleta): ?>
                                        <div class="atleta-escalacao">
                                            <div class="numero-camisa"><?php echo $atleta['numero_camisa']; ?></div>
                                            <div class="atleta-nome"><?php echo htmlspecialchars($atleta['atleta_nome']); ?></div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Equipe 2 -->
                                <div>
                                    <h4 style="color: var(--primary-color); margin-bottom: 20px;">
                                        <i class="fas fa-users"></i> <?php echo htmlspecialchars($partida_atual['equipe2_nome']); ?>
                                    </h4>
                                    
                                    <?php if (empty($atletas_equipe2)): ?>
                                    <p style="color: #6c757d; font-style: italic;">Nenhum atleta escalado</p>
                                    <?php else: ?>
                                    <div class="escalacao-lista">
                                        <?php foreach ($atletas_equipe2 as $atleta): ?>
                                        <div class="atleta-escalacao">
                                            <div class="numero-camisa"><?php echo $atleta['numero_camisa']; ?></div>
                                            <div class="atleta-nome"><?php echo htmlspecialchars($atleta['atleta_nome']); ?></div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Botão para finalizar partida -->
                    <?php if ($partida_atual['status_partida'] === 'EM_ANDAMENTO'): ?>
                    <div style="background: #f0f8ff; padding: 20px; border-radius: 8px; margin-top: 30px; text-align: center;">
                        <h4 style="color: var(--info-color); margin-bottom: 15px;">
                            <i class="fas fa-flag-checkered"></i> Finalizar Partida
                        </h4>
                        <p style="margin-bottom: 20px;">Clique no botão abaixo para finalizar a partida e consolidar o resultado.</p>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="acao" value="finalizar_partida">
                            <input type="hidden" name="partida_id" value="<?php echo $partida_atual['partida_id']; ?>">
                            <input type="hidden" name="gols_equipe1" value="<?php echo count(array_filter($eventos_partida['gols'] ?? [], function($g) use ($atletas_equipe1) { 
                                return in_array($g['atleta_id'], array_column($atletas_equipe1, 'atleta_id')); 
                            })); ?>">
                            <input type="hidden" name="gols_equipe2" value="<?php echo count(array_filter($eventos_partida['gols'] ?? [], function($g) use ($atletas_equipe2) { 
                                return in_array($g['atleta_id'], array_column($atletas_equipe2, 'atleta_id')); 
                            })); ?>">
                            <textarea name="observacoes" placeholder="Observações finais da partida..." style="width: 100%; max-width: 400px; margin-bottom: 15px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;"></textarea><br>
                            <button type="submit" class="btn btn-success" onclick="return confirm('Confirma a finalização da partida? Esta ação não pode ser desfeita facilmente.')">
                                <i class="fas fa-flag-checkered"></i> Finalizar Partida
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <div class="action-buttons" style="margin-top: 30px;">
                        <a href="?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Voltar às Partidas
                        </a>
                        
                        <!-- BOTÕES DE IMPRESSÃO -->
                        <button type="button" class="btn btn-info" onclick="imprimirSumula()">
                            <i class="fas fa-print"></i> Imprimir Súmula Atual
                        </button>
                        
                        <button type="button" class="btn btn-warning" onclick="gerarSumulaEmBranco()">
                            <i class="fas fa-file-alt"></i> Gerar Súmula em Branco
                        </button>
                        
                        <?php if (in_array($partida_atual['status_partida'], ['AGENDADA', 'EM_ANDAMENTO'])): ?>
                        <a href="?campeonato_id=<?php echo $campeonato_id; ?>&acao=resultado&id=<?php echo $partida_atual['partida_id']; ?>" class="btn btn-success">
                            <i class="fas fa-edit"></i> Registrar Resultado Final
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

           <?php endif; ?>

           <!-- Links rápidos -->
           <div style="margin-top: 40px; text-align: center;">
               <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                   <a href="esporte_campeonatos.php" class="btn btn-secondary">
                       <i class="fas fa-arrow-left"></i> Voltar aos Campeonatos
                   </a>
                   
                   <a href="campeonato_chaves.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-info">
                       <i class="fas fa-sitemap"></i> Ver Chaves
                   </a>
                   
                   <a href="campeonato_equipes.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-primary">
                       <i class="fas fa-users"></i> Gerenciar Equipes
                   </a>
                   
                   <a href="campeonato_classificacao.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-success">
                       <i class="fas fa-trophy"></i> Ver Classificação
                   </a>
               </div>
           </div>
       </div>
   </div>

   <!-- Modal para cancelar partida -->
   <div id="modalCancelar" class="modal">
       <div class="modal-content">
           <div class="modal-header">
               <h3 class="modal-title">
                   <i class="fas fa-exclamation-triangle"></i>
                   Cancelar Partida
               </h3>
               <span class="close" onclick="fecharModal()">&times;</span>
           </div>
           <form method="POST" id="formCancelar">
               <input type="hidden" name="acao" value="cancelar_partida">
               <input type="hidden" name="partida_id" id="partidaIdCancelar">
               
               <div class="modal-body">
                   <p>Tem certeza que deseja cancelar esta partida?</p>
                   <p style="color: var(--danger-color); font-weight: 600;">
                       <i class="fas fa-warning"></i> Esta ação não pode ser desfeita facilmente!
                   </p>
                   
                   <div class="form-group" style="margin-top: 20px;">
                       <label for="motivo_cancelamento" class="required">Motivo do cancelamento</label>
                       <textarea class="form-control" id="motivo_cancelamento" name="motivo_cancelamento" 
                                 rows="3" placeholder="Descreva o motivo do cancelamento..." required></textarea>
                   </div>
               </div>
               
               <div class="modal-footer">
                   <button type="button" class="btn btn-secondary" onclick="fecharModal()">
                       <i class="fas fa-times"></i> Cancelar
                   </button>
                   <button type="submit" class="btn btn-danger">
                       <i class="fas fa-ban"></i> Confirmar Cancelamento
                   </button>
               </div>
           </form>
       </div>
   </div>

   <script>
       // Toggle sidebar para mobile
       document.addEventListener('DOMContentLoaded', function() {
           const sidebar = document.getElementById('sidebar');
           const mobileToggle = document.querySelector('.mobile-toggle');
           const toggleBtn = document.querySelector('.toggle-btn');

           // Toggle para mobile
           if (mobileToggle) {
               mobileToggle.addEventListener('click', function() {
                   sidebar.classList.toggle('show');
               });
           }

           // Toggle para desktop
           if (toggleBtn) {
               toggleBtn.addEventListener('click', function() {
                   sidebar.classList.toggle('collapsed');
               });
           }

           // Fechar sidebar ao clicar fora (mobile)
           document.addEventListener('click', function(e) {
               if (window.innerWidth <= 768) {
                   if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                       sidebar.classList.remove('show');
                   }
               }
           });

           // Submenu toggle
           const menuItems = document.querySelectorAll('.menu-link');
           menuItems.forEach(item => {
               item.addEventListener('click', function(e) {
                   const parentItem = this.closest('.menu-item');
                   const submenu = parentItem.querySelector('.submenu');
                   
                   if (submenu) {
                       e.preventDefault();
                       parentItem.classList.toggle('open');
                   }
               });
           });

           // Highlight active menu
           const currentPage = window.location.pathname.split('/').pop();
           const menuLinks = document.querySelectorAll('.menu-link, .submenu-link');
           
           menuLinks.forEach(link => {
               const href = link.getAttribute('href');
               if (href && href.includes(currentPage)) {
                   link.classList.add('active');
                   
                   const parentMenuItem = link.closest('.menu-item');
                   if (parentMenuItem && parentMenuItem.querySelector('.submenu')) {
                       parentMenuItem.classList.add('open');
                   }
               }
           });

           // Verificar empate e mostrar pênaltis
           const gols1Input = document.getElementById('gols_equipe1');
           const gols2Input = document.getElementById('gols_equipe2');
           const penaltisSection = document.getElementById('penaltis-section');
           
           if (gols1Input && gols2Input && penaltisSection) {
               function verificarEmpate() {
                   const gols1 = parseInt(gols1Input.value) || 0;
                   const gols2 = parseInt(gols2Input.value) || 0;
                   
                   if (gols1 === gols2) {
                       penaltisSection.style.display = 'block';
                   } else {
                       penaltisSection.style.display = 'none';
                       document.getElementById('penaltis_equipe1').value = '';
                       document.getElementById('penaltis_equipe2').value = '';
                   }
               }
               
               gols1Input.addEventListener('input', verificarEmpate);
               gols2Input.addEventListener('input', verificarEmpate);
               
               // Verificar na inicialização
               verificarEmpate();
           }

           // Validação do formulário de resultado
           const formResultado = document.querySelector('form[action=""][method="POST"]');
           if (formResultado) {
               formResultado.addEventListener('submit', function(e) {
                   const gols1 = parseInt(document.getElementById('gols_equipe1').value) || 0;
                   const gols2 = parseInt(document.getElementById('gols_equipe2').value) || 0;
                   const penaltis1Input = document.getElementById('penaltis_equipe1');
                   const penaltis2Input = document.getElementById('penaltis_equipe2');
                   
                   // Se houve empate e pênaltis estão visíveis, validar pênaltis
                   if (gols1 === gols2 && penaltisSection && penaltisSection.style.display === 'block') {
                       const penaltis1 = penaltis1Input ? parseInt(penaltis1Input.value) || 0 : 0;
                       const penaltis2 = penaltis2Input ? parseInt(penaltis2Input.value) || 0 : 0;
                       
                       if (penaltis1 === penaltis2) {
                           e.preventDefault();
                           alert('Nos pênaltis deve haver um vencedor! Os placares não podem ser iguais.');
                           return false;
                       }
                   }
               });
           }
       });

       // Função para cancelar partida
       function cancelarPartida(partidaId) {
           document.getElementById('partidaIdCancelar').value = partidaId;
           document.getElementById('modalCancelar').style.display = 'block';
       }

       // Função para fechar modal
       function fecharModal() {
           document.getElementById('modalCancelar').style.display = 'none';
           document.getElementById('motivo_cancelamento').value = '';
       }

       // Fechar modal ao clicar fora
       window.onclick = function(event) {
           const modal = document.getElementById('modalCancelar');
           if (event.target === modal) {
               fecharModal();
           }
       }

       // Auto-refresh para partidas em andamento (opcional)
       if (window.location.search.includes('acao=listar')) {
           const partidasAndamento = document.querySelectorAll('.status-andamento').length;
           if (partidasAndamento > 0) {
               // Atualizar a cada 2 minutos se houver partidas em andamento
               setInterval(function() {
                   location.reload();
               }, 120000);
           }
       }

       // Funções para tabs
        function mostrarTab(tabName) {
            // Esconder todas as abas
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remover active de todos os botões
            const tabBtns = document.querySelectorAll('.tab-btn');
            tabBtns.forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Mostrar aba selecionada
            const selectedTab = document.getElementById('tab-' + tabName);
            if (selectedTab) {
                selectedTab.classList.add('active');
            }
            
            // Ativar botão selecionado
            const selectedBtn = document.querySelector(`[onclick="mostrarTab('${tabName}')"]`);
            if (selectedBtn) {
                selectedBtn.classList.add('active');
            }
        }

        // Função para excluir evento
        function excluirEvento(tipo, id) {
            if (!confirm('Tem certeza que deseja excluir este evento?')) {
                return;
            }
            
            // Criar formulário para excluir
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const acaoInput = document.createElement('input');
            acaoInput.type = 'hidden';
            acaoInput.name = 'acao';
            acaoInput.value = 'excluir_' + tipo;
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = tipo + '_id';
            idInput.value = id;
            
            form.appendChild(acaoInput);
            form.appendChild(idInput);
            document.body.appendChild(form);
            form.submit();
        }

        // Validação para não permitir atletas iguais na substituição
        document.addEventListener('DOMContentLoaded', function() {
            const atletaSai = document.querySelector('select[name="atleta_sai"]');
            const atletaEntra = document.querySelector('select[name="atleta_entra"]');
            
            if (atletaSai && atletaEntra) {
                function validarSubstituicao() {
                    if (atletaSai.value && atletaEntra.value && atletaSai.value === atletaEntra.value) {
                        alert('O atleta que sai não pode ser o mesmo que entra!');
                        atletaEntra.value = '';
                    }
                }
                
                atletaSai.addEventListener('change', validarSubstituicao);
                atletaEntra.addEventListener('change', validarSubstituicao);
            }
            
            // Auto-atualizar placar baseado nos gols registrados
            function atualizarPlacar() {
                // Esta função pode ser expandida para atualizar automaticamente
                // o placar baseado nos gols registrados por equipe
            }
            
            // Validação de formulários de eventos
            const formsEvento = document.querySelectorAll('.form-evento');
            formsEvento.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const minuto = form.querySelector('input[name="minuto"]');
                    if (minuto && (minuto.value < 1 || minuto.value > 120)) {
                        e.preventDefault();
                        alert('O minuto deve estar entre 1 e 120!');
                        return false;
                    }
                });
            });
        });

        // Função para alternar entre escalações das equipes
        function mostrarEscalacao(equipe) {
            const escalacoes = document.querySelectorAll('.escalacao-equipe');
            escalacoes.forEach(esc => {
                esc.style.display = 'none';
            });
            
            const escalacaoSelecionada = document.getElementById('escalacao-' + equipe);
            if (escalacaoSelecionada) {
                escalacaoSelecionada.style.display = 'block';
            }
        }

        // Função para buscar atletas por nome
        function filtrarAtletas(input, equipe) {
            const filtro = input.value.toLowerCase();
            const atletas = document.querySelectorAll('#escalacao-' + equipe + ' .atleta-escalacao');
            
            atletas.forEach(atleta => {
                const nome = atleta.querySelector('.atleta-nome').textContent.toLowerCase();
                if (nome.includes(filtro)) {
                    atleta.style.display = 'flex';
                } else {
                    atleta.style.display = 'none';
                }
            });
        }

        // Função para calcular estatísticas em tempo real
        function calcularEstatisticas() {
            // Contar gols por equipe
            const golsEquipe1 = document.querySelectorAll('.evento-gol[data-equipe="1"]').length;
            const golsEquipe2 = document.querySelectorAll('.evento-gol[data-equipe="2"]').length;
            
            // Contar cartões
            const cartoesAmarelos = document.querySelectorAll('.evento-tipo.amarelo').length;
            const cartoesVermelhos = document.querySelectorAll('.evento-tipo.vermelho').length;
            
            // Atualizar displays se existirem
            const placarDisplay = document.querySelector('.placar-tempo-real');
            if (placarDisplay) {
                placarDisplay.textContent = `${golsEquipe1} x ${golsEquipe2}`;
            }
        }

        // Função para salvar eventos temporariamente (localStorage)
        function salvarEventoTemporario(evento) {
            const partidaId = document.querySelector('input[name="partida_id"]').value;
            const chave = `partida_${partidaId}_eventos`;
            
            let eventos = JSON.parse(localStorage.getItem(chave) || '[]');
            eventos.push({
                ...evento,
                timestamp: new Date().toISOString()
            });
            
            localStorage.setItem(chave, JSON.stringify(eventos));
        }

        // Função para recuperar eventos temporários
        function recuperarEventosTemporarios() {
            const partidaId = document.querySelector('input[name="partida_id"]').value;
            const chave = `partida_${partidaId}_eventos`;
            
            return JSON.parse(localStorage.getItem(chave) || '[]');
        }

        // Função para limpar eventos temporários
        function limparEventosTemporarios() {
            const partidaId = document.querySelector('input[name="partida_id"]').value;
            const chave = `partida_${partidaId}_eventos`;
            
            localStorage.removeItem(chave);
        }

        // Função para imprimir súmula
        function imprimirSumula() {
            // Preparar a página para impressão
            prepararImpressao();
            
            // Imprimir
            setTimeout(function() {
                window.print();
                
                // Restaurar após impressão
                setTimeout(function() {
                    restaurarAposImpressao();
                }, 1000);
            }, 500);
        }

        function prepararImpressao() {
            // Adicionar cabeçalho de impressão se não existir
            if (!document.querySelector('.sumula-header')) {
                const pageContent = document.querySelector('.page-content');
                const header = document.createElement('div');
                header.className = 'sumula-header print-only';
                header.innerHTML = `
                    <h1>SÚMULA OFICIAL DA PARTIDA</h1>
                    <h2><?php echo htmlspecialchars($campeonato['campeonato_nome']); ?></h2>
                    <p><strong>Data:</strong> ${new Date().toLocaleDateString('pt-BR')}</p>
                `;
                pageContent.insertBefore(header, pageContent.firstChild);
            }
            
            // Mostrar todas as abas
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(tab => {
                tab.style.display = 'block';
            });
            
            // Adicionar informações para impressão
            adicionarInfoImpressao();
        }

        function restaurarAposImpressao() {
            // Restaurar exibição das abas
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach((tab, index) => {
                if (index === 0) {
                    tab.style.display = 'block';
                } else {
                    tab.style.display = 'none';
                }
            });
        }

        function adicionarInfoImpressao() {
            // Adicionar placar em destaque para impressão
            const partidaInfo = document.querySelector('.campeonato-info');
            if (partidaInfo && !document.querySelector('.placar-print')) {
                const placar = document.createElement('div');
                placar.className = 'placar-print print-only';
                
                <?php if ($partida_atual && $partida_atual['status_partida'] === 'FINALIZADA'): ?>
                placar.innerHTML = `
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div style="flex: 1; text-align: center;">
                            <div style="font-size: 16pt; margin-bottom: 10px;"><?php echo htmlspecialchars($partida_atual['equipe1_nome']); ?></div>
                            <div style="font-size: 36pt;"><?php echo $partida_atual['gols_equipe1'] ?? '0'; ?></div>
                        </div>
                        <div style="font-size: 24pt; margin: 0 20px;">X</div>
                        <div style="flex: 1; text-align: center;">
                            <div style="font-size: 16pt; margin-bottom: 10px;"><?php echo htmlspecialchars($partida_atual['equipe2_nome']); ?></div>
                            <div style="font-size: 36pt;"><?php echo $partida_atual['gols_equipe2'] ?? '0'; ?></div>
                        </div>
                    </div>
                `;
                <?php else: ?>
                placar.innerHTML = `
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div style="flex: 1; text-align: center;">
                            <div style="font-size: 16pt;"><?php echo htmlspecialchars($partida_atual['equipe1_nome'] ?? ''); ?></div>
                        </div>
                        <div style="font-size: 24pt; margin: 0 20px;">VS</div>
                        <div style="flex: 1; text-align: center;">
                            <div style="font-size: 16pt;"><?php echo htmlspecialchars($partida_atual['equipe2_nome'] ?? ''); ?></div>
                        </div>
                    </div>
                `;
                <?php endif; ?>
                
                partidaInfo.parentNode.insertBefore(placar, partidaInfo.nextSibling);
            }
            
            // Adicionar assinaturas no final
            if (!document.querySelector('.assinaturas-print')) {
                const pageContent = document.querySelector('.page-content');
                const assinaturas = document.createElement('div');
                assinaturas.className = 'assinaturas-print print-only';
                assinaturas.innerHTML = `
                    <div class="assinatura-item">
                        <div style="margin-bottom: 40px;"></div>
                        <div><strong>Árbitro Principal</strong></div>
                    </div>
                    <div class="assinatura-item">
                        <div style="margin-bottom: 40px;"></div>
                        <div><strong>Delegado/Mesário</strong></div>
                    </div>
                    <div class="assinatura-item">
                        <div style="margin-bottom: 40px;"></div>
                        <div><strong>Responsável Técnico</strong></div>
                    </div>
                `;
                pageContent.appendChild(assinaturas);
            }
        }

        // Detectar quando a impressão for cancelada/finalizada
        window.onbeforeprint = prepararImpressao;
        window.onafterprint = restaurarAposImpressao;

        // Função para gerar súmula em branco para preenchimento
        function gerarSumulaEmBranco() {
            const modalidade = '<?php echo strtoupper($campeonato['campeonato_modalidade']); ?>';
            
            // Abrir nova janela com a súmula em branco
            const url = `imprimir_sumula_branca.php?campeonato_id=<?php echo $campeonato_id; ?>&partida_id=<?php echo $partida_id; ?>&modalidade=${modalidade}`;
            window.open(url, '_blank', 'width=800,height=600');
        }
   </script>
</body>
</html>
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
$acao = $_GET['acao'] ?? 'visualizar';

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

// Buscar configurações do campeonato
$config_campeonato = [
    'pontos_vitoria' => 3,
    'pontos_empate' => 1,
    'pontos_derrota' => 0,
    'criterio_desempate_1' => 'SALDO_GOLS',
    'criterio_desempate_2' => 'GOLS_MARCADOS',
    'criterio_desempate_3' => 'MENOS_GOLS_SOFRIDOS'
];

try {
    $stmt = $conn->prepare("SELECT * FROM tb_campeonato_configuracoes WHERE campeonato_id = :id");
    $stmt->bindValue(':id', $campeonato_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $config_campeonato = array_merge($config_campeonato, $stmt->fetch(PDO::FETCH_ASSOC));
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar configurações: " . $e->getMessage());
}

// Processamento de ações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'atualizar_classificacao') {
    try {
        $conn->beginTransaction();
        
        // Recalcular classificação de todas as equipes
        $equipes_atualizadas = atualizarClassificacaoCompleta($conn, $campeonato_id, $config_campeonato);
        
        $conn->commit();
        
        $mensagem = "Classificação atualizada com sucesso! {$equipes_atualizadas} equipes atualizadas.";
        $tipo_mensagem = "success";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $mensagem = "Erro ao atualizar classificação: " . $e->getMessage();
        $tipo_mensagem = "error";
        error_log("Erro ao atualizar classificação: " . $e->getMessage());
    }
}

// Buscar chaves do campeonato
$chaves = [];
try {
    $stmt = $conn->prepare("
        SELECT * FROM tb_campeonato_chaves 
        WHERE campeonato_id = :campeonato_id 
        ORDER BY fase, chave_numero
    ");
    $stmt->bindValue(':campeonato_id', $campeonato_id);
    $stmt->execute();
    $chaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar chaves: " . $e->getMessage());
}

// Buscar classificação geral e por chaves
$classificacao_geral = [];
$classificacao_por_chave = [];
$estatisticas_gerais = [
    'total_equipes' => 0,
    'partidas_jogadas' => 0,
    'total_gols' => 0,
    'media_gols' => 0
];

try {
    // Classificação geral
    $classificacao_geral = buscarClassificacao($conn, $campeonato_id, null, $config_campeonato);
    
    // Classificação por chave
    foreach ($chaves as $chave) {
        $classificacao_chave = buscarClassificacao($conn, $campeonato_id, $chave['chave_id'], $config_campeonato);
        if (!empty($classificacao_chave)) {
            $classificacao_por_chave[$chave['chave_id']] = [
                'chave' => $chave,
                'classificacao' => $classificacao_chave
            ];
        }
    }
    
    // Estatísticas gerais
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT e.equipe_id) as total_equipes,
            COUNT(DISTINCT p.partida_id) as partidas_jogadas,
            COALESCE(SUM(p.gols_equipe1 + p.gols_equipe2), 0) as total_gols
        FROM tb_campeonato_equipes e
        LEFT JOIN tb_campeonato_partidas p ON (p.equipe1_id = e.equipe_id OR p.equipe2_id = e.equipe_id) 
            AND p.campeonato_id = e.campeonato_id AND p.status_partida = 'FINALIZADA'
        WHERE e.campeonato_id = :campeonato_id
    ");
    $stmt->bindValue(':campeonato_id', $campeonato_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        $estatisticas_gerais['total_equipes'] = $stats['total_equipes'];
        $estatisticas_gerais['partidas_jogadas'] = $stats['partidas_jogadas'];
        $estatisticas_gerais['total_gols'] = $stats['total_gols'];
        $estatisticas_gerais['media_gols'] = $stats['partidas_jogadas'] > 0 ? 
            round($stats['total_gols'] / $stats['partidas_jogadas'], 2) : 0;
    }
    
} catch (PDOException $e) {
    error_log("Erro ao buscar classificação: " . $e->getMessage());
    $mensagem = "Erro ao carregar classificação.";
    $tipo_mensagem = "error";
}

// Buscar artilheiros
$artilheiros = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            a.atleta_nome,
            e.equipe_nome,
            SUM(est.gols) as total_gols,
            COUNT(DISTINCT est.partida_id) as jogos_disputados,
            ROUND(SUM(est.gols) / COUNT(DISTINCT est.partida_id), 2) as media_gols
        FROM tb_atletas a
        INNER JOIN tb_campeonato_estatisticas_atletas est ON a.atleta_id = est.atleta_id
        INNER JOIN tb_campeonato_equipes e ON est.equipe_id = e.equipe_id
        INNER JOIN tb_campeonato_partidas p ON est.partida_id = p.partida_id
        WHERE e.campeonato_id = :campeonato_id 
            AND p.status_partida = 'FINALIZADA'
            AND est.gols > 0
        GROUP BY a.atleta_id, a.atleta_nome, e.equipe_nome
        ORDER BY total_gols DESC, media_gols DESC, jogos_disputados DESC
        LIMIT 10
    ");
    $stmt->bindValue(':campeonato_id', $campeonato_id);
    $stmt->execute();
    $artilheiros = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar artilheiros: " . $e->getMessage());
}

// Mensagens da URL
if (isset($_GET['mensagem'])) {
    $mensagem = $_GET['mensagem'];
    $tipo_mensagem = $_GET['tipo'] ?? 'info';
}

// Função para buscar classificação
function buscarClassificacao($conn, $campeonato_id, $chave_id = null, $config) {
    try {
        // Sempre calcular em tempo real se não há dados na tabela
        $where_chave = $chave_id ? "AND chave_id = :chave_id" : "AND chave_id IS NULL";
        
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM tb_campeonato_classificacao 
            WHERE campeonato_id = :campeonato_id {$where_chave}
        ");
        
        $stmt->bindValue(':campeonato_id', $campeonato_id);
        if ($chave_id) {
            $stmt->bindValue(':chave_id', $chave_id);
        }
        $stmt->execute();
        
        $registros_existentes = $stmt->fetchColumn();
        
        // Se não há registros na tabela, calcular em tempo real
        if ($registros_existentes == 0) {
            error_log("Nenhum registro na tabela de classificação. Calculando em tempo real...");
            return calcularClassificacaoTempoReal($conn, $campeonato_id, $chave_id, $config);
        }
        
        // Se há registros, buscar da tabela
        $stmt = $conn->prepare("
            SELECT 
                c.*,
                e.equipe_nome
            FROM tb_campeonato_classificacao c
            INNER JOIN tb_campeonato_equipes e ON c.equipe_id = e.equipe_id
            WHERE c.campeonato_id = :campeonato_id {$where_chave}
            ORDER BY c.pontos DESC, c.saldo_gols DESC, c.gols_pro DESC, e.equipe_nome ASC
        ");
        
        $stmt->bindValue(':campeonato_id', $campeonato_id);
        if ($chave_id) {
            $stmt->bindValue(':chave_id', $chave_id);
        }
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Erro ao buscar classificação: " . $e->getMessage());
        // Em caso de erro, tentar calcular em tempo real
        return calcularClassificacaoTempoReal($conn, $campeonato_id, $chave_id, $config);
    }
}

// Função para calcular classificação em tempo real
function calcularClassificacaoTempoReal($conn, $campeonato_id, $chave_id = null, $config) {
    try {
        error_log("=== CALCULANDO CLASSIFICAÇÃO TEMPO REAL ===");
        error_log("Campeonato: $campeonato_id, Chave: " . ($chave_id ?? 'GERAL'));
        
        // CORREÇÃO: Buscar equipes corretas baseado no contexto
        if ($chave_id) {
            // Se é para uma chave específica, buscar apenas equipes dessa chave
            $stmt = $conn->prepare("
                SELECT DISTINCT e.equipe_id, e.equipe_nome
                FROM tb_campeonato_equipes e
                WHERE e.campeonato_id = :campeonato_id AND e.chave_id = :chave_id
                ORDER BY e.equipe_nome
            ");
            $stmt->bindValue(':campeonato_id', $campeonato_id);
            $stmt->bindValue(':chave_id', $chave_id);
        } else {
            // Se é classificação geral, buscar todas as equipes
            $stmt = $conn->prepare("
                SELECT DISTINCT e.equipe_id, e.equipe_nome
                FROM tb_campeonato_equipes e
                WHERE e.campeonato_id = :campeonato_id
                ORDER BY e.equipe_nome
            ");
            $stmt->bindValue(':campeonato_id', $campeonato_id);
        }
        
        $stmt->execute();
        $equipes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Equipes encontradas: " . count($equipes));
        
        if (empty($equipes)) {
            error_log("ERRO: Nenhuma equipe encontrada!");
            return [];
        }
        
        $classificacao = [];
        
        foreach ($equipes as $equipe) {
            $stats = [
                'equipe_id' => $equipe['equipe_id'],
                'equipe_nome' => $equipe['equipe_nome'],
                'pontos' => 0,
                'jogos' => 0,
                'vitorias' => 0,
                'empates' => 0,
                'derrotas' => 0,
                'gols_pro' => 0,
                'gols_contra' => 0,
                'saldo_gols' => 0,
                'aproveitamento' => 0.00
            ];
            
            // CORREÇÃO: Adicionar filtro por chave nas consultas de partidas
            $where_chave = $chave_id ? "AND p.chave_id = :chave_id" : "";
            
            // Estatísticas como mandante
            $stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as jogos,
                    SUM(CASE WHEN gols_equipe1 > gols_equipe2 THEN 1 ELSE 0 END) as vitorias,
                    SUM(CASE WHEN gols_equipe1 = gols_equipe2 THEN 1 ELSE 0 END) as empates,
                    SUM(CASE WHEN gols_equipe1 < gols_equipe2 THEN 1 ELSE 0 END) as derrotas,
                    COALESCE(SUM(gols_equipe1), 0) as gols_pro,
                    COALESCE(SUM(gols_equipe2), 0) as gols_contra
                FROM tb_campeonato_partidas p
                WHERE p.campeonato_id = :campeonato_id 
                    AND p.equipe1_id = :equipe_id 
                    AND p.status_partida = 'FINALIZADA'
                    {$where_chave}
            ");
            $stmt->bindValue(':campeonato_id', $campeonato_id);
            $stmt->bindValue(':equipe_id', $equipe['equipe_id']);
            if ($chave_id) {
                $stmt->bindValue(':chave_id', $chave_id);
            }
            $stmt->execute();
            $mandante = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Estatísticas como visitante
            $stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as jogos,
                    SUM(CASE WHEN gols_equipe2 > gols_equipe1 THEN 1 ELSE 0 END) as vitorias,
                    SUM(CASE WHEN gols_equipe2 = gols_equipe1 THEN 1 ELSE 0 END) as empates,
                    SUM(CASE WHEN gols_equipe2 < gols_equipe1 THEN 1 ELSE 0 END) as derrotas,
                    COALESCE(SUM(gols_equipe2), 0) as gols_pro,
                    COALESCE(SUM(gols_equipe1), 0) as gols_contra
                FROM tb_campeonato_partidas p
                WHERE p.campeonato_id = :campeonato_id 
                    AND p.equipe2_id = :equipe_id 
                    AND p.status_partida = 'FINALIZADA'
                    {$where_chave}
            ");
            $stmt->bindValue(':campeonato_id', $campeonato_id);
            $stmt->bindValue(':equipe_id', $equipe['equipe_id']);
            if ($chave_id) {
                $stmt->bindValue(':chave_id', $chave_id);
            }
            $stmt->execute();
            $visitante = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Somar estatísticas
            $stats['jogos'] = ($mandante['jogos'] ?? 0) + ($visitante['jogos'] ?? 0);
            $stats['vitorias'] = ($mandante['vitorias'] ?? 0) + ($visitante['vitorias'] ?? 0);
            $stats['empates'] = ($mandante['empates'] ?? 0) + ($visitante['empates'] ?? 0);
            $stats['derrotas'] = ($mandante['derrotas'] ?? 0) + ($visitante['derrotas'] ?? 0);
            $stats['gols_pro'] = ($mandante['gols_pro'] ?? 0) + ($visitante['gols_pro'] ?? 0);
            $stats['gols_contra'] = ($mandante['gols_contra'] ?? 0) + ($visitante['gols_contra'] ?? 0);
            $stats['saldo_gols'] = $stats['gols_pro'] - $stats['gols_contra'];
            
            // Calcular pontos
            $stats['pontos'] = ($stats['vitorias'] * $config['pontos_vitoria']) + 
                              ($stats['empates'] * $config['pontos_empate']) + 
                              ($stats['derrotas'] * $config['pontos_derrota']);
            
            // Calcular aproveitamento
            if ($stats['jogos'] > 0) {
                $stats['aproveitamento'] = round(($stats['pontos'] / ($stats['jogos'] * $config['pontos_vitoria'])) * 100, 2);
            }
            
            $classificacao[] = $stats;
            
            error_log("Equipe {$equipe['equipe_nome']}: {$stats['jogos']} jogos, {$stats['pontos']} pontos");
        }
        
        // Ordenar classificação
        usort($classificacao, function($a, $b) use ($config) {
            // Primeiro critério: pontos
            if ($a['pontos'] != $b['pontos']) {
                return $b['pontos'] - $a['pontos'];
            }
            
            // Critérios de desempate
            $criterios = [
                $config['criterio_desempate_1'],
                $config['criterio_desempate_2'],
                $config['criterio_desempate_3']
            ];
            
            foreach ($criterios as $criterio) {
                $resultado = aplicarCriterioDesempate($a, $b, $criterio);
                if ($resultado != 0) {
                    return $resultado;
                }
            }
            
            // Último critério: ordem alfabética
            return strcmp($a['equipe_nome'], $b['equipe_nome']);
        });
        
        // Adicionar posições
        foreach ($classificacao as $index => &$equipe) {
            $equipe['posicao'] = $index + 1;
        }
        
        error_log("Classificação final: " . count($classificacao) . " equipes ordenadas");
        return $classificacao;
        
    } catch (PDOException $e) {
        error_log("Erro ao calcular classificação: " . $e->getMessage());
        return [];
    }
}

// Buscar equipes agrupadas por chave
$equipes_por_chave = [];
try {
    $stmt = $conn->prepare("
        SELECT e.*, c.chave_nome, c.chave_numero, c.fase
        FROM tb_campeonato_equipes e
        LEFT JOIN tb_campeonato_chaves c ON e.chave_id = c.chave_id
        WHERE e.campeonato_id = :campeonato_id
        ORDER BY c.fase, c.chave_numero, e.equipe_nome
    ");
    $stmt->bindValue(':campeonato_id', $campeonato_id);
    $stmt->execute();
    $todas_equipes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organizar por chave
    foreach ($todas_equipes as $equipe) {
        $chave_key = $equipe['chave_id'] ? $equipe['chave_id'] : 'sem_chave';
        if (!isset($equipes_por_chave[$chave_key])) {
            $equipes_por_chave[$chave_key] = [
                'chave_info' => $equipe,
                'equipes' => []
            ];
        }
        $equipes_por_chave[$chave_key]['equipes'][] = $equipe;
    }
    
} catch (PDOException $e) {
    error_log("Erro ao buscar equipes por chave: " . $e->getMessage());
}

// Função para aplicar critério de desempate
function aplicarCriterioDesempate($a, $b, $criterio) {
    switch ($criterio) {
        case 'SALDO_GOLS':
            return $b['saldo_gols'] - $a['saldo_gols'];
        case 'GOLS_MARCADOS':
            return $b['gols_pro'] - $a['gols_pro'];
        case 'MENOS_GOLS_SOFRIDOS':
            return $a['gols_contra'] - $b['gols_contra'];
        case 'CONFRONTO_DIRETO':
            // Implementar confronto direto seria mais complexo
            return 0;
        default:
            return 0;
    }
}

// Função para atualizar classificação completa
function atualizarClassificacaoCompleta($conn, $campeonato_id, $config) {
    $equipes_atualizadas = 0;
    
    // Atualizar classificação geral
    $classificacao_geral = calcularClassificacaoTempoReal($conn, $campeonato_id, null, $config);
    
    foreach ($classificacao_geral as $equipe) {
        $stmt = $conn->prepare("
            INSERT INTO tb_campeonato_classificacao (
                campeonato_id, equipe_id, chave_id, posicao, pontos, jogos, vitorias, empates, derrotas,
                gols_pro, gols_contra, saldo_gols, aproveitamento
            ) VALUES (
                :campeonato_id, :equipe_id, NULL, :posicao, :pontos, :jogos, :vitorias, :empates, :derrotas,
                :gols_pro, :gols_contra, :saldo_gols, :aproveitamento
            )
            ON DUPLICATE KEY UPDATE
                posicao = VALUES(posicao),
                pontos = VALUES(pontos),
                jogos = VALUES(jogos),
                vitorias = VALUES(vitorias),
                empates = VALUES(empates),
                derrotas = VALUES(derrotas),
                gols_pro = VALUES(gols_pro),
                gols_contra = VALUES(gols_contra),
                saldo_gols = VALUES(saldo_gols),
                aproveitamento = VALUES(aproveitamento)
        ");
        
        $stmt->bindValue(':campeonato_id', $campeonato_id);
        $stmt->bindValue(':equipe_id', $equipe['equipe_id']);
        $stmt->bindValue(':posicao', $equipe['posicao']);
        $stmt->bindValue(':pontos', $equipe['pontos']);
        $stmt->bindValue(':jogos', $equipe['jogos']);
        $stmt->bindValue(':vitorias', $equipe['vitorias']);
        $stmt->bindValue(':empates', $equipe['empates']);
        $stmt->bindValue(':derrotas', $equipe['derrotas']);
        $stmt->bindValue(':gols_pro', $equipe['gols_pro']);
        $stmt->bindValue(':gols_contra', $equipe['gols_contra']);
        $stmt->bindValue(':saldo_gols', $equipe['saldo_gols']);
        $stmt->bindValue(':aproveitamento', $equipe['aproveitamento']);
        
        $stmt->execute();
        $equipes_atualizadas++;
    }
    
    // Atualizar classificação por chaves
    $stmt = $conn->prepare("SELECT * FROM tb_campeonato_chaves WHERE campeonato_id = :campeonato_id");
    $stmt->bindValue(':campeonato_id', $campeonato_id);
    $stmt->execute();
    $chaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($chaves as $chave) {
        $classificacao_chave = calcularClassificacaoTempoReal($conn, $campeonato_id, $chave['chave_id'], $config);
        
        foreach ($classificacao_chave as $equipe) {
            $stmt = $conn->prepare("
                INSERT INTO tb_campeonato_classificacao (
                    campeonato_id, equipe_id, chave_id, posicao, pontos, jogos, vitorias, empates, derrotas,
                    gols_pro, gols_contra, saldo_gols, aproveitamento
                ) VALUES (
                    :campeonato_id, :equipe_id, :chave_id, :posicao, :pontos, :jogos, :vitorias, :empates, :derrotas,
                    :gols_pro, :gols_contra, :saldo_gols, :aproveitamento
                )
                ON DUPLICATE KEY UPDATE
                    posicao = VALUES(posicao),
                    pontos = VALUES(pontos),
                    jogos = VALUES(jogos),
                    vitorias = VALUES(vitorias),
                    empates = VALUES(empates),
                    derrotas = VALUES(derrotas),
                    gols_pro = VALUES(gols_pro),
                    gols_contra = VALUES(gols_contra),
                    saldo_gols = VALUES(saldo_gols),
                    aproveitamento = VALUES(aproveitamento)
            ");
            
            $stmt->bindValue(':campeonato_id', $campeonato_id);
            $stmt->bindValue(':equipe_id', $equipe['equipe_id']);
            $stmt->bindValue(':chave_id', $chave['chave_id']);
            $stmt->bindValue(':posicao', $equipe['posicao']);
            $stmt->bindValue(':pontos', $equipe['pontos']);
            $stmt->bindValue(':jogos', $equipe['jogos']);
            $stmt->bindValue(':vitorias', $equipe['vitorias']);
            $stmt->bindValue(':empates', $equipe['empates']);
            $stmt->bindValue(':derrotas', $equipe['derrotas']);
            $stmt->bindValue(':gols_pro', $equipe['gols_pro']);
            $stmt->bindValue(':gols_contra', $equipe['gols_contra']);
            $stmt->bindValue(':saldo_gols', $equipe['saldo_gols']);
            $stmt->bindValue(':aproveitamento', $equipe['aproveitamento']);
            
            $stmt->execute();
        }
    }
    
    return $equipes_atualizadas;
}

// Funções auxiliares
function getNomeFase($fase) {
    $nomes = [
        1 => 'Primeira Fase',
        2 => 'Quartas de Final',
        3 => 'Semifinais',
        4 => 'Final'
    ];
    return $nomes[$fase] ?? "Fase $fase";
}

function getPosicaoClass($posicao, $total_equipes) {
    if ($posicao <= 2) return 'pos-destaque';
    if ($posicao <= 4) return 'pos-boa';
    if ($posicao > $total_equipes - 2) return 'pos-ruim';
    return 'pos-normal';
}

function formatarCriterio($criterio) {
    $criterios = [
        'SALDO_GOLS' => 'Saldo de Gols',
        'GOLS_MARCADOS' => 'Gols Marcados',
        'MENOS_GOLS_SOFRIDOS' => 'Menos Gols Sofridos',
        'CONFRONTO_DIRETO' => 'Confronto Direto'
    ];
    return $criterios[$criterio] ?? $criterio;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classificação - <?php echo htmlspecialchars($campeonato['campeonato_nome']); ?></title>
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
            --gold-color: #ffd700;
            --silver-color: #c0c0c0;
            --bronze-color: #cd7f32;
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

       /* Classificação Table */
       .classificacao-table {
           width: 100%;
           border-collapse: collapse;
           font-size: 0.9rem;
           background: white;
           border-radius: 12px;
           overflow: hidden;
           box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
       }

       .classificacao-table th {
           background: linear-gradient(135deg, var(--primary-color), #34495e);
           color: white;
           padding: 15px 12px;
           text-align: center;
           font-weight: 600;
           font-size: 0.85rem;
           text-transform: uppercase;
           letter-spacing: 0.5px;
       }

       .classificacao-table th:first-child {
           text-align: left;
           padding-left: 20px;
       }

       .classificacao-table td {
           padding: 15px 12px;
           text-align: center;
           border-bottom: 1px solid #e9ecef;
           transition: background-color 0.3s;
       }

       .classificacao-table td:first-child {
           text-align: left;
           padding-left: 20px;
           font-weight: 600;
       }

       .classificacao-table tbody tr:hover {
           background-color: #f8f9fa;
       }

       /* Posições especiais */
       .pos-destaque {
           background: linear-gradient(135deg, #fff3cd, #ffeaa7) !important;
           border-left: 4px solid var(--gold-color);
       }

       .pos-destaque:hover {
           background: linear-gradient(135deg, #ffeaa7, #fdcb6e) !important;
       }

       .pos-boa {
           background: linear-gradient(135deg, #d4edda, #c3e6cb) !important;
           border-left: 4px solid var(--success-color);
       }

       .pos-boa:hover {
           background: linear-gradient(135deg, #c3e6cb, #a5d6a7) !important;
       }

       .pos-ruim {
           background: linear-gradient(135deg, #f8d7da, #f5c6cb) !important;
           border-left: 4px solid var(--danger-color);
       }

       .pos-ruim:hover {
           background: linear-gradient(135deg, #f5c6cb, #ef9a9a) !important;
       }

       .pos-normal {
           border-left: 4px solid transparent;
       }

       /* Posição number */
       .posicao-number {
           display: inline-flex;
           align-items: center;
           justify-content: center;
           width: 30px;
           height: 30px;
           border-radius: 50%;
           font-weight: bold;
           margin-right: 12px;
           font-size: 0.9rem;
       }

       .posicao-1 {
           background: var(--gold-color);
           color: #333;
       }

       .posicao-2 {
           background: var(--silver-color);
           color: #333;
       }

       .posicao-3 {
           background: var(--bronze-color);
           color: white;
       }

       .posicao-4,
       .posicao-5,
       .posicao-6 {
           background: var(--success-color);
           color: white;
       }

       .posicao-default {
           background: #6c757d;
           color: white;
       }

       /* Artilheiros */
       .artilheiros-container {
           background: white;
           border-radius: 15px;
           padding: 25px;
           box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
           margin-bottom: 20px;
       }

       .artilheiro-item {
           display: flex;
           align-items: center;
           padding: 15px 0;
           border-bottom: 1px solid #e9ecef;
           transition: all 0.3s;
       }

       .artilheiro-item:last-child {
           border-bottom: none;
       }

       .artilheiro-item:hover {
           background-color: #f8f9fa;
           border-radius: 8px;
           margin: 0 -10px;
           padding: 15px 10px;
       }

       .artilheiro-posicao {
           width: 40px;
           text-align: center;
           font-weight: bold;
           font-size: 1.2rem;
           color: var(--secondary-color);
       }

       .artilheiro-info {
           flex: 1;
           margin-left: 15px;
       }

       .artilheiro-nome {
           font-weight: 600;
           color: var(--primary-color);
           font-size: 1.1rem;
       }

       .artilheiro-equipe {
           color: #6c757d;
           font-size: 0.9rem;
           margin-top: 2px;
       }

       .artilheiro-stats {
           text-align: right;
           min-width: 120px;
       }

       .artilheiro-gols {
           font-size: 1.8rem;
           font-weight: bold;
           color: var(--secondary-color);
           display: block;
       }

       .artilheiro-media {
           font-size: 0.8rem;
           color: #6c757d;
       }

       /* Tabs */
       .tabs-container {
           margin-bottom: 30px;
       }

       .tabs-nav {
           display: flex;
           background: white;
           border-radius: 12px 12px 0 0;
           box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
           overflow: hidden;
       }

       .tab-btn {
           flex: 1;
           padding: 15px 20px;
           background: transparent;
           border: none;
           cursor: pointer;
           font-weight: 600;
           color: #6c757d;
           transition: all 0.3s;
           display: flex;
           align-items: center;
           justify-content: center;
           gap: 8px;
       }

       .tab-btn.active {
           background: var(--secondary-color);
           color: white;
       }

       .tab-btn:hover:not(.active) {
           background: #f8f9fa;
           color: var(--primary-color);
       }

       .tab-content {
           display: none;
           background: white;
           border-radius: 0 0 12px 12px;
           box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
       }

       .tab-content.active {
           display: block;
       }

       /* Action buttons */
       .action-buttons {
           display: flex;
           gap: 15px;
           justify-content: center;
           margin: 30px 0;
           flex-wrap: wrap;
       }

       /* Critérios de desempate */
       .criterios-info {
           background: #f8f9fa;
           padding: 20px;
           border-radius: 8px;
           margin-bottom: 20px;
           border-left: 4px solid var(--info-color);
       }

       .criterios-info h5 {
           color: var(--primary-color);
           margin-bottom: 10px;
           display: flex;
           align-items: center;
           gap: 8px;
       }

       .criterios-list {
           list-style: none;
           padding: 0;
       }

       .criterios-list li {
           padding: 5px 0;
           color: #6c757d;
       }

       .criterios-list li:before {
           content: "→";
           color: var(--info-color);
           font-weight: bold;
           margin-right: 8px;
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

           .classificacao-table {
               font-size: 0.8rem;
           }

           .classificacao-table th,
           .classificacao-table td {
               padding: 10px 8px;
           }

           .classificacao-table th:first-child,
           .classificacao-table td:first-child {
               padding-left: 12px;
           }

           .tabs-nav {
               flex-direction: column;
           }

           .action-buttons {
               flex-direction: column;
               align-items: stretch;
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

           .classificacao-table {
               font-size: 0.75rem;
           }

           .posicao-number {
               width: 25px;
               height: 25px;
               font-size: 0.8rem;
               margin-right: 8px;
           }

           .artilheiro-gols {
               font-size: 1.5rem;
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
       
       <?php echo $menuManager->generateSidebar('campeonato_classificacao.php'); ?>
   </div>

   <!-- Main Content -->
   <div class="main-content" id="mainContent">
       <div class="header">
           <div>
               <button class="mobile-toggle">
                   <i class="fas fa-bars"></i>
               </button>
               <h2>Classificação do Campeonato</h2>
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
               <i class="fas fa-trophy"></i>
               Classificação do Campeonato
           </h1>

           <!-- Breadcrumb -->
           <div class="breadcrumb">
               <a href="dashboard.php">Dashboard</a>
               <i class="fas fa-chevron-right"></i>
               <a href="esporte.php">Esporte</a>
               <i class="fas fa-chevron-right"></i>
               <a href="esporte_campeonatos.php">Campeonatos</a>
               <i class="fas fa-chevron-right"></i>
               <span>Classificação</span>
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

           <!-- Estatísticas -->
           <div class="stats-container">
               <div class="stat-card">
                   <span class="stat-number"><?php echo $estatisticas_gerais['total_equipes']; ?></span>
                   <span class="stat-label">Equipes</span>
               </div>
               <div class="stat-card">
                   <span class="stat-number"><?php echo $estatisticas_gerais['partidas_jogadas']; ?></span>
                   <span class="stat-label">Partidas Jogadas</span>
               </div>
               <div class="stat-card">
                   <span class="stat-number"><?php echo $estatisticas_gerais['total_gols']; ?></span>
                   <span class="stat-label">Gols Marcados</span>
               </div>
               <div class="stat-card">
                   <span class="stat-number"><?php echo $estatisticas_gerais['media_gols']; ?></span>
                   <span class="stat-label">Média Gols/Jogo</span>
               </div>
           </div>
        

           <!-- Botão para atualizar classificação -->
           <?php 
            $fase_atual = 1;
            $pode_avancar_fase = false;
            $proxima_fase_nome = '';
            $todas_partidas_finalizadas = false;

            try {
                // Buscar fase atual
                $stmt = $conn->prepare("
                    SELECT MAX(fase) as fase_atual 
                    FROM tb_campeonato_chaves 
                    WHERE campeonato_id = :campeonato_id
                ");
                $stmt->bindValue(':campeonato_id', $campeonato_id);
                $stmt->execute();
                
                $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
                $fase_atual = $resultado['fase_atual'] ?? 1;
                
                // Verificar se todas as partidas da fase atual estão finalizadas
                $stmt = $conn->prepare("
                    SELECT 
                        COUNT(*) as total_partidas,
                        SUM(CASE WHEN p.status_partida = 'FINALIZADA' THEN 1 ELSE 0 END) as finalizadas
                    FROM tb_campeonato_partidas p
                    INNER JOIN tb_campeonato_chaves c ON p.chave_id = c.chave_id
                    WHERE c.campeonato_id = :campeonato_id AND c.fase = :fase_atual
                ");
                $stmt->bindValue(':campeonato_id', $campeonato_id);
                $stmt->bindValue(':fase_atual', $fase_atual);
                $stmt->execute();
                
                $partidas_info = $stmt->fetch(PDO::FETCH_ASSOC);
                $todas_partidas_finalizadas = $partidas_info['total_partidas'] > 0 && 
                                            $partidas_info['total_partidas'] == $partidas_info['finalizadas'];
                
                // NOVA LÓGICA: Determinar se pode avançar baseado na fase atual
                if ($todas_partidas_finalizadas && $fase_atual < 4) { // Pode avançar até a final
                    // Contar quantas equipes podem avançar
                    if ($fase_atual == 1) {
                        // Fase de grupos - verificar quantas chaves existem
                        $stmt = $conn->prepare("
                            SELECT COUNT(*) as total_chaves 
                            FROM tb_campeonato_chaves 
                            WHERE campeonato_id = :campeonato_id AND fase = 1
                        ");
                        $stmt->bindValue(':campeonato_id', $campeonato_id);
                        $stmt->execute();
                        $total_chaves = $stmt->fetchColumn();
                        
                        $pode_avancar_fase = $total_chaves > 0;
                        $proxima_fase_nome = $total_chaves == 2 ? 'Semifinais' : 'Quartas de Final';
                        
                    } elseif ($fase_atual == 2) {
                        // Quartas de final - pode avançar para semifinal
                        $pode_avancar_fase = true;
                        $proxima_fase_nome = 'Semifinais';
                        
                    } elseif ($fase_atual == 3) {
                        // Semifinal - pode avançar para final
                        $stmt = $conn->prepare("
                            SELECT COUNT(*) as partidas_finalizadas
                            FROM tb_campeonato_partidas p
                            INNER JOIN tb_campeonato_chaves c ON p.chave_id = c.chave_id
                            WHERE c.campeonato_id = :campeonato_id AND c.fase = 3 AND p.status_partida = 'FINALIZADA'
                        ");
                        $stmt->bindValue(':campeonato_id', $campeonato_id);
                        $stmt->execute();
                        $partidas_semi_finalizadas = $stmt->fetchColumn();
                        
                        // Na semifinal, se há pelo menos 1 partida finalizada, pode avançar para final
                        $pode_avancar_fase = $partidas_semi_finalizadas >= 1;
                        $proxima_fase_nome = 'Final';
                    }
                } else {
                    $pode_avancar_fase = false;
                }
                
                error_log("Fase atual: $fase_atual, Pode avançar: " . ($pode_avancar_fase ? 'SIM' : 'NÃO') . ", Próxima: $proxima_fase_nome");
                
            } catch (PDOException $e) {
                error_log("Erro ao verificar fase atual: " . $e->getMessage());
                $fase_atual = 1;
                $pode_avancar_fase = false;
                $proxima_fase_nome = '';
                $todas_partidas_finalizadas = false;
            }
            ?>

            <div class="action-buttons">
                <a href="campeonato_partidas.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-calendar-alt"></i> Ver Partidas
                </a>
                
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="acao" value="atualizar_classificacao">
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-sync-alt"></i> Atualizar Classificação
                    </button>
                </form>
                
                <!-- Botão para avançar fase -->
                <?php if (!empty($classificacao_geral) && $pode_avancar_fase): ?>
                <a href="campeonato_avancar_fases.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-success">
                    <i class="fas fa-level-up-alt"></i> Avançar para <?php echo $proxima_fase_nome; ?>
                </a>
                <?php elseif (!empty($classificacao_geral) && $fase_atual >= 4): ?>
                <a href="campeonato_resultado_final.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-success">
                    <i class="fas fa-trophy"></i> Ver Resultado Final
                </a>
                <?php elseif (!empty($classificacao_geral) && !$todas_partidas_finalizadas): ?>
                <div class="btn btn-warning" style="opacity: 0.6; cursor: not-allowed;" title="Finalize todas as partidas da fase atual primeiro">
                    <i class="fas fa-clock"></i> Aguardando Partidas da Fase <?php echo getNomeFase($fase_atual); ?>
                </div>
                <?php endif; ?>
            </div>

           <div class="tabs-container">
               <div class="tabs-nav">
                   <button class="tab-btn active" onclick="showTab('geral')">
                       <i class="fas fa-list"></i> Classificação Geral
                   </button>
                   <?php if (!empty($classificacao_por_chave)): ?>
                   <button class="tab-btn" onclick="showTab('chaves')">
                       <i class="fas fa-layer-group"></i> Por Chaves
                   </button>
                   <?php endif; ?>
                   <?php if (!empty($artilheiros)): ?>
                   <button class="tab-btn" onclick="showTab('artilheiros')">
                       <i class="fas fa-futbol"></i> Artilheiros
                   </button>
                   <?php endif; ?>
               </div>

               <!-- Tab Classificação Geral -->
               <div id="tab-geral" class="tab-content active">
                   <?php if (empty($classificacao_geral)): ?>
                   <div class="empty-state">
                       <div class="empty-state-icon">
                           <i class="fas fa-list"></i>
                       </div>
                       <h3>Classificação Não Disponível</h3>
                       <p>
                           A classificação será gerada automaticamente quando as partidas forem sendo finalizadas.<br>
                           Certifique-se de que existem partidas cadastradas e finalizadas.
                       </p>
                   </div>
                   <?php else: ?>
                   <!-- Critérios de desempate -->
                   <div class="criterios-info">
                       <h5>
                           <i class="fas fa-info-circle"></i>
                           Critérios de Desempate
                       </h5>
                       <ul class="criterios-list">
                           <li>1º <?php echo formatarCriterio($config_campeonato['criterio_desempate_1']); ?></li>
                           <li>2º <?php echo formatarCriterio($config_campeonato['criterio_desempate_2']); ?></li>
                           <li>3º <?php echo formatarCriterio($config_campeonato['criterio_desempate_3']); ?></li>
                       </ul>
                   </div>

                   <table class="classificacao-table">
                       <thead>
                           <tr>
                               <th>Posição / Equipe</th>
                               <th>Pts</th>
                               <th>J</th>
                               <th>V</th>
                               <th>E</th>
                               <th>D</th>
                               <th>GP</th>
                               <th>GC</th>
                               <th>SG</th>
                               <th>%</th>
                           </tr>
                       </thead>
                       <tbody>
                           <?php foreach ($classificacao_geral as $equipe): ?>
                           <tr class="<?php echo getPosicaoClass($equipe['posicao'], count($classificacao_geral)); ?>">
                               <td>
                                   <span class="posicao-number <?php 
                                       if ($equipe['posicao'] <= 3) {
                                           echo 'posicao-' . $equipe['posicao'];
                                       } elseif ($equipe['posicao'] <= 6) {
                                           echo 'posicao-4';
                                       } else {
                                           echo 'posicao-default';
                                       }
                                   ?>">
                                       <?php echo $equipe['posicao']; ?>
                                   </span>
                                   <?php echo htmlspecialchars($equipe['equipe_nome']); ?>
                               </td>
                               <td><strong><?php echo $equipe['pontos']; ?></strong></td>
                               <td><?php echo $equipe['jogos']; ?></td>
                               <td><?php echo $equipe['vitorias']; ?></td>
                               <td><?php echo $equipe['empates']; ?></td>
                               <td><?php echo $equipe['derrotas']; ?></td>
                               <td><?php echo $equipe['gols_pro']; ?></td>
                               <td><?php echo $equipe['gols_contra']; ?></td>
                               <td><?php echo ($equipe['saldo_gols'] >= 0 ? '+' : '') . $equipe['saldo_gols']; ?></td>
                               <td><?php echo number_format($equipe['aproveitamento'], 1); ?>%</td>
                           </tr>
                           <?php endforeach; ?>
                       </tbody>
                   </table>
                   <?php endif; ?>
               </div>

               <!-- Tab Classificação por Chaves -->
               <?php if (!empty($classificacao_por_chave)): ?>
               <div id="tab-chaves" class="tab-content">
                   <?php foreach ($classificacao_por_chave as $dados_chave): ?>
                   <div class="card" style="margin-bottom: 30px;">
                       <div class="card-header">
                           <div class="card-title">
                               <i class="fas fa-layer-group"></i>
                               <?php echo getNomeFase($dados_chave['chave']['fase']) . ' - ' . htmlspecialchars($dados_chave['chave']['chave_nome']); ?>
                           </div>
                       </div>
                       <div class="card-body" style="padding: 0;">
                           <table class="classificacao-table" style="box-shadow: none;">
                               <thead>
                                   <tr>
                                       <th>Posição / Equipe</th>
                                       <th>Pts</th>
                                       <th>J</th>
                                       <th>V</th>
                                       <th>E</th>
                                       <th>D</th>
                                       <th>GP</th>
                                       <th>GC</th>
                                       <th>SG</th>
                                       <th>%</th>
                                   </tr>
                               </thead>
                               <tbody>
                                   <?php foreach ($dados_chave['classificacao'] as $equipe): ?>
                                   <tr class="<?php echo getPosicaoClass($equipe['posicao'], count($dados_chave['classificacao'])); ?>">
                                       <td>
                                           <span class="posicao-number <?php 
                                               if ($equipe['posicao'] <= 2) {
                                                   echo 'posicao-' . $equipe['posicao'];
                                               } elseif ($equipe['posicao'] <= 4) {
                                                   echo 'posicao-4';
                                               } else {
                                                   echo 'posicao-default';
                                               }
                                           ?>">
                                               <?php echo $equipe['posicao']; ?>
                                           </span>
                                           <?php echo htmlspecialchars($equipe['equipe_nome']); ?>
                                       </td>
                                       <td><strong><?php echo $equipe['pontos']; ?></strong></td>
                                       <td><?php echo $equipe['jogos']; ?></td>
                                       <td><?php echo $equipe['vitorias']; ?></td>
                                       <td><?php echo $equipe['empates']; ?></td>
                                       <td><?php echo $equipe['derrotas']; ?></td>
                                       <td><?php echo $equipe['gols_pro']; ?></td>
                                       <td><?php echo $equipe['gols_contra']; ?></td>
                                       <td><?php echo ($equipe['saldo_gols'] >= 0 ? '+' : '') . $equipe['saldo_gols']; ?></td>
                                       <td><?php echo number_format($equipe['aproveitamento'], 1); ?>%</td>
                                   </tr>
                                   <?php endforeach; ?>
                               </tbody>
                           </table>
                       </div>
                   </div>
                   <?php endforeach; ?>
               </div>
               <?php endif; ?>

               <!-- Tab Artilheiros -->
               <?php if (!empty($artilheiros)): ?>
               <div id="tab-artilheiros" class="tab-content">
                   <div class="artilheiros-container">
                       <h3 style="color: var(--primary-color); margin-bottom: 25px; display: flex; align-items: center; gap: 10px;">
                           <i class="fas fa-futbol"></i>
                           Artilharia do Campeonato
                       </h3>
                       
                       <?php if (empty($artilheiros)): ?>
                       <div class="empty-state">
                           <div class="empty-state-icon">
                               <i class="fas fa-futbol"></i>
                           </div>
                           <h3>Nenhum Gol Registrado</h3>
                           <p>Os artilheiros aparecerão aqui quando os gols forem registrados nas estatísticas das partidas.</p>
                       </div>
                       <?php else: ?>
                       <?php foreach ($artilheiros as $index => $artilheiro): ?>
                       <div class="artilheiro-item">
                           <div class="artilheiro-posicao">
                               <?php 
                               if ($index + 1 <= 3) {
                                   $trofeus = ['🥇', '🥈', '🥉'];
                                   echo $trofeus[$index];
                               } else {
                                   echo $index + 1 . 'º';
                               }
                               ?>
                           </div>
                           
                           <div class="artilheiro-info">
                               <div class="artilheiro-nome"><?php echo htmlspecialchars($artilheiro['atleta_nome']); ?></div>
                               <div class="artilheiro-equipe">
                                   <i class="fas fa-users"></i>
                                   <?php echo htmlspecialchars($artilheiro['equipe_nome']); ?>
                               </div>
                           </div>
                           
                           <div class="artilheiro-stats">
                               <span class="artilheiro-gols"><?php echo $artilheiro['total_gols']; ?></span>
                               <div class="artilheiro-media">
                                   <?php echo $artilheiro['jogos_disputados']; ?> jogos • 
                                   <?php echo number_format($artilheiro['media_gols'], 2); ?> gols/jogo
                               </div>
                           </div>
                       </div>
                       <?php endforeach; ?>
                       <?php endif; ?>
                   </div>
               </div>
               <?php endif; ?>
           </div>

           <!-- Legenda da Classificação -->
           <?php if (!empty($classificacao_geral)): ?>
           <div class="card">
               <div class="card-header">
                   <div class="card-title">
                       <i class="fas fa-info-circle"></i>
                       Legenda
                   </div>
               </div>
               <div class="card-body">
                   <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                       <div>
                           <h5 style="color: var(--primary-color); margin-bottom: 10px;">Posições</h5>
                           <div style="display: flex; align-items: center; margin-bottom: 8px;">
                               <div style="width: 20px; height: 20px; background: var(--gold-color); border-radius: 50%; margin-right: 10px;"></div>
                               <span>1º Lugar - Campeão</span>
                           </div>
                           <div style="display: flex; align-items: center; margin-bottom: 8px;">
                               <div style="width: 20px; height: 20px; background: var(--silver-color); border-radius: 50%; margin-right: 10px;"></div>
                               <span>2º Lugar - Vice-Campeão</span>
                           </div>
                           <div style="display: flex; align-items: center; margin-bottom: 8px;">
                               <div style="width: 20px; height: 20px; background: var(--bronze-color); border-radius: 50%; margin-right: 10px;"></div>
                               <span>3º Lugar - 3º Colocado</span>
                           </div>
                           <div style="display: flex; align-items: center;">
                               <div style="width: 20px; height: 20px; background: var(--success-color); border-radius: 50%; margin-right: 10px;"></div>
                               <span>Posições de Destaque</span>
                           </div>
                       </div>
                       
                       <div>
                           <h5 style="color: var(--primary-color); margin-bottom: 10px;">Abreviações</h5>
                           <div style="font-size: 0.9rem; line-height: 1.6;">
                               <strong>Pts</strong> - Pontos<br>
                               <strong>J</strong> - Jogos<br>
                               <strong>V</strong> - Vitórias<br>
                               <strong>E</strong> - Empates<br>
                               <strong>D</strong> - Derrotas<br>
                               <strong>GP</strong> - Gols Pró<br>
                               <strong>GC</strong> - Gols Contra<br>
                               <strong>SG</strong> - Saldo de Gols<br>
                               <strong>%</strong> - Aproveitamento
                           </div>
                       </div>
                       
                       <div>
                           <h5 style="color: var(--primary-color); margin-bottom: 10px;">Sistema de Pontuação</h5>
                           <div style="font-size: 0.9rem; line-height: 1.6;">
                               <strong>Vitória:</strong> <?php echo $config_campeonato['pontos_vitoria']; ?> pontos<br>
                               <strong>Empate:</strong> <?php echo $config_campeonato['pontos_empate']; ?> ponto<?php echo $config_campeonato['pontos_empate'] > 1 ? 's' : ''; ?><br>
                               <strong>Derrota:</strong> <?php echo $config_campeonato['pontos_derrota']; ?> ponto<?php echo $config_campeonato['pontos_derrota'] > 1 ? 's' : ''; ?>
                           </div>
                       </div>
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
                   
                   <a href="campeonato_partidas.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-primary">
                       <i class="fas fa-calendar-alt"></i> Ver Partidas
                   </a>
                   
                   <a href="campeonato_equipes.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-warning">
                       <i class="fas fa-users"></i> Gerenciar Equipes
                   </a>
               </div>
           </div>
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

           // Auto-refresh da classificação a cada 5 minutos (opcional)
           setInterval(function() {
               // Verificar se há novas partidas finalizadas
               const currentTime = new Date().getTime();
               const lastUpdate = localStorage.getItem('last_classification_update');
               
               if (!lastUpdate || (currentTime - parseInt(lastUpdate)) > 300000) { // 5 minutos
                   console.log('Verificando atualizações na classificação...');
                   localStorage.setItem('last_classification_update', currentTime.toString());
               }
           }, 300000); // 5 minutos
       });

       // Função para alternar tabs
       function showTab(tabName) {
           // Ocultar todas as tabs
           const tabContents = document.querySelectorAll('.tab-content');
           tabContents.forEach(tab => {
               tab.classList.remove('active');
           });
           
           // Remover classe active dos botões
           const tabBtns = document.querySelectorAll('.tab-btn');
           tabBtns.forEach(btn => {
               btn.classList.remove('active');
           });
           
           // Mostrar tab selecionada
           const selectedTab = document.getElementById('tab-' + tabName);
           if (selectedTab) {
               selectedTab.classList.add('active');
           }
           
           // Ativar botão correspondente
           const selectedBtn = event.target;
           selectedBtn.classList.add('active');
       }

       // Função para imprimir classificação
       function imprimirClassificacao() {
           const printWindow = window.open('', '_blank');
           const campeonatoNome = '<?php echo addslashes($campeonato['campeonato_nome']); ?>';
           const dataAtual = new Date().toLocaleDateString('pt-BR');
           
           let tabela = document.querySelector('#tab-geral .classificacao-table');
           if (tabela) {
               printWindow.document.write(`
                   <html>
                   <head>
                       <title>Classificação - ${campeonatoNome}</title>
                       <style>
                           body { font-family: Arial, sans-serif; margin: 20px; }
                           h1 { text-align: center; color: #2c3e50; }
                           table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                           th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
                           th { background-color: #f8f9fa; font-weight: bold; }
                           .pos-destaque { background-color: #fff3cd; }
                           .pos-boa { background-color: #d4edda; }
                           .footer { text-align: center; margin-top: 20px; font-size: 0.8em; color: #666; }
                       </style>
                   </head>
                   <body>
                       <h1>Classificação - ${campeonatoNome}</h1>
                       <p style="text-align: center;">Gerado em: ${dataAtual}</p>
                       ${tabela.outerHTML}
                       <div class="footer">
                           <p>Sistema de Gestão Esportiva - Prefeitura</p>
                       </div>
                   </body>
                   </html>
               `);
               printWindow.document.close();
               printWindow.print();
           }
       }

       // Função para exportar para CSV
       function exportarCSV() {
           const campeonatoNome = '<?php echo addslashes($campeonato['campeonato_nome']); ?>';
           const classificacao = <?php echo json_encode($classificacao_geral); ?>;
           
           let csv = 'Posição,Equipe,Pontos,Jogos,Vitórias,Empates,Derrotas,Gols Pró,Gols Contra,Saldo Gols,Aproveitamento\n';
           
           classificacao.forEach(equipe => {
               csv += `${equipe.posicao},${equipe.equipe_nome},${equipe.pontos},${equipe.jogos},${equipe.vitorias},${equipe.empates},${equipe.derrotas},${equipe.gols_pro},${equipe.gols_contra},${equipe.saldo_gols},${equipe.aproveitamento}\n`;
           });
           
           const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
           const link = document.createElement('a');
           const url = URL.createObjectURL(blob);
           link.setAttribute('href', url);
           link.setAttribute('download', `classificacao_${campeonatoNome.replace(/\s+/g, '_')}.csv`);
           link.style.visibility = 'hidden';
           document.body.appendChild(link);
           link.click();
           document.body.removeChild(link);
       }

       // Adicionar botões de impressão e exportação se houver classificação
       <?php if (!empty($classificacao_geral)): ?>
       document.addEventListener('DOMContentLoaded', function() {
           const actionButtons = document.querySelector('.action-buttons');
           if (actionButtons) {
               const printBtn = document.createElement('button');
               printBtn.className = 'btn btn-info';
               printBtn.innerHTML = '<i class="fas fa-print"></i> Imprimir';
               printBtn.onclick = imprimirClassificacao;
               
               const exportBtn = document.createElement('button');
               exportBtn.className = 'btn btn-success';
               exportBtn.innerHTML = '<i class="fas fa-download"></i> Exportar CSV';
               exportBtn.onclick = exportarCSV;
               
               actionButtons.appendChild(printBtn);
               actionButtons.appendChild(exportBtn);
           }
       });
       <?php endif; ?>
   </script>
</body>
</html>
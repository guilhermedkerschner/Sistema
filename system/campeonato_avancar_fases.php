<?php
// campeonato_avancar_fases.php
session_start();

if (!isset($_SESSION['usersystem_logado'])) {
    header("Location: ../acessdeniedrestrict.php"); 
    exit;
}

require_once "../lib/config.php";
require_once "./core/MenuManager.php";
require_once 'funcoes_classificacao.php';

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
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar dados do usuário: " . $e->getMessage());
    header("Location: ../acessdeniedrestrict.php");
    exit;
}

// Verificar permissões
$is_admin = ($usuario_dados['usuario_nivel_id'] == 1);
$usuario_departamento = strtoupper($usuario_dados['usuario_departamento'] ?? '');
$tem_permissao = $is_admin || $usuario_departamento === 'ESPORTE';

if (!$tem_permissao) {
    header("Location: dashboard.php?erro=acesso_negado");
    exit;
}

// Inicializar MenuManager
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

// Processamento das ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao_post = $_POST['acao'] ?? '';
    
    try {
        $conn->beginTransaction();
        
        if ($acao_post === 'avancar_fase') {
            $classificados_por_chave = $_POST['classificados'] ?? [];
            $criterio_avanco = $_POST['criterio_avanco'] ?? 'primeiros_colocados';
            
            if (empty($classificados_por_chave)) {
                throw new Exception("Nenhuma equipe selecionada para avançar.");
            }
            
            // Criar nova fase
            $equipes_classificadas = [];
            foreach ($classificados_por_chave as $chave_id => $equipes_ids) {
                foreach ($equipes_ids as $equipe_id) {
                    $equipes_classificadas[] = (int)$equipe_id;
                }
            }
            
            $total_classificados = count($equipes_classificadas);
            error_log("Total de classificados: $total_classificados");
            
            // NOVA LÓGICA: Determinar a fase correta baseada no número de equipes
            $nova_fase_correta = determinarFaseCorreta($total_classificados);
            
            // Log para debug
            error_log("Fase atual: $fase_atual");
            error_log("Total classificados: $total_classificados");
            error_log("Nova fase determinada: $nova_fase_correta");
            
            // Determinar estrutura da nova fase
            $nova_estrutura = determinarEstruturaNovaFase($total_classificados, $nova_fase_correta);
            
            // Criar novas chaves
            foreach ($nova_estrutura as $info_chave) {
                $stmt = $conn->prepare("
                    INSERT INTO tb_campeonato_chaves 
                    (campeonato_id, fase, chave_numero, chave_nome, equipes_por_chave, status_chave) 
                    VALUES (:campeonato_id, :fase, :chave_numero, :chave_nome, :equipes_por_chave, 'ATIVA')
                ");
                
                $stmt->bindValue(':campeonato_id', $campeonato_id);
                $stmt->bindValue(':fase', $nova_fase_correta); // Usar a fase correta
                $stmt->bindValue(':chave_numero', $info_chave['numero']);
                $stmt->bindValue(':chave_nome', $info_chave['nome']);
                $stmt->bindValue(':equipes_por_chave', $info_chave['equipes_por_chave']);
                $stmt->execute();
                
                $nova_chave_id = $conn->lastInsertId();
                
                // Mover equipes para nova chave
                $equipes_desta_chave = array_slice($equipes_classificadas, 
                    ($info_chave['numero'] - 1) * $info_chave['equipes_por_chave'], 
                    $info_chave['equipes_por_chave']
                );
                
                foreach ($equipes_desta_chave as $equipe_id) {
                    $stmt = $conn->prepare("UPDATE tb_campeonato_equipes SET chave_id = :nova_chave_id WHERE equipe_id = :equipe_id");
                    $stmt->bindValue(':nova_chave_id', $nova_chave_id);
                    $stmt->bindValue(':equipe_id', $equipe_id);
                    $stmt->execute();
                }
                
                // Gerar partidas da nova chave baseado no tipo de fase
                if ($nova_fase_correta >= 3) { // Semifinal ou Final
                    gerarPartidasEliminatorias($conn, $campeonato_id, $nova_chave_id, $equipes_desta_chave);
                } else {
                    gerarPartidasChave($conn, $campeonato_id, $nova_chave_id, $equipes_desta_chave);
                }
            }
            
            $conn->commit();
            
            $mensagem = "Fase avançada com sucesso! {$total_classificados} equipes classificadas para " . getNomeFase($nova_fase_correta) . ".";
            $tipo_mensagem = "success";
            
            // Redirecionar para ver as novas chaves
            header("Location: campeonato_chaves.php?campeonato_id=" . $campeonato_id . "&mensagem=" . urlencode($mensagem) . "&tipo=success");
            exit;
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        $mensagem = "Erro ao avançar fase: " . $e->getMessage();
        $tipo_mensagem = "error";
        error_log("Erro ao avançar fase: " . $e->getMessage());
    }
}

// Buscar fase atual e chaves
$fase_atual = 1;
$chaves_atuais = [];
try {
    $stmt = $conn->prepare("
        SELECT MAX(fase) as fase_atual 
        FROM tb_campeonato_chaves 
        WHERE campeonato_id = :campeonato_id
    ");
    $stmt->bindValue(':campeonato_id', $campeonato_id);
    $stmt->execute();
    
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    $fase_atual = $resultado['fase_atual'] ?? 1;
    
    // Buscar chaves da fase atual
    $stmt = $conn->prepare("
        SELECT * FROM tb_campeonato_chaves 
        WHERE campeonato_id = :campeonato_id AND fase = :fase_atual
        ORDER BY chave_numero
    ");
    $stmt->bindValue(':campeonato_id', $campeonato_id);
    $stmt->bindValue(':fase_atual', $fase_atual);
    $stmt->execute();
    $chaves_atuais = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Erro ao buscar chaves: " . $e->getMessage());
}

// Buscar classificação de cada chave para mostrar os classificáveis
$classificacoes_chaves = [];
foreach ($chaves_atuais as $chave) {
    $classificacao = buscarClassificacaoChave($conn, $campeonato_id, $chave['chave_id']);
    if (!empty($classificacao)) {
        $classificacoes_chaves[$chave['chave_id']] = [
            'chave' => $chave,
            'classificacao' => $classificacao
        ];
    }
}

// Verificar se todas as partidas da fase atual estão finalizadas
$fase_finalizada = verificarFaseFinalizada($conn, $campeonato_id, $fase_atual);

// Funções auxiliares
function determinarEstruturaNovaFase($total_equipes, $nova_fase) {
    $estruturas = [];
    
    // Determinar qual fase realmente deve ser baseado no número de equipes
    $fase_real = determinarFaseCorreta($total_equipes);
    
    if ($fase_real == 3) { // Semifinal (4 equipes)
        $estruturas[] = [
            'numero' => 1,
            'nome' => "Semifinal",
            'equipes_por_chave' => $total_equipes
        ];
    } elseif ($fase_real == 4) { // Final (2 equipes)
        $estruturas[] = [
            'numero' => 1,
            'nome' => "Final",
            'equipes_por_chave' => $total_equipes
        ];
    } elseif ($fase_real == 2) { // Quartas (8+ equipes)
        $chaves_necessarias = ceil($total_equipes / 4);
        for ($i = 1; $i <= $chaves_necessarias; $i++) {
            $estruturas[] = [
                'numero' => $i,
                'nome' => "Quartas - Chave $i",
                'equipes_por_chave' => min(4, $total_equipes - (($i - 1) * 4))
            ];
        }
    }
    
    return $estruturas;
}

// Nova função para determinar a fase correta baseada no número de equipes
function determinarFaseCorreta($total_equipes) {
    if ($total_equipes == 2) {
        return 4; // Final
    } elseif ($total_equipes == 4) {
        return 3; // Semifinal
    } elseif ($total_equipes >= 6 && $total_equipes <= 8) {
        return 2; // Quartas (mas pode ir direto para semifinal se for exatamente 4 classificados)
    } else {
        return 2; // Quartas para casos com mais equipes
    }
}

function buscarClassificacaoChave($conn, $campeonato_id, $chave_id) {
    // Usar a mesma função que já existe, mas para chave específica
    $config = [
        'pontos_vitoria' => 3,
        'pontos_empate' => 1,
        'pontos_derrota' => 0,
        'criterio_desempate_1' => 'SALDO_GOLS',
        'criterio_desempate_2' => 'GOLS_MARCADOS',
        'criterio_desempate_3' => 'MENOS_GOLS_SOFRIDOS'
    ];
    
    return calcularClassificacaoTempoReal($conn, $campeonato_id, $chave_id, $config);
}

function verificarFaseFinalizada($conn, $campeonato_id, $fase) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total_partidas,
                   SUM(CASE WHEN p.status_partida = 'FINALIZADA' THEN 1 ELSE 0 END) as finalizadas
            FROM tb_campeonato_partidas p
            INNER JOIN tb_campeonato_chaves c ON p.chave_id = c.chave_id
            WHERE c.campeonato_id = :campeonato_id AND c.fase = :fase
        ");
        $stmt->bindValue(':campeonato_id', $campeonato_id);
        $stmt->bindValue(':fase', $fase);
        $stmt->execute();
        
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $resultado['total_partidas'] > 0 && $resultado['total_partidas'] == $resultado['finalizadas'];
        
    } catch (PDOException $e) {
        error_log("Erro ao verificar fase: " . $e->getMessage());
        return false;
    }
}

function gerarPartidasEliminatorias($conn, $campeonato_id, $chave_id, $equipes) {
    $total_equipes = count($equipes);
    
    if ($total_equipes == 2) {
        // Final - apenas 1 partida
        $stmt = $conn->prepare("
            INSERT INTO tb_campeonato_partidas 
            (campeonato_id, chave_id, equipe1_id, equipe2_id, status_partida, rodada) 
            VALUES (:campeonato_id, :chave_id, :equipe1_id, :equipe2_id, 'AGENDADA', 1)
        ");
        
        $stmt->bindValue(':campeonato_id', $campeonato_id);
        $stmt->bindValue(':chave_id', $chave_id);
        $stmt->bindValue(':equipe1_id', $equipes[0]);
        $stmt->bindValue(':equipe2_id', $equipes[1]);
        $stmt->execute();
        
    } elseif ($total_equipes == 4) {
        // Semifinal - 2 partidas (1º vs 4º, 2º vs 3º)
        $confrontos = [
            [$equipes[0], $equipes[3]], // 1º vs 4º
            [$equipes[1], $equipes[2]]  // 2º vs 3º
        ];
        
        foreach ($confrontos as $confronto) {
            $stmt = $conn->prepare("
                INSERT INTO tb_campeonato_partidas 
                (campeonato_id, chave_id, equipe1_id, equipe2_id, status_partida, rodada) 
                VALUES (:campeonato_id, :chave_id, :equipe1_id, :equipe2_id, 'AGENDADA', 1)
            ");
            
            $stmt->bindValue(':campeonato_id', $campeonato_id);
            $stmt->bindValue(':chave_id', $chave_id);
            $stmt->bindValue(':equipe1_id', $confronto[0]);
            $stmt->bindValue(':equipe2_id', $confronto[1]);
            $stmt->execute();
        }
    }
}

function gerarPartidasChave($conn, $campeonato_id, $chave_id, $equipes_ids) {
    // Buscar dados completos das equipes
    $equipes = [];
    foreach ($equipes_ids as $equipe_id) {
        $stmt = $conn->prepare("SELECT * FROM tb_campeonato_equipes WHERE equipe_id = :id");
        $stmt->bindValue(':id', $equipe_id);
        $stmt->execute();
        $equipe = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($equipe) {
            $equipes[] = $equipe;
        }
    }
    
    $total_equipes = count($equipes);
    
    // Gerar todas as combinações possíveis (todos contra todos)
    for ($i = 0; $i < $total_equipes; $i++) {
        for ($j = $i + 1; $j < $total_equipes; $j++) {
            $stmt = $conn->prepare("
                INSERT INTO tb_campeonato_partidas 
                (campeonato_id, chave_id, equipe1_id, equipe2_id, status_partida, rodada) 
                VALUES (:campeonato_id, :chave_id, :equipe1_id, :equipe2_id, 'AGENDADA', 1)
            ");
            
            $stmt->bindValue(':campeonato_id', $campeonato_id);
            $stmt->bindValue(':chave_id', $chave_id);
            $stmt->bindValue(':equipe1_id', $equipes[$i]['equipe_id']);
            $stmt->bindValue(':equipe2_id', $equipes[$j]['equipe_id']);
            $stmt->execute();
        }
    }
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

// Incluir as funções de classificação do arquivo anterior
require_once 'funcoes_classificacao.php'; // Você precisará criar este arquivo com as funções

// Mensagens da URL
if (isset($_GET['mensagem'])) {
    $mensagem = $_GET['mensagem'];
    $tipo_mensagem = $_GET['tipo'] ?? 'info';
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Avançar Fases - <?php echo htmlspecialchars($campeonato['campeonato_nome']); ?></title>
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

        .classificacao-chave {
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            overflow: hidden;
        }

        .chave-header {
            background: var(--info-color);
            color: white;
            padding: 15px 20px;
            font-weight: 600;
        }

        .classificacao-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .classificacao-table th,
        .classificacao-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .classificacao-table th {
            background: #f8f9fa;
            font-weight: 600;
            text-align: center;
        }

        .classificacao-table td:first-child {
            font-weight: 600;
        }

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

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-warning {
            background-color: var(--warning-color);
            color: white;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .checkbox-equipe {
            margin-right: 10px;
            transform: scale(1.2);
        }

        .equipe-selecionada {
            background-color: #e3f2fd !important;
        }

        .form-group {
            margin-bottom: 20px;
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

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin: 30px 0;
            flex-wrap: wrap;
        }

        .fase-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid var(--info-color);
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
        
        <?php echo $menuManager->generateSidebar('campeonato_avancar_fases.php'); ?>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="header">
            <div>
                <button class="mobile-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Avançar Fases do Campeonato</h2>
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
                <i class="fas fa-level-up-alt"></i>
                Avançar Fases do Campeonato
            </h1>

            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <a href="esporte.php">Esporte</a>
                <i class="fas fa-chevron-right"></i>
                <a href="esporte_campeonatos.php">Campeonatos</a>
                <i class="fas fa-chevron-right"></i>
                <a href="campeonato_classificacao.php?campeonato_id=<?php echo $campeonato_id; ?>">Classificação</a>
                <i class="fas fa-chevron-right"></i>
                <span>Avançar Fases</span>
            </div>

            <!-- Informações do campeonato -->
            <div class="fase-info">
                <h3><?php echo htmlspecialchars($campeonato['campeonato_nome']); ?></h3>
                <p><strong>Fase Atual:</strong> <?php echo getNomeFase($fase_atual); ?></p>
                <p><strong>Total de Chaves:</strong> <?php echo count($chaves_atuais); ?></p>
                <p><strong>Status da Fase:</strong> 
                    <?php if ($fase_finalizada): ?>
                        <span style="color: var(--success-color);">✓ Todas as partidas finalizadas</span>
                    <?php else: ?>
                        <span style="color: var(--warning-color);">⚠ Ainda há partidas pendentes</span>
                    <?php endif; ?>
                </p>
            </div>

            <!-- Mensagens -->
            <?php if ($mensagem): ?>
            <div class="alert alert-<?php echo $tipo_mensagem == 'success' ? 'success' : ($tipo_mensagem == 'warning' ? 'warning' : 'error'); ?>">
                <i class="fas fa-<?php echo $tipo_mensagem == 'success' ? 'check-circle' : ($tipo_mensagem == 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
                <?php echo $mensagem; ?>
            </div>
            <?php endif; ?>

            <?php if (!$fase_finalizada): ?>
            <!-- Aviso sobre partidas pendentes -->
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Atenção:</strong> Ainda existem partidas não finalizadas na fase atual. 
                Recomenda-se finalizar todas as partidas antes de avançar para a próxima fase.
            </div>
            <?php endif; ?>

            <?php if (empty($classificacoes_chaves)): ?>
            <!-- Estado vazio -->
            <div class="card">
                <div class="card-body" style="text-align: center; padding: 60px;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 4rem; color: #6c757d; margin-bottom: 20px;"></i>
                    <h3>Não é possível avançar fase</h3>
                    <p>Não há chaves ou classificações disponíveis para avançar de fase.<br>
                    Certifique-se de que as chaves foram criadas e as partidas foram jogadas.</p>
                    
                    <div style="margin-top: 30px;">
                        <a href="campeonato_chaves.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-primary">
                            <i class="fas fa-sitemap"></i> Ver Chaves
                        </a>
                        <a href="campeonato_partidas.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-warning">
                            <i class="fas fa-calendar-alt"></i> Ver Partidas
                        </a>
                    </div>
                </div>
            </div>
            <?php else: ?>

            <!-- Formulário para avançar fase -->
            <form method="POST" action="">
                <input type="hidden" name="acao" value="avancar_fase">
                <input type="hidden" name="nova_fase" value="<?php echo $fase_atual + 1; ?>">

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fas fa-users"></i>
                            Selecionar Equipes Classificadas
                        </div>
                    </div>
                    <div class="card-body">
                        <p>Selecione as equipes que devem avançar para <strong><?php echo getNomeFase($fase_atual + 1); ?></strong>:</p>

                        <?php foreach ($classificacoes_chaves as $dados_chave): ?>
                        <div class="classificacao-chave">
                            <div class="chave-header">
                                <i class="fas fa-layer-group"></i>
                                <?php echo htmlspecialchars($dados_chave['chave']['chave_nome']); ?>
                            </div>
                            
                            <table class="classificacao-table">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">Selecionar</th>
                                        <th>Pos</th>
                                        <th>Equipe</th>
                                        <th>Pts</th>
                                        <th>J</th>
                                        <th>V</th>
                                        <th>E</th>
                                        <th>D</th>
                                        <th>SG</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dados_chave['classificacao'] as $index => $equipe): ?>
                                    <tr id="equipe-<?php echo $equipe['equipe_id']; ?>" <?php echo $index < 2 ? 'class="equipe-pre-selecionada"' : ''; ?>>
                                        <td style="text-align: center;">
                                            <input type="checkbox" 
                                                   class="checkbox-equipe" 
                                                   name="classificados[<?php echo $dados_chave['chave']['chave_id']; ?>][]" 
                                                   value="<?php echo $equipe['equipe_id']; ?>"
                                                   <?php echo $index < 2 ? 'checked' : ''; ?>
                                                   onchange="toggleEquipeSelecionada(<?php echo $equipe['equipe_id']; ?>)">
                                        </td>
                                        <td style="text-align: center;"><strong><?php echo $equipe['posicao']; ?>º</strong></td>
                                        <td><?php echo htmlspecialchars($equipe['equipe_nome']); ?></td>
                                        <td style="text-align: center;"><strong><?php echo $equipe['pontos']; ?></strong></td>
                                       <td style="text-align: center;"><?php echo $equipe['jogos']; ?></td>
                                       <td style="text-align: center;"><?php echo $equipe['vitorias']; ?></td>
                                       <td style="text-align: center;"><?php echo $equipe['empates']; ?></td>
                                       <td style="text-align: center;"><?php echo $equipe['derrotas']; ?></td>
                                       <td style="text-align: center;"><?php echo ($equipe['saldo_gols'] >= 0 ? '+' : '') . $equipe['saldo_gols']; ?></td>
                                   </tr>
                                   <?php endforeach; ?>
                               </tbody>
                           </table>
                       </div>
                       <?php endforeach; ?>

                       <div class="form-group">
                           <label for="criterio_avanco">Critério de Avanço:</label>
                           <select class="form-control" id="criterio_avanco" name="criterio_avanco">
                               <option value="primeiros_colocados">Primeiros Colocados de cada Chave</option>
                               <option value="melhor_campanha">Melhor Campanha Geral</option>
                               <option value="personalizado">Seleção Personalizada</option>
                           </select>
                       </div>

                       <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin: 20px 0;">
                            <h5 style="color: var(--info-color); margin-bottom: 10px;">
                                <i class="fas fa-info-circle"></i> Informações sobre o Avanço
                            </h5>
                            
                            <?php 
                            // Calcular quantas equipes serão selecionadas por padrão
                            $equipes_por_chave = 2; // Padrão: 2 primeiros de cada chave
                            $total_chaves = count($chaves_atuais);
                            $total_classificados_esperado = $total_chaves * $equipes_por_chave;
                            $proxima_fase_esperada = determinarFaseCorreta($total_classificados_esperado);
                            ?>
                            
                            <p><strong>Próxima Fase:</strong> <?php echo getNomeFase($proxima_fase_esperada); ?></p>
                            <p><strong>Situação:</strong>
                                <?php if ($total_chaves == 2 && $total_classificados_esperado == 4): ?>
                                    Com 2 chaves, os 4 classificados (2 de cada) vão direto para a <strong>Semifinal</strong>.
                                <?php elseif ($total_chaves >= 3): ?>
                                    Com <?php echo $total_chaves; ?> chaves, os classificados irão para as <strong>Quartas de Final</strong>.
                                <?php else: ?>
                                    Configuração especial do campeonato.
                                <?php endif; ?>
                            </p>
                            <p><strong>Recomendação:</strong> 
                                <?php if ($fase_atual == 1): ?>
                                    Normalmente avançam os 2 primeiros colocados de cada chave.
                                <?php elseif ($fase_atual == 2): ?>
                                    Normalmente avançam os vencedores de cada chave.
                                <?php else: ?>
                                    Consulte o regulamento do campeonato.
                                <?php endif; ?>
                            </p>
                            <p id="equipes-selecionadas-info"><strong>Equipes selecionadas:</strong> <span id="contador-selecionadas">0</span></p>
                        </div>
                   </div>
               </div>

               <div class="action-buttons">
                   <a href="campeonato_classificacao.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-secondary">
                       <i class="fas fa-arrow-left"></i> Voltar à Classificação
                   </a>
                   
                   <button type="button" class="btn btn-warning" onclick="selecionarPrimeiros()">
                       <i class="fas fa-magic"></i> Selecionar 2 Primeiros de Cada Chave
                   </button>
                   
                   <button type="submit" class="btn btn-success" onclick="return confirmarAvanco()">
                       <i class="fas fa-level-up-alt"></i> Avançar para <?php echo getNomeFase($fase_atual + 1); ?>
                   </button>
               </div>
           </form>

           <?php endif; ?>

           <!-- Links rápidos -->
           <div style="margin-top: 40px; text-align: center;">
               <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                   <a href="esporte_campeonatos.php" class="btn btn-secondary">
                       <i class="fas fa-arrow-left"></i> Voltar aos Campeonatos
                   </a>
                   
                   <a href="campeonato_chaves.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-primary">
                       <i class="fas fa-sitemap"></i> Ver Chaves
                   </a>
                   
                   <a href="campeonato_partidas.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-warning">
                       <i class="fas fa-calendar-alt"></i> Ver Partidas
                   </a>
               </div>
           </div>
       </div>
   </div>

   <script>
       // Contar equipes selecionadas
       function atualizarContador() {
           const checkboxes = document.querySelectorAll('.checkbox-equipe:checked');
           document.getElementById('contador-selecionadas').textContent = checkboxes.length;
       }

       // Toggle visual da equipe selecionada
       function toggleEquipeSelecionada(equipeId) {
           const row = document.getElementById('equipe-' + equipeId);
           const checkbox = row.querySelector('.checkbox-equipe');
           
           if (checkbox.checked) {
               row.classList.add('equipe-selecionada');
           } else {
               row.classList.remove('equipe-selecionada');
           }
           
           atualizarContador();
       }

       // Selecionar automaticamente os 2 primeiros de cada chave
       function selecionarPrimeiros() {
           // Desmarcar todos primeiro
           document.querySelectorAll('.checkbox-equipe').forEach(cb => {
               cb.checked = false;
               const equipeId = cb.value;
               const row = document.getElementById('equipe-' + equipeId);
               row.classList.remove('equipe-selecionada');
           });
           
           // Marcar os 2 primeiros de cada tabela
           document.querySelectorAll('.classificacao-chave').forEach(chave => {
               const checkboxes = chave.querySelectorAll('.checkbox-equipe');
               for (let i = 0; i < Math.min(2, checkboxes.length); i++) {
                   checkboxes[i].checked = true;
                   const equipeId = checkboxes[i].value;
                   const row = document.getElementById('equipe-' + equipeId);
                   row.classList.add('equipe-selecionada');
               }
           });
           
           atualizarContador();
       }

       // Confirmação antes de avançar
       function confirmarAvanco() {
           const selecionadas = document.querySelectorAll('.checkbox-equipe:checked').length;
           
           if (selecionadas === 0) {
               alert('Selecione pelo menos uma equipe para avançar de fase!');
               return false;
           }
           
           const proximaFase = '<?php echo getNomeFase($fase_atual + 1); ?>';
           const mensagem = `Confirma o avanço de ${selecionadas} equipe(s) para ${proximaFase}?\n\nEsta ação criará novas chaves e partidas.`;
           
           return confirm(mensagem);
       }

       // Gerenciar critério de avanço
       document.addEventListener('DOMContentLoaded', function() {
           const criterioSelect = document.getElementById('criterio_avanco');
           
           criterioSelect.addEventListener('change', function() {
               const valor = this.value;
               
               if (valor === 'primeiros_colocados') {
                   selecionarPrimeiros();
               } else if (valor === 'melhor_campanha') {
                   // Implementar lógica para melhor campanha geral
                   alert('Funcionalidade em desenvolvimento. Use a seleção personalizada.');
               }
               // Para 'personalizado', deixar como está
           });
           
           // Marcar equipes pré-selecionadas
           document.querySelectorAll('.equipe-pre-selecionada .checkbox-equipe').forEach(checkbox => {
               const equipeId = checkbox.value;
               toggleEquipeSelecionada(equipeId);
           });
           
           // Contador inicial
           atualizarContador();
       });
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
        });
   </script>
</body>
</html>
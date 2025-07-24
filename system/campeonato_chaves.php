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

// Obter ID do campeonato
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
    // CORRIGIDO: Tabela correta é tb_campeonatos, não tb_campeonatos_equipes
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

// Buscar equipes do campeonato
// Buscar equipes do campeonato
$equipes = [];
try {
    // CORRIGIDO: Verificar se a tabela de atletas existe, se não, usar contagem 0
    $stmt = $conn->prepare("
        SELECT e.*,
               COALESCE((
                   SELECT COUNT(*) 
                   FROM tb_campeonato_equipe_atletas ca 
                   WHERE ca.equipe_id = e.equipe_id
               ), 0) as total_atletas
        FROM tb_campeonato_equipes e 
        WHERE e.campeonato_id = :campeonato_id 
        ORDER BY e.equipe_nome
    ");
    $stmt->bindValue(':campeonato_id', $campeonato_id);
    $stmt->execute();
    $equipes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // DEBUG: Verificar se está buscando as equipes
    error_log("Buscando equipes para campeonato_id: $campeonato_id - Encontradas: " . count($equipes));
    
} catch (PDOException $e) {
    error_log("Erro ao buscar equipes: " . $e->getMessage());
    
    // Tentar consulta mais simples se a primeira falhar
    try {
        $stmt = $conn->prepare("
            SELECT e.*, 0 as total_atletas
            FROM tb_campeonato_equipes e 
            WHERE e.campeonato_id = :campeonato_id 
            ORDER BY e.equipe_nome
        ");
        $stmt->bindValue(':campeonato_id', $campeonato_id);
        $stmt->execute();
        $equipes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        
        error_log("Consulta simples - Equipes encontradas: " . count($equipes));
        
    } catch (PDOException $e2) {
        error_log("Erro na consulta simples de equipes: " . $e2->getMessage());
        $mensagem = "Erro ao buscar equipes do campeonato.";
        $tipo_mensagem = "error";
    }
}

$total_equipes = count($equipes);
if ($total_equipes < 4) {
    $mensagem = "É necessário ter pelo menos 4 equipes para gerar as chaves do campeonato.";
    $tipo_mensagem = "warning";
}
// Buscar chaves existentes
$chaves_existentes = [];
$partidas = [];
try {
    // Verificar se já existem chaves geradas
    $stmt = $conn->prepare("
        SELECT * FROM tb_campeonato_chaves 
        WHERE campeonato_id = :campeonato_id 
        ORDER BY fase, chave_numero
    ");
    $stmt->bindValue(':campeonato_id', $campeonato_id);
    $stmt->execute();
    $chaves_existentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar partidas das chaves
    if (!empty($chaves_existentes)) {
        $stmt = $conn->prepare("
            SELECT p.*, 
                   e1.equipe_nome as equipe1_nome,
                   e2.equipe_nome as equipe2_nome,
                   c.fase, c.chave_numero
            FROM tb_campeonato_partidas p
            LEFT JOIN tb_campeonato_equipes e1 ON p.equipe1_id = e1.equipe_id
            LEFT JOIN tb_campeonato_equipes e2 ON p.equipe2_id = e2.equipe_id
            LEFT JOIN tb_campeonato_chaves c ON p.chave_id = c.chave_id
            WHERE p.campeonato_id = :campeonato_id
            ORDER BY c.fase DESC, c.chave_numero, p.partida_data
        ");
        $stmt->bindValue(':campeonato_id', $campeonato_id);
        $stmt->execute();
        $partidas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar chaves: " . $e->getMessage());
}



// Processamento de ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   // Pegar a ação do POST, não do GET
   $acao_post = $_POST['acao'] ?? '';
   
   // DEBUG: Verificar o que está sendo enviado
   error_log("POST recebido: " . print_r($_POST, true));
   error_log("Total de equipes: $total_equipes");
   error_log("Ação POST: " . $acao_post);
   
   try {
       $conn->beginTransaction();
       
       if ($acao_post === 'gerar_chaves' && $total_equipes >= 4) {
           error_log("Iniciando geração de chaves...");
           
           // Limpar chaves existentes se necessário
           if (!empty($chaves_existentes)) {
               error_log("Limpando chaves existentes...");
               
               $stmt = $conn->prepare("DELETE FROM tb_campeonato_partidas WHERE campeonato_id = :campeonato_id");
               $stmt->bindValue(':campeonato_id', $campeonato_id);
               $stmt->execute();
               error_log("Partidas removidas: " . $stmt->rowCount());
               
               $stmt = $conn->prepare("DELETE FROM tb_campeonato_chaves WHERE campeonato_id = :campeonato_id");
               $stmt->bindValue(':campeonato_id', $campeonato_id);
               $stmt->execute();
               error_log("Chaves removidas: " . $stmt->rowCount());
           }
           
           // Embaralhar equipes para sorteio
           shuffle($equipes);
           error_log("Equipes embaralhadas: " . count($equipes));
           
           // Determinar estrutura do campeonato baseado no número de equipes
           $estrutura_chaves = determinarEstrutura($total_equipes);
           error_log("Estrutura determinada: " . print_r($estrutura_chaves, true));
           
           // Gerar chaves
           $chaves_geradas = 0;
           foreach ($estrutura_chaves as $fase => $info_fase) {
               error_log("Processando fase $fase com " . $info_fase['total_chaves'] . " chaves");
               
               for ($i = 0; $i < $info_fase['total_chaves']; $i++) {
                   $stmt = $conn->prepare("
                       INSERT INTO tb_campeonato_chaves 
                       (campeonato_id, fase, chave_numero, chave_nome, equipes_por_chave, status_chave) 
                       VALUES (:campeonato_id, :fase, :chave_numero, :chave_nome, :equipes_por_chave, 'ATIVA')
                   ");
                   
                   $chave_numero = $i + 1;
                   $chave_nome = $info_fase['nome'] . " - Chave " . $chave_numero;
                   
                   $stmt->bindValue(':campeonato_id', $campeonato_id);
                   $stmt->bindValue(':fase', $fase);
                   $stmt->bindValue(':chave_numero', $chave_numero);
                   $stmt->bindValue(':chave_nome', $chave_nome);
                   $stmt->bindValue(':equipes_por_chave', $info_fase['equipes_por_chave']);
                   $stmt->execute();
                   
                   $chave_id = $conn->lastInsertId();
                   $chaves_geradas++;
                   
                   error_log("Chave criada: ID=$chave_id, Nome=$chave_nome");
                   
                   // Distribuir equipes para a primeira fase
                   if ($fase === 1) {
                       $inicio_equipes = $i * $info_fase['equipes_por_chave'];
                       $equipes_chave = array_slice($equipes, $inicio_equipes, $info_fase['equipes_por_chave']);
                       
                       error_log("Equipes para chave $chave_id: " . count($equipes_chave) . " equipes");
                       
                       // Gerar partidas para esta chave
                       $partidas_geradas = gerarPartidasChave($conn, $campeonato_id, $chave_id, $equipes_chave);
                       error_log("Partidas geradas para chave $chave_id: $partidas_geradas");
                   }
               }
           }
           
           $conn->commit();
           error_log("Chaves geradas com sucesso! Total: $chaves_geradas");
           
           $mensagem = "Chaves geradas com sucesso! ($chaves_geradas chaves criadas)";
           $tipo_mensagem = "success";
           
           // Recarregar dados
           header("Location: campeonato_chaves.php?campeonato_id=" . $campeonato_id . "&mensagem=" . urlencode($mensagem) . "&tipo=success");
           exit;
           
       } elseif ($acao_post === 'regerar_chaves') {
           error_log("Regenerando chaves...");
           
           // Regerar chaves (limpar tudo e gerar novamente)
           $stmt = $conn->prepare("DELETE FROM tb_campeonato_partidas WHERE campeonato_id = :campeonato_id");
           $stmt->bindValue(':campeonato_id', $campeonato_id);
           $stmt->execute();
           
           $stmt = $conn->prepare("DELETE FROM tb_campeonato_chaves WHERE campeonato_id = :campeonato_id");
           $stmt->bindValue(':campeonato_id', $campeonato_id);
           $stmt->execute();
           
           $conn->commit();
           
           $mensagem = "Chaves removidas. Você pode gerar novas chaves agora.";
           $tipo_mensagem = "success";
           
           header("Location: campeonato_chaves.php?campeonato_id=" . $campeonato_id . "&mensagem=" . urlencode($mensagem) . "&tipo=success");
           exit;
       } else {
           error_log("Condições não atendidas - Ação: $acao_post, Total equipes: $total_equipes");
           $mensagem = "Erro: Condições não atendidas para gerar chaves. Ação: $acao_post, Equipes: $total_equipes";
           $tipo_mensagem = "error";
       }
       
   } catch (Exception $e) {
       $conn->rollBack();
       $mensagem = "Erro ao processar chaves: " . $e->getMessage();
       $tipo_mensagem = "error";
       error_log("Erro ao processar chaves: " . $e->getMessage());
       error_log("Stack trace: " . $e->getTraceAsString());
   }
}

// Função para determinar estrutura do campeonato
function determinarEstrutura($total_equipes) {
    $estruturas = [];
    
    if ($total_equipes <= 8) {
        // Campeonato simples - uma fase com grupos
        $grupos = ceil($total_equipes / 4);
        $estruturas[1] = [
            'nome' => 'Fase de Grupos',
            'total_chaves' => $grupos,
            'equipes_por_chave' => min(4, $total_equipes)
        ];
    } else {
        // Campeonato com múltiplas fases
        $estruturas[1] = [
            'nome' => 'Primeira Fase',
            'total_chaves' => ceil($total_equipes / 4),
            'equipes_por_chave' => 4
        ];
        
        if ($total_equipes > 8) {
            $estruturas[2] = [
                'nome' => 'Quartas de Final',
                'total_chaves' => 2,
                'equipes_por_chave' => 4
            ];
        }
        
        if ($total_equipes > 16) {
            $estruturas[3] = [
                'nome' => 'Semifinal',
                'total_chaves' => 1,
                'equipes_por_chave' => 4
            ];
        }
    }
    
    return $estruturas;
}

// Função para gerar partidas de uma chave
function gerarPartidasChave($conn, $campeonato_id, $chave_id, $equipes_chave) {
    $total_equipes = count($equipes_chave);
    $partidas_geradas = 0;
    
    error_log("Gerando partidas para chave $chave_id com $total_equipes equipes");
    
    // PRIMEIRO: Atualizar as equipes com o chave_id (ESTA PARTE ESTAVA FALTANDO!)
    foreach ($equipes_chave as $equipe) {
        try {
            $stmt = $conn->prepare("UPDATE tb_campeonato_equipes SET chave_id = :chave_id WHERE equipe_id = :equipe_id");
            $stmt->bindValue(':chave_id', $chave_id);
            $stmt->bindValue(':equipe_id', $equipe['equipe_id']);
            $stmt->execute();
            
            error_log("Equipe {$equipe['equipe_nome']} (ID: {$equipe['equipe_id']}) atribuída à chave $chave_id");
            
        } catch (PDOException $e) {
            error_log("Erro ao atribuir equipe {$equipe['equipe_id']} à chave $chave_id: " . $e->getMessage());
        }
    }
    
    // SEGUNDO: Gerar todas as combinações possíveis (todos contra todos)
    for ($i = 0; $i < $total_equipes; $i++) {
        for ($j = $i + 1; $j < $total_equipes; $j++) {
            try {
                $stmt = $conn->prepare("
                    INSERT INTO tb_campeonato_partidas 
                    (campeonato_id, chave_id, equipe1_id, equipe2_id, status_partida, rodada) 
                    VALUES (:campeonato_id, :chave_id, :equipe1_id, :equipe2_id, 'AGENDADA', 1)
                ");
                
                $stmt->bindValue(':campeonato_id', $campeonato_id);
                $stmt->bindValue(':chave_id', $chave_id);
                $stmt->bindValue(':equipe1_id', $equipes_chave[$i]['equipe_id']);
                $stmt->bindValue(':equipe2_id', $equipes_chave[$j]['equipe_id']);
                $stmt->execute();
                
                $partidas_geradas++;
                error_log("Partida criada: {$equipes_chave[$i]['equipe_nome']} vs {$equipes_chave[$j]['equipe_nome']}");
                
            } catch (PDOException $e) {
                error_log("Erro ao criar partida: " . $e->getMessage());
            }
        }
    }
    
    error_log("Total de partidas geradas para chave $chave_id: $partidas_geradas");
    return $partidas_geradas;
}

// Mensagens da URL
if (isset($_GET['mensagem'])) {
    $mensagem = $_GET['mensagem'];
    $tipo_mensagem = $_GET['tipo'] ?? 'info';
}

// Organizar partidas por fase e chave
$partidas_organizadas = [];
foreach ($partidas as $partida) {
    $fase = $partida['fase'];
    $chave = $partida['chave_numero'];
    
    if (!isset($partidas_organizadas[$fase])) {
        $partidas_organizadas[$fase] = [];
    }
    if (!isset($partidas_organizadas[$fase][$chave])) {
        $partidas_organizadas[$fase][$chave] = [];
    }
    
    $partidas_organizadas[$fase][$chave][] = $partida;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chaves do Campeonato - <?php echo htmlspecialchars($campeonato['campeonato_nome']); ?></title>
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

        .campeonato-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .stat-item {
            text-align: center;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            display: block;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.8;
            margin-top: 5px;
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

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin: 30px 0;
            flex-wrap: wrap;
        }

        /* Chaves Layout */
        .chaves-container {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .fase-section {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .fase-header {
            background: linear-gradient(135deg, var(--info-color), #20c997);
            color: white;
            padding: 20px 25px;
            font-size: 1.4rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .chaves-grid {
            padding: 25px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
        }

        .chave-card {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s;
        }

        .chave-card:hover {
            border-color: var(--secondary-color);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .chave-header {
            background: #f8f9fa;
            padding: 15px 20px;
            font-weight: 600;
            color: var(--primary-color);
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .chave-body {
            padding: 0;
        }

        .partida {
            padding: 15px 20px;
            border-bottom: 1px solid #f1f3f4;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: background-color 0.3s;
        }

        .partida:last-child {
            border-bottom: none;
        }

        .partida:hover {
            background-color: #f8f9fa;
        }

        .equipes {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        .equipe {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .equipe-nome {
            flex: 1;
        }

        .placar {
            font-weight: bold;
            font-size: 1.1rem;
            color: var(--secondary-color);
            min-width: 30px;
            text-align: right;
        }

        .vs {
           font-weight: bold;
           color: #6c757d;
           margin: 0 10px;
       }

       .status-partida {
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
           margin: 5% auto;
           padding: 0;
           border-radius: 15px;
           width: 90%;
           max-width: 500px;
           box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
       }

       .modal-header {
           background: linear-gradient(135deg, var(--warning-color), #e67e22);
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

           .campeonato-stats {
               grid-template-columns: repeat(2, 1fr);
           }

           .chaves-grid {
               grid-template-columns: 1fr;
               padding: 15px;
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

           .campeonato-stats {
               grid-template-columns: 1fr;
           }

           .chave-card {
               min-width: auto;
           }
       }
       /* Estilos para lista de equipes nas chaves */
        .equipes-lista {
            padding: 15px;
        }

        .equipe-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 15px;
            margin-bottom: 8px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid var(--secondary-color);
            transition: all 0.3s;
        }

        .equipe-item:hover {
            background: #e3f2fd;
            transform: translateX(5px);
        }

        .equipe-item:last-child {
            margin-bottom: 0;
        }

        .posicao-badge {
            background: var(--secondary-color);
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .equipe-nome-chave {
            font-weight: 500;
            color: var(--primary-color);
            flex: 1;
        }

        .btn-ver-partidas {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: var(--info-color);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-ver-partidas:hover {
            background: #138496;
            transform: translateY(-1px);
            text-decoration: none;
            color: white;
        }

        /* Responsivo */
        @media (max-width: 768px) {
            .equipe-item {
                padding: 8px 12px;
            }
            
            .posicao-badge {
                width: 25px;
                height: 25px;
                font-size: 0.8rem;
            }
            
            .equipe-nome-chave {
                font-size: 0.9rem;
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
       
       <?php echo $menuManager->generateSidebar('campeonato_chaves.php'); ?>
   </div>

   <!-- Main Content -->
   <div class="main-content" id="mainContent">
       <div class="header">
           <div>
               <button class="mobile-toggle">
                   <i class="fas fa-bars"></i>
               </button>
               <h2>Chaves do Campeonato</h2>
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
               <i class="fas fa-sitemap"></i>
               Chaves do Campeonato
           </h1>

           <!-- Breadcrumb -->
           <div class="breadcrumb">
               <a href="dashboard.php">Dashboard</a>
               <i class="fas fa-chevron-right"></i>
               <a href="esporte.php">Esporte</a>
               <i class="fas fa-chevron-right"></i>
               <a href="esporte_campeonatos.php">Campeonatos</a>
               <i class="fas fa-chevron-right"></i>
               <span>Chaves</span>
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
               
               <div class="campeonato-stats">
                   <div class="stat-item">
                       <span class="stat-number"><?php echo $total_equipes; ?></span>
                       <span class="stat-label">Equipes</span>
                   </div>
                   <div class="stat-item">
                       <span class="stat-number"><?php echo count($chaves_existentes); ?></span>
                       <span class="stat-label">Chaves</span>
                   </div>
                   <div class="stat-item">
                       <span class="stat-number"><?php echo count($partidas); ?></span>
                       <span class="stat-label">Partidas</span>
                   </div>
                   <div class="stat-item">
                       <span class="stat-number"><?php echo count(array_filter($partidas, function($p) { return $p['status_partida'] === 'FINALIZADA'; })); ?></span>
                       <span class="stat-label">Finalizadas</span>
                   </div>
               </div>
           </div>

           <!-- Mensagens -->
           <?php if ($mensagem): ?>
           <div class="alert alert-<?php echo $tipo_mensagem == 'success' ? 'success' : ($tipo_mensagem == 'warning' ? 'warning' : 'error'); ?>">
               <i class="fas fa-<?php echo $tipo_mensagem == 'success' ? 'check-circle' : ($tipo_mensagem == 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
               <?php echo $mensagem; ?>
           </div>
           <?php endif; ?>

           <!-- Botões de ação -->
           <?php if ($total_equipes >= 4): ?>
           <div class="action-buttons">
               <a href="campeonato_equipes.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-secondary">
                   <i class="fas fa-users"></i> Gerenciar Equipes
               </a>
               
               <?php if (empty($chaves_existentes)): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="acao" value="gerar_chaves">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Confirma a geração das chaves? Esta ação criará automaticamente todas as partidas.')">
                        <i class="fas fa-random"></i> Gerar Chaves
                    </button>
                </form>
               <?php else: ?>
               <a href="campeonato_partidas.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-primary">
                   <i class="fas fa-calendar-alt"></i> Ver Partidas
               </a>
               
               <form method="POST" style="display: inline;">
                   <input type="hidden" name="acao" value="regerar_chaves">
                   <button type="submit" class="btn btn-warning" onclick="return confirm('Atenção! Esta ação irá remover todas as chaves e partidas existentes. Confirma?')">
                       <i class="fas fa-sync-alt"></i> Regerar Chaves
                   </button>
               </form>
               <?php endif; ?>
           </div>
           <?php endif; ?>

           <?php if ($total_equipes < 4): ?>
           <!-- Estado quando não há equipes suficientes -->
           <div class="empty-state">
               <div class="empty-state-icon">
                   <i class="fas fa-exclamation-triangle"></i>
               </div>
               <h3>Equipes Insuficientes</h3>
               <p>
                   São necessárias pelo menos <strong>4 equipes</strong> para gerar as chaves do campeonato.<br>
                   Atualmente há apenas <strong><?php echo $total_equipes; ?> equipe(s)</strong> cadastrada(s).
               </p>
               
               <a href="campeonato_equipes.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-primary">
                   <i class="fas fa-plus"></i> Cadastrar Mais Equipes
               </a>
           </div>
           
           <?php elseif (empty($chaves_existentes)): ?>
           <!-- Estado quando não há chaves geradas -->
           <div class="empty-state">
               <div class="empty-state-icon">
                   <i class="fas fa-sitemap"></i>
               </div>
               <h3>Chaves Não Geradas</h3>
               <p>
                   As chaves deste campeonato ainda não foram geradas.<br>
                   Clique no botão acima para criar automaticamente as chaves e partidas.
               </p>
               
               <div style="margin-top: 20px; padding: 15px; background: #e3f2fd; border-radius: 8px; color: #1565c0;">
                   <i class="fas fa-info-circle"></i>
                   <strong>Como funciona:</strong> O sistema irá distribuir as <?php echo $total_equipes; ?> equipes automaticamente em chaves e criar todas as partidas necessárias.
               </div>
           </div>
           
           <?php else: ?>
           <!-- Exibir chaves geradas -->
            <div class="chaves-container">
                <?php 
                // Organizar chaves por fase
                $chaves_por_fase = [];
                foreach ($chaves_existentes as $chave) {
                    $fase = $chave['fase'];
                    if (!isset($chaves_por_fase[$fase])) {
                        $chaves_por_fase[$fase] = [];
                    }
                    $chaves_por_fase[$fase][] = $chave;
                }
                
                // Nomes das fases
                $nomes_fases = [
                    1 => 'Primeira Fase',
                    2 => 'Quartas de Final', 
                    3 => 'Semifinais',
                    4 => 'Final'
                ];
                
                foreach ($chaves_por_fase as $numero_fase => $chaves_fase):
                ?>
                <div class="fase-section">
                    <div class="fase-header">
                        <i class="fas fa-layer-group"></i>
                        <?php echo isset($nomes_fases[$numero_fase]) ? $nomes_fases[$numero_fase] : "Fase $numero_fase"; ?>
                    </div>
                    
                    <div class="chaves-grid">
                        <?php foreach ($chaves_fase as $chave): ?>
                        <div class="chave-card">
                            <div class="chave-header">
                                <span>
                                    <i class="fas fa-users"></i>
                                    <?php echo htmlspecialchars($chave['chave_nome']); ?>
                                </span>
                                <span class="status-partida status-<?php echo strtolower($chave['status_chave']); ?>">
                                    <?php echo $chave['status_chave']; ?>
                                </span>
                            </div>
                            
                            <div class="chave-body">
                                <?php 
                                // Buscar equipes desta chave
                                $equipes_chave = [];
                                try {
                                    // Primeiro, verificar se a coluna chave_id existe na tabela de equipes
                                    $stmt_check = $conn->query("SHOW COLUMNS FROM tb_campeonato_equipes LIKE 'chave_id'");
                                    
                                    if ($stmt_check->rowCount() > 0) {
                                        // Se a coluna existe, buscar por chave_id
                                        $stmt_equipes = $conn->prepare("
                                            SELECT e.equipe_nome, e.equipe_id
                                            FROM tb_campeonato_equipes e 
                                            WHERE e.chave_id = :chave_id
                                            ORDER BY e.equipe_nome
                                        ");
                                        $stmt_equipes->bindValue(':chave_id', $chave['chave_id']);
                                        $stmt_equipes->execute();
                                        $equipes_chave = $stmt_equipes->fetchAll(PDO::FETCH_ASSOC);
                                    } else {
                                        // Se a coluna não existe, buscar todas as equipes e distribuir manualmente
                                        // Este é um fallback temporário
                                        $stmt_equipes = $conn->prepare("
                                            SELECT e.equipe_nome, e.equipe_id
                                            FROM tb_campeonato_equipes e 
                                            WHERE e.campeonato_id = :campeonato_id
                                            ORDER BY e.equipe_nome
                                        ");
                                        $stmt_equipes->bindValue(':campeonato_id', $campeonato_id);
                                        $stmt_equipes->execute();
                                        $todas_equipes = $stmt_equipes->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        // Distribuir equipes manualmente (fallback)
                                        $total_chaves = count($chaves_existentes);
                                        $equipes_por_chave = ceil(count($todas_equipes) / $total_chaves);
                                        $inicio = ($chave['chave_numero'] - 1) * $equipes_por_chave;
                                        $equipes_chave = array_slice($todas_equipes, $inicio, $equipes_por_chave);
                                    }
                                    
                                } catch (PDOException $e) {
                                    error_log("Erro ao buscar equipes da chave: " . $e->getMessage());
                                    $equipes_chave = [];
                                }
                                
                                if (empty($equipes_chave)): ?>
                                <div style="text-align: center; padding: 20px; color: #6c757d;">
                                    <i class="fas fa-users-slash"></i><br>
                                    Nenhuma equipe nesta chave
                                </div>
                                <?php else: ?>
                                <div class="equipes-lista">
                                    <?php foreach ($equipes_chave as $index => $equipe): ?>
                                    <div class="equipe-item">
                                        <div class="posicao-badge"><?php echo $index + 1; ?></div>
                                        <div class="equipe-nome-chave"><?php echo htmlspecialchars($equipe['equipe_nome']); ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- Botão para ver partidas desta chave -->
                                <div style="text-align: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e9ecef;">
                                    <a href="campeonato_partidas.php?campeonato_id=<?php echo $campeonato_id; ?>&filtro_fase=<?php echo $numero_fase; ?>" 
                                    class="btn-ver-partidas">
                                        <i class="fas fa-calendar-alt"></i> Ver Partidas
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
           <?php endif; ?>

           <!-- Links rápidos -->
           <div style="margin-top: 40px; text-align: center;">
               <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                   <a href="esporte_campeonatos.php" class="btn btn-secondary">
                       <i class="fas fa-arrow-left"></i> Voltar aos Campeonatos
                   </a>
                   
                   <?php if (!empty($chaves_existentes)): ?>
                   <a href="campeonato_partidas.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-primary">
                       <i class="fas fa-calendar-alt"></i> Gerenciar Partidas
                   </a>
                   
                   <a href="campeonato_classificacao.php?campeonato_id=<?php echo $campeonato_id; ?>" class="btn btn-success">
                       <i class="fas fa-trophy"></i> Ver Classificação
                   </a>
                   <?php endif; ?>
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

           // Auto-refresh para partidas em andamento (opcional)
           const partidasAndamento = document.querySelectorAll('.status-andamento');
           if (partidasAndamento.length > 0) {
               // Atualizar a cada 30 segundos se houver partidas em andamento
               setInterval(function() {
                   // Aqui você poderia fazer uma requisição AJAX para atualizar os placares
                   console.log('Verificando atualizações de partidas...');
               }, 30000);
           }
       });

       // Função para confirmação de ações
       function confirmarAcao(mensagem) {
           return confirm(mensagem);
       }

       // Função para mostrar detalhes da partida (futura expansão)
       function mostrarDetalhesPartida(partidaId) {
           // Implementar modal com detalhes da partida
           console.log('Mostrar detalhes da partida:', partidaId);
       }
   </script>
</body>
</html>
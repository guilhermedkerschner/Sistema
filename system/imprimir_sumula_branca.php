<?php
session_start();

// Verificar se o usuário está logado
if (!isset($_SESSION['usersystem_logado'])) {
    header("Location: ../acessdeniedrestrict.php"); 
    exit;
}

require_once "../lib/config.php";

$campeonato_id = (int)$_GET['campeonato_id'];
$partida_id = (int)$_GET['partida_id'];
$modalidade = strtoupper($_GET['modalidade'] ?? 'FUTEBOL');

// Buscar dados básicos
try {
    // Campeonato
    $stmt = $conn->prepare("SELECT * FROM tb_campeonatos WHERE campeonato_id = :id");
    $stmt->bindValue(':id', $campeonato_id);
    $stmt->execute();
    $campeonato = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Partida
    $stmt = $conn->prepare("
        SELECT p.*, 
               e1.equipe_nome as equipe1_nome,
               e2.equipe_nome as equipe2_nome,
               c.fase, c.chave_nome
        FROM tb_campeonato_partidas p
        LEFT JOIN tb_campeonato_equipes e1 ON p.equipe1_id = e1.equipe_id
        LEFT JOIN tb_campeonato_equipes e2 ON p.equipe2_id = e2.equipe_id
        LEFT JOIN tb_campeonato_chaves c ON p.chave_id = c.chave_id
        WHERE p.partida_id = :id
    ");
    $stmt->bindValue(':id', $partida_id);
    $stmt->execute();
    $partida = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Atletas Equipe 1
    $stmt = $conn->prepare("
        SELECT ea.numero_camisa, a.atleta_nome 
        FROM tb_campeonato_equipe_atletas ea
        JOIN tb_atletas a ON ea.atleta_id = a.atleta_id
        WHERE ea.equipe_id = :equipe_id
        ORDER BY ea.numero_camisa
    ");
    $stmt->bindValue(':equipe_id', $partida['equipe1_id']);
    $stmt->execute();
    $atletas_equipe1 = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Atletas Equipe 2
    $stmt->bindValue(':equipe_id', $partida['equipe2_id']);
    $stmt->execute();
    $atletas_equipe2 = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Erro ao buscar dados: " . $e->getMessage());
}

// Função para gerar linhas em branco
function gerarLinhasVazias($quantidade, $largura = "300px") {
    $html = "";
    for ($i = 0; $i < $quantidade; $i++) {
        $html .= "<div class='linha-vazia' style='width: $largura; border-bottom: 1px solid #000; height: 20px; margin-bottom: 3px;'></div>";
    }
    return $html;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Súmula em Branco - <?php echo $modalidade; ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12pt;
            line-height: 1.3;
            margin: 15mm;
            color: #000;
            background: white;
        }
        
        .header {
            text-align: center;
            border: 2px solid #000;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .header h1 {
            font-size: 18pt;
            font-weight: bold;
            margin: 0 0 10px 0;
        }
        
        .header h2 {
            font-size: 14pt;
            margin: 5px 0;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .info-label {
            font-weight: bold;
            width: 120px;
            flex-shrink: 0;
        }
        
        .info-value {
            border-bottom: 1px solid #000;
            flex: 1;
            min-height: 20px;
            padding-left: 5px;
        }
        
        .equipes-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin: 20px 0;
        }
        
        .equipe-box {
            border: 2px solid #000;
            padding: 15px;
        }
        
        .equipe-title {
            text-align: center;
            font-weight: bold;
            font-size: 14pt;
            margin-bottom: 15px;
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
        }
        
        .atleta-linha {
            display: grid;
            grid-template-columns: 30px 1fr 40px;
            gap: 10px;
            margin-bottom: 8px;
            align-items: center;
        }
        
        .numero-box {
            border: 1px solid #000;
            text-align: center;
            height: 25px;
            line-height: 23px;
            font-weight: bold;
        }
        
        .nome-linha {
            border-bottom: 1px solid #000;
            height: 25px;
            line-height: 23px;
            padding-left: 5px;
        }
        
        .eventos-section {
            margin: 25px 0;
            page-break-inside: avoid;
        }
        
        .eventos-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-top: 15px;
        }
        
        .evento-box {
            border: 1px solid #000;
            padding: 10px;
            min-height: 150px;
        }
        
        .evento-title {
            font-weight: bold;
            text-align: center;
            border-bottom: 1px solid #000;
            margin-bottom: 10px;
            padding-bottom: 5px;
        }
        
        .evento-linha {
            display: grid;
            grid-template-columns: 30px 1fr 30px;
            gap: 5px;
            margin-bottom: 5px;
            align-items: center;
            font-size: 10pt;
        }
        
        .placar-section {
            text-align: center;
            margin: 25px 0;
            padding: 20px;
            border: 3px solid #000;
        }
        
        .placar-grid {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 30px;
            align-items: center;
        }
        
        .placar-equipe {
            text-align: center;
        }
        
        .placar-nome {
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .placar-box {
            border: 2px solid #000;
            width: 80px;
            height: 80px;
            margin: 0 auto;
            font-size: 36pt;
            line-height: 76px;
            text-align: center;
        }
        
        .assinaturas {
            margin-top: 40px;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 30px;
        }
        
        .assinatura-box {
            text-align: center;
            padding-top: 60px;
            border-top: 1px solid #000;
        }
        
        .linha-vazia {
            border-bottom: 1px solid #000;
            height: 18px;
            margin-bottom: 3px;
        }
        
        .observacoes-box {
            border: 1px solid #000;
            padding: 10px;
            margin: 20px 0;
            min-height: 80px;
        }
        
        @media print {
            body { margin: 10mm; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <!-- Cabeçalho -->
    <div class="header">
        <h1>SÚMULA OFICIAL - <?php echo $modalidade; ?></h1>
        <h2><?php echo htmlspecialchars($campeonato['campeonato_nome']); ?></h2>
        <p><strong>Categoria:</strong> <?php echo htmlspecialchars($campeonato['campeonato_categoria']); ?></p>
    </div>
    
    <!-- Informações da Partida -->
    <div class="info-grid">
        <div>
            <div class="info-item">
                <span class="info-label">Data:</span>
                <div class="info-value"><?php echo $partida['partida_data'] ? date('d/m/Y', strtotime($partida['partida_data'])) : ''; ?></div>
            </div>
            <div class="info-item">
                <span class="info-label">Horário:</span>
                <div class="info-value"><?php echo $partida['partida_data'] ? date('H:i', strtotime($partida['partida_data'])) : ''; ?></div>
            </div>
            <div class="info-item">
                <span class="info-label">Local:</span>
                <div class="info-value"><?php echo htmlspecialchars($partida['local_partida'] ?? ''); ?></div>
            </div>
        </div>
        <div>
            <div class="info-item">
                <span class="info-label">Fase:</span>
                <div class="info-value"><?php echo htmlspecialchars($partida['chave_nome'] ?? ''); ?></div>
            </div>
            <div class="info-item">
                <span class="info-label">Árbitro:</span>
                <div class="info-value"><?php echo htmlspecialchars($partida['arbitro_principal'] ?? ''); ?></div>
            </div>
            <div class="info-item">
                <span class="info-label">Público:</span>
                <div class="info-value"></div>
            </div>
        </div>
    </div>
    
    <!-- Placar -->
    <div class="placar-section">
        <h3 style="margin-top: 0;">RESULTADO FINAL</h3>
        <div class="placar-grid">
            <div class="placar-equipe">
                <div class="placar-nome"><?php echo htmlspecialchars($partida['equipe1_nome']); ?></div>
                <div class="placar-box"></div>
            </div>
            <div style="font-size: 24pt; font-weight: bold;">X</div>
            <div class="placar-equipe">
                <div class="placar-nome"><?php echo htmlspecialchars($partida['equipe2_nome']); ?></div>
                <div class="placar-box"></div>
            </div>
        </div>
    </div>
    
    <!-- Escalações -->
    <div class="equipes-section">
        <div class="equipe-box">
            <div class="equipe-title"><?php echo htmlspecialchars($partida['equipe1_nome']); ?></div>
            
            <?php 
            // Exibir atletas cadastrados
            $contador = 1;
            foreach ($atletas_equipe1 as $atleta) {
                echo "<div class='atleta-linha'>";
                echo "<div class='numero-box'>{$atleta['numero_camisa']}</div>";
                echo "<div class='nome-linha'>" . htmlspecialchars($atleta['atleta_nome']) . "</div>";
                echo "<div style='border-bottom: 1px solid #000; height: 25px;'></div>"; // Coluna para anotações
                echo "</div>";
                $contador++;
            }
            
            // Completar até 18 linhas (padrão futebol)
            $linhas_total = ($modalidade === 'FUTSAL') ? 12 : 18;
            for ($i = $contador; $i <= $linhas_total; $i++) {
                echo "<div class='atleta-linha'>";
                echo "<div class='numero-box'></div>";
                echo "<div class='nome-linha'></div>";
                echo "<div style='border-bottom: 1px solid #000; height: 25px;'></div>";
                echo "</div>";
            }
            ?>
            
            <div style="margin-top: 15px; font-weight: bold;">
                <div class="info-item">
                    <span class="info-label">Técnico:</span>
                    <div class="info-value"></div>
                </div>
            </div>
        </div>
        
        <div class="equipe-box">
            <div class="equipe-title"><?php echo htmlspecialchars($partida['equipe2_nome']); ?></div>
            
            <?php 
            // Exibir atletas cadastrados
            $contador = 1;
            foreach ($atletas_equipe2 as $atleta) {
                echo "<div class='atleta-linha'>";
                echo "<div class='numero-box'>{$atleta['numero_camisa']}</div>";
                echo "<div class='nome-linha'>" . htmlspecialchars($atleta['atleta_nome']) . "</div>";
                echo "<div style='border-bottom: 1px solid #000; height: 25px;'></div>";
                echo "</div>";
                $contador++;
            }
            
            // Completar até o total de linhas
            for ($i = $contador; $i <= $linhas_total; $i++) {
                echo "<div class='atleta-linha'>";
                echo "<div class='numero-box'></div>";
                echo "<div class='nome-linha'></div>";
                echo "<div style='border-bottom: 1px solid #000; height: 25px;'></div>";
                echo "</div>";
            }
            ?>
            
            <div style="margin-top: 15px; font-weight: bold;">
                <div class="info-item">
                    <span class="info-label">Técnico:</span>
                    <div class="info-value"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Eventos da Partida -->
    <div class="eventos-section">
        <h3 style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px;">EVENTOS DA PARTIDA</h3>
        
        <div class="eventos-grid">
            <div class="evento-box">
                <div class="evento-title">GOLS</div>
                <?php for ($i = 0; $i < 12; $i++): ?>
                <div class="evento-linha">
                    <div style="border: 1px solid #000; height: 20px;"></div>
                    <div style="border-bottom: 1px solid #000; height: 20px;"></div>
                    <div style="border: 1px solid #000; height: 20px;"></div>
                </div>
                <?php endfor; ?>
                <div style="font-size: 8pt; margin-top: 5px;">Nº - Nome - Min</div>
            </div>
            
            <div class="evento-box">
                <div class="evento-title">CARTÕES AMARELOS</div>
                <?php for ($i = 0; $i < 12; $i++): ?>
                <div class="evento-linha">
                    <div style="border: 1px solid #000; height: 20px;"></div>
                    <div style="border-bottom: 1px solid #000; height: 20px;"></div>
                    <div style="border: 1px solid #000; height: 20px;"></div>
                </div>
                <?php endfor; ?>
                <div style="font-size: 8pt; margin-top: 5px;">Nº - Nome - Min</div>
            </div>
            
            <div class="evento-box">
                <div class="evento-title">CARTÕES VERMELHOS</div>
                <?php for ($i = 0; $i < 12; $i++): ?>
                <div class="evento-linha">
                    <div style="border: 1px solid #000; height: 20px;"></div>
                    <div style="border-bottom: 1px solid #000; height: 20px;"></div>
                    <div style="border: 1px solid #000; height: 20px;"></div>
                </div>
                <?php endfor; ?>
                <div style="font-size: 8pt; margin-top: 5px;">Nº - Nome - Min</div>
            </div>
        </div>
    </div>
    
    <!-- Substituições -->
    <div class="eventos-section">
        <h3 style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px;">SUBSTITUIÇÕES</h3>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="evento-box">
                <div class="evento-title"><?php echo htmlspecialchars($partida['equipe1_nome']); ?></div>
                <?php 
                $max_subs = ($modalidade === 'FUTSAL') ? 12 : 5;
                for ($i = 0; $i < $max_subs; $i++): 
                ?>
                <div style="display: grid; grid-template-columns: 30px 1fr 30px 1fr 30px; gap: 5px; margin-bottom: 8px; align-items: center; font-size: 10pt;">
                    <div style="border: 1px solid #000; height: 20px; text-align: center; line-height: 18px;">SAI</div>
                    <div style="border-bottom: 1px solid #000; height: 20px;"></div>
                    <div style="border: 1px solid #000; height: 20px; text-align: center; line-height: 18px;">ENT</div>
                    <div style="border-bottom: 1px solid #000; height: 20px;"></div>
                    <div style="border: 1px solid #000; height: 20px;"></div>
                </div>
                <?php endfor; ?>
                <div style="font-size: 8pt; margin-top: 5px;">Sai Nº - Entra Nº - Min</div>
            </div>
            
            <div class="evento-box">
                <div class="evento-title"><?php echo htmlspecialchars($partida['equipe2_nome']); ?></div>
                <?php for ($i = 0; $i < $max_subs; $i++): ?>
                <div style="display: grid; grid-template-columns: 30px 1fr 30px 1fr 30px; gap: 5px; margin-bottom: 8px; align-items: center; font-size: 10pt;">
                    <div style="border: 1px solid #000; height: 20px; text-align: center; line-height: 18px;">SAI</div>
                    <div style="border-bottom: 1px solid #000; height: 20px;"></div>
                    <div style="border: 1px solid #000; height: 20px; text-align: center; line-height: 18px;">ENT</div>
                    <div style="border-bottom: 1px solid #000; height: 20px;"></div>
                    <div style="border: 1px solid #000; height: 20px;"></div>
                </div>
                <?php endfor; ?>
                <div style="font-size: 8pt; margin-top: 5px;">Sai Nº - Entra Nº - Min</div>
            </div>
        </div>
    </div>
    
    <!-- Observações -->
    <div class="observacoes-box">
        <div style="font-weight: bold; margin-bottom: 10px;">OBSERVAÇÕES:</div>
        <div style="min-height: 60px;"></div>
    </div>
    
    <!-- Assinaturas -->
    <div class="assinaturas">
        <div class="assinatura-box">
            <div><strong>ÁRBITRO PRINCIPAL</strong></div>
            <div style="font-size: 10pt; margin-top: 5px;">Nome e Assinatura</div>
        </div>
        <div class="assinatura-box">
            <div><strong>DELEGADO/MESÁRIO</strong></div>
            <div style="font-size: 10pt; margin-top: 5px;">Nome e Assinatura</div>
        </div>
        <div class="assinatura-box">
            <div><strong>RESPONSÁVEL TÉCNICO</strong></div>
            <div style="font-size: 10pt; margin-top: 5px;">Nome e Assinatura</div>
        </div>
    </div>
    
    <!-- Botões para impressão -->
    <div class="no-print" style="text-align: center; margin-top: 30px;">
        <button onclick="window.print()" style="padding: 10px 20px; font-size: 14px; background: #4caf50; color: white; border: none; border-radius: 5px; cursor: pointer;">
            <i class="fas fa-print"></i> Imprimir Súmula
        </button>
        <button onclick="window.close()" style="padding: 10px 20px; font-size: 14px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">
            Fechar
        </button>
    </div>
    
    <script>
        // Auto-imprimir quando a página carregar (opcional)
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>
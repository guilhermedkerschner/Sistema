<?php
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
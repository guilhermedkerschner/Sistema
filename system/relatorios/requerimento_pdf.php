<?php
session_start();

if (!isset($_SESSION['usersystem_logado'])) {
    header("Location: ../../acessdeniedrestrict.php"); 
    exit;
}

require_once "../../database/conect.php";

$id = $_GET['id'] ?? null;

if (!$id || !is_numeric($id)) {
    echo "ID inválido";
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT r.*, p.cad_pro_nome, p.cad_pro_cpf, p.cad_pro_telefone,
               c.com_nome, s.ser_nome, sec.sec_nome,
               u.usuario_nome as cadastrado_por
        FROM tb_requerimentos r
        INNER JOIN tb_cad_produtores p ON r.req_produtor_id = p.cad_pro_id
        LEFT JOIN tb_cad_comunidades c ON p.cad_pro_comunidade_id = c.com_id
        INNER JOIN tb_cad_servicos s ON r.req_servico_id = s.ser_id
        LEFT JOIN tb_cad_secretarias sec ON s.ser_secretaria_id = sec.sec_id
        LEFT JOIN tb_usuarios_sistema u ON r.req_usuario_cadastro = u.usuario_id
        WHERE r.req_id = :id
    ");
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        echo "Requerimento não encontrado";
        exit;
    }
    
    $requerimento = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Formatar tipo
    $tipo_formatado = match($requerimento['req_tipo']) {
        'agricultura' => 'AGRICULTURA',
        'meio_ambiente' => 'MEIO AMBIENTE',
        'vacinas' => 'VACINAS',
        'exames' => 'EXAMES',
        default => 'INDEFINIDO'
    };
    
    $secretaria_nome = match($requerimento['req_tipo']) {
        'agricultura' => 'Secretaria Municipal de Agricultura',
        'meio_ambiente' => 'Secretaria Municipal de Meio Ambiente',
        'vacinas' => 'Secretaria Municipal de Agricultura',
        'exames' => 'Secretaria Municipal de Agricultura',
        default => 'Secretaria Municipal'
    };
    
    // Configurar cabeçalhos para exibir PDF no navegador
    header('Content-Type: text/html; charset=utf-8');
    
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <title>Requerimento <?= htmlspecialchars($requerimento['req_numero']) ?></title>
        <style>
            @page {
                size: A4;
                margin: 1.5cm;
            }
            
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: Arial, sans-serif;
                font-size: 12px;
                line-height: 1.3;
                color: #000;
                background: white;
            }
            
            .container {
                width: 100%;
                max-width: 210mm;
                margin: 0 auto;
                padding: 10px;
            }
            
            .header {
                text-align: center;
                margin-bottom: 15px;
                border-bottom: 2px solid #000;
                padding-bottom: 10px;
            }
            
            .header h1 {
                font-size: 16px;
                font-weight: bold;
                margin-bottom: 4px;
                text-transform: uppercase;
            }
            
            .header h2 {
                font-size: 13px;
                margin-bottom: 4px;
            }
            
            .header .cidade {
                font-size: 12px;
                font-weight: normal;
            }
            
            .documento-info {
                text-align: right;
                margin-bottom: 12px;
                font-size: 11px;
                background: #f0f0f0;
                padding: 6px;
                border: 1px solid #ccc;
            }
            
            .destinatario {
                margin-bottom: 12px;
                font-size: 11px;
            }
            
            .destinatario p {
                margin-bottom: 2px;
                line-height: 1.3;
            }
            
            .dados-section {
                margin-bottom: 14px;
            }
            
            .section-title {
                background-color: #e0e0e0;
                padding: 5px 10px;
                font-weight: bold;
                text-transform: uppercase;
                border: 1px solid #999;
                margin-bottom: 6px;
                font-size: 11px;
            }
            
            .dados-grid {
                display: table;
                width: 100%;
                border-collapse: collapse;
            }
            
            .dados-row {
                display: table-row;
            }
            
            .dados-item {
                display: table-cell;
                padding: 6px 8px;
                border: 1px solid #ccc;
                vertical-align: top;
                font-size: 11px;
            }
            
            .label {
                font-weight: bold;
                margin-bottom: 3px;
                font-size: 10px;
            }
            
            .value {
                font-size: 11px;
                min-height: 14px;
            }
            
            .descricao-box {
                border: 1px solid #ccc;
                padding: 10px;
                min-height: 90px;
                margin-bottom: 12px;
                background-color: #fafafa;
                font-size: 11px;
                line-height: 1.4;
            }
            
            .observacoes-box {
                border: 1px solid #999;
                padding: 8px;
                min-height: 70px;
                margin-top: 6px;
                background: white;
                position: relative;
            }
            
            .observacoes-box::before {
                content: '';
                position: absolute;
                top: 15px;
                left: 8px;
                right: 8px;
                height: 1px;
                border-top: 1px dotted #999;
            }
            
            .observacoes-box::after {
                content: '';
                position: absolute;
                top: 30px;
                left: 8px;
                right: 8px;
                height: 1px;
                border-top: 1px dotted #999;
            }
            
            .linha-extra1 {
                position: absolute;
                top: 45px;
                left: 8px;
                right: 8px;
                height: 1px;
                border-top: 1px dotted #999;
            }
            
            .linha-extra2 {
                position: absolute;
                top: 60px;
                left: 8px;
                right: 8px;
                height: 1px;
                border-top: 1px dotted #999;
            }
            
            .data-local {
                text-align: right;
                margin: 10px 0;
                font-size: 11px;
            }
            
            .assinaturas {
                margin-top: 16px;
            }
            
            .assinatura-line {
                margin-top: 30px;
                border-top: 1px solid #000;
                text-align: center;
                padding-top: 4px;
                font-size: 10px;
            }
            
            .termo-secao {
                margin-top: 16px;
            }
            
            .declaracao {
                font-size: 10px;
                text-align: justify;
                margin: 10px 0;
                line-height: 1.3;
            }
            
            .controle-interno {
                margin-top: 16px;
                border: 1px solid #ccc;
                padding: 10px;
                background: #f8f8f8;
            }
            
            .controle-interno .titulo {
                font-weight: bold;
                font-size: 11px;
                margin-bottom: 6px;
                text-align: center;
                text-transform: uppercase;
            }
            
            .controle-grid {
                display: table;
                width: 100%;
                border-collapse: collapse;
            }
            
            .controle-item {
                display: table-cell;
                padding: 4px;
                border: 1px solid #999;
                text-align: center;
                font-size: 10px;
                vertical-align: middle;
                height: 24px;
            }
            
            .footer {
                margin-top: 10px;
                font-size: 9px;
                border-top: 1px solid #ccc;
                padding-top: 6px;
                text-align: justify;
            }
            
            @media print {
                body {
                    margin: 0;
                    padding: 0;
                }
                
                .container {
                    padding: 0;
                    margin: 0;
                    max-width: none;
                }
                
                .no-print {
                    display: none;
                }
            }
            
            .status-info {
                background-color: #e3f2fd;
                border: 1px solid #1976d2;
                padding: 5px;
                margin-bottom: 10px;
                font-size: 11px;
                text-align: center;
            }
            
            .servico-header {
                border: 1px solid #ccc; 
                padding: 6px; 
                margin-bottom: 6px; 
                background: #f0f0f0;
                font-size: 12px;
                font-weight: bold;
            }
            
            .obs-existentes {
                border: 1px solid #ccc; 
                padding: 6px; 
                background: #fff3cd; 
                font-size: 10px;
                margin-bottom: 6px;
            }
        </style>
        <script>
            // Auto-print quando carregar
            window.onload = function() {
                window.print();
            }
        </script>
    </head>
    <body>
        <div class="container">
            <!-- Header -->
            <div class="header">
                <h1>REQUERIMENTO PARA SERVIÇOS</h1>
                <h2><?= htmlspecialchars($secretaria_nome) ?></h2>
                <p class="cidade">Santa Izabel do Oeste - PR</p>
            </div>
            
            <!-- Informações do Documento -->
            <div class="documento-info">
                <strong>Protocolo: <?= htmlspecialchars($requerimento['req_numero']) ?></strong> | 
                Data: <?= date('d/m/Y H:i', strtotime($requerimento['req_data_solicitacao'])) ?> | 
                Status: 
                <?php
                $status_texto = match($requerimento['req_status']) {
                    'agendado' => 'AGENDADO',
                    'em_andamento' => 'EM ANDAMENTO',
                    'concluido' => 'CONCLUÍDO',
                    'cancelado' => 'CANCELADO',
                    default => strtoupper($requerimento['req_status'])
                };
                echo $status_texto;
                ?>
            </div>
            
            <!-- Destinatário -->
            <div class="destinatario">
                <p><strong>Ilmo. Sr. Secretário Municipal - <?= htmlspecialchars($secretaria_nome) ?> - Santa Izabel do Oeste - PR</strong></p>
            </div>
            
            <!-- Dados do Requerente -->
            <div class="dados-section">
                <div class="section-title">DADOS DO REQUERENTE:</div>
                
                <div class="dados-grid">
                    <div class="dados-row">
                        <div class="dados-item" style="width: 45%;">
                            <div class="label">Nome:</div>
                            <div class="value"><?= htmlspecialchars($requerimento['cad_pro_nome']) ?></div>
                        </div>
                        <div class="dados-item" style="width: 25%;">
                            <div class="label">CPF:</div>
                            <div class="value"><?= htmlspecialchars($requerimento['cad_pro_cpf']) ?></div>
                        </div>
                        <div class="dados-item" style="width: 30%;">
                            <div class="label">Comunidade:</div>
                            <div class="value"><?= htmlspecialchars($requerimento['com_nome'] ?? 'Não informado') ?></div>
                        </div>
                    </div>
                    <div class="dados-row">
                        <div class="dados-item" style="width: 70%;">
                            <div class="label">Contato:</div>
                            <div class="value"><?= htmlspecialchars($requerimento['cad_pro_telefone'] ?? 'Não informado') ?></div>
                        </div>
                        <div class="dados-item" style="width: 30%;">
                            <div class="label">Cadastrado por:</div>
                            <div class="value"><?= htmlspecialchars($requerimento['cadastrado_por'] ?? 'Sistema') ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Serviços Solicitados -->
            <div class="dados-section">
                <div class="section-title">SERVIÇOS SOLICITADOS:</div>
                
                <div class="servico-header">
                    <?= $tipo_formatado ?> - <?= htmlspecialchars($requerimento['ser_nome']) ?>
                </div>
                
                <div class="descricao-box">
                    <?= nl2br(htmlspecialchars($requerimento['req_descricao'])) ?>
                </div>
                
                <?php if (!empty($requerimento['req_observacoes'])): ?>
                <div class="obs-existentes">
                    <strong>Observações do Sistema:</strong> <?= nl2br(htmlspecialchars($requerimento['req_observacoes'])) ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Data e Local -->
            <div class="data-local">
                <?php
                // Array com os meses em português
                $meses = [
                    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
                    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
                    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
                ];
                
                $data = strtotime($requerimento['req_data_solicitacao']);
                $dia = date('d', $data);
                $mes = $meses[(int)date('n', $data)];
                $ano = date('Y', $data);
                ?>
                Santa Izabel do Oeste - PR, <?= $dia ?> de <?= $mes ?> de <?= $ano ?>
            </div>
            
            <!-- Assinaturas -->
            <div class="assinaturas">
                <div style="width: 48%; float: left;">
                    <div class="assinatura-line">
                        Requerente: <?= htmlspecialchars($requerimento['cad_pro_nome']) ?>
                    </div>
                </div>
                
                <div style="width: 48%; float: right;">
                    <div class="assinatura-line">
                        Responsável: <?= htmlspecialchars($requerimento['cadastrado_por'] ?? 'Sistema') ?>
                    </div>
                </div>
                
                <div style="clear: both;"></div>
            </div>
            
            <!-- Termo de Ciência -->
            <div class="termo-secao">
                <div class="section-title">TERMO DE CIÊNCIA:</div>
                <div class="declaracao">
                    Ratifico serem verdadeiras as informações prestadas, e afirmo estar ciente de que qualquer omissão de informação ou apresentação de 
                    declaração falsa constitui crime de falsidade ideológica (art. 299 do Código Penal). Autorizo a verificação dos dados apresentados 
                    e me comprometo a seguir as orientações técnicas repassadas pelo técnico responsável.
                </div>
            </div>
            
            <!-- Controle Interno -->
            <div class="controle-interno">
                <div class="titulo">CONTROLE INTERNO - USO EXCLUSIVO DA SECRETARIA</div>
                
                <div class="controle-grid">
                    <div style="display: table-row;">
                        <div class="controle-item" style="width: 25%;"><strong>RECEBIDO:</strong><br>____/____/____</div>
                        <div class="controle-item" style="width: 25%;"><strong>ANALISADO:</strong><br>____/____/____</div>
                        <div class="controle-item" style="width: 25%;"><strong>□ AUTORIZADO</strong><br><strong>□ NÃO AUTORIZADO</strong></div>
                        <div class="controle-item" style="width: 25%;"><strong>TÉCNICO:</strong><br>_________________</div>
                    </div>
                </div>
                
                <div style="border: 1px solid #999; margin-top: 6px; position: relative;">
                    <div style="padding: 4px; font-size: 10px; font-weight: bold; background: #f0f0f0; border-bottom: 1px solid #999;">
                        OBSERVAÇÕES/MOTIVO:
                    </div>
                    <div class="observacoes-box">
                        <div class="linha-extra1"></div>
                        <div class="linha-extra2"></div>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="footer">
                <strong>Art. 299 - CP:</strong> Crime de falsidade ideológica: reclusão de 1 a 5 anos (documento público) ou 1 a 3 anos (documento particular), e multa.
            </div>
            
            <!-- Botões para não impressão -->
            <div class="no-print" style="position: fixed; top: 10px; right: 10px; background: white; padding: 10px; border: 1px solid #ccc; border-radius: 5px; z-index: 1000;">
                <button onclick="window.print()" style="background: #28a745; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; margin-right: 5px; font-size: 12px;">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <button onclick="window.close()" style="background: #6c757d; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                    <i class="fas fa-times"></i> Fechar
                </button>
            </div>
        </div>
    </body>
    </html>
    
    <?php
    
} catch (PDOException $e) {
    error_log("Erro ao buscar requerimento para PDF: " . $e->getMessage());
    echo "Erro ao carregar dados do requerimento";
}
?>
<?php
/**
 * Arquivo: check_system.php
 * Descrição: Verificação inicial do sistema Eai Cidadão!
 */

// Incluir arquivo de configuração
require_once '../../database/conect.php';

// Função para verificar se uma tabela existe
function tabelaExiste($conn, $tabela) {
    try {
        $result = $conn->query("SHOW TABLES LIKE '{$tabela}'");
        return $result->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Função para criar tabela se não existir
function criarTabela($conn, $tabela, $sql) {
    if (!tabelaExiste($conn, $tabela)) {
        try {
            $conn->exec($sql);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    return true;
}

// Função para verificar se existe administrador
function existeAdmin($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total 
            FROM tb_usuarios_sistema u
            JOIN tb_niveis_acesso n ON u.usuario_nivel_id = n.nivel_id
            WHERE n.nivel_nome = 'Administrador'
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['total'] > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Lista de tabelas necessárias com suas definições SQL
$tabelas = [
    'tb_modulos' => "
        CREATE TABLE tb_modulos (
            modulo_id INT AUTO_INCREMENT PRIMARY KEY,
            modulo_nome VARCHAR(50) NOT NULL,
            modulo_descricao VARCHAR(255),
            modulo_icone VARCHAR(50),
            modulo_status ENUM('ativo', 'inativo') NOT NULL DEFAULT 'ativo',
            modulo_data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            modulo_data_modificacao DATETIME ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ",
    'tb_niveis_acesso' => "
        CREATE TABLE tb_niveis_acesso (
            nivel_id INT AUTO_INCREMENT PRIMARY KEY,
            nivel_nome VARCHAR(50) NOT NULL,
            nivel_descricao VARCHAR(255),
            nivel_permissoes JSON,
            nivel_status ENUM('ativo', 'inativo') NOT NULL DEFAULT 'ativo',
            nivel_data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            nivel_data_modificacao DATETIME ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ",
    'tb_usuarios_sistema' => "
        CREATE TABLE tb_usuarios_sistema (
            usuario_id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_nome VARCHAR(100) NOT NULL,
            usuario_email VARCHAR(100) NOT NULL UNIQUE,
            usuario_login VARCHAR(50) NOT NULL UNIQUE,
            usuario_senha VARCHAR(255) NOT NULL,
            usuario_cpf VARCHAR(14) UNIQUE,
            usuario_telefone VARCHAR(20),
            usuario_cargo VARCHAR(100),
            usuario_departamento VARCHAR(100),
            usuario_nivel_id INT,
            usuario_ultimo_acesso DATETIME,
            usuario_ultimo_ip VARCHAR(50),
            usuario_token_recuperacao VARCHAR(100),
            usuario_token_expiracao DATETIME,
            usuario_status ENUM('ativo', 'inativo', 'bloqueado', 'pendente') NOT NULL DEFAULT 'ativo',
            usuario_tentativas_login INT DEFAULT 0,
            usuario_data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            usuario_data_modificacao DATETIME ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_nivel_id) REFERENCES tb_niveis_acesso(nivel_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ",
    'tb_permissoes_usuario' => "
        CREATE TABLE tb_permissoes_usuario (
            permissao_id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            modulo_id INT NOT NULL,
            permissao_visualizar BOOLEAN NOT NULL DEFAULT FALSE,
            permissao_criar BOOLEAN NOT NULL DEFAULT FALSE,
            permissao_editar BOOLEAN NOT NULL DEFAULT FALSE,
            permissao_excluir BOOLEAN NOT NULL DEFAULT FALSE,
            permissao_aprovar BOOLEAN NOT NULL DEFAULT FALSE,
            permissao_data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            permissao_data_modificacao DATETIME ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES tb_usuarios_sistema(usuario_id) ON DELETE CASCADE,
            FOREIGN KEY (modulo_id) REFERENCES tb_modulos(modulo_id) ON DELETE CASCADE,
            UNIQUE KEY usuario_modulo (usuario_id, modulo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ",
    'tb_logs_acesso' => "
        CREATE TABLE tb_logs_acesso (
            log_id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT,
            log_ip VARCHAR(50),
            log_data_acesso DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            log_acao VARCHAR(100),
            log_modulo_id INT,
            log_detalhes TEXT,
            FOREIGN KEY (usuario_id) REFERENCES tb_usuarios_sistema(usuario_id) ON DELETE SET NULL,
            FOREIGN KEY (log_modulo_id) REFERENCES tb_modulos(modulo_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ",
    'tb_sessoes_usuario' => "
        CREATE TABLE tb_sessoes_usuario (
            sessao_id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            sessao_token VARCHAR(255) NOT NULL,
            sessao_ip VARCHAR(50),
            sessao_user_agent VARCHAR(255),
            sessao_data_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sessao_data_expiracao DATETIME NOT NULL,
            sessao_data_ultima_atividade DATETIME,
            sessao_status ENUM('ativa', 'expirada', 'encerrada') NOT NULL DEFAULT 'ativa',
            FOREIGN KEY (usuario_id) REFERENCES tb_usuarios_sistema(usuario_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    "
];

// Verificar se todas as tabelas existem
$tabelas_faltantes = [];
foreach ($tabelas as $tabela => $sql) {
    if (!tabelaExiste($conn, $tabela)) {
        $tabelas_faltantes[] = $tabela;
    }
}

// Status da verificação
$status = [
    'sistema_ok' => count($tabelas_faltantes) === 0,
    'tabelas_faltantes' => $tabelas_faltantes,
    'admin_existe' => existeAdmin($conn)
];

// Se solicitado para criar tabelas faltantes
if (isset($_GET['criar']) && $_GET['criar'] === 'true') {
    $criadas = [];
    $falhas = [];
    
    foreach ($tabelas_faltantes as $tabela) {
        if (criarTabela($conn, $tabela, $tabelas[$tabela])) {
            $criadas[] = $tabela;
        } else {
            $falhas[] = $tabela;
        }
    }
    
    $status['tabelas_criadas'] = $criadas;
    $status['tabelas_falhas'] = $falhas;
    
    // Criar dados iniciais se as tabelas foram criadas com sucesso
    if (count($falhas) === 0 && count($criadas) > 0) {
        // Inserir módulos
        if (in_array('tb_modulos', $criadas)) {
            $modulos = [
                ['Administração', 'Configurações gerais do sistema', 'fa-cog'],
                ['Agricultura', 'Gestão de serviços agrícolas', 'fa-leaf'],
                ['Assistência Social', 'Gestão de programas sociais', 'fa-hands-helping'],
                ['Educação', 'Gestão escolar e educacional', 'fa-graduation-cap'],
                ['Esporte', 'Gestão de eventos e atividades esportivas', 'fa-running'],
                ['Fazenda', 'Gestão financeira e orçamentária', 'fa-money-bill-wave'],
                ['Fiscalização', 'Gestão de fiscalizações e autuações', 'fa-search'],
                ['Meio Ambiente', 'Gestão ambiental e licenciamentos', 'fa-tree'],
                ['Obras', 'Gestão de obras e infraestrutura', 'fa-hard-hat'],
                ['Rodoviário', 'Gestão da frota e vias públicas', 'fa-truck'],
                ['Serviços Urbanos', 'Gestão de serviços urbanos', 'fa-city'],
                ['Cultura e Turismo', 'Gestão de eventos culturais e turísticos', 'fa-palette']
            ];
            
            $stmt = $conn->prepare("
                INSERT INTO tb_modulos (modulo_nome, modulo_descricao, modulo_icone) 
                VALUES (?, ?, ?)
            ");
            
            foreach ($modulos as $modulo) {
                $stmt->execute($modulo);
            }
            
            $status['modulos_criados'] = true;
        }
        
        // Inserir níveis de acesso
        if (in_array('tb_niveis_acesso', $criadas)) {
            $niveis = [
                ['Administrador', 'Acesso total ao sistema', '{"todos_modulos": true, "todos_privilegios": true}'],
                ['Gestor', 'Acesso gerencial aos módulos designados', '{"todos_modulos": false, "privilegios_padrão": ["visualizar", "criar", "editar", "aprovar"]}'],
                ['Colaborador', 'Acesso operacional básico', '{"todos_modulos": false, "privilegios_padrão": ["visualizar", "criar"]}'],
                ['Consulta', 'Acesso apenas para visualização', '{"todos_modulos": false, "privilegios_padrão": ["visualizar"]}']
            ];
            
            $stmt = $conn->prepare("
                INSERT INTO tb_niveis_acesso (nivel_nome, nivel_descricao, nivel_permissoes) 
                VALUES (?, ?, ?)
            ");
            
            foreach ($niveis as $nivel) {
                $stmt->execute($nivel);
            }
            
            $status['niveis_criados'] = true;
        }
    }
}

// Se solicitado para criar usuário administrador
if (isset($_GET['criar_admin']) && $_GET['criar_admin'] === 'true' && !$status['admin_existe']) {
    // Verifica se os níveis de acesso existem
    if (tabelaExiste($conn, 'tb_niveis_acesso')) {
        $senha = isset($_GET['senha']) ? $_GET['senha'] : 'admin@123';
        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        
        try {
            // Obter ID do nível Administrador
            $stmt = $conn->prepare("SELECT nivel_id FROM tb_niveis_acesso WHERE nivel_nome = 'Administrador'");
            $stmt->execute();
            $nivel = $stmt->fetch();
            
            if ($nivel) {
                // Inserir administrador
                $stmt = $conn->prepare("
                    INSERT INTO tb_usuarios_sistema (
                        usuario_nome, usuario_email, usuario_login, usuario_senha, 
                        usuario_nivel_id, usuario_cargo, usuario_departamento
                    ) VALUES (
                        'Administrador do Sistema', 
                        'admin@santaizabeloeste.pr.gov.br', 
                        'admin', 
                        ?, 
                        ?, 
                        'Administrador de Sistema', 
                        'Tecnologia da Informação'
                    )
                ");
                $stmt->bindParam(1, $senha_hash);
                $stmt->bindParam(2, $nivel['nivel_id']);
                $stmt->execute();
                
                $status['admin_criado'] = true;
                $status['admin_senha'] = $senha;
            }
        } catch (Exception $e) {
            $status['admin_erro'] = $e->getMessage();
        }
    }
}

// Exibir resultado em formato JSON
header('Content-Type: application/json');
echo json_encode($status, JSON_PRETTY_PRINT);
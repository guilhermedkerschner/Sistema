<?php
/**
 * Arquivo: carregar_permissoes.php
 * Descrição: Carrega as permissões de um usuário específico
 * 
 * Parte do sistema de administração da Prefeitura
 */

// Headers para resposta JSON
header('Content-Type: application/json; charset=utf-8');

// Inicia a sessão
session_start();

// Verificar se o usuário está logado e é administrador
if (!isset($_SESSION['usersystem_logado'])) {
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado.']);
    exit;
}

// Incluir arquivo de configuração com conexão ao banco de dados
require_once "../lib/config.php";

// Verificar se é administrador
$usuario_logado_id = $_SESSION['usersystem_id'];
$is_admin = false;

try {
    $stmt = $conn->prepare("SELECT usuario_nivel_id FROM tb_usuarios_sistema WHERE usuario_id = :id");
    $stmt->bindParam(':id', $usuario_logado_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        $is_admin = ($usuario['usuario_nivel_id'] == 1);
    }
} catch (PDOException $e) {
    error_log("Erro ao verificar permissões: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do sistema.']);
    exit;
}

if (!$is_admin) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado. Apenas administradores podem visualizar permissões.']);
    exit;
}

// Verificar se o método é GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Método de requisição inválido.']);
    exit;
}

// Capturar ID do usuário
$usuario_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

// Validações
if ($usuario_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID do usuário inválido.']);
    exit;
}

try {
    // Verificar se o usuário existe e buscar suas informações
    $stmt = $conn->prepare("
        SELECT 
            usuario_id, 
            usuario_nome, 
            usuario_login, 
            usuario_departamento, 
            usuario_nivel_id,
            usuario_status 
        FROM tb_usuarios_sistema 
        WHERE usuario_id = :id
    ");
    $stmt->bindParam(':id', $usuario_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Usuário não encontrado.']);
        exit;
    }
    
    $usuario_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Erro ao verificar usuário: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro ao buscar informações do usuário.']);
    exit;
}

try {
    // Verificar se a tabela de permissões existe
    $table_check = $conn->query("SHOW TABLES LIKE 'tb_permissoes_usuario'");
    $table_exists = $table_check->rowCount() > 0;
    
    $permissoes_usuario = [];
    
    if ($table_exists) {
        // Buscar permissões do usuário
        $stmt = $conn->prepare("
            SELECT 
                modulo, 
                acao, 
                ativo,
                data_criacao,
                data_modificacao
            FROM tb_permissoes_usuario 
            WHERE usuario_id = :usuario_id AND ativo = TRUE
            ORDER BY modulo, acao
        ");
        $stmt->bindParam(':usuario_id', $usuario_id);
        $stmt->execute();
        
        $permissoes_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Organizar permissões por módulo
        foreach ($permissoes_raw as $perm) {
            $modulo = $perm['modulo'];
            $acao = $perm['acao'];
            
            if (!isset($permissoes_usuario[$modulo])) {
                $permissoes_usuario[$modulo] = [];
            }
            
            $permissoes_usuario[$modulo][$acao] = [
                'ativo' => (bool)$perm['ativo'],
                'data_criacao' => $perm['data_criacao'],
                'data_modificacao' => $perm['data_modificacao']
            ];
        }
    }
    
    // Se o usuário for administrador (nível 1), dar todas as permissões automaticamente
    if ($usuario_info['usuario_nivel_id'] == 1) {
        $modulos_sistema = [
            'dashboard' => ['visualizar'],
            'usuarios' => ['visualizar', 'criar', 'editar', 'excluir'],
            'relatorios' => ['visualizar', 'exportar'],
            'agricultura' => ['visualizar', 'criar', 'editar', 'excluir', 'gerenciar'],
            'assistencia_social' => ['visualizar', 'criar', 'editar', 'excluir', 'gerenciar'],
            'cultura_turismo' => ['visualizar', 'criar', 'editar', 'excluir', 'gerenciar'],
            'educacao' => ['visualizar', 'criar', 'editar', 'excluir', 'gerenciar'],
            'esporte' => ['visualizar', 'criar', 'editar', 'excluir', 'gerenciar'],
            'fazenda' => ['visualizar', 'criar', 'editar', 'excluir', 'gerenciar'],
            'fiscalizacao' => ['visualizar', 'criar', 'editar', 'excluir', 'gerenciar'],
            'meio_ambiente' => ['visualizar', 'criar', 'editar', 'excluir', 'gerenciar'],
            'obras' => ['visualizar', 'criar', 'editar', 'excluir', 'gerenciar'],
            'rodoviario' => ['visualizar', 'criar', 'editar', 'excluir', 'gerenciar'],
            'servicos_urbanos' => ['visualizar', 'criar', 'editar', 'excluir', 'gerenciar']
        ];
        
        // Sobrescrever com todas as permissões para admin
        $permissoes_usuario = [];
        foreach ($modulos_sistema as $modulo => $acoes) {
            $permissoes_usuario[$modulo] = [];
            foreach ($acoes as $acao) {
                $permissoes_usuario[$modulo][$acao] = [
                    'ativo' => true,
                    'data_criacao' => date('Y-m-d H:i:s'),
                    'data_modificacao' => date('Y-m-d H:i:s')
                ];
            }
        }
    }
    
    // Buscar estatísticas de permissões
    $total_permissoes = 0;
    $modulos_com_acesso = 0;
    
    foreach ($permissoes_usuario as $modulo => $acoes) {
        if (!empty($acoes)) {
            $modulos_com_acesso++;
            $total_permissoes += count($acoes);
        }
    }
    
    // Preparar resposta
    $resposta = [
        'success' => true,
        'data' => [
            'usuario' => [
                'id' => (int)$usuario_info['usuario_id'],
                'nome' => $usuario_info['usuario_nome'],
                'login' => $usuario_info['usuario_login'],
                'departamento' => $usuario_info['usuario_departamento'],
                'nivel_id' => (int)$usuario_info['usuario_nivel_id'],
                'nivel_nome' => $usuario_info['usuario_nivel_id'] == 1 ? 'Administrador' : 'Usuário',
                'status' => $usuario_info['usuario_status']
            ],
            'permissoes' => $permissoes_usuario,
            'estatisticas' => [
                'total_permissoes' => $total_permissoes,
                'modulos_com_acesso' => $modulos_com_acesso,
                'e_administrador' => $usuario_info['usuario_nivel_id'] == 1,
                'ultima_consulta' => date('Y-m-d H:i:s')
            ]
        ],
        'message' => "Permissões do usuário '{$usuario_info['usuario_nome']}' carregadas com sucesso."
    ];
    
    echo json_encode($resposta, JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    // Log do erro
    error_log("Erro ao carregar permissões - Usuário ID: {$usuario_id}, Erro: " . $e->getMessage());
    
    echo json_encode([
        'success' => false, 
        'message' => 'Erro ao buscar permissões no banco de dados.',
        'error_code' => $e->getCode()
    ]);
    
} catch (Exception $e) {
    // Log do erro
    error_log("Erro geral ao carregar permissões: " . $e->getMessage());
    
    echo json_encode([
        'success' => false, 
        'message' => 'Erro interno do sistema.'
    ]);
}

/**
 * Função auxiliar para verificar se um usuário tem uma permissão específica
 * Pode ser usada em outras partes do sistema
 */
function verificarPermissaoUsuario($conn, $usuario_id, $modulo, $acao) {
    try {
        // Verificar se o usuário é admin
        $stmt = $conn->prepare("SELECT usuario_nivel_id FROM tb_usuarios_sistema WHERE usuario_id = :id");
        $stmt->bindParam(':id', $usuario_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Admin tem todas as permissões
            if ($usuario['usuario_nivel_id'] == 1) {
                return true;
            }
        }
        
        // Verificar permissão específica
        $stmt = $conn->prepare("
            SELECT COUNT(*) as tem_permissao 
            FROM tb_permissoes_usuario 
            WHERE usuario_id = :usuario_id 
            AND modulo = :modulo 
            AND acao = :acao 
            AND ativo = TRUE
        ");
        $stmt->bindParam(':usuario_id', $usuario_id);
        $stmt->bindParam(':modulo', $modulo);
        $stmt->bindParam(':acao', $acao);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['tem_permissao'] > 0;
        
    } catch (PDOException $e) {
        error_log("Erro ao verificar permissão: " . $e->getMessage());
        return false;
    }
}
?>
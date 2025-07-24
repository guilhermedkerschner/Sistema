<?php
/**
 * Arquivo: includes/menu_config.php
 * Descrição: Configuração centralizada do menu do sistema
 * 
 * Este arquivo contém todas as configurações de menu, departamentos e permissões
 */

// Verificar se já foi incluído
if (!defined('MENU_CONFIG_LOADED')) {
    define('MENU_CONFIG_LOADED', true);

    // Configuração dos departamentos
    $departamentos_config = [
        'AGRICULTURA' => [
            'nome' => 'Agricultura',
            'icon' => 'fas fa-leaf',
            'color' => '#2e7d32',
            'cor_tema' => '#2e7d32',
            'ativo' => true,
            'submenu' => [
                'Projetos' => ['url' => 'agricultura_projetos.php', 'icon' => 'fas fa-project-diagram'],
                'Programas' => ['url' => 'agricultura_programas.php', 'icon' => 'fas fa-tasks'],
                'Relatórios' => ['url' => 'agricultura_relatorios.php', 'icon' => 'fas fa-chart-bar']
            ]
        ],
        'ASSISTENCIA_SOCIAL' => [
            'nome' => 'Assistência Social',
            'icon' => 'fas fa-hands-helping',
            'color' => '#e91e63',
            'cor_tema' => '#e91e63',
            'ativo' => true,
            'submenu' => [
                'Atendimentos' => ['url' => 'assistencia_atendimentos.php', 'icon' => 'fas fa-user-check'],
                'Benefícios' => ['url' => 'assistencia_beneficios.php', 'icon' => 'fas fa-gift'],
                'Programas Habitacionais' => ['url' => 'assistencia.php', 'icon' => 'fas fa-home'],
                'Relatórios' => ['url' => 'assistencia_relatorios.php', 'icon' => 'fas fa-chart-bar']
            ]
        ],
        'CULTURA_E_TURISMO' => [
            'nome' => 'Cultura e Turismo',
            'icon' => 'fas fa-palette',
            'color' => '#ff5722',
            'cor_tema' => '#ff5722',
            'ativo' => true,
            'submenu' => [
                'Eventos' => ['url' => 'cultura_eventos.php', 'icon' => 'fas fa-calendar-alt'],
                'Pontos Turísticos' => ['url' => 'cultura_pontos.php', 'icon' => 'fas fa-map-marked-alt'],
                'Programação Cultural' => ['url' => 'cultura_programacao.php', 'icon' => 'fas fa-theater-masks'],
                'Relatórios' => ['url' => 'cultura_relatorios.php', 'icon' => 'fas fa-chart-bar']
            ]
        ],
        'EDUCACAO' => [
            'nome' => 'Educação',
            'icon' => 'fas fa-graduation-cap',
            'color' => '#9c27b0',
            'cor_tema' => '#9c27b0',
            'ativo' => true,
            'submenu' => [
                'Escolas' => ['url' => 'educacao_escolas.php', 'icon' => 'fas fa-school'],
                'Professores' => ['url' => 'educacao_professores.php', 'icon' => 'fas fa-chalkboard-teacher'],
                'Alunos' => ['url' => 'educacao_alunos.php', 'icon' => 'fas fa-user-graduate'],
                'Transporte Escolar' => ['url' => 'educacao_transporte.php', 'icon' => 'fas fa-bus'],
                'Relatórios' => ['url' => 'educacao_relatorios.php', 'icon' => 'fas fa-chart-bar']
            ]
        ],
        'ESPORTE' => [
            'nome' => 'Esporte',
            'icon' => 'fas fa-running',
            'color' => '#4caf50',
            'cor_tema' => '#4caf50',
            'ativo' => true,
            'submenu' => [
                'Atletas' => ['url' => 'esporte_atletas.php', 'icon' => 'fas fa-user-friends'],
                'Campeonatos' => ['url' => 'esporte_campeonatos.php', 'icon' => 'fas fa-trophy'],
                'Espaços Físicos' => ['url' => 'esporte_espacos.php', 'icon' => 'fas fa-map-marker-alt'],
                'Equipe de Momento' => ['url' => 'esporte_equipe.php', 'icon' => 'fas fa-users-cog'],
                'Relatórios' => ['url' => 'esporte_relatorios.php', 'icon' => 'fas fa-chart-bar']
            ]
        ],
        'FAZENDA' => [
            'nome' => 'Fazenda',
            'icon' => 'fas fa-money-bill-wave',
            'color' => '#ff9800',
            'cor_tema' => '#ff9800',
            'ativo' => true,
            'submenu' => [
                'Orçamento' => ['url' => 'fazenda_orcamento.php', 'icon' => 'fas fa-calculator'],
                'Receitas' => ['url' => 'fazenda_receitas.php', 'icon' => 'fas fa-plus-circle'],
                'Despesas' => ['url' => 'fazenda_despesas.php', 'icon' => 'fas fa-minus-circle'],
                'Contratos' => ['url' => 'fazenda_contratos.php', 'icon' => 'fas fa-file-contract'],
                'Relatórios' => ['url' => 'fazenda_relatorios.php', 'icon' => 'fas fa-chart-bar']
            ]
        ],
        'FISCALIZACAO' => [
            'nome' => 'Fiscalização',
            'icon' => 'fas fa-search',
            'color' => '#673ab7',
            'cor_tema' => '#673ab7',
            'ativo' => true,
            'submenu' => [
                'Denúncias' => ['url' => 'fiscalizacao_denuncias.php', 'icon' => 'fas fa-exclamation-triangle'],
                'Fiscalizações' => ['url' => 'fiscalizacao_fiscalizacoes.php', 'icon' => 'fas fa-clipboard-check'],
                'Autuações' => ['url' => 'fiscalizacao_autuacoes.php', 'icon' => 'fas fa-file-invoice'],
                'Relatórios' => ['url' => 'fiscalizacao_relatorios.php', 'icon' => 'fas fa-chart-bar']
            ]
        ],
        'MEIO_AMBIENTE' => [
            'nome' => 'Meio Ambiente',
            'icon' => 'fas fa-tree',
            'color' => '#009688',
            'cor_tema' => '#009688',
            'ativo' => true,
            'submenu' => [
                'Licenciamentos' => ['url' => 'ambiente_licenciamentos.php', 'icon' => 'fas fa-file-alt'],
                'Projetos Ambientais' => ['url' => 'ambiente_projetos.php', 'icon' => 'fas fa-seedling'],
                'Monitoramento' => ['url' => 'ambiente_monitoramento.php', 'icon' => 'fas fa-chart-line'],
                'Relatórios' => ['url' => 'ambiente_relatorios.php', 'icon' => 'fas fa-chart-bar']
            ]
        ],
        'OBRAS' => [
            'nome' => 'Obras',
            'icon' => 'fas fa-hard-hat',
            'color' => '#795548',
            'cor_tema' => '#795548',
            'ativo' => true,
            'submenu' => [
                'Projetos' => ['url' => 'obras_projetos.php', 'icon' => 'fas fa-drafting-compass'],
                'Licitações' => ['url' => 'obras_licitacoes.php', 'icon' => 'fas fa-gavel'],
                'Andamento' => ['url' => 'obras_andamento.php', 'icon' => 'fas fa-tasks'],
                'Equipamentos' => ['url' => 'obras_equipamentos.php', 'icon' => 'fas fa-tools'],
                'Relatórios' => ['url' => 'obras_relatorios.php', 'icon' => 'fas fa-chart-bar']
            ]
        ],
        'RODOVIARIO' => [
            'nome' => 'Rodoviário',
            'icon' => 'fas fa-truck',
            'color' => '#607d8b',
            'cor_tema' => '#607d8b',
            'ativo' => true,
            'submenu' => [
                'Frota' => ['url' => 'rodoviario_frota.php', 'icon' => 'fas fa-car'],
                'Manutenção' => ['url' => 'rodoviario_manutencao.php', 'icon' => 'fas fa-wrench'],
                'Combustível' => ['url' => 'rodoviario_combustivel.php', 'icon' => 'fas fa-gas-pump'],
                'Relatórios' => ['url' => 'rodoviario_relatorios.php', 'icon' => 'fas fa-chart-bar']
            ]
        ],
        'SERVICOS_URBANOS' => [
            'nome' => 'Serviços Urbanos',
            'icon' => 'fas fa-city',
            'color' => '#2196f3',
            'cor_tema' => '#2196f3',
            'ativo' => true,
            'submenu' => [
                'Solicitações' => ['url' => 'urbanos_solicitacoes.php', 'icon' => 'fas fa-clipboard-list'],
                'Manutenções' => ['url' => 'urbanos_manutencoes.php', 'icon' => 'fas fa-tools'],
                'Coleta de Lixo' => ['url' => 'urbanos_coleta.php', 'icon' => 'fas fa-trash'],
                'Iluminação' => ['url' => 'urbanos_iluminacao.php', 'icon' => 'fas fa-lightbulb'],
                'Relatórios' => ['url' => 'urbanos_relatorios.php', 'icon' => 'fas fa-chart-bar']
            ]
        ]
    ];

    // Configuração dos menus administrativos
    $menus_admin = [
        'usuarios' => [
            'nome' => 'Gerenciar Usuários',
            'icon' => 'fas fa-users-cog',
            'submenu' => [
                'Lista de Usuários' => ['url' => 'lista_usuarios.php', 'icon' => 'fas fa-list'],
                'Adicionar Usuário' => ['url' => 'adicionar_usuario.php', 'icon' => 'fas fa-user-plus'],
                'Permissões' => ['url' => 'permissoes.php', 'icon' => 'fas fa-shield-alt']
            ]
        ],
        'relatorios' => [
            'nome' => 'Relatórios Gerais',
            'icon' => 'fas fa-chart-pie',
            'submenu' => [
                'Consolidado Geral' => ['url' => 'relatorios_geral.php', 'icon' => 'fas fa-chart-area'],
                'Por Departamento' => ['url' => 'relatorios_departamento.php', 'icon' => 'fas fa-chart-bar'],
                'Estatísticas' => ['url' => 'relatorios_estatisticas.php', 'icon' => 'fas fa-chart-pie']
            ]
        ],
        'sistema' => [
            'nome' => 'Sistema',
            'icon' => 'fas fa-cogs',
            'submenu' => [
                'Configurações' => ['url' => 'sistema_config.php', 'icon' => 'fas fa-cog'],
                'Backup' => ['url' => 'sistema_backup.php', 'icon' => 'fas fa-database'],
                'Logs' => ['url' => 'sistema_logs.php', 'icon' => 'fas fa-file-alt']
            ]
        ]
    ];

    // Função para obter configuração do departamento
    function getDepartamentoConfig($departamento_key) {
        global $departamentos_config;
        return $departamentos_config[$departamento_key] ?? null;
    }

    // Função para verificar se departamento está ativo
    function isDepartamentoAtivo($departamento_key) {
        global $departamentos_config;
        return ($departamentos_config[$departamento_key]['ativo'] ?? false);
    }

    // Função para obter cor do tema do departamento
    function getCorTemaDepartamento($departamento_key) {
        global $departamentos_config;
        return $departamentos_config[$departamento_key]['cor_tema'] ?? '#4caf50';
    }

    // Função para obter lista de departamentos ativos
    function getDepartamentosAtivos() {
        global $departamentos_config;
        return array_filter($departamentos_config, function($dept) {
            return $dept['ativo'] ?? false;
        });
    }

    // Função para verificar se usuário tem acesso ao departamento
    function usuarioTemAcessoDepartamento($usuario_departamento, $departamento_key, $is_admin = false) {
        if ($is_admin) {
            return true; // Admin tem acesso a tudo
        }
        
        return strtoupper($usuario_departamento) === strtoupper($departamento_key);
    }

    // Função para obter páginas atuais do departamento (para marcar menu ativo)
    function getPaginasDepartamento($departamento_key) {
        global $departamentos_config;
        $paginas = [];
        
        if (isset($departamentos_config[$departamento_key]['submenu'])) {
            foreach ($departamentos_config[$departamento_key]['submenu'] as $item => $config) {
                $pagina = basename($config['url'], '.php');
                $paginas[] = $pagina;
            }
        }
        
        // Adicionar página principal do departamento
        $paginas[] = strtolower($departamento_key);
        
        return $paginas;
    }

    // Função para verificar se menu deve estar aberto
    function isMenuDepartamentoAberto($departamento_key, $pagina_atual) {
        $paginas_departamento = getPaginasDepartamento($departamento_key);
        return in_array($pagina_atual, $paginas_departamento);
    }

    // Função para verificar se submenu está ativo
    function isSubmenuAtivo($url, $pagina_atual) {
        $pagina_url = basename($url, '.php');
        return $pagina_url === $pagina_atual;
    }

    // Função para buscar departamentos do banco de dados (se necessário)
    function buscarDepartamentosBanco($conn) {
        try {
            $stmt = $conn->query("
                SELECT DISTINCT usuario_departamento as departamento 
                FROM tb_usuarios_sistema 
                WHERE usuario_departamento IS NOT NULL 
                AND usuario_departamento != ''
                ORDER BY usuario_departamento
            ");
            
            $departamentos_banco = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $dept_key = strtoupper($row['departamento']);
                $departamentos_banco[] = $dept_key;
            }
            
            return $departamentos_banco;
            
        } catch (PDOException $e) {
            error_log("Erro ao buscar departamentos: " . $e->getMessage());
            return [];
        }
    }

    // Função para sincronizar departamentos (adicionar novos encontrados no banco)
    function sincronizarDepartamentos($conn) {
        global $departamentos_config;
        
        $departamentos_banco = buscarDepartamentosBanco($conn);
        
        foreach ($departamentos_banco as $dept_key) {
            if (!isset($departamentos_config[$dept_key])) {
                // Adicionar departamento não configurado com configuração padrão
                $nome_dept = ucwords(strtolower(str_replace('_', ' ', $dept_key)));
                
                $departamentos_config[$dept_key] = [
                    'nome' => $nome_dept,
                    'icon' => 'fas fa-building',
                    'color' => '#6c757d',
                    'cor_tema' => '#6c757d',
                    'ativo' => false, // Inativo por padrão até ser configurado
                    'submenu' => [
                        'Relatórios' => ['url' => strtolower($dept_key) . '_relatorios.php', 'icon' => 'fas fa-chart-bar']
                    ]
                ];
            }
        }
    }

    // Configurações específicas por página
    $configuracao_paginas = [
        'esporte_atletas' => [
            'titulo' => 'Gerenciamento de Atletas',
            'icone' => 'fas fa-user-friends',
            'breadcrumb' => ['Dashboard', 'Esporte', 'Atletas']
        ],
        'esporte_campeonatos' => [
            'titulo' => 'Gerenciamento de Campeonatos',
            'icone' => 'fas fa-trophy',
            'breadcrumb' => ['Dashboard', 'Esporte', 'Campeonatos']
        ],
        'esporte_espacos' => [
            'titulo' => 'Espaços Físicos',
            'icone' => 'fas fa-map-marker-alt',
            'breadcrumb' => ['Dashboard', 'Esporte', 'Espaços Físicos']
        ],
        'esporte_equipe' => [
            'titulo' => 'Equipe de Momento',
            'icone' => 'fas fa-users-cog',
            'breadcrumb' => ['Dashboard', 'Esporte', 'Equipe de Momento']
        ]
    ];

    // Função para obter configuração da página
    function getConfiguracaPagina($pagina_atual) {
        global $configuracao_paginas;
        return $configuracao_paginas[$pagina_atual] ?? [
            'titulo' => 'Sistema da Prefeitura',
            'icone' => 'fas fa-home',
            'breadcrumb' => ['Dashboard']
        ];
    }
}
?>
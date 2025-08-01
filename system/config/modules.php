<?php
/**
 * Configuração Central dos Módulos do Sistema
 * Prefeitura Municipal de Santa Izabel do Oeste
 */

return [
    'dashboard' => [
        'info' => [
            'name' => 'Dashboard',
            'description' => 'Painel principal do sistema',
            'icon' => 'fas fa-tachometer-alt',
            'category' => 'system',
            'order' => 0
        ],
        'files' => [
            'main' => 'dashboard.php'
        ],
        'menu' => [
            'parent' => false,
            'submenu' => []
        ],
        'permissions' => [
            'levels' => [1, 2, 3, 4],
            'departments' => ['all']
        ]
    ],

    'usuarios' => [
        'info' => [
            'name' => 'Gerenciar Usuários',
            'description' => 'Administração de usuários do sistema',
            'icon' => 'fas fa-users-cog',
            'category' => 'admin',
            'order' => 1
        ],
        'files' => [
            'main' => 'lista_usuarios.php'
        ],
        'menu' => [
            'parent' => true,
            'submenu' => [
                'lista' => [
                    'name' => 'Lista de Usuários',
                    'files' => ['main' => 'lista_usuarios.php']
                ],
                'adicionar' => [
                    'name' => 'Adicionar Usuário',
                    'files' => ['main' => 'adicionar_usuario.php']
                ],
                'permissoes' => [
                    'name' => 'Permissões',
                    'files' => ['main' => 'permissoes.php']
                ],
                'eaicidadao' => [
                    'name' => 'Usuários Eai-Cidadão',
                    'files' => ['main' => 'usuarios_eaicidadao.php']
                ]
            ]
        ],
        'permissions' => [
            'levels' => [1],
            'departments' => ['all']
        ]
    ],

    'relatorios' => [
        'info' => [
            'name' => 'Relatórios Gerais',
            'description' => 'Relatórios do sistema',
            'icon' => 'fas fa-chart-pie',
            'category' => 'admin',
            'order' => 2
        ],
        'files' => [
            'main' => 'relatorios_gerais.php'
        ],
        'menu' => [
            'parent' => true,
            'submenu' => [
                'consolidado' => [
                    'name' => 'Consolidado Geral',
                    'files' => ['main' => 'relatorios_consolidado.php']
                ],
                'departamentos' => [
                    'name' => 'Por Departamento',
                    'files' => ['main' => 'relatorios_departamentos.php']
                ],
                'estatisticas' => [
                    'name' => 'Estatísticas',
                    'files' => ['main' => 'relatorios_estatisticas.php']
                ]
            ]
        ],
        'permissions' => [
            'levels' => [1, 2],
            'departments' => ['all']
        ]
    ],

    'agricultura' => [
        'info' => [
            'name' => 'Agricultura',
            'description' => 'Gestão de projetos e programas agrícolas',
            'icon' => 'fa-solid fa-tractor',
            'category' => 'department',
            'order' => 10
        ],
        'files' => [
            'main' => 'agricultura.php'
        ],
        'menu' => [
            'parent' => true,
            'submenu' => [
                'paginainicial' => [
                    'name' => 'Pagina Inicial',
                    'files' => ['main' => 'agricultura.php']
                ],
                'cadastros' => [
                    'name' => 'Cadastros',
                    'files' => ['main' => 'agricultura_cadastros.php']
                ],
                'requerimentos' => [
                    'name' => 'Requerimentos',
                    'files' => ['main' => 'agricultura_requerimentos.php']
                ],
                'promaq' => [
                    'name' => 'Promaq',
                    'files' => ['main' => 'agricultura_promaq.php']
                ],
                'relatorios' => [
                    'name' => 'Relatórios',
                    'files' => ['main' => 'agricultura_relatorios.php']
                ],
            ],
        ],
        'permissions' => [
            'levels' => [1, 2, 3, 4],
            'departments' => ['ADMINISTRAÇÃO','Tecnologia da Informação','AGRICULTURA']
        ]
    ],

    'assistencia_social' => [
        'info' => [
            'name' => 'Assistência Social',
            'description' => 'Programas sociais e habitacionais',
            'icon' => 'fas fa-hand-holding-heart',
            'category' => 'department',
            'order' => 11
        ],
        'files' => [
            'main' => 'assistencia.php'
        ],
        'menu' => [
            'parent' => true,
            'submenu' => [
                'paginainicialassistencia' => [
                    'name' => 'Pagina Inicial',
                    'files' => ['main' => 'assistencia.php']
                ],
                'habitacao' => [
                    'name' => 'Habitação',
                    'files' => ['main' => 'assistencia_habitacao.php']
                ]
            ]
        ],
        'permissions' => [
            'levels' => [1, 2, 3, 4],
            'departments' => ['ADMINISTRAÇÃO','ASSISTENCIA_SOCIAL']
        ]
    ],

    'esporte' => [
        'info' => [
            'name' => 'Esporte',
            'description' => 'Gestão de atletas e modalidades',
            'icon' => 'fas fa-running',
            'category' => 'department',
            'order' => 12
        ],
        'files' => [
            'main' => 'esporte.php'
        ],
        'menu' => [
            'parent' => true,
            'submenu' => [
                'inicial' => [
                    'name' => 'Página inicial',
                    'files' => [
                        'main' => 'esporte.php'
                    ]
                ],
                'atletas' => [
                    'name' => 'Atletas',
                    'files' => [
                        'main' => 'esporte_atletas.php'
                    ]
                ],
                'equipes' => [
                    'name' => 'Equipes',
                    'files' => [
                        'main' => 'esporte_equipe.php'
                    ]
                ],
                'campeonatos' => [
                    'name' => 'Campeonatos',
                    'files' => [
                        'main' => 'esporte_campeonatos.php',
                        'campeonato_equipes.php'
                    ]
                ]
            ]
        ],
        'permissions' => [
            'levels' => [1, 2, 3, 4],
            'departments' => ['ADMINISTRAÇÃO','ESPORTE']
        ]
    ]
];
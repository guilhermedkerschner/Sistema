<?php
/**
 * Configuração de Permissões do Sistema
 * Prefeitura Municipal de Santa Izabel do Oeste
 * 
 * Define as ações possíveis, hierarquia de acesso e regras de permissões
 */

return [
    // =====================================
    // NÍVEIS DE USUÁRIO (hierárquico - menor número = mais poder)
    // =====================================
    'user_levels' => [
        1 => [
            'name' => 'Administrador',
            'slug' => 'admin',
            'description' => 'Acesso total ao sistema, gerenciamento de usuários e configurações',
            'color' => '#e74c3c',
            'icon' => 'fas fa-crown',
            'inherits_from' => null,
            'can_manage_users' => true,
            'can_access_all_modules' => true,
            'can_view_logs' => true,
            'can_modify_permissions' => true
        ],
        2 => [
            'name' => 'Gestor',
            'slug' => 'manager',
            'description' => 'Gestão completa do departamento, aprovações e relatórios',
            'color' => '#f39c12',
            'icon' => 'fas fa-user-tie',
            'inherits_from' => null,
            'can_manage_users' => false,
            'can_access_all_modules' => false,
            'can_view_logs' => true,
            'can_modify_permissions' => false
        ],
        3 => [
            'name' => 'Funcionário',
            'slug' => 'employee',
            'description' => 'Operações do dia a dia, criação e edição de registros',
            'color' => '#3498db',
            'icon' => 'fas fa-user',
            'inherits_from' => null,
            'can_manage_users' => false,
            'can_access_all_modules' => false,
            'can_view_logs' => false,
            'can_modify_permissions' => false
        ],
        4 => [
            'name' => 'Consulta',
            'slug' => 'viewer',
            'description' => 'Apenas visualização de dados e relatórios básicos',
            'color' => '#95a5a6',
            'icon' => 'fas fa-eye',
            'inherits_from' => null,
            'can_manage_users' => false,
            'can_access_all_modules' => false,
            'can_view_logs' => false,
            'can_modify_permissions' => false
        ]
    ],

    // =====================================
    // AÇÕES DISPONÍVEIS NO SISTEMA
    // =====================================
    'actions' => [
        'view' => [
            'name' => 'Visualizar',
            'description' => 'Visualizar registros, relatórios e dados',
            'icon' => 'fas fa-eye',
            'requires_approval' => false,
            'log_action' => false
        ],
        'create' => [
            'name' => 'Criar',
            'description' => 'Criar novos registros e cadastros',
            'icon' => 'fas fa-plus',
            'requires_approval' => false,
            'log_action' => true
        ],
        'edit' => [
            'name' => 'Editar',
            'description' => 'Editar registros existentes',
            'icon' => 'fas fa-edit',
            'requires_approval' => false,
            'log_action' => true
        ],
        'delete' => [
            'name' => 'Excluir',
            'description' => 'Excluir registros (ação irreversível)',
            'icon' => 'fas fa-trash',
            'requires_approval' => true,
            'log_action' => true
        ],
        'manage' => [
            'name' => 'Gerenciar',
            'description' => 'Gestão completa do módulo (todas as ações)',
            'icon' => 'fas fa-cogs',
            'requires_approval' => false,
            'log_action' => true
        ],
        'export' => [
            'name' => 'Exportar',
            'description' => 'Exportar dados e relatórios',
            'icon' => 'fas fa-download',
            'requires_approval' => false,
            'log_action' => true
        ],
        'approve' => [
            'name' => 'Aprovar',
            'description' => 'Aprovar solicitações e processos',
            'icon' => 'fas fa-check-circle',
            'requires_approval' => false,
            'log_action' => true
        ],
        'audit' => [
            'name' => 'Auditoria',
            'description' => 'Acessar logs, auditoria e histórico completo',
            'icon' => 'fas fa-search',
            'requires_approval' => false,
            'log_action' => true
        ],
        'configure' => [
            'name' => 'Configurar',
            'description' => 'Configurar parâmetros e definições do módulo',
            'icon' => 'fas fa-sliders-h',
            'requires_approval' => true,
            'log_action' => true
        ],
        'import' => [
            'name' => 'Importar',
            'description' => 'Importar dados externos',
            'icon' => 'fas fa-upload',
            'requires_approval' => true,
            'log_action' => true
        ]
    ],

    // =====================================
    // PERMISSÕES PADRÃO POR NÍVEL
    // =====================================
    'default_permissions' => [
        1 => ['view', 'create', 'edit', 'delete', 'manage', 'export', 'approve', 'audit', 'configure', 'import'], // Admin - TODAS
        2 => ['view', 'create', 'edit', 'delete', 'manage', 'export', 'approve', 'audit'], // Gestor - sem configurações críticas
        3 => ['view', 'create', 'edit', 'export'], // Funcionário - operações básicas
        4 => ['view'] // Consulta - apenas visualização
    ],

    // =====================================
    // GRUPOS DE PERMISSÕES PRÉ-DEFINIDOS
    // =====================================
    'permission_groups' => [
        'full_access' => [
            'name' => 'Acesso Completo',
            'description' => 'Todas as permissões disponíveis',
            'permissions' => ['view', 'create', 'edit', 'delete', 'manage', 'export', 'approve', 'audit', 'configure', 'import'],
            'color' => '#e74c3c'
        ],
        'manager_access' => [
            'name' => 'Acesso de Gestão',
            'description' => 'Gestão completa exceto configurações do sistema',
            'permissions' => ['view', 'create', 'edit', 'delete', 'manage', 'export', 'approve', 'audit'],
            'color' => '#f39c12'
        ],
        'operator_access' => [
            'name' => 'Acesso Operacional',
            'description' => 'Operações do dia a dia',
            'permissions' => ['view', 'create', 'edit', 'export'],
            'color' => '#3498db'
        ],
        'read_only' => [
            'name' => 'Somente Leitura',
            'description' => 'Apenas visualização de dados',
            'permissions' => ['view'],
            'color' => '#95a5a6'
        ],
        'financial_operator' => [
            'name' => 'Operador Financeiro',
            'description' => 'Específico para módulos financeiros',
            'permissions' => ['view', 'create', 'edit', 'export', 'approve'],
            'color' => '#27ae60'
        ]
    ],

    // =====================================
    // PERMISSÕES ESPECIAIS (sobrescreve as padrão)
    // =====================================
    'special_permissions' => [
        // Super Administrador
        'super_admin' => [
            'description' => 'Acesso irrestrito ao sistema',
            'modules' => ['ALL'],
            'actions' => ['ALL'],
            'departments' => ['ALL'],
            'restrictions' => []
        ],
        
        // Administrador de Departamento
        'department_admin' => [
            'description' => 'Administrador específico de departamento',
            'modules' => ['DEPARTMENT_MODULES'], // Será resolvido dinamicamente
            'actions' => ['view', 'create', 'edit', 'delete', 'manage', 'export', 'approve', 'audit'],
            'departments' => ['USER_DEPARTMENT'], // Será resolvido dinamicamente
            'restrictions' => ['cannot_delete_self', 'cannot_modify_admin_users']
        ],
        
        // Gestor Financeiro
        'financial_manager' => [
            'description' => 'Gestão específica para área financeira',
            'modules' => ['fazenda', 'dashboard'],
            'actions' => ['view', 'create', 'edit', 'manage', 'export', 'approve', 'audit'],
            'departments' => ['FAZENDA'],
            'restrictions' => ['require_approval_for_large_amounts']
        ],
        
        // Auditor
        'auditor' => [
            'description' => 'Acesso especial para auditoria',
            'modules' => ['ALL'],
            'actions' => ['view', 'audit', 'export'],
            'departments' => ['ALL'],
            'restrictions' => ['read_only_access', 'extended_log_access']
        ],
        
        // Operador de Assistência Social
        'social_operator' => [
            'description' => 'Operador específico da assistência social',
            'modules' => ['assistencia_social', 'dashboard'],
            'actions' => ['view', 'create', 'edit', 'export'],
            'departments' => ['ASSISTENCIA_SOCIAL'],
            'restrictions' => ['cannot_approve_high_value_benefits']
        ]
    ],

    // =====================================
    // RESTRIÇÕES POR MÓDULO
    // =====================================
    'module_restrictions' => [
        'admin' => [
            'min_level' => 1,
            'required_permissions' => ['audit', 'manage'],
            'required_departments' => ['ALL'],
            'time_restrictions' => [],
            'ip_restrictions' => [],
            'additional_checks' => ['must_be_admin']
        ],
        
        'fazenda' => [
            'min_level' => 2,
            'required_permissions' => ['approve'],
            'required_departments' => ['FAZENDA', 'ALL'],
            'time_restrictions' => ['business_hours'], // 8h-18h
            'ip_restrictions' => [], // Pode adicionar IPs específicos
            'additional_checks' => ['financial_clearance']
        ],
        
        'assistencia_social' => [
            'min_level' => 3,
            'required_permissions' => ['view', 'create'],
            'required_departments' => ['ASSISTENCIA_SOCIAL', 'ALL'],
            'time_restrictions' => [],
            'ip_restrictions' => [],
            'additional_checks' => []
        ],
        
        'obras' => [
            'min_level' => 2,
            'required_permissions' => ['manage'],
            'required_departments' => ['OBRAS', 'ALL'],
            'time_restrictions' => [],
            'ip_restrictions' => [],
            'additional_checks' => ['engineering_qualification']
        ],
        
        'educacao' => [
            'min_level' => 3,
            'required_permissions' => ['view', 'edit'],
            'required_departments' => ['EDUCACAO', 'ALL'],
            'time_restrictions' => [],
            'ip_restrictions' => [],
            'additional_checks' => []
        ]
    ],

    // =====================================
    // REGRAS DE HERANÇA DE PERMISSÕES
    // =====================================
    'inheritance_rules' => [
        'level_inheritance' => true, // Níveis menores herdam permissões dos maiores
        'department_inheritance' => false, // Departamentos não herdam entre si
        'module_inheritance' => [
            // Módulos que herdam permissões de outros
            'agriculture_reports' => 'agricultura',
            'social_reports' => 'assistencia_social',
            'financial_reports' => 'fazenda'
        ]
    ],

    // =====================================
    // CONFIGURAÇÕES DE SEGURANÇA
    // =====================================
    'security_settings' => [
        'session_timeout' => 3600, // 1 hora em segundos
        'max_failed_attempts' => 3,
        'lockout_duration' => 900, // 15 minutos
        'require_strong_passwords' => true,
        'force_password_change' => [
            'enabled' => true,
            'days' => 90
        ],
        'two_factor_auth' => [
            'enabled' => false,
            'required_for_levels' => [1] // Apenas admins
        ],
        'ip_whitelist' => [
            'enabled' => false,
            'admin_only' => true,
            'allowed_ips' => []
        ],
        'audit_log' => [
            'enabled' => true,
            'log_all_actions' => true,
            'retention_days' => 365
        ]
    ],

    // =====================================
    // HORÁRIOS DE FUNCIONAMENTO
    // =====================================
    'time_restrictions' => [
        'business_hours' => [
            'enabled' => true,
            'start_time' => '08:00',
            'end_time' => '18:00',
            'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'timezone' => 'America/Sao_Paulo',
            'exceptions' => [] // Feriados, etc.
        ],
        'maintenance_window' => [
            'enabled' => false,
            'start_time' => '02:00',
            'end_time' => '04:00',
            'days' => ['sunday'],
            'allow_admin_access' => true
        ]
    ],

    // =====================================
    // NOTIFICAÇÕES E ALERTAS
    // =====================================
    'notification_settings' => [
        'permission_changes' => [
            'enabled' => true,
            'notify_user' => true,
            'notify_admin' => true,
            'email_notification' => true
        ],
        'suspicious_activity' => [
            'enabled' => true,
            'failed_login_threshold' => 3,
            'unusual_ip_access' => true,
            'after_hours_access' => true
        ],
        'system_alerts' => [
            'high_privilege_actions' => true,
            'bulk_operations' => true,
            'data_export' => true
        ]
    ],

    // =====================================
    // VALIDAÇÕES CUSTOMIZADAS
    // =====================================
    'custom_validations' => [
        'financial_operations' => [
            'max_amount_without_approval' => 10000.00,
            'require_dual_approval_above' => 50000.00,
            'audit_all_transactions' => true
        ],
        'user_management' => [
            'admin_cannot_delete_self' => true,
            'require_approval_for_admin_creation' => true,
            'min_admins_required' => 1
        ],
        'data_protection' => [
            'mask_sensitive_data' => true,
            'encrypt_personal_info' => true,
            'gdpr_compliance' => true
        ]
    ],

    // =====================================
    // TEMPLATES DE PERMISSÕES POR CARGO
    // =====================================
    'role_templates' => [
        'prefeito' => [
            'level' => 1,
            'permissions' => 'full_access',
            'modules' => ['ALL'],
            'departments' => ['ALL']
        ],
        'secretario' => [
            'level' => 2,
            'permissions' => 'manager_access',
            'modules' => ['DEPARTMENT_MODULES'],
            'departments' => ['USER_DEPARTMENT']
        ],
        'coordenador' => [
            'level' => 2,
            'permissions' => 'manager_access',
            'modules' => ['DEPARTMENT_MODULES'],
            'departments' => ['USER_DEPARTMENT']
        ],
        'tecnico' => [
            'level' => 3,
            'permissions' => 'operator_access',
            'modules' => ['DEPARTMENT_MODULES'],
            'departments' => ['USER_DEPARTMENT']
        ],
        'assistente' => [
            'level' => 3,
            'permissions' => 'operator_access',
            'modules' => ['DEPARTMENT_MODULES'],
            'departments' => ['USER_DEPARTMENT']
        ],
        'estagiario' => [
            'level' => 4,
            'permissions' => 'read_only',
            'modules' => ['DEPARTMENT_MODULES'],
            'departments' => ['USER_DEPARTMENT']
        ]
    ]
];
<?php
/**
 * Configuração de Tema e Cores Padrão do Sistema
 * Prefeitura Municipal de Santa Izabel do Oeste
 */

return [
    // =====================================
    // CORES PADRÃO DO SISTEMA
    // =====================================
    'colors' => [
        // Cores principais do sistema
        'primary' => '#4169E1',      // Azul escuro - sidebar e elementos principais
        'secondary' => '#4169E1',     // Azul médio - botões secundários
        'success' => '#27ae60',       // Verde - sucessos e aprovações
        'danger' => '#e74c3c',        // Vermelho - erros e exclusões
        'warning' => '#f39c12',       // Laranja - avisos e pendências
        'info' => '#17a2b8',          // Azul claro - informações
        'light' => '#ecf0f1',         // Cinza claro - backgrounds
        'dark' => '#333',          // Escuro - textos principais
        
        // Cores administrativas
        'admin' => '#4169E1',         // Vermelho - administração
        'system' => '#4169E1',        // Cinza - sistema
        
        // Cores departamentais (paleta harmoniosa)
        'department_1' => '#3498db',  // Azul
        'department_2' => '#9b59b6',  // Roxo
        'department_3' => '#e91e63',  // Rosa
        'department_4' => '#f39c12',  // Laranja
        'department_5' => '#27ae60',  // Verde
        'department_6' => '#2ecc71',  // Verde claro
        'department_7' => '#16a085',  // Verde azulado
        'department_8' => '#8e44ad',  // Roxo escuro
        'department_9' => '#d35400',  // Laranja escuro
        'department_10' => '#c0392b', // Vermelho escuro
        'department_11' => '#7f8c8d', // Cinza azulado
    ],

    // =====================================
    // MAPEAMENTO DE CORES POR CATEGORIA
    // =====================================
    'category_colors' => [
        'system' => '#6c757d',        // Módulos do sistema
        'admin' => '#e74c3c',         // Módulos administrativos
        'department' => 'auto',       // Será atribuída automaticamente
        'user' => '#6c757d',          // Módulos do usuário
        'report' => '#17a2b8',        // Relatórios
        'integration' => '#6f42c1',   // Integrações
    ],

    // =====================================
    // CONFIGURAÇÕES DE TEMA
    // =====================================
    'theme' => [
        'sidebar_width' => '250px',
        'sidebar_collapsed_width' => '70px',
        'header_height' => '60px',
        'border_radius' => '8px',
        'box_shadow' => '0 2px 5px rgba(0, 0, 0, 0.1)',
        'transition' => 'all 0.3s ease',
    ],

    // =====================================
    // ÍCONES PADRÃO POR CATEGORIA
    // =====================================
    'default_icons' => [
        'system' => 'fas fa-cogs',
        'admin' => 'fas fa-shield-alt',
        'department' => 'fas fa-building',
        'user' => 'fas fa-user',
        'report' => 'fas fa-chart-bar',
        'integration' => 'fas fa-plug',
    ]
];
<?php
/**
 * Gerenciador de Menus e Permissões
 * Classe responsável por carregar, filtrar e gerar menus baseado nas permissões do usuário
 */

class MenuManager {
    private $modules;
    private $permissions;
    private $theme;
    private $departments;
    private $userSession;
    private $departmentColorIndex = 0;

    public function __construct($userSession = null) {
        // Verificar e carregar arquivos de configuração com validação
        $this->loadConfigurations();
        $this->userSession = $userSession;
        
        // Atribuir cores automaticamente aos módulos departamentais
        $this->assignDepartmentColors();
    }

    /**
     * Carrega as configurações com validação de erro
     */
    private function loadConfigurations() {
        // Carregar módulos
        $modulesFile = __DIR__ . '/../config/modules.php';
        if (file_exists($modulesFile)) {
            $this->modules = require_once $modulesFile;
        }
        if (!is_array($this->modules)) {
            $this->modules = $this->getDefaultModules();
        }

        // Carregar permissões
        $permissionsFile = __DIR__ . '/../config/permissions.php';
        if (file_exists($permissionsFile)) {
            $this->permissions = require_once $permissionsFile;
        }
        if (!is_array($this->permissions)) {
            $this->permissions = $this->getDefaultPermissions();
        }

        // Carregar tema
        $themeFile = __DIR__ . '/../config/theme.php';
        if (file_exists($themeFile)) {
            $this->theme = require_once $themeFile;
        }
        if (!is_array($this->theme)) {
            $this->theme = $this->getDefaultTheme();
        }

        // Carregar departamentos
        $departmentsFile = __DIR__ . '/../config/departments.php';
        if (file_exists($departmentsFile)) {
            $this->departments = require_once $departmentsFile;
        }
        if (!is_array($this->departments)) {
            $this->departments = $this->getDefaultDepartments();
        }
    }

    /**
     * Retorna configuração padrão de módulos
     */
    private function getDefaultModules() {
        return [
            'dashboard' => [
                'info' => [
                    'name' => 'Dashboard',
                    'icon' => 'fas fa-tachometer-alt',
                    'category' => 'admin',
                    'color' => '#007bff'
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
            ]
        ];
    }

    /**
     * Retorna configuração padrão de permissões
     */
    private function getDefaultPermissions() {
        return [
            'levels' => [
                1 => ['name' => 'Administrador', 'access' => 'all'],
                2 => ['name' => 'Coordenador', 'access' => 'department'],
                3 => ['name' => 'Operador', 'access' => 'limited'],
                4 => ['name' => 'Usuário', 'access' => 'basic']
            ]
        ];
    }

    /**
     * Retorna configuração padrão de tema
     */
    private function getDefaultTheme() {
        return [
            'colors' => [
                'primary' => '#007bff',
                'secondary' => '#6c757d',
                'success' => '#28a745',
                'danger' => '#dc3545',
                'warning' => '#ffc107',
                'info' => '#17a2b8',
                'admin' => '#e74c3c',
                'department_1' => '#4caf50'
            ],
            'category_colors' => [
                'admin' => '#dc3545',
                'department' => '#007bff',
                'user' => '#28a745',
                'report' => '#ffc107'
            ]
        ];
    }

    /**
     * Retorna configuração padrão de departamentos
     */
    private function getDefaultDepartments() {
        return [
            'ADMINISTRACAO' => ['name' => 'Administração', 'color' => '#dc3545'],
            'ESPORTE' => ['name' => 'Esporte', 'color' => '#4caf50'],
            'ASSISTENCIA_SOCIAL' => ['name' => 'Assistência Social', 'color' => '#28a745']
        ];
    }

    /**
     * Atribui cores automaticamente aos módulos departamentais
     */
    private function assignDepartmentColors() {
        $departmentColorIndex = 1;
        
        foreach ($this->modules as $key => &$module) {
            // Verificar se o módulo tem a estrutura esperada
            if (!isset($module['info']) || !is_array($module['info'])) {
                $module['info'] = ['category' => 'user', 'color' => '#6c757d'];
            }

            if (($module['info']['category'] ?? '') === 'department') {
                $colorKey = 'department_' . $departmentColorIndex;
                $module['info']['color'] = $this->theme['colors'][$colorKey] ?? $this->theme['colors']['department_1'] ?? '#007bff';
                $departmentColorIndex++;
                
                // Reset se passar do limite de cores disponíveis
                if ($departmentColorIndex > 11) {
                    $departmentColorIndex = 1;
                }
            } else {
                // Atribuir cor baseada na categoria
                $category = $module['info']['category'] ?? 'user';
                $module['info']['color'] = $this->theme['category_colors'][$category] ?? $this->theme['colors']['secondary'] ?? '#6c757d';
            }
        }
    }

    /**
     * Retorna todos os módulos disponíveis para o usuário atual
     */
    public function getAvailableModules() {
        if (!$this->userSession) {
            return [];
        }

        $availableModules = [];
        $userLevel = $this->userSession['usuario_nivel_id'] ?? 4;
        $userDepartment = strtoupper($this->userSession['usuario_departamento'] ?? '');

        foreach ($this->modules as $key => $module) {
            // Verificar estrutura do módulo - aceitar tanto 'permissions' quanto 'access'
            $permissions = null;
            if (isset($module['permissions'])) {
                $permissions = $module['permissions'];
            } elseif (isset($module['access'])) {
                // Converter estrutura 'access' para 'permissions'
                $permissions = [
                    'levels' => range($module['access']['min_level'] ?? 4, 4),
                    'departments' => $module['access']['departments'] ?? ['all']
                ];
            }

            if (!$permissions) {
                continue;
            }
            
            // Verificar se o usuário tem permissão por nível
            $hasLevelPermission = in_array($userLevel, $permissions['levels'] ?? []);
            
            // Se é admin (nível 1), tem acesso a tudo
            if ($userLevel == 1) {
                $hasLevelPermission = true;
            }
            
            // Verificar se o usuário tem permissão por departamento
            $departments = $permissions['departments'] ?? [];
            $hasDepartmentPermission = in_array('all', $departments) || 
                                     in_array('ALL', $departments) ||
                                     in_array($userDepartment, $departments);
            
            if ($hasLevelPermission && $hasDepartmentPermission) {
                $availableModules[$key] = $module;
            }
        }

        return $availableModules;
    }

    /**
     * Gera o HTML da sidebar com base nos módulos disponíveis
     */
    public function generateSidebar($currentPage = '') {
        return $this->generateMenu($currentPage);
    }

    /**
     * Gera HTML do menu
     */
    public function generateMenu($currentPage = '') {
        $availableModules = $this->getAvailableModules();
        $groupedModules = $this->groupModulesByCategory($availableModules);
        
        $html = '<ul class="menu">';
        
        foreach ($groupedModules as $category => $modules) {
            if ($category !== 'user') {
                $html .= '<div class="menu-separator"></div>';
                $html .= '<div class="menu-category">' . $this->getCategoryName($category) . '</div>';
            }
            
            foreach ($modules as $key => $module) {
                $html .= $this->generateMenuItemHTML($key, $module, $currentPage);
            }
        }
        
        // Adicionar itens especiais do usuário
        $html .= '<div class="menu-separator"></div>';
        $html .= $this->generateUserMenuItems();
        
        $html .= '</ul>';
        
        return $html;
    }

    /**
     * Retorna as cores do tema atual baseado no usuário
     */
    public function getThemeColors() {
        $isAdmin = ($this->userSession['usuario_nivel_id'] ?? 4) == 1;
        $userDepartment = strtoupper($this->userSession['usuario_departamento'] ?? '');
        
        $colors = [
            'primary' => $this->theme['colors']['secondary'] ?? '#3498db',
            'title' => 'Sistema'
        ];
        
        if ($isAdmin) {
            $colors['primary'] = $this->theme['colors']['admin'] ?? '#e74c3c';
            $colors['title'] = 'Administração Geral';
        } elseif (isset($this->departments[$userDepartment])) {
            $colors['primary'] = $this->departments[$userDepartment]['color'] ?? '#3498db';
            $colors['title'] = $this->departments[$userDepartment]['name'] ?? 'Sistema';
        }
        
        return $colors;
    }

    /**
     * Agrupa módulos por categoria
     */
    private function groupModulesByCategory($modules) {
        $grouped = [];
        
        foreach ($modules as $key => $module) {
            $category = $module['info']['category'] ?? 'user';
            
            // Reorganizar ordem das categorias
            if ($category === 'admin') $category = 'admin';
            elseif ($category === 'department') $category = 'department';
            else $category = 'user';
            
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            
            $grouped[$category][$key] = $module;
        }
        
        return $grouped;
    }

    /**
     * Retorna o nome da categoria para exibição
     */
    private function getCategoryName($category) {
        $names = [
            'admin' => 'Administração',
            'department' => 'Departamentos',
            'user' => 'Usuário',
            'report' => 'Relatórios',
            'integration' => 'Integrações'
        ];
        
        return $names[$category] ?? ucfirst($category);
    }

    /**
     * Gera HTML para um item de menu
     */
    private function generateMenuItemHTML($key, $module, $currentPage) {
        $info = $module['info'] ?? [];
        $menu = $module['menu'] ?? [];
        $isActive = $this->isModuleActive($key, $currentPage);
        $hasSubmenu = ($menu['parent'] ?? false) && !empty($menu['submenu'] ?? []);
        
        $html = '<li class="menu-item' . ($isActive && $hasSubmenu ? ' open' : '') . '">';
        
        if ($hasSubmenu) {
            // Item com submenu
            $html .= '<a href="#" class="menu-link' . ($isActive ? ' active' : '') . '">';
            $html .= '<span class="menu-icon"><i class="' . ($info['icon'] ?? 'fas fa-circle') . '"></i></span>';
            $html .= '<span class="menu-text">' . ($info['name'] ?? 'Item') . '</span>';
            $html .= '<span class="arrow"><i class="fas fa-chevron-right"></i></span>';
            $html .= '</a>';
            
            // Submenu
            $html .= '<ul class="submenu">';
            foreach ($menu['submenu'] as $subKey => $subItem) {
                $subFile = $subItem['files']['main'] ?? '';
                $subIsActive = $this->isPageActive($subFile, $currentPage);
                
                $html .= '<li>';
                $html .= '<a href="' . $subFile . '" class="submenu-link' . ($subIsActive ? ' active' : '') . '">';
                $html .= ($subItem['name'] ?? 'Subitem');
                $html .= '</a>';
                $html .= '</li>';
            }
            $html .= '</ul>';
        } else {
            // Item simples
            $mainFile = $module['files']['main'] ?? '#';
            $html .= '<a href="' . $mainFile . '" class="menu-link' . ($isActive ? ' active' : '') . '">';
            $html .= '<span class="menu-icon"><i class="' . ($info['icon'] ?? 'fas fa-circle') . '"></i></span>';
            $html .= '<span class="menu-text">' . ($info['name'] ?? 'Item') . '</span>';
            $html .= '</a>';
        }
        
        $html .= '</li>';
        
        return $html;
    }

    /**
     * Gera itens especiais do menu do usuário
     */
    private function generateUserMenuItems() {
        $html = '<li class="menu-item">';
        $html .= '<a href="../controller/logout_system.php" class="menu-link">';
        $html .= '<span class="menu-icon"><i class="fas fa-sign-out-alt"></i></span>';
        $html .= '<span class="menu-text">Sair</span>';
        $html .= '</a>';
        $html .= '</li>';
        
        return $html;
    }

    /**
     * Verifica se um módulo está ativo
     */
    private function isModuleActive($moduleKey, $currentPage) {
        $module = $this->modules[$moduleKey] ?? null;
        if (!$module || !is_array($module)) return false;
        
        // Verificar arquivo principal
        if (isset($module['files']['main']) && $this->isPageActive($module['files']['main'], $currentPage)) {
            return true;
        }
        
        // Verificar submenu
        if (isset($module['menu']['submenu']) && is_array($module['menu']['submenu'])) {
            foreach ($module['menu']['submenu'] as $subItem) {
                if (isset($subItem['files']) && is_array($subItem['files'])) {
                    foreach ($subItem['files'] as $file) {
                        if ($this->isPageActive($file, $currentPage)) {
                            return true;
                        }
                    }
                }
            }
        }
        
        return false;
    }

    /**
     * Verifica se uma página está ativa
     */
    private function isPageActive($file, $currentPage) {
        $filePage = basename($file, '.php');
        $currentPageClean = basename($currentPage, '.php');
        
        // Verificações específicas para páginas relacionadas
        if ($filePage === 'esporte_campeonatos' && $currentPageClean === 'campeonato_equipes') {
            return true;
        }
        
        return $filePage === $currentPageClean;
    }

    /**
     * Gera breadcrumb para a página atual
     */
    public function generateBreadcrumb($currentPage) {
        $breadcrumb = ['Dashboard' => 'dashboard.php'];
        
        foreach ($this->modules as $key => $module) {
            if ($this->isModuleActive($key, $currentPage)) {
                $moduleName = $module['info']['name'] ?? 'Página';
                $mainFile = $module['files']['main'] ?? '#';
                $breadcrumb[$moduleName] = $mainFile;
                break;
            }
        }
        
        return $breadcrumb;
    }

    /**
     * Retorna configurações de departamentos
     */
    public function getDepartments() {
        return $this->departments;
    }
}
?>
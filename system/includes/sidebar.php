<?php
/**
 * Arquivo: includes/sidebar.php
 * Menu lateral dinâmico baseado na configuração
 */

// Incluir configuração do menu se não foi incluída
if (!defined('MENU_CONFIG_LOADED')) {
    require_once 'menu_config.php';
}

// Página atual para destacar menu ativo
$pagina_atual = basename($_SERVER['PHP_SELF'], '.php');

// Sincronizar departamentos do banco (opcional)
if (isset($conn)) {
    sincronizarDepartamentos($conn);
}

// Obter departamentos ativos
$departamentos_ativos = getDepartamentosAtivos();
?>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3><?php echo $titulo_sistema ?? 'Sistema'; ?></h3>
        <button class="toggle-btn">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
    <ul class="menu">
        <!-- Dashboard -->
        <li class="menu-item">
            <a href="dashboard.php" class="menu-link <?php echo ($pagina_atual == 'dashboard') ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span>
                <span class="menu-text">Dashboard</span>
            </a>
        </li>
        
        <?php if ($is_admin ?? false): ?>
        <!-- Menus administrativos -->
        <div class="menu-separator"></div>
        <div class="menu-category">Administração</div>
        
        <?php foreach ($menus_admin as $menu_key => $menu_config): ?>
        <li class="menu-item">
            <a href="#" class="menu-link">
                <span class="menu-icon"><i class="<?php echo $menu_config['icon']; ?>"></i></span>
                <span class="menu-text"><?php echo $menu_config['nome']; ?></span>
                <span class="arrow"><i class="fas fa-chevron-right"></i></span>
            </a>
            <ul class="submenu">
                <?php foreach ($menu_config['submenu'] as $item_nome => $item_config): ?>
                <li><a href="<?php echo $item_config['url']; ?>" class="submenu-link <?php echo isSubmenuAtivo($item_config['url'], $pagina_atual) ? 'active' : ''; ?>">
                    <i class="<?php echo $item_config['icon']; ?> me-2"></i><?php echo $item_nome; ?>
                </a></li>
                <?php endforeach; ?>
            </ul>
        </li>
        <?php endforeach; ?>
        
        <!-- Departamentos para admin -->
        <div class="menu-separator"></div>
        <div class="menu-category">Departamentos</div>
        
        <?php foreach ($departamentos_ativos as $dept_key => $dept_config): ?>
        <li class="menu-item <?php echo isMenuDepartamentoAberto($dept_key, $pagina_atual) ? 'open' : ''; ?>">
            <a href="#" class="menu-link">
                <span class="menu-icon"><i class="<?php echo $dept_config['icon']; ?>"></i></span>
                <span class="menu-text"><?php echo $dept_config['nome']; ?></span>
                <span class="arrow"><i class="fas fa-chevron-right"></i></span>
            </a>
            <ul class="submenu">
                <?php foreach ($dept_config['submenu'] as $item_nome => $item_config): ?>
                <li><a href="<?php echo $item_config['url']; ?>" class="submenu-link <?php echo isSubmenuAtivo($item_config['url'], $pagina_atual) ? 'active' : ''; ?>">
                    <i class="<?php echo $item_config['icon']; ?> me-2"></i><?php echo $item_nome; ?>
                </a></li>
                <?php endforeach; ?>
            </ul>
        </li>
        <?php endforeach; ?>
        
        <?php else: ?>
        <!-- Menu específico do departamento para usuários normais -->
        <?php 
        $usuario_dept = strtoupper($usuario_departamento ?? '');
        if (isset($departamentos_ativos[$usuario_dept]) && usuarioTemAcessoDepartamento($usuario_departamento, $usuario_dept, false)): 
            $dept_config = $departamentos_ativos[$usuario_dept];
        ?>
        <div class="menu-separator"></div>
        <div class="menu-category"><?php echo $dept_config['nome']; ?></div>
        
        <li class="menu-item <?php echo isMenuDepartamentoAberto($usuario_dept, $pagina_atual) ? 'open' : ''; ?>">
            <a href="#" class="menu-link">
                <span class="menu-icon"><i class="<?php echo $dept_config['icon']; ?>"></i></span>
                <span class="menu-text"><?php echo $dept_config['nome']; ?></span>
                <span class="arrow"><i class="fas fa-chevron-right"></i></span>
            </a>
            <ul class="submenu">
                <?php foreach ($dept_config['submenu'] as $item_nome => $item_config): ?>
                <li><a href="<?php echo $item_config['url']; ?>" class="submenu-link <?php echo isSubmenuAtivo($item_config['url'], $pagina_atual) ? 'active' : ''; ?>">
                    <i class="<?php echo $item_config['icon']; ?> me-2"></i><?php echo $item_nome; ?>
                </a></li>
                <?php endforeach; ?>
            </ul>
        </li>
        <?php endif; ?>
        <?php endif; ?>
        
        <!-- Menu comum -->
        <div class="menu-separator"></div>
        
        <li class="menu-item">
            <a href="perfil.php" class="menu-link <?php echo ($pagina_atual == 'perfil') ? 'active' : ''; ?>">
                <span class="menu-icon"><i class="fas fa-user-cog"></i></span>
                <span class="menu-text">Meu Perfil</span>
            </a>
        </li>
        
        <li class="menu-item">
            <a href="../controller/logout_system.php" class="menu-link">
                <span class="menu-icon"><i class="fas fa-sign-out-alt"></i></span>
                <span class="menu-text">Sair</span>
            </a>
        </li>
    </ul>
</div>
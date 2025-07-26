<?php
/**
 * Header Include
 * Sistema da Prefeitura
 */
?>

<!-- Header Completo -->
<div class="header">
    <!-- Logo do Sistema (esquerda) -->
    <div class="header-logo">
        <button class="mobile-toggle" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <div class="logo-placeholder">
            <i class="fas fa-city"></i>
        </div>
        <div class="system-title">SGM - Eai Cidadão!</div>
    </div>

    <!-- Barra de Pesquisa (centro) -->
    <div class="header-search">
        <div class="search-container">
            <i class="fas fa-search search-icon"></i>
            <input type="text" class="search-input" placeholder="Pesquisar módulos, usuários, documentos..." id="globalSearch">
        </div>
    </div>

    <!-- Menu do Usuário (direita) -->
    <div class="user-menu" id="userMenu">
        <button class="user-trigger" onclick="toggleUserMenu()">
            <div class="user-avatar">
                <?php echo strtoupper(substr($usuario_dados['usuario_nome'], 0, 2)); ?>
                <?php if ($is_admin): ?>
                    <div class="notification-badge">!</div>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($usuario_dados['usuario_nome']); ?></div>
                <div class="user-email"><?php echo htmlspecialchars($usuario_dados['usuario_email']); ?></div>
            </div>
            <i class="fas fa-chevron-down dropdown-arrow"></i>
        </button>

        <div class="user-dropdown">
            <div class="dropdown-header">
                <div class="dropdown-user-name"><?php echo htmlspecialchars($usuario_dados['usuario_nome']); ?></div>
                <div class="dropdown-user-email"><?php echo htmlspecialchars($usuario_dados['usuario_email']); ?></div>
            </div>
            <div class="dropdown-menu">
                <a href="perfil.php" class="dropdown-item">
                    <i class="fas fa-user"></i>
                    Meu Perfil
                </a>
                <a href="configuracoes.php" class="dropdown-item">
                    <i class="fas fa-cog"></i>
                    Configurações
                </a>
                <?php if ($is_admin): ?>
                <div class="dropdown-divider"></div>
                <a href="lista_usuarios.php" class="dropdown-item">
                    <i class="fas fa-users-cog"></i>
                    Gerenciar Usuários
                </a>
                <a href="sistema_config.php" class="dropdown-item">
                    <i class="fas fa-tools"></i>
                    Configurações do Sistema
                </a>
                <?php endif; ?>
                <div class="dropdown-divider"></div>
                <a href="ajuda.php" class="dropdown-item">
                    <i class="fas fa-question-circle"></i>
                    Ajuda e Suporte
                </a>
                <a href="../controller/logout_system.php" class="dropdown-item danger">
                    <i class="fas fa-sign-out-alt"></i>
                    Sair
                </a>
            </div>
        </div>
    </div>
</div>
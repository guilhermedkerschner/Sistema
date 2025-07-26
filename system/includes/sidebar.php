<?php
/**
 * Sidebar Include
 * Sistema da Prefeitura
 */
?>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3><?php echo $themeColors['title']; ?></h3>
        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
    <?php echo $menuManager->generateSidebar(basename(__FILE__, '.php')); ?>
</div>
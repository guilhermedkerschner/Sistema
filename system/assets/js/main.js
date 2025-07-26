/**
 * Main JavaScript
 * Sistema da Prefeitura
 */

// Variáveis globais
let sidebarCollapsed = false;
let userMenuOpen = false;

// Inicialização
document.addEventListener('DOMContentLoaded', function() {
    initializePage();
    initializeSearch();
});

function initializePage() {
    // Event listeners
    document.addEventListener('click', handleDocumentClick);
    window.addEventListener('resize', handleWindowResize);
    
    // Submenu toggle
    const menuItems = document.querySelectorAll('.menu-item');
    menuItems.forEach(function(item) {
        const menuLink = item.querySelector('.menu-link');
        if (menuLink && menuLink.querySelector('.arrow')) {
            menuLink.addEventListener('click', function(e) {
                e.preventDefault();
                item.classList.toggle('open');
                menuItems.forEach(function(otherItem) {
                   if (otherItem !== item && otherItem.classList.contains('open')) {
                       otherItem.classList.remove('open');
                   }
               });
           });
       }
   });

   // Handle window resize
   handleWindowResize();
}

function toggleSidebar() {
   const sidebar = document.querySelector('.sidebar');
   const mainContent = document.querySelector('.main-content');
   
   if (window.innerWidth <= 768) {
       // No mobile, comportamento normal (mostrar/esconder)
       sidebar.classList.toggle('show');
   } else {
       // No desktop, colapsar para caixinha
       sidebar.classList.toggle('collapsed');
       mainContent.classList.toggle('expanded');
       sidebarCollapsed = !sidebarCollapsed;
   }
}

function toggleUserMenu() {
   const userMenu = document.getElementById('userMenu');
   userMenu.classList.toggle('open');
   userMenuOpen = !userMenuOpen;
}

function initializeSearch() {
   const searchInput = document.getElementById('globalSearch');
   
   searchInput.addEventListener('input', function(e) {
       const query = e.target.value.toLowerCase();
       
       if (query.length > 2) {
           // Aqui você pode implementar a lógica de pesquisa
           console.log('Pesquisando por:', query);
       }
   });
}

// Event handlers
function handleDocumentClick(e) {
   // Fechar user menu ao clicar fora
   const userMenu = document.getElementById('userMenu');
   if (!userMenu.contains(e.target) && userMenuOpen) {
       userMenu.classList.remove('open');
       userMenuOpen = false;
   }
   
   // Fechar sidebar no mobile ao clicar fora
   if (window.innerWidth <= 768) {
       const sidebar = document.querySelector('.sidebar');
       const isClickInsideSidebar = sidebar.contains(e.target);
       const isToggleBtn = e.target.closest('.mobile-toggle');
       
       if (!isClickInsideSidebar && !isToggleBtn && sidebar.classList.contains('show')) {
          sidebar.classList.remove('show');
      }
  }
}

function handleWindowResize() {
  if (window.innerWidth > 768) {
      const sidebar = document.querySelector('.sidebar');
      sidebar.classList.remove('show');
  }
}

// Atalhos de teclado
document.addEventListener('keydown', function(e) {
  // Ctrl+K para focar na pesquisa
  if (e.ctrlKey && e.key === 'k') {
      e.preventDefault();
      document.getElementById('globalSearch').focus();
  }
  
  // ESC para fechar modais e menus
  if (e.key === 'Escape') {
      // Fechar user menu
      const userMenu = document.getElementById('userMenu');
      if (userMenuOpen) {
          userMenu.classList.remove('open');
          userMenuOpen = false;
      }
      
      // Fechar sidebar no mobile
      if (window.innerWidth <= 768) {
          const sidebar = document.querySelector('.sidebar');
          if (sidebar.classList.contains('show')) {
              sidebar.classList.remove('show');
          }
      }
  }
});

// Função para expandir sidebar no hover (desktop)
function addHoverExpansion() {
   const sidebar = document.querySelector('.sidebar');
   let hoverTimeout;
   
   if (sidebar) {
       sidebar.addEventListener('mouseenter', function() {
           hoverTimeout = setTimeout(() => {
               if (this.classList.contains('collapsed') && window.innerWidth > 768) {
                   this.style.width = '280px';
                   this.style.overflow = 'visible';
                   
                   // Mostrar conteúdo
                   const hiddenElements = this.querySelectorAll('.menu-text, .sidebar-header h3, .menu, .menu-separator, .menu-category');
                   hiddenElements.forEach(el => {
                       el.style.display = el.classList.contains('menu') ? 'block' : 
                                       el.classList.contains('menu-text') ? 'inline' : 
                                       'block';
                   });
               }
           }, 200);
       });
       
       sidebar.addEventListener('mouseleave', function() {
           clearTimeout(hoverTimeout);
           if (this.classList.contains('collapsed') && window.innerWidth > 768) {
               this.style.width = '60px';
               this.style.overflow = 'hidden';
               
               // Esconder conteúdo novamente
               const hiddenElements = this.querySelectorAll('.menu-text, .sidebar-header h3, .menu, .menu-separator, .menu-category');
               hiddenElements.forEach(el => {
                   el.style.display = 'none';
               });
           }
       });
   }
}

// Chamar função de hover expansion
addHoverExpansion();
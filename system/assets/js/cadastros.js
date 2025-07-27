// Controlar exibição dos formulários
function toggleFormulario(tipo) {
    const form = document.getElementById('formCadastro' + tipo.charAt(0).toUpperCase() + tipo.slice(1));
    const btn = document.querySelector('.btn-cadastrar');
    
    if (form.classList.contains('show')) {
        form.classList.remove('show');
        btn.innerHTML = '<i class="fas fa-plus"></i> Cadastrar Novo ' + getTipoSingular(tipo);
    } else {
        form.classList.add('show');
        btn.innerHTML = '<i class="fas fa-minus"></i> Ocultar Formulário';
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function getTipoSingular(tipo) {
    const tipos = {
        'produtores': 'Produtor',
        'bancos': 'Banco', 
        'comunidades': 'Comunidade',
        'servicos': 'Serviço',
        'maquinas': 'Máquina',
        'veterinarios': 'Veterinário'
    };
    return tipos[tipo] || tipo;
}

function cancelarCadastro(tipo) {
    if (confirm('Tem certeza que deseja cancelar? Todos os dados preenchidos serão perdidos.')) {
        const formId = getFormId(tipo);
        document.getElementById(formId).reset();
        toggleFormulario(tipo);
    }
}

function getFormId(tipo) {
    const forms = {
        'produtores': 'formProdutor',
        'bancos': 'formBanco',
        'comunidades': 'formComunidade', 
        'servicos': 'formServico',
        'maquinas': 'formMaquina',
        'veterinarios': 'formVeterinario'
    };
    return forms[tipo];
}

// Máscara para CPF
function mascaraCPF(cpf) {
    cpf = cpf.replace(/\D/g, '');
    cpf = cpf.replace(/(\d{3})(\d)/, '$1.$2');
    cpf = cpf.replace(/(\d{3})(\d)/, '$1.$2');
    cpf = cpf.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    return cpf;
}

// Máscara para telefone
function mascaraTelefone(telefone) {
    telefone = telefone.replace(/\D/g, '');
    if (telefone.length <= 10) {
        telefone = telefone.replace(/(\d{2})(\d)/, '($1) $2');
        telefone = telefone.replace(/(\d{4})(\d)/, '$1-$2');
    } else {
        telefone = telefone.replace(/(\d{2})(\d)/, '($1) $2');
        telefone = telefone.replace(/(\d{5})(\d)/, '$1-$2');
    }
    return telefone;
}

// Aplicar máscaras
document.addEventListener('DOMContentLoaded', function() {
    // CPF
    const cpfInputs = document.querySelectorAll('input[id*="cpf"]');
    cpfInputs.forEach(input => {
        input.addEventListener('input', function(e) {
            e.target.value = mascaraCPF(e.target.value);
        });
    });

    // Telefone
    const telefoneInputs = document.querySelectorAll('input[id*="telefone"]');
    telefoneInputs.forEach(input => {
        input.addEventListener('input', function(e) {
            e.target.value = mascaraTelefone(e.target.value);
        });
    });

    // Auto-preencher dados do titular (produtores)
    if (document.getElementById('nome')) {
        document.getElementById('nome').addEventListener('blur', function() {
            if (!document.getElementById('titular_nome').value) {
                document.getElementById('titular_nome').value = this.value;
            }
        });

        document.getElementById('cpf').addEventListener('blur', function() {
            if (!document.getElementById('titular_cpf').value) {
                document.getElementById('titular_cpf').value = this.value;
            }
        });

        document.getElementById('telefone').addEventListener('blur', function() {
            if (!document.getElementById('titular_telefone').value) {
                document.getElementById('titular_telefone').value = this.value;
            }
        });
    }

    // Submissão automática dos filtros
    const filtroInputs = document.querySelectorAll('.filtro-input, .filtro-select');
    filtroInputs.forEach(input => {
        if (input.type === 'text') {
            let timeout;
            input.addEventListener('input', function() {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    input.closest('form').submit();
                }, 1000);
            });
        } else {
            input.addEventListener('change', function() {
                input.closest('form').submit();
            });
        }
    });
});

// Funções de CRUD
function editarRegistro(tipo, id) {
    window.location.href = `editar_${tipo.slice(0, -1)}.php?id=${id}`;
}

function excluirRegistro(tipo, id, nome) {
    if (!confirm(`Tem certeza que deseja excluir "${nome}"?`)) {
        return;
    }

    if (!confirm(`ATENÇÃO: Esta ação não pode ser desfeita!\n\n"${nome}" será removido permanentemente do sistema.\n\nDeseja realmente continuar?`)) {
        return;
    }

    // Mostrar loading
    const loadingHtml = `
        <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                   background: rgba(0,0,0,0.5); display: flex; align-items: center; 
                   justify-content: center; z-index: 9999;">
            <div style="background: white; padding: 30px; border-radius: 10px; text-align: center;">
                <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #4169E1; margin-bottom: 15px;"></i>
                <p>Excluindo registro...</p>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', loadingHtml);

    // Fazer requisição AJAX
    fetch(`controller/excluir_${tipo.slice(0, -1)}.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ id: id })
    })
    .then(response => response.json())
    .then(data => {
        // Remover loading
        document.querySelector('[style*="z-index: 9999"]').remove();
        
        if (data.success) {
            // Mostrar mensagem de sucesso
            const successAlert = `
                <div class="alert alert-success" style="position: fixed; top: 20px; right: 20px; z-index: 1000; min-width: 300px;">
                    <i class="fas fa-check-circle"></i>
                    ${data.message}
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', successAlert);
            
            // Remover alerta após 3 segundos e recarregar página
            setTimeout(() => {
                location.reload();
            }, 2000);
        } else {
            // Mostrar mensagem de erro
            const errorAlert = `
                <div class="alert alert-error" style="position: fixed; top: 20px; right: 20px; z-index: 1000; min-width: 300px;">
                    <i class="fas fa-exclamation-circle"></i>
                    Erro: ${data.message}
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', errorAlert);
            
            // Remover alerta após 5 segundos
            setTimeout(() => {
                document.querySelector('.alert-error').remove();
            }, 5000);
        }
    })
    .catch(error => {
        // Remover loading
        document.querySelector('[style*="z-index: 9999"]').remove();
        
        console.error('Error:', error);
        const errorAlert = `
            <div class="alert alert-error" style="position: fixed; top: 20px; right: 20px; z-index: 1000; min-width: 300px;">
                <i class="fas fa-exclamation-circle"></i>
                Erro interno do sistema. Tente novamente.
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', errorAlert);
        
        setTimeout(() => {
            document.querySelector('.alert-error').remove();
        }, 5000);
    });
}

// Ajustar layout para sidebar
function adjustMainContent() {
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');
    
    if (sidebar && mainContent) {
        if (sidebar.classList.contains('collapsed')) {
            mainContent.classList.add('sidebar-collapsed');
        } else {
            mainContent.classList.remove('sidebar-collapsed');
        }
    }
}

// Observar mudanças na sidebar
const sidebarObserver = new MutationObserver(adjustMainContent);
const sidebar = document.querySelector('.sidebar');
if (sidebar) {
    sidebarObserver.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
}

// Remover alertas automaticamente
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-20px)';
            setTimeout(() => {
                alert.remove();
            }, 300);
        }, 5000);
    });

    adjustMainContent();
});
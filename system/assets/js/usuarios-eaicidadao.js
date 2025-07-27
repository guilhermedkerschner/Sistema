/**
 * JavaScript Específico - Usuários E-aiCidadão
 * Sistema da Prefeitura
 */

document.addEventListener('DOMContentLoaded', function() {
    
    // Aplicar máscaras
    applyMasks();
    
    // Validação de formulário
    setupFormValidation();
    
    // Busca de CEP
    setupCepSearch();
    
    // Auto-dismiss de alertas
    setupAlertDismiss();
    
});

// Aplicar máscaras de input
function applyMasks() {
    // Máscara para CPF
    const cpfInputs = document.querySelectorAll('.cpf-mask');
    cpfInputs.forEach(input => {
        input.addEventListener('input', function() {
            let value = this.value.replace(/\D/g, '');
            value = value.replace(/(\d{3})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            this.value = value;
        });
    });

    // Máscara para CEP
    const cepInputs = document.querySelectorAll('.cep-mask');
    cepInputs.forEach(input => {
        input.addEventListener('input', function() {
            let value = this.value.replace(/\D/g, '');
            value = value.replace(/(\d{5})(\d)/, '$1-$2');
            this.value = value;
        });
    });

    // Máscara para telefone
    const phoneInputs = document.querySelectorAll('.phone-mask');
    phoneInputs.forEach(input => {
        input.addEventListener('input', function() {
            let value = this.value.replace(/\D/g, '');
            if (value.length <= 10) {
                value = value.replace(/(\d{2})(\d)/, '($1) $2');
                value = value.replace(/(\d{4})(\d)/, '$1-$2');
            } else {
                value = value.replace(/(\d{2})(\d)/, '($1) $2');
                value = value.replace(/(\d{5})(\d)/, '$1-$2');
            }
            this.value = value;
        });
    });
}

// Configurar validação de formulário
function setupFormValidation() {
    const forms = document.querySelectorAll('.user-form');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!validateForm(this)) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
        
        // Validação em tempo real
        const inputs = form.querySelectorAll('input[required], select[required]');
        inputs.forEach(input => {
            input.addEventListener('blur', function() {
                validateField(this);
            });
        });
    });
}

// Validar formulário
function validateForm(form) {
    let isValid = true;
    const inputs = form.querySelectorAll('input[required], select[required]');
    
    inputs.forEach(input => {
        if (!validateField(input)) {
            isValid = false;
        }
    });
    
    // Validação específica de senha
    const senhaInput = form.querySelector('#cad_usu_senha');
    if (senhaInput && senhaInput.value.length > 0 && senhaInput.value.length < 6) {
        showFieldError(senhaInput, 'A senha deve ter pelo menos 6 caracteres');
        isValid = false;
    }
    
    // Validação de email
    const emailInput = form.querySelector('#cad_usu_email');
    if (emailInput && emailInput.value && !isValidEmail(emailInput.value)) {
        showFieldError(emailInput, 'Email inválido');
        isValid = false;
    }
    
    return isValid;
}

// Validar campo individual
function validateField(field) {
    clearFieldError(field);
    
    if (field.hasAttribute('required') && !field.value.trim()) {
        showFieldError(field, 'Este campo é obrigatório');
        return false;
    }
    
    return true;
}

// Mostrar erro no campo
function showFieldError(field, message) {
    field.classList.add('error');
    
    // Remover mensagem anterior se existir
    const existingError = field.parentNode.querySelector('.field-error');
    if (existingError) {
        existingError.remove();
    }
    
    // Adicionar nova mensagem
    const errorDiv = document.createElement('div');
    errorDiv.className = 'field-error';
    errorDiv.textContent = message;
    field.parentNode.appendChild(errorDiv);
}

// Limpar erro do campo
function clearFieldError(field) {
    field.classList.remove('error');
    const errorDiv = field.parentNode.querySelector('.field-error');
    if (errorDiv) {
        errorDiv.remove();
    }
}

// Validar email
function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

// Busca de CEP
function setupCepSearch() {
    const cepInput = document.getElementById('cad_usu_cep');
    
    if (cepInput) {
        cepInput.addEventListener('blur', function() {
            const cep = this.value.replace(/\D/g, '');
            
            if (cep.length === 8) {
                searchCep(cep);
            }
        });
    }
}

// Pesquisar CEP na API
function searchCep(cep) {
    const loading = document.createElement('div');
    loading.className = 'cep-loading';
    loading.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Buscando CEP...';
    
    const cepInput = document.getElementById('cad_usu_cep');
    cepInput.parentNode.appendChild(loading);
    
    fetch(`https://viacep.com.br/ws/${cep}/json/`)
        .then(response => response.json())
        .then(data => {
            loading.remove();
            
            if (!data.erro) {
                fillAddressFields(data);
                showMessage('CEP encontrado!', 'success');
            } else {
                showMessage('CEP não encontrado', 'warning');
            }
        })
        .catch(error => {
            loading.remove();
            console.error('Erro ao buscar CEP:', error);
            showMessage('Erro ao buscar CEP', 'error');
        });
}

// Preencher campos de endereço
function fillAddressFields(data) {
    const fields = {
        'cad_usu_endereco': data.logradouro,
        'cad_usu_bairro': data.bairro,
        'cad_usu_cidade': data.localidade,
        'cad_usu_estado': data.uf
    };
    
    Object.keys(fields).forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field && fields[fieldId]) {
            field.value = fields[fieldId];
        }
    });
}

// Configurar auto-dismiss de alertas
function setupAlertDismiss() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    
    alerts.forEach(alert => {
        // Auto-dismiss após 5 segundos
        setTimeout(() => {
            if (alert.parentNode) {
                alert.style.opacity = '0';
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.remove();
                    }
                }, 300);
            }
        }, 5000);
    });
}

// Função para confirmar exclusão
function confirmarExclusao(userId, nomeUsuario) {
    document.getElementById('userIdExcluir').value = userId;
    document.getElementById('nomeUsuarioExcluir').textContent = nomeUsuario;
    openModal('confirmDeleteModal');
}

// Funções de modal
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        
        // Animar entrada
        setTimeout(() => {
            modal.classList.add('show');
        }, 10);
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
        
        // Animar saída
        setTimeout(() => {
            modal.style.display = 'none';
        }, 300);
    }
}

// Fechar modal clicando fora
document.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal-overlay')) {
        const modal = event.target.closest('.modal');
        if (modal) {
            closeModal(modal.id);
        }
    }
});

// Fechar modal com ESC
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modals = document.querySelectorAll('.modal[style*="flex"]');
        modals.forEach(modal => {
            closeModal(modal.id);
        });
    }
});

// Mostrar mensagem
function showMessage(message, type = 'info') {
    const alertClass = type === 'error' ? 'alert-danger' : `alert-${type}`;
    const alertHtml = `
        <div class="alert ${alertClass} alert-dismissible">
            <div class="alert-content">
                <i class="fas fa-${getAlertIcon(type)}"></i>
                <span>${message}</span>
            </div>
            <button type="button" class="alert-close" onclick="this.parentElement.style.display='none'">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    const container = document.querySelector('.content-container');
    if (container) {
        container.insertAdjacentHTML('afterbegin', alertHtml);
        
        // Auto-dismiss
        setTimeout(() => {
            const alert = container.querySelector('.alert');
            if (alert) {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }
        }, 3000);
    }
}

// Obter ícone do alerta
function getAlertIcon(type) {
    const icons = {
        'success': 'check-circle',
        'error': 'exclamation-triangle',
        'warning': 'exclamation-triangle',
        'info': 'info-circle'
    };
    return icons[type] || 'info-circle';
}
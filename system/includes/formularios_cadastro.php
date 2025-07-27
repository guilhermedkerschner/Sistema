<!-- Formulário de Produtores -->
<div class="form-cadastro" id="formCadastroProdutores" style="<?= $aba_ativa === 'produtores' ? '' : 'display: none;' ?>">
    <form id="formProdutor" method="POST" action="controller/salvar_produtor.php">
        <div class="form-section">
            <h3><i class="fas fa-user"></i> Dados Pessoais</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="nome">Nome Completo *</label>
                    <input type="text" id="nome" name="nome" required>
                </div>
                <div class="form-group">
                    <label for="cpf">CPF *</label>
                    <input type="text" id="cpf" name="cpf" required maxlength="14" placeholder="000.000.000-00">
                </div>
                <div class="form-group">
                    <label for="telefone">Telefone</label>
                    <input type="text" id="telefone" name="telefone" maxlength="20" placeholder="(00) 00000-0000">
                </div>
                <div class="form-group">
                    <label for="comunidade_prod">Comunidade</label>
                    <select id="comunidade_prod" name="comunidade_id">
                        <option value="">Selecione uma comunidade</option>
                        <?php foreach ($comunidades as $comunidade): ?>
                            <option value="<?= $comunidade['com_id'] ?>"><?= htmlspecialchars($comunidade['com_nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="form-section">
            <h3><i class="fas fa-credit-card"></i> Dados Bancários</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="titular_nome">Nome do Titular *</label>
                    <input type="text" id="titular_nome" name="titular_nome" required>
                </div>
                <div class="form-group">
                    <label for="titular_cpf">CPF do Titular *</label>
                    <input type="text" id="titular_cpf" name="titular_cpf" required maxlength="14" placeholder="000.000.000-00">
                </div>
                <div class="form-group">
                    <label for="titular_telefone">Telefone do Titular</label>
                    <input type="text" id="titular_telefone" name="titular_telefone" maxlength="20" placeholder="(00) 00000-0000">
                </div>
                <div class="form-group">
                    <label for="banco">Banco *</label>
                    <select id="banco" name="banco_id" required>
                        <option value="">Selecione um banco</option>
                        <?php foreach ($bancos as $banco): ?>
                            <option value="<?= $banco['ban_id'] ?>"><?= $banco['ban_codigo'] . ' - ' . htmlspecialchars($banco['ban_nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="agencia">Agência *</label>
                    <input type="text" id="agencia" name="agencia" required maxlength="20" placeholder="0000">
                </div>
                <div class="form-group">
                    <label for="conta">Conta *</label>
                    <input type="text" id="conta" name="conta" required maxlength="30" placeholder="00000-0">
                </div>
                <div class="form-group">
                    <label for="tipo_conta">Tipo de Conta *</label>
                    <select id="tipo_conta" name="tipo_conta" required>
                        <option value="">Selecione o tipo</option>
                        <option value="corrente">Conta Corrente</option>
                        <option value="poupanca">Conta Poupança</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="form-buttons">
            <button type="button" class="btn-secondary" onclick="cancelarCadastro('produtores')">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <button type="submit" class="btn-primary">
                <i class="fas fa-save"></i> Salvar Produtor
            </button>
        </div>
    </form>
</div>

<!-- Formulário de Bancos -->
<div class="form-cadastro" id="formCadastroBancos" style="<?= $aba_ativa === 'bancos' ? '' : 'display: none;' ?>">
    <form id="formBanco" method="POST" action="controller/salvar_banco.php">
        <div class="form-section">
            <h3><i class="fas fa-university"></i> Dados do Banco</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="banco_codigo">Código do Banco *</label>
                    <input type="text" id="banco_codigo" name="banco_codigo" required maxlength="10" placeholder="Ex: 001, 033, 104">
                </div>
                <div class="form-group">
                    <label for="banco_nome">Nome do Banco *</label>
                    <input type="text" id="banco_nome" name="banco_nome" required maxlength="255" placeholder="Ex: Banco do Brasil S.A.">
                </div>
            </div>
        </div>

        <div class="form-buttons">
            <button type="button" class="btn-secondary" onclick="cancelarCadastro('bancos')">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <button type="submit" class="btn-primary">
                <i class="fas fa-save"></i> Salvar Banco
            </button>
        </div>
    </form>
</div>

<!-- Formulário de Comunidades -->
<div class="form-cadastro" id="formCadastroComunidades" style="<?= $aba_ativa === 'comunidades' ? '' : 'display: none;' ?>">
    <form id="formComunidade" method="POST" action="controller/salvar_comunidade.php">
        <div class="form-section">
            <h3><i class="fas fa-home"></i> Dados da Comunidade</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="comunidade_nome">Nome da Comunidade *</label>
                    <input type="text" id="comunidade_nome" name="comunidade_nome" required maxlength="255">
                </div>
                <div class="form-group">
                    <label for="comunidade_descricao">Descrição</label>
                    <textarea id="comunidade_descricao" name="comunidade_descricao" rows="3" placeholder="Descreva a localização ou características da comunidade"></textarea>
                </div>
            </div>
        </div>

        <div class="form-buttons">
            <button type="button" class="btn-secondary" onclick="cancelarCadastro('comunidades')">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <button type="submit" class="btn-primary">
                <i class="fas fa-save"></i> Salvar Comunidade
            </button>
        </div>
    </form>
</div>

<!-- Formulário de Serviços -->
<div class="form-cadastro" id="formCadastroServicos" style="<?= $aba_ativa === 'servicos' ? '' : 'display: none;' ?>">
    <form id="formServico" method="POST" action="controller/salvar_servico.php">
        <div class="form-section">
            <h3><i class="fas fa-cogs"></i> Dados do Serviço</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="servico_nome">Nome do Serviço *</label>
                    <input type="text" id="servico_nome" name="servico_nome" required maxlength="255">
                </div>
                <div class="form-group">
                    <label for="servico_secretaria">Secretaria *</label>
                    <select id="servico_secretaria" name="servico_secretaria_id" required>
                        <option value="">Selecione uma secretaria</option>
                        <?php foreach ($secretarias as $secretaria): ?>
                            <option value="<?= $secretaria['sec_id'] ?>"><?= htmlspecialchars($secretaria['sec_nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="form-buttons">
            <button type="button" class="btn-secondary" onclick="cancelarCadastro('servicos')">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <button type="submit" class="btn-primary">
                <i class="fas fa-save"></i> Salvar Serviço
            </button>
        </div>
    </form>
</div>

<!-- Formulário de Máquinas -->
<div class="form-cadastro" id="formCadastroMaquinas" style="<?= $aba_ativa === 'maquinas' ? '' : 'display: none;' ?>">
    <form id="formMaquina" method="POST" action="controller/salvar_maquina.php">
        <div class="form-section">
            <h3><i class="fas fa-tractor"></i> Dados da Máquina</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="maquina_nome">Nome do Maquinário *</label>
                    <input type="text" id="maquina_nome" name="maquina_nome" required maxlength="255">
                </div>
                <div class="form-group">
                    <label for="maquina_valor">Valor/Hora (R$) *</label>
                    <input type="number" id="maquina_valor" name="maquina_valor_hora" required step="0.01" min="0" placeholder="0,00">
                </div>
                <div class="form-group">
                    <label for="maquina_disponibilidade">Disponibilidade *</label>
                    <select id="maquina_disponibilidade" name="maquina_disponibilidade" required>
                        <option value="">Selecione</option>
                        <option value="disponivel">Disponível</option>
                        <option value="manutencao">Em Manutenção</option>
                        <option value="indisponivel">Indisponível</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="maquina_observacoes">Observações</label>
                    <textarea id="maquina_observacoes" name="maquina_observacoes" rows="3" placeholder="Observações sobre a máquina"></textarea>
                </div>
            </div>
        </div>

        <div class="form-buttons">
            <button type="button" class="btn-secondary" onclick="cancelarCadastro('maquinas')">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <button type="submit" class="btn-primary">
                <i class="fas fa-save"></i> Salvar Máquina
            </button>
        </div>
    </form>
</div>

<!-- Formulário de Veterinários -->
<div class="form-cadastro" id="formCadastroVeterinarios" style="<?= $aba_ativa === 'veterinarios' ? '' : 'display: none;' ?>">
    <form id="formVeterinario" method="POST" action="controller/salvar_veterinario.php">
        <div class="form-section">
            <h3><i class="fas fa-user-md"></i> Dados do Veterinário</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="veterinario_nome">Nome Completo *</label>
                    <input type="text" id="veterinario_nome" name="veterinario_nome" required maxlength="255">
                </div>
                <div class="form-group">
                    <label for="veterinario_cpf">CPF *</label>
                    <input type="text" id="veterinario_cpf" name="veterinario_cpf" required maxlength="14" placeholder="000.000.000-00">
                </div>
                <div class="form-group">
                    <label for="veterinario_crmv">CRMV *</label>
                    <input type="text" id="veterinario_crmv" name="veterinario_crmv" required maxlength="20" placeholder="Ex: CRMV-PR 1234">
               </div>
               <div class="form-group">
                   <label for="veterinario_telefone">Telefone</label>
                   <input type="text" id="veterinario_telefone" name="veterinario_telefone" maxlength="20" placeholder="(00) 00000-0000">
               </div>
           </div>
       </div>

       <div class="form-buttons">
           <button type="button" class="btn-secondary" onclick="cancelarCadastro('veterinarios')">
               <i class="fas fa-times"></i> Cancelar
           </button>
           <button type="submit" class="btn-primary">
               <i class="fas fa-save"></i> Salvar Veterinário
           </button>
       </div>
   </form>
</div>
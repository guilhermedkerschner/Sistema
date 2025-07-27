<div class="lista-container">
    <div class="lista-header">
        <h3 class="lista-title">
            <i class="<?= $config_aba['icone'] ?>"></i>
            <?= $config_aba['titulo'] ?> Encontrados
        </h3>
        <div class="contador-resultados">
            <?= count($dados_aba) ?> resultado(s)
        </div>
    </div>

    <?php if (count($dados_aba) > 0): ?>
        <div class="table-responsive">
            <?php switch ($aba_ativa): 
                case 'produtores': ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>CPF</th>
                                <th>Telefone</th>
                                <th>Comunidade</th>
                                <th>Banco</th>
                                <th>Status</th>
                                <th>Cadastrado em</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dados_aba as $item): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($item['cad_pro_nome']) ?></strong>
                                    <br>
                                    <small style="color: #6b7280;">
                                        Titular: <?= htmlspecialchars($item['cad_pro_titular_nome']) ?>
                                    </small>
                                </td>
                                <td><?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $item['cad_pro_cpf']) ?></td>
                                <td><?= htmlspecialchars($item['cad_pro_telefone']) ?></td>
                                <td><?= htmlspecialchars($item['com_nome'] ?? 'Não informada') ?></td>
                                <td>
                                    <?= $item['ban_codigo'] ?> - <?= htmlspecialchars($item['ban_nome']) ?>
                                    <br>
                                    <small style="color: #6b7280;">
                                        Ag: <?= $item['cad_pro_agencia'] ?> 
                                        Conta: <?= $item['cad_pro_conta'] ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $item['cad_pro_status'] ?>">
                                        <?= ucfirst($item['cad_pro_status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= date('d/m/Y H:i', strtotime($item['cad_pro_data_cadastro'])) ?>
                                    <br>
                                    <small style="color: #6b7280;">
                                        por <?= htmlspecialchars($item['cadastrado_por']) ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="acoes">
                                        <button class="btn-acao btn-editar" 
                                                onclick="editarRegistro('produtores', <?= $item['cad_pro_id'] ?>)"
                                                title="Editar">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button class="btn-acao btn-excluir" 
                                                onclick="excluirRegistro('produtores', <?= $item['cad_pro_id'] ?>, '<?= htmlspecialchars($item['cad_pro_nome']) ?>')"
                                                title="Excluir">
                                            <i class="fas fa-trash"></i> Excluir
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php break; 
                
                case 'bancos': ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nome</th>
                                <th>Status</th>
                                <th>Produtores Vinculados</th>
                                <th>Cadastrado em</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dados_aba as $item): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($item['ban_codigo']) ?></strong></td>
                                <td><strong><?= htmlspecialchars($item['ban_nome']) ?></strong></td>
                                <td>
                                    <span class="status-badge status-<?= $item['ban_status'] ?? 'ativo' ?>">
                                        <?= ucfirst($item['ban_status'] ?? 'ativo') ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="background: #e0f2fe; color: #0277bd; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                        <?= $item['total_produtores'] ?> produtores
                                    </span>
                                </td>
                                <td>
                                    <?= date('d/m/Y H:i', strtotime($item['ban_data_cadastro'])) ?>
                                    <br>
                                    <small style="color: #6b7280;">
                                        por <?= htmlspecialchars($item['cadastrado_por']) ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="acoes">
                                        <button class="btn-acao btn-editar" 
                                                onclick="editarRegistro('bancos', <?= $item['ban_id'] ?>)"
                                                title="Editar">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <?php if ($item['total_produtores'] == 0): ?>
                                        <button class="btn-acao btn-excluir" 
                                                onclick="excluirRegistro('bancos', <?= $item['ban_id'] ?>, '<?= htmlspecialchars($item['ban_nome']) ?>')"
                                                title="Excluir">
                                            <i class="fas fa-trash"></i> Excluir
                                        </button>
                                        <?php else: ?>
                                        <button class="btn-acao" 
                                                style="background: #94a3b8; cursor: not-allowed;"
                                                title="Não é possível excluir: há produtores vinculados"
                                                disabled>
                                            <i class="fas fa-lock"></i> Protegido
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php break; 
                
                case 'comunidades': ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Descrição</th>
                                <th>Produtores Vinculados</th>
                                <th>Status</th>
                                <th>Cadastrado em</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dados_aba as $item): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($item['com_nome']) ?></strong></td>
                                <td><?= htmlspecialchars($item['com_descricao'] ?? 'Sem descrição') ?></td>
                                <td>
                                    <span style="background: #e0f2fe; color: #0277bd; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                        <?= $item['total_produtores'] ?> produtores
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $item['com_status'] ?? 'ativo' ?>">
                                        <?= ucfirst($item['com_status'] ?? 'ativo') ?>
                                    </span>
                                </td>
                                <td>
                                    <?= date('d/m/Y H:i', strtotime($item['com_data_cadastro'])) ?>
                                    <br>
                                    <small style="color: #6b7280;">
                                        por <?= htmlspecialchars($item['cadastrado_por']) ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="acoes">
                                        <button class="btn-acao btn-editar" 
                                                onclick="editarRegistro('comunidades', <?= $item['com_id'] ?>)"
                                                title="Editar">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <?php if ($item['total_produtores'] == 0): ?>
                                        <button class="btn-acao btn-excluir" 
                                                onclick="excluirRegistro('comunidades', <?= $item['com_id'] ?>, '<?= htmlspecialchars($item['com_nome']) ?>')"
                                                title="Excluir">
                                            <i class="fas fa-trash"></i> Excluir
                                        </button>
                                        <?php else: ?>
                                        <button class="btn-acao" 
                                                style="background: #94a3b8; cursor: not-allowed;"
                                                title="Não é possível excluir: há produtores vinculados"
                                                disabled>
                                            <i class="fas fa-lock"></i> Protegido
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php break; 
                
                case 'servicos': ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Serviço</th>
                                <th>Secretaria</th>
                                <th>Status</th>
                                <th>Cadastrado em</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dados_aba as $item): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($item['ser_nome']) ?></strong></td>
                                <td><?= htmlspecialchars($item['sec_nome']) ?></td>
                                <td>
                                    <span class="status-badge status-<?= $item['ser_status'] ?>">
                                        <?= ucfirst($item['ser_status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= date('d/m/Y H:i', strtotime($item['ser_data_cadastro'])) ?>
                                    <br>
                                    <small style="color: #6b7280;">
                                        por <?= htmlspecialchars($item['cadastrado_por']) ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="acoes">
                                        <button class="btn-acao btn-editar" 
                                                onclick="editarRegistro('servicos', <?= $item['ser_id'] ?>)"
                                                title="Editar">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button class="btn-acao btn-excluir" 
                                                onclick="excluirRegistro('servicos', <?= $item['ser_id'] ?>, '<?= htmlspecialchars($item['ser_nome']) ?>')"
                                                title="Excluir">
                                            <i class="fas fa-trash"></i> Excluir
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php break; 
                
                case 'maquinas': ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Máquina</th>
                                <th>Valor/Hora</th>
                                <th>Disponibilidade</th>
                                <th>Observações</th>
                                <th>Status</th>
                                <th>Cadastrado em</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dados_aba as $item): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($item['maq_nome']) ?></strong></td>
                                <td>
                                    <strong style="color: #059669;">R$ <?= number_format($item['maq_valor_hora'], 2, ',', '.') ?></strong>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $item['maq_disponibilidade'] ?>">
                                        <?= ucfirst($item['maq_disponibilidade']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= !empty($item['maq_observacoes']) ? htmlspecialchars(substr($item['maq_observacoes'], 0, 50)) . '...' : 'Sem observações' ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $item['maq_status'] ?>">
                                        <?= ucfirst($item['maq_status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= date('d/m/Y H:i', strtotime($item['maq_data_cadastro'])) ?>
                                    <br>
                                    <small style="color: #6b7280;">
                                        por <?= htmlspecialchars($item['cadastrado_por']) ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="acoes">
                                        <button class="btn-acao btn-editar" 
                                                onclick="editarRegistro('maquinas', <?= $item['maq_id'] ?>)"
                                                title="Editar">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button class="btn-acao btn-excluir" 
                                                onclick="excluirRegistro('maquinas', <?= $item['maq_id'] ?>, '<?= htmlspecialchars($item['maq_nome']) ?>')"
                                                title="Excluir">
                                            <i class="fas fa-trash"></i> Excluir
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php break; 
                
                case 'veterinarios': ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>CPF</th>
                                <th>CRMV</th>
                                <th>Telefone</th>
                                <th>Status</th>
                                <th>Cadastrado em</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dados_aba as $item): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($item['vet_nome']) ?></strong></td>
                                <td><?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $item['vet_cpf']) ?></td>
                                <td><strong><?= htmlspecialchars($item['vet_crmv']) ?></strong></td>
                                <td><?= htmlspecialchars($item['vet_telefone']) ?></td>
                                <td>
                                    <span class="status-badge status-<?= $item['vet_status'] ?>">
                                        <?= ucfirst($item['vet_status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= date('d/m/Y H:i', strtotime($item['vet_data_cadastro'])) ?>
                                    <br>
                                    <small style="color: #6b7280;">
                                        por <?= htmlspecialchars($item['cadastrado_por']) ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="acoes">
                                        <button class="btn-acao btn-editar" 
                                                onclick="editarRegistro('veterinarios', <?= $item['vet_id'] ?>)"
                                                title="Editar">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button class="btn-acao btn-excluir" 
                                                onclick="excluirRegistro('veterinarios', <?= $item['vet_id'] ?>, '<?= htmlspecialchars($item['vet_nome']) ?>')"
                                                title="Excluir">
                                            <i class="fas fa-trash"></i> Excluir
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php break; 
            endswitch; ?>
        </div>
    <?php else: ?>
        <div class="sem-registros">
            <i class="<?= $config_aba['icone'] ?>"></i>
            <h3>Nenhum registro encontrado</h3>
            <?php if (!empty(array_filter($filtros))): ?>
                <p>Nenhum registro foi encontrado com os filtros aplicados.</p>
                <a href="?aba=<?= $aba_ativa ?>" class="btn-limpar" style="margin-top: 15px;">
                    <i class="fas fa-eraser"></i>
                    Limpar Filtros
                </a>
            <?php else: ?>
                <p>Ainda não há registros cadastrados no sistema.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
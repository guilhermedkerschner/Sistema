<?php
// Teste rápido - criar um arquivo test_chaves.php para verificar
echo "Teste de acesso às chaves";
echo "<br>Parâmetros recebidos:<br>";
print_r($_GET);

if (isset($_GET['campeonato_id'])) {
    echo "<br>ID do campeonato: " . $_GET['campeonato_id'];
    echo "<br><a href='campeonato_chaves.php?campeonato_id=" . $_GET['campeonato_id'] . "'>Link direto para chaves</a>";
}
?>
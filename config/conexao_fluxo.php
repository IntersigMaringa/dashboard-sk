<?php
require_once __DIR__ . '/db_credentials.php';
$dbFluxo = db_credentials('fluxo');
$dbs = db_credentials('fluxo_dbs');

$servidor = $dbFluxo['host'];
$usuario  = $dbFluxo['user'];
$senha    = $dbFluxo['pass'];
$charset  = isset($dbFluxo['charset']) ? $dbFluxo['charset'] : 'utf8mb4';

$prefixe = $dbs['estoque_mesquita'];
$prefixp = $dbs['publico_mesquita'];
$prefixv = $dbs['vendas_mesquita'];
$prefixf = $dbs['financeiro_mesquita'];
$prefixd = 'dashboard';

$prefixjrp = $dbs['publico_jr'];
$prefixjrv = $dbs['vendas_jr'];
$prefixjrf = $dbs['financeiro_jr'];

$dbname = $prefixjrf;

$conexao = mysqli_connect($servidor, $usuario, $senha, $dbname);
if (!$conexao) {
    die("Houve erro: " . mysqli_connect_error());
}

mysqli_set_charset($conexao, $charset);

?>
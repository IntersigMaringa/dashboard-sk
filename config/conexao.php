<?php
// Conexão principal (dashboard)
require_once __DIR__ . '/db_credentials.php';
$db = db_credentials('dashboard');

$servidor = $db['host'];
$usuario  = $db['user'];
$senha    = $db['pass'];
$dbname   = $db['name'];
$charset  = isset($db['charset']) ? $db['charset'] : 'utf8';

$dash = mysqli_connect($servidor, $usuario, $senha, $dbname);

if (!$dash) {
    die("Falha na conexão: " . mysqli_connect_error());
}

mysqli_set_charset($dash, $charset);

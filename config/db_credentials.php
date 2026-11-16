<?php
/**
 * Carrega credenciais do arquivo config/database.ini.
 * Para uso local, copie config/database.ini.example para config/database.ini.
 */
function db_credentials($section = null)
{
    static $config = null;
    if ($config === null) {
        $iniPath = __DIR__ . '/database.ini';
        if (!is_file($iniPath)) {
            die('Arquivo de configuração de banco não encontrado: config/database.ini. Copie config/database.ini.example para config/database.ini e configure as credenciais.');
        }
        $config = parse_ini_file($iniPath, true);
        if ($config === false) {
            die('Falha ao ler config/database.ini.');
        }
    }
    if ($section === null) {
        return $config;
    }
    if (!isset($config[$section])) {
        die('Seção de configuração não encontrada: ' . htmlspecialchars($section));
    }
    return $config[$section];
}

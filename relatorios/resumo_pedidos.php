<?php
// --- NO CADASTRO DA SERIE ELE PEGA OS VALORES SOMENTE O QUE ESTIVER MARCADA PARA ENVIAR SPED ---
require_once '../config/config.php';
require_once '../config/session.php'; 
$nomeEmpresa = $empresa_nome ?? 'Empresa';

date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION["user_name"])) {
    // Redireciona para o login se não estiver logado
    header("Location: ../index.php");
    exit();
}

$nome_usuario_logado = htmlspecialchars($_SESSION["user_name"]);
$id_usuario_logado = (int) $_SESSION["user_id"];
$vendedor = 0;

// --- montar URL completa da página atual ---
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$page_url = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');

$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$user_agent = mb_substr($user_agent, 0, 255); 

if (isset($dash) && $stmt = $dash->prepare(
    "INSERT INTO logs (user_id, user_name, pagina_url, acesso, user_agent) VALUES (?, ?, ?, NOW(), ?)"
)) {
    $stmt->bind_param("isss",
        $id_usuario_logado,
        $nome_usuario_logado,
        $page_url,
        $user_agent
    );
    $stmt->execute();
}

// --- FUNÇÃO AUXILIAR PARA PREPARED STATEMENTS COM MYSQLI ---
function executar_consulta_preparada($conexao, $sql, $params = []) {
    $stmt = mysqli_prepare($conexao, $sql);
    if (!$stmt) {
        die("Erro na preparação da consulta: " . mysqli_error($conexao) . " SQL: " . $sql);
    }
    if (!empty($params)) {
        $types = str_repeat('s', count($params));
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        die("Erro na execução da consulta: " . mysqli_stmt_error($stmt));
    }
    return mysqli_stmt_get_result($stmt);
}

// Função auxiliar para converter datas
function convert_date_br_to_sql($date_input) {
    if (empty($date_input)) return '';
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date_input, $matches)) {
        return "{$matches[3]}-{$matches[2]}-{$matches[1]}";
    }
    return $date_input;
}

function get_situacao_badge($status) {
    switch ($status) {
        case 'EA':
            return '<span class="badge border border-warning text-warning px-2 pt-5px pb-5px rounded fs-12px d-inline-flex align-items-center"><i class="fa fa-circle fs-9px fa-fw me-5px"></i>Em Orçamento</span>';
        case 'FI':
            return '<span class="badge border border-success text-success px-2 pt-5px pb-5px rounded fs-12px d-inline-flex align-items-center"><i class="fa fa-circle fs-9px fa-fw me-5px"></i>Faturado</span>';
        case 'BM':
            return '<span class="badge border border-primary text-primary px-2 pt-5px pb-5px rounded fs-12px d-inline-flex align-items-center"><i class="fa fa-circle fs-9px fa-fw me-5px"></i>Baixa Manual</span>';
        case 'AI':
            return '<span class="badge border border-primary text-primary px-2 pt-5px pb-5px rounded fs-12px d-inline-flex align-items-center"><i class="fa fa-circle fs-9px fa-fw me-5px"></i>Aprovado</span>';
        case 'FP':
            return '<span class="badge border border-secondary text-secondary px-2 pt-5px pb-5px rounded fs-12px d-inline-flex align-items-center"><i class="fa fa-circle fs-9px fa-fw me-5px"></i>Parcial</span>';
        case 'RE':
            return '<span class="badge border border-secondary text-danger px-2 pt-5px pb-5px rounded fs-12px d-inline-flex align-items-center"><i class="fa fa-circle fs-9px fa-fw me-5px"></i>Reprovado</span>';
        default:
            return '<span class="badge border border-dark text-danger px-2 pt-5px pb-5px rounded fs-12px d-inline-flex align-items-center"><i class="fa fa-circle fs-9px fa-fw me-5px"></i>' . htmlspecialchars($status) . '</span>';
    }
}

function formatar_moeda_posicao($valor) {
    if (abs((float) $valor) < 0.005) {
        return 'R$ -';
    }
    return 'R$ ' . number_format((float) $valor, 2, ',', '.');
}

// --- AJUSTES PRINCIPAIS: CONDIÇÕES FIXAS ---
$condicoes_fixas = " o.tipo = 1 ";

// --- FILTROS DINÂMICOS ---
$filial = $_GET['filial'] ?? '';
$chave_mvto = $_GET['nf'] ?? '';
$data_inicio_raw = $_GET['data_inicio'] ?? '';
$data_fim_raw = $_GET['data_fim'] ?? '';
$uf = isset($_GET['uf']) ? trim($_GET['uf']) : '';
$operacao = $_GET['operacao'] ?? '';
$vendedor_selecionado = $_GET['vendedor'] ?? '';
$situacao = $_GET['situacao'] ?? '';

$colunas_data = [
    'data_cadastro' => 'o.data_cadastro',
    'data_aprov'    => 'o.data_aprov',
    'data_baixa'    => 'o.data_baixa',
    'data_recad'    => 'o.data_recad',
];
$tipo_data = $_GET['tipo_data'] ?? 'data_cadastro';
if (!isset($colunas_data[$tipo_data])) {
    $tipo_data = 'data_cadastro';
}
$coluna_data = $colunas_data[$tipo_data];

if (empty($data_inicio_raw) && empty($data_fim_raw) && empty($chave_mvto)) {
    $hoje = new DateTime();
    $data_inicio_sql_padrao = $hoje->format('Y-m-d');
    $data_fim_sql_padrao = $hoje->format('Y-m-d');
    $data_inicio_raw = (new DateTime($data_inicio_sql_padrao))->format('d/m/Y');
    $data_fim_raw = (new DateTime($data_fim_sql_padrao))->format('d/m/Y');
}

$data_inicio = convert_date_br_to_sql($data_inicio_raw);
$data_fim = convert_date_br_to_sql($data_fim_raw);

// --- CONSTRUÇÃO DA CLÁUSULA WHERE E PARÂMETROS ---
$filtros_dinamicos = [];
$params = [];

if ($filial) {
    $filtros_dinamicos[] = "o.FILIAL = ?";
    $params[] = $filial;
}
if ($vendedor_selecionado) {
    $filtros_dinamicos[] = "o.vendedor = ?";
    $params[] = $vendedor_selecionado;
}
if ($situacao) {
    $filtros_dinamicos[] = "o.situacao = ?";
    $params[] = $situacao;
}
if ($chave_mvto) {
    $filtros_dinamicos[] = "o.CODIGO = ?";
    $params[] = $chave_mvto;
}
if ($data_inicio) {
    $filtros_dinamicos[] = $tipo_data === 'data_recad'
        ? "DATE(o.data_recad) >= ?"
        : "{$coluna_data} >= ?";
    $params[] = $data_inicio;
}
if ($data_fim) {
    $filtros_dinamicos[] = $tipo_data === 'data_recad'
        ? "DATE(o.data_recad) <= ?"
        : "{$coluna_data} <= ?";
    $params[] = $data_fim;
}
if ($uf) {
    $filtros_dinamicos[] = "c.estado = ?";
    $params[] = $uf;
}

$where = "WHERE " . $condicoes_fixas;
if (!empty($filtros_dinamicos)) {
    $where .= " AND " . implode(' AND ', $filtros_dinamicos);
}


$groupBy = " GROUP BY DATE({$coluna_data}), o.vendedor, v.nome";
            
// Obter lista de filiais
$result_filial = mysqli_query($dash, "SELECT filial, nome_fantasia FROM {$banco_vendas}.empresas WHERE ativa = 'S' ORDER BY filial ASC");
$filiais = [];
while ($row = mysqli_fetch_assoc($result_filial)) {
    $filiais[] = [
        'codigo' => $row['filial'],
        'nome' => $row['nome_fantasia']
    ];
}

// Lista de vendedores
$result_vendedores = mysqli_query($dash, "SELECT codigo, nome FROM {$banco_publico}.cad_vend WHERE ativo = 'S' AND tipo = 'V' ORDER BY nome ASC");
$vendedores = [];
while ($row = mysqli_fetch_assoc($result_vendedores)) {
    $vendedores[] = [
        'codigo' => $row['codigo'],
        'nome' => $row['nome']
    ];
}

// Consulta principal detalhada de pedidos
$dadosResumoCFOP = [];
if (!$chave_mvto) {
    $sqlResumoCFOP = "SELECT o.filial, o.codigo, o.data_cadastro, o.data_baixa, o.situacao, CONCAT(o.cliente, ' - ', c.apelido) AS cliente,
        CONCAT(o.vendedor, ' - ', v.nome) AS vendedor, o.total_produtos, o.total_servicos, o.desc_prod, o.desc_serv, o.total_geral, c.cidade
        FROM {$banco_vendas}.cad_orca o 
        LEFT JOIN {$banco_publico}.cad_clie c ON (c.codigo = o.cliente)
        LEFT JOIN {$banco_publico}.cad_vend v ON (v.codigo = o.vendedor)
        {$where}";

    $dadosResumoCFOP = mysqli_fetch_all(
        executar_consulta_preparada($dash, $sqlResumoCFOP, $params),
        MYSQLI_ASSOC
    );
}

// --- Consulta para o resumo agrupado por vendedor ---
$sqlResumoCstCfop = "SELECT CONCAT(o.vendedor, ' - ', v.nome) AS vendedor, sum(o.total_produtos) total_prod, sum(o.total_servicos) as total_serv,
    sum(o.desc_prod) as des_prod, sum(o.desc_serv) as des_ser, sum(o.total_geral) as total_geral
    FROM {$banco_vendas}.cad_orca o 
    LEFT JOIN {$banco_publico}.cad_clie c ON (c.codigo = o.cliente)
    LEFT JOIN {$banco_publico}.cad_vend v ON (v.codigo = o.vendedor)
    {$where}
       {$groupBy}
    ORDER BY o.vendedor ASC";

$dadosResumoCstCfop = mysqli_fetch_all(
    executar_consulta_preparada($dash, $sqlResumoCstCfop, $params),
    MYSQLI_ASSOC
);

// --- Consulta para Posição Diária / Acumulada de Pedidos do Mês (ignorando meta) ---
$vendedores_posicao = [];
$linhas_posicao = [];


if (!$chave_mvto && !empty($data_inicio) && !empty($data_fim)) {
    $sqlPosicaoDiaria = "SELECT 
            DATE({$coluna_data}) AS dia,
            o.vendedor AS cod_vendedor,
            COALESCE(NULLIF(TRIM(v.nome), ''), CONCAT('Vendedor ', o.vendedor)) AS nome_vendedor,
            SUM(o.total_geral) AS total_dia
        FROM {$banco_vendas}.cad_orca o
        LEFT JOIN {$banco_publico}.cad_clie c ON (c.codigo = o.cliente)
        LEFT JOIN {$banco_publico}.cad_vend v ON (v.codigo = o.vendedor)
        {$where}
            {$groupBy}
        ORDER BY dia ASC, nome_vendedor ASC";

            // print_r($sqlPosicaoDiaria);
            // print_r($params);

    $dadosPosicaoDiaria = mysqli_fetch_all(
        executar_consulta_preparada($dash, $sqlPosicaoDiaria, $params),
        MYSQLI_ASSOC
    );

    $valores_por_dia = [];
    $totais_por_dia = [];

    foreach ($dadosPosicaoDiaria as $row) {
        if (empty($row['dia'])) continue;

        $dia = $row['dia'];
        $codVend = (string) ($row['cod_vendedor'] ?? '0');
        $nomeVend = $row['nome_vendedor'];
        $valor = (float) $row['total_dia'];

        $vendedores_posicao[$codVend] = $nomeVend;
        $valores_por_dia[$dia][$codVend] = ($valores_por_dia[$dia][$codVend] ?? 0.0) + $valor;
        $totais_por_dia[$dia] = ($totais_por_dia[$dia] ?? 0.0) + $valor;
    }

    asort($vendedores_posicao);

    $dtInicio = DateTime::createFromFormat('Y-m-d', $data_inicio);
    $dtFim = DateTime::createFromFormat('Y-m-d', $data_fim);

    if ($dtInicio && $dtFim && $dtInicio <= $dtFim && !empty($vendedores_posicao)) {
        $mesmoMes = ($dtInicio->format('Y-m') === $dtFim->format('Y-m'));
        $dtFimInclusivo = (clone $dtFim)->modify('+1 day');
        $periodo = new DatePeriod($dtInicio, new DateInterval('P1D'), $dtFimInclusivo);

        $acumuladoGeral = 0.0;
        $acumuladoVendedores = array_fill_keys(array_keys($vendedores_posicao), 0.0);

        foreach ($periodo as $dt) {
            $dataStr = $dt->format('Y-m-d');
            $diaSemana = (int) $dt->format('N'); // 6 = sábado, 7 = domingo
            $totalDia = $totais_por_dia[$dataStr] ?? 0.0;
            $acumuladoGeral += $totalDia;

            $diarioVend = [];
            foreach ($vendedores_posicao as $codVend => $nomeVend) {
                $valVend = $valores_por_dia[$dataStr][$codVend] ?? 0.0;
                $diarioVend[$codVend] = $valVend;
                $acumuladoVendedores[$codVend] += $valVend;
            }

            $textoFds = '';
            if ($totalDia == 0.0) {
                if ($diaSemana === 6) $textoFds = 'sábado';
                elseif ($diaSemana === 7) $textoFds = 'domingo';
            }

            $linhas_posicao[] = [
                'label_dia' => $mesmoMes ? $dt->format('j') : $dt->format('d/m'),
                'texto_fds' => $textoFds,
                'total_dia' => $totalDia,
                'acumulado_geral' => $acumuladoGeral,
                'diario_vendedores' => $diarioVend,
                'acumulado_vendedores' => $acumuladoVendedores,
            ];
        }
    }
}
?>

<!doctype html>
<html lang="pt-br">
<!--begin::Head-->

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Pedidos <?php echo htmlspecialchars($nomeEmpresa); ?> </title>

    <!--begin::Accessibility Meta Tags-->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
    <meta name="color-scheme" content="light dark" />
    <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)" />
    <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)" />
    <meta name="supported-color-schemes" content="light dark" />
    <link rel="preload" href="../css/adminlte.css" as="style" />
    <!--end::Accessibility Features-->
    <!--begin::Fonts-->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.1/index.css"
        integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q=" crossorigin="anonymous" media="print"
        onload="this.media='all'" />
    <!--end::Fonts-->
    <!--begin::Third Party Plugin(OverlayScrollbars)-->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css"
        crossorigin="anonymous" />
    <!--end::Third Party Plugin(OverlayScrollbars)-->
    <!--begin::Third Party Plugin(Bootstrap Icons)-->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
        crossorigin="anonymous" />
    <!--end::Third Party Plugin(Bootstrap Icons)-->
    <!--begin::Required Plugin(AdminLTE)-->
    <link rel="stylesheet" href="../css/adminlte.css" />
    <!--end::Required Plugin(AdminLTE)-->
    <!-- apexcharts -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css"
        integrity="sha256-4MX+61mt9NVvvuPjUWdUdyfZfxSB1/Rf9WtqRHgG5S0=" crossorigin="anonymous" />
    <!-- jsvectormap -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.5.3/dist/css/jsvectormap.min.css"
        integrity="sha256-+uGLJmmTKOqBr+2E6KDYs/NRsHxSkONXFHUL0fy2O/4=" crossorigin="anonymous" />

    <script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>
    <link rel="icon" type="image/x-icon" href="../img/intersig.ico">

    <style>
    h1 {
        text-align: center;
        color: #2c3e50;
        margin-bottom: 25px;
    }
    td {
        font-size: 10px;
    }
    th {
        font-size: 12px;
    }
    label {
        display: block;
        margin-bottom: 8px;
        font-weight: bold;
        color: #555;
    }

    input[type=text] {
        width: 100%;
        padding: 8px;
        margin-bottom: 0;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-sizing: border-box;
        font-size: 12px;
    }

    input[type=text]:focus {
        border-color: #007bff;
        outline: none;
        box-shadow: 0 0 0 2px rgba(0, 123, 255, .25);
    }

    /* --- ESTILOS ESSENCIAIS PARA O AUTOCOMPLETE APARECER --- */
    .ui-autocomplete {
        position: absolute;
        z-index: 1000;
        max-height: 250px;
        overflow-y: auto;
        overflow-x: hidden;
        background: #fff;
        border: 1px solid #ddd;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        list-style: none;
        padding: 4px 0;
        margin: 0;
    }

    .ui-menu-item {
        padding: 8px 12px;
        cursor: pointer;
        font-size: 15px;
    }

    .ui-state-active,
    .ui-menu-item:hover {
        background-color: #007bff;
        color: #fff;
        border: none;
    }

    .select-filial,
    .select-vendedor {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid #444;
        border-radius: 8px;
        background-color: #1e1e1e;
        color: #fff;
        font-size: 15px;
        appearance: none;
        outline: none;
        transition: all 0.2s ease;
        background-image: linear-gradient(45deg, transparent 50%, #00bcd4 50%),
            linear-gradient(135deg, #00bcd4 50%, transparent 50%);
        background-position: calc(100% - 20px) calc(1em + 2px),
            calc(100% - 15px) calc(1em + 2px);
        background-size: 5px 5px, 5px 5px;
        background-repeat: no-repeat;
    }

    .select-filial:hover,
    .select-vendedor:hover {
        border-color: #00bcd4;
    }

    .select-filial:focus,
    .select-vendedor:focus {
        box-shadow: 0 0 0 2px #00bcd440;
        border-color: #00bcd4;
    }

    .card-header {
        margin-bottom: 15px;
        font-size: 14px;
        border-bottom: 2px solid #3498db;
        padding-bottom: 8px;
    }

    .btn-sm-custom {
        padding: 6px 12px;
        font-size: 13px;
        height: 36px;
    }

    .form-control-sm {
        padding: 6px 8px;
        font-size: 13px;
    }
    </style>
</head>
<!--end::Head-->
<!--begin::Body-->

<body class="layout-fixed sidebar-expand-lg sidebar-open" data-bs-theme="dark">

    <!--begin::App Wrapper-->
    <div class="app-wrapper">
        <!--begin::Header-->
        <?php require_once '../menu/nav_dash.php'; ?>
        <?php require_once '../menu/sidebar.php'; ?>
        <?php require_once '../menu/menu_dash.php'; ?>
    </div>
    <!--end::Sidebar Wrapper-->
    </aside>
    <!--end::Sidebar-->

    <!--begin::App Main-->
    <main class="app-main">
        <!--begin::App Content Header-->
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6">
                        <!-- <h3 class="mb-0">Resumo por CFOP</h3> -->
                    </div>
                </div>
            </div>
        </div>
        <!--end::App Content Header-->

        <!--begin::App Content-->
        <div class="app-content">
            <div class="container-fluid">

                <!--begin::Row-->
                <div class="row">
                    <!-- Start col -->
                    <div class="col-lg-12 connectedSortable">

                        <div class="row">
                            <div class="col-md-12">
                                <div class="card filter-card">
                                    <div class="card-header">
                                        <strong>Filtros</strong>
                                    </div>
                                    <div class="card-body">
                                        <form method="GET" id="form-filtros">
                                            <div class="row g-3 align-items-end">
                                                <!-- Coluna da Filial -->
                                                <div class="col-md-2">
                                                    <label class="form-label">Filial</label>
                                                    <select name="filial" class="select-filial form-control-sm">
                                                        <option value="">Todas</option>
                                                        <?php foreach ($filiais as $f): ?>
                                                        <option value="<?= $f['codigo'] ?>"
                                                            <?= ($f['codigo'] == $filial ? 'selected' : '') ?>>
                                                            <?= $f['codigo'] ?> -
                                                            <?= htmlspecialchars($f['nome']) ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <!-- Coluna da vendedores -->
                                                <div class="col-md-2">
                                                    <label class="form-label">Vendedor</label>
                                                    <select name="vendedor" class="select-vendedor form-control-sm">
                                                        <option value="">Todos</option>
                                                        <?php foreach ($vendedores as $vendedor): ?>
                                                        <option value="<?= htmlspecialchars($vendedor['codigo']) ?>"
                                                            <?= ($vendedor_selecionado == $vendedor['codigo']) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($vendedor['nome']) ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <!-- Coluna da Data de Início -->
                                                <div class="col-md-2">
                                                    <label class="form-label">Data Início</label>
                                                    <input type="text" name="data_inicio" id="data_inicio"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($data_inicio_raw) ?>"
                                                        placeholder="dd/mm/aaaa" maxlength="10">
                                                </div>

                                                <!-- Coluna da Data Fim -->
                                                <div class="col-md-2">
                                                    <label class="form-label">Data Fim</label>
                                                    <input type="text" name="data_fim" id="data_fim"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($data_fim_raw) ?>"
                                                        placeholder="dd/mm/aaaa" maxlength="10">
                                                </div>

                                                <!-- Coluna do Tipo de Data -->
                                                <div class="col-md-2">
                                                    <label class="form-label">Tipo de Data</label>
                                                    <select name="tipo_data" class="select-filial form-control-sm">
                                                        <option value="data_cadastro" <?= ($tipo_data == 'data_cadastro' ? 'selected' : '') ?>>Data Cadastro</option>
                                                        <option value="data_aprov" <?= ($tipo_data == 'data_aprov' ? 'selected' : '') ?>>Data Aprovação</option>
                                                        <option value="data_baixa" <?= ($tipo_data == 'data_baixa' ? 'selected' : '') ?>>Data Baixa</option>
                                                        <option value="data_recad" <?= ($tipo_data == 'data_recad' ? 'selected' : '') ?>>Data Recadastro</option>
                                                    </select>
                                                </div>

                                                <!-- Coluna da UF -->
                                                <div class="col-md-2">
                                                    <label class="form-label">UF</label>
                                                    <select name="uf" class="select-filial form-control-sm">
                                                        <option value="">Todas</option>
                                                        <?php
                                                        $estados = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
                                                        foreach ($estados as $sigla):
                                                        ?>
                                                        <option value="<?= $sigla ?>" <?= ($uf == $sigla ? 'selected' : '') ?>><?= $sigla ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="col-md-2">
                                                    <label class="form-label">Situação</label>
                                                    <select name="situacao" class="select-filial form-control-sm">
                                                        <option value="">Todas</option>
                                                        <option value="EA" <?= ($situacao == 'EA' ? 'selected' : '') ?>>Em Orçamento</option>
                                                        <option value="FI" <?= ($situacao == 'FI' ? 'selected' : '') ?>>Faturado</option>
                                                        <option value="BM" <?= ($situacao == 'BM' ? 'selected' : '') ?>>Baixa Manual</option>
                                                        <option value="AI" <?= ($situacao == 'AI' ? 'selected' : '') ?>>Aprovado</option>
                                                        <option value="FP" <?= ($situacao == 'FP' ? 'selected' : '') ?>>Parcial</option>
                                                        <option value="RE" <?= ($situacao == 'RE' ? 'selected' : '') ?>>Reprovado</option>
                                                    </select>
                                                </div>

                                                <!-- Coluna dos Botões -->
                                                <div class="col-md-2 d-flex gap-2">
                                                    <button type="submit"
                                                        class="btn btn-primary btn-sm-custom flex-grow-1">Filtrar</button>
                                                    <?php if ($chave_mvto || $data_inicio_raw || $data_fim_raw || $filial || $situacao || $uf || $vendedor_selecionado || $tipo_data !== 'data_cadastro'): ?>
                                                    <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>"
                                                        class="btn btn-outline-secondary btn-sm-custom flex-grow-1">Limpar</a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- Botões de Filtro Rápido -->
                                            <div class="row mt-3">
                                                <div class="col-md-12">
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <span class="text-muted small align-self-center me-2">Período rápido:</span>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('hoje')">Hoje</button>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('semana')">Esta Semana</button>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('mes')">Este Mês</button>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('primeiro_trimestre')">1º Trimestre</button>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('segundo_trimestre')">2º Trimestre</button>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('terceiro_trimestre')">3º Trimestre</button>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('quarto_trimestre')">4º Trimestre</button>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('primeiro_semestre')">1º Semestre</button>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('segundo_semestre')">2º Semestre</button>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('ano')">Este Ano</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <?php if (!empty($dadosResumoCFOP)): ?>
                <!-- Detalhamento de Pedidos -->
                 
                <script>
                function verNotasCFOP(codigo) {
                    const url = `pedido.php?codigo_pedido=${encodeURIComponent(codigo)}`;
                    window.location.href = url;
                }
                </script>

                <?php if (!$chave_mvto): ?>

                <!-- --- TABELA DE RESUMO AGRUPADO POR VENDEDOR --- -->
                <?php if (!empty($dadosResumoCstCfop)): ?>
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <strong>RESUMO - TOTAIS (Agrupado por Vendedor)</strong>
                                <button class="btn btn-success btn-sm float-end"
                                    onclick="exportToExcel('tabelaCstCfop', 'Resumo_CST_CFOP.xlsx')">
                                    <i class="bi bi-file-earmark-excel"></i> Exportar para Excel
                                </button>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table id="tabelaCstCfop" class="table table-striped table-bordered table-sm">
                                        <thead>
                                            <tr>
                                                <th>Vendedor</th>
                                                <th>Total Produtos</th>
                                                <th>Total Serviços</th>
                                                <th>Desconto Produtos</th>
                                                <th>Desconto Serviços</th>
                                                <th>Total Geral</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $total_prod = 0;
                                            $total_serv = 0;
                                            $des_prod = 0;
                                            $des_ser = 0;
                                            $total_geral_vend = 0;

                                            foreach ($dadosResumoCstCfop as $row):
                                                $total_prod += $row['total_prod'];
                                                $total_serv += $row['total_serv'];
                                                $des_prod += $row['des_prod'];
                                                $des_ser += $row['des_ser'];
                                                $total_geral_vend += $row['total_geral'];
                                            ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['vendedor']) ?></td>
                                                <td><?= number_format($row['total_prod'], 2, ",", ".") ?></td>
                                                <td><?= number_format($row['total_serv'], 2, ",", ".") ?></td>
                                                <td><?= number_format($row['des_prod'], 2, ",", ".") ?></td>
                                                <td><?= number_format($row['des_ser'], 2, ",", ".") ?></td>
                                                <td><?= number_format($row['total_geral'], 2, ",", ".") ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="1" class="text-end">TOTAIS</th>
                                                <th><?= number_format($total_prod, 2, ",", ".") ?></th>
                                                <th><?= number_format($total_serv, 2, ",", ".") ?></th>
                                                <th><?= number_format($des_prod, 2, ",", ".") ?></th>
                                                <th><?= number_format($des_ser, 2, ",", ".") ?></th>
                                                <th><?= number_format($total_geral_vend, 2, ",", ".") ?></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- --- NOVA TABELA: POSIÇÃO DE PEDIDOS DO MÊS (DIÁRIO E ACUMULADO POR VENDEDOR) --- -->
                <?php if (!empty($linhas_posicao) && !empty($vendedores_posicao)): ?>
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <strong>POSIÇÃO DE PEDIDOS DO MÊS</strong>
                                <button class="btn btn-success btn-sm float-end"
                                    onclick="exportToExcel('tabelaPosicaoPedidos', 'Posicao_Pedidos_Mes.xlsx')">
                                    <i class="bi bi-file-earmark-excel"></i> Exportar para Excel
                                </button>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table id="tabelaPosicaoPedidos" class="table table-striped table-bordered table-sm align-middle">
                                        <thead>
                                            <tr class="text-center">
                                                <th>Dia</th>
                                                <th>TOTAL PEDIDOS DIARIA</th>
                                                <th>SOMATORIA PEDIDOS MÊS</th>
                                                <?php foreach ($vendedores_posicao as $nomeVend): ?>
                                                    <th>PEDIDOS DIARIA <?= htmlspecialchars(mb_strtoupper($nomeVend, 'UTF-8')) ?></th>
                                                <?php endforeach; ?>
                                                <?php foreach ($vendedores_posicao as $nomeVend): ?>
                                                    <th>ACUMULADO <?= htmlspecialchars(mb_strtoupper($nomeVend, 'UTF-8')) ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($linhas_posicao as $linha): ?>
                                            <tr class="<?= $linha['texto_fds'] ? 'table-secondary' : '' ?>">
                                                <td class="text-center fw-bold"><?= htmlspecialchars($linha['label_dia']) ?></td>

                                                <?php if ($linha['texto_fds']): ?>
                                                    <td class="text-center fst-italic text-muted"><?= $linha['texto_fds'] ?></td>
                                                    <td class="text-center fst-italic text-muted"><?= $linha['texto_fds'] ?></td>
                                                    <?php foreach ($vendedores_posicao as $codVend => $nomeVend): ?>
                                                        <td class="text-center fst-italic text-muted"><?= $linha['texto_fds'] ?></td>
                                                    <?php endforeach; ?>
                                                    <?php foreach ($vendedores_posicao as $codVend => $nomeVend): ?>
                                                        <td class="text-center fst-italic text-muted"><?= $linha['texto_fds'] ?></td>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <td class="text-end"><?= formatar_moeda_posicao($linha['total_dia']) ?></td>
                                                    <td class="text-end fw-semibold"><?= formatar_moeda_posicao($linha['acumulado_geral']) ?></td>
                                                    <?php foreach ($vendedores_posicao as $codVend => $nomeVend): ?>
                                                        <td class="text-end"><?= formatar_moeda_posicao($linha['diario_vendedores'][$codVend] ?? 0) ?></td>
                                                    <?php endforeach; ?>
                                                    <?php foreach ($vendedores_posicao as $codVend => $nomeVend): ?>
                                                        <td class="text-end"><?= formatar_moeda_posicao($linha['acumulado_vendedores'][$codVend] ?? 0) ?></td>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <?php $ultimaLinha = end($linhas_posicao); ?>
                                        <tfoot>
                                            <tr>
                                                <th class="text-center">TOTAIS</th>
                                                <th class="text-end"><?= formatar_moeda_posicao($ultimaLinha['acumulado_geral']) ?></th>
                                                <th class="text-end"><?= formatar_moeda_posicao($ultimaLinha['acumulado_geral']) ?></th>
                                                <?php foreach ($vendedores_posicao as $codVend => $nomeVend): ?>
                                                    <th class="text-end"><?= formatar_moeda_posicao($ultimaLinha['acumulado_vendedores'][$codVend] ?? 0) ?></th>
                                                <?php endforeach; ?>
                                                <?php foreach ($vendedores_posicao as $codVend => $nomeVend): ?>
                                                    <th class="text-end"><?= formatar_moeda_posicao($ultimaLinha['acumulado_vendedores'][$codVend] ?? 0) ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php endif; ?>
                <?php endif; ?>

            </div>

            <script>
            // Funções para filtros rápidos
            function formatDate(date) {
                const dia = String(date.getDate()).padStart(2, '0');
                const mes = String(date.getMonth() + 1).padStart(2, '0');
                const ano = date.getFullYear();
                return `${dia}/${mes}/${ano}`;
            }

            function setPeriodo(tipo) {
                const hoje = new Date();
                const anoAtual = hoje.getFullYear();
                let dataInicio, dataFim;

                switch (tipo) {
                    case 'hoje':
                        dataInicio = dataFim = formatDate(hoje);
                        break;

                    case 'semana':
                        const inicioSemana = new Date(hoje);
                        inicioSemana.setDate(hoje.getDate() - hoje.getDay());
                        dataInicio = formatDate(inicioSemana);
                        dataFim = formatDate(hoje);
                        break;

                    case 'mes':
                        dataInicio = formatDate(new Date(anoAtual, hoje.getMonth(), 1));
                        dataFim = formatDate(new Date(anoAtual, hoje.getMonth() + 1, 0));
                        break;

                    case 'trimestre':
                        const trimestre = Math.floor(hoje.getMonth() / 3);
                        dataInicio = formatDate(new Date(anoAtual, trimestre * 3, 1));
                        dataFim = formatDate(new Date(anoAtual, trimestre * 3 + 3, 0));
                        break;

                    case 'primeiro_trimestre':
                        dataInicio = formatDate(new Date(anoAtual, 0, 1));
                        dataFim = formatDate(new Date(anoAtual, 2, 31));
                        break;

                    case 'segundo_trimestre':
                        dataInicio = formatDate(new Date(anoAtual, 3, 1));
                        dataFim = formatDate(new Date(anoAtual, 5, 30));
                        break;

                    case 'terceiro_trimestre':
                        dataInicio = formatDate(new Date(anoAtual, 6, 1));
                        dataFim = formatDate(new Date(anoAtual, 8, 30));
                        break;

                    case 'quarto_trimestre':
                        dataInicio = formatDate(new Date(anoAtual, 9, 1));
                        dataFim = formatDate(new Date(anoAtual, 11, 31));
                        break;

                    case 'primeiro_semestre':
                        dataInicio = formatDate(new Date(anoAtual, 0, 1));
                        dataFim = formatDate(new Date(anoAtual, 5, 30));
                        break;

                    case 'segundo_semestre':
                        dataInicio = formatDate(new Date(anoAtual, 6, 1));
                        dataFim = formatDate(new Date(anoAtual, 11, 31));
                        break;

                    case 'ano':
                        dataInicio = formatDate(new Date(anoAtual, 0, 1));
                        dataFim = formatDate(new Date(anoAtual, 11, 31));
                        break;

                    default:
                        console.log('Tipo de período não reconhecido:', tipo);
                        return;
                }

                document.getElementById('data_inicio').value = dataInicio;
                document.getElementById('data_fim').value = dataFim;
                document.getElementById('form-filtros').submit();
            }

            function mascaraData(input) {
                let value = input.value.replace(/\D/g, '');
                if (value.length > 2) value = value.substring(0, 2) + '/' + value.substring(2);
                if (value.length > 5) value = value.substring(0, 5) + '/' + value.substring(5, 9);
                input.value = value;
            }

            document.addEventListener('DOMContentLoaded', function() {
                const inputInicio = document.getElementById('data_inicio');
                const inputFim = document.getElementById('data_fim');
                if (inputInicio) inputInicio.addEventListener('input', function() {
                    mascaraData(this);
                });
                if (inputFim) inputFim.addEventListener('input', function() {
                    mascaraData(this);
                });
            });

            function exportToExcel(tableId, filename) {
                const table = document.getElementById(tableId);
                const wb = XLSX.utils.table_to_book(table, {
                    sheet: "Dados"
                });
                XLSX.writeFile(wb, filename);
            }
            </script>
        </div>
        <!--end::App Content-->
    </main>
    <!--end::App Main-->

    <!--begin::Footer-->
    <?php require_once '../menu/footer.php'; ?>
    <!--end::Footer-->

    <!--begin::Script-->
    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/browser/overlayscrollbars.browser.es6.min.js"
        crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="../js/adminlte.js"></script>
    <script>
    const SELECTOR_SIDEBAR_WRAPPER = '.sidebar-wrapper';
    const Default = {
        scrollbarTheme: 'os-theme-light',
        scrollbarAutoHide: 'leave',
        scrollbarClickScroll: true,
    };
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarWrapper = document.querySelector(SELECTOR_SIDEBAR_WRAPPER);
        if (sidebarWrapper && OverlayScrollbarsGlobal?.OverlayScrollbars !== undefined) {
            OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
                scrollbars: {
                    theme: Default.scrollbarTheme,
                    autoHide: Default.scrollbarAutoHide,
                    clickScroll: Default.scrollbarClickScroll,
                },
            });
        }
    });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js" crossorigin="anonymous"></script>
    <script>
    new Sortable(document.querySelector('.connectedSortable'), {
        group: 'shared',
        handle: '.card-header',
    });

    const cardHeaders = document.querySelectorAll('.connectedSortable .card-header');
    cardHeaders.forEach((cardHeader) => {
        cardHeader.style.cursor = 'move';
    });
    </script>
</body>
</html>
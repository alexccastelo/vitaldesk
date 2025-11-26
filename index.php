<?php
// index.php - Dashboard
require_once 'config.php';
require_once 'auth.php';

// Carregar listas para filtros
$stmtProf = $pdo->query("SELECT * FROM profissionais WHERE ativo = 1 ORDER BY nome ASC");
$profissionais = $stmtProf->fetchAll(PDO::FETCH_ASSOC);

$stmtSalas = $pdo->query("SELECT * FROM salas WHERE ativo = 1 ORDER BY nome ASC");
$salas = $stmtSalas->fetchAll(PDO::FETCH_ASSOC);

// Descobrir menor e maior data de registros finalizados (para intervalo padrão)
$stmtRange = $pdo->query("
    SELECT MIN(data) AS min_data, MAX(data) AS max_data
    FROM registros
    WHERE hora_checkout IS NOT NULL
");
$rangeRow  = $stmtRange->fetch(PDO::FETCH_ASSOC);
$minDataDB = $rangeRow && $rangeRow['min_data'] ? $rangeRow['min_data'] : null;
$maxDataDB = $rangeRow && $rangeRow['max_data'] ? $rangeRow['max_data'] : null;

// Ler filtros (GET)
$data_inicio     = isset($_GET['data_inicio']) ? trim($_GET['data_inicio']) : '';
$data_fim        = isset($_GET['data_fim']) ? trim($_GET['data_fim']) : '';
$profissional_id = isset($_GET['profissional_id']) ? (int)$_GET['profissional_id'] : 0;
$sala_id         = isset($_GET['sala_id']) ? (int)$_GET['sala_id'] : 0;

// Regras de intervalo de datas
if ($data_inicio === '' && $data_fim === '') {
    // Sem filtros de data -> usa intervalo completo do banco, se existir
    if ($minDataDB !== null && $maxDataDB !== null) {
        $data_inicio = $minDataDB;
        $data_fim    = $maxDataDB;
    } else {
        // Se não houver registros, cai em um intervalo "últimos 7 dias" só para não quebrar nada
        $data_fim    = date('Y-m-d');
        $data_inicio = date('Y-m-d', strtotime('-6 days'));
    }
} elseif ($data_inicio === '' && $data_fim !== '') {
    $data_inicio = $data_fim;
} elseif ($data_inicio !== '' && $data_fim === '') {
    $data_fim = $data_inicio;
}

// Descobrir nome do profissional/sala selecionados (quando houver)
$profNomeFiltro = null;
if ($profissional_id > 0) {
    $stmt = $pdo->prepare("SELECT nome FROM profissionais WHERE id = :id");
    $stmt->execute([':id' => $profissional_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $profNomeFiltro = $row['nome'];
    }
}

$salaNomeFiltro = null;
if ($sala_id > 0) {
    $stmt = $pdo->prepare("SELECT nome FROM salas WHERE id = :id");
    $stmt->execute([':id' => $sala_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $salaNomeFiltro = $row['nome'];
    }
}

// Montar WHERE e parâmetros comuns
$where  = "hora_checkout IS NOT NULL";
$param  = [];

// Filtro de data (sempre)
$where                .= " AND data BETWEEN :data_inicio AND :data_fim";
$param[':data_inicio'] = $data_inicio;
$param[':data_fim']    = $data_fim;

// Filtro por profissional (se selecionado)
if ($profNomeFiltro !== null) {
    $where .= " AND profissional = :profissional";
    $param[':profissional'] = $profNomeFiltro;
}

// Filtro por sala (se selecionado)
if ($salaNomeFiltro !== null) {
    $where .= " AND sala = :sala";
    $param[':sala'] = $salaNomeFiltro;
}

// Query string para link do relatório em PDF/print
$queryStringRelatorio = http_build_query([
    'data_inicio'     => $data_inicio,
    'data_fim'        => $data_fim,
    'profissional_id' => $profissional_id,
    'sala_id'         => $sala_id,
]);

/*
 |---------------------------------------------------------
 | EXPORTAR CSV (detalhado) – usando os mesmos filtros
 |---------------------------------------------------------
*/
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sqlDetalhes = "
        SELECT data, profissional, sala, hora_checkin, hora_checkout, total_horas
        FROM registros
        WHERE $where
        ORDER BY data, profissional, sala, hora_checkin
    ";
    $stmtDet = $pdo->prepare($sqlDetalhes);
    $stmtDet->execute($param);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="relatorio_salas_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');

    // BOM para Excel/LibreOffice reconhecer UTF-8
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Cabeçalho
    fputcsv($output, [
        'Data',
        'Profissional',
        'Sala',
        'Check-in',
        'Check-out',
        'Horas (decimais)'
    ], ';');

    while ($row = $stmtDet->fetch(PDO::FETCH_ASSOC)) {
        $dataBR = DateTime::createFromFormat('Y-m-d', $row['data']);
        $dataBR = $dataBR ? $dataBR->format('d/m/Y') : $row['data'];

        fputcsv($output, [
            $dataBR,
            $row['profissional'],
            $row['sala'],
            $row['hora_checkin'],
            $row['hora_checkout'],
            $row['total_horas'] !== null
                ? number_format((float)$row['total_horas'], 2, ',', '')
                : ''
        ], ';');
    }

    fclose($output);
    exit;
}

/*
 |---------------------------------------------------------
 | CONSULTAS PARA O DASHBOARD
 |---------------------------------------------------------
*/

// RESUMO POR DIA
$sqlDia = "
    SELECT data,
           SUM(IFNULL(total_horas, 0)) AS horas_total
    FROM registros
    WHERE $where
    GROUP BY data
    ORDER BY data
";
$stmtDia = $pdo->prepare($sqlDia);
$stmtDia->execute($param);
$resumoDia = $stmtDia->fetchAll(PDO::FETCH_ASSOC);

// RESUMO POR PROFISSIONAL
$sqlProf = "
    SELECT profissional,
           SUM(IFNULL(total_horas, 0)) AS horas_total
    FROM registros
    WHERE $where
    GROUP BY profissional
    ORDER BY profissional
";
$stmtResumoProf = $pdo->prepare($sqlProf);
$stmtResumoProf->execute($param);
$resumoProf = $stmtResumoProf->fetchAll(PDO::FETCH_ASSOC);

// RESUMO POR SALA
$sqlSala = "
    SELECT sala,
           SUM(IFNULL(total_horas, 0)) AS horas_total
    FROM registros
    WHERE $where
    GROUP BY sala
    ORDER BY sala
";
$stmtResumoSala = $pdo->prepare($sqlSala);
$stmtResumoSala->execute($param);
$resumoSala = $stmtResumoSala->fetchAll(PDO::FETCH_ASSOC);

// TOTAL GERAL
$sqlTotal = "
    SELECT SUM(IFNULL(total_horas, 0)) AS horas_total
    FROM registros
    WHERE $where
";
$stmtTotal = $pdo->prepare($sqlTotal);
$stmtTotal->execute($param);
$totalGeralRow = $stmtTotal->fetch(PDO::FETCH_ASSOC);
$totalGeral = $totalGeralRow && $totalGeralRow['horas_total'] !== null
    ? (float)$totalGeralRow['horas_total']
    : 0.0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Espaço Vital Clínica</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container">
    <h1>Dashboard de Uso das Salas</h1>
    <?php include 'user_info.php'; ?>

    <div class="card">
        <h2>Filtros</h2>
        <form method="get" class="flex-row">
            <div class="flex-col">
                <label for="data_inicio">Data inicial</label>
                <input type="date" id="data_inicio" name="data_inicio"
                       value="<?= htmlspecialchars($data_inicio) ?>">
            </div>
            <div class="flex-col">
                <label for="data_fim">Data final</label>
                <input type="date" id="data_fim" name="data_fim"
                       value="<?= htmlspecialchars($data_fim) ?>">
            </div>
            <div class="flex-col">
                <label for="profissional_id">Profissional</label>
                <select id="profissional_id" name="profissional_id">
                    <option value="0">(Todos)</option>
                    <?php foreach ($profissionais as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"
                            <?= $profissional_id === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-col">
                <label for="sala_id">Sala</label>
                <select id="sala_id" name="sala_id">
                    <option value="0">(Todas)</option>
                    <?php foreach ($salas as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"
                            <?= $sala_id === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-col" style="align-self: flex-end;">
                <button type="submit" name="aplicar" value="1">Aplicar filtros</button>
                <button type="submit" name="export" value="csv">Exportar CSV</button>
                <a href="index.php" class="btn-secondary">Limpar filtros</a>
            </div>
        </form>

        <p class="info" style="margin-top: 8px;">
            Período: <strong>
                <?= htmlspecialchars(
                    DateTime::createFromFormat('Y-m-d', $data_inicio)->format('d/m/Y')
                ) ?>
                a
                <?= htmlspecialchars(
                    DateTime::createFromFormat('Y-m-d', $data_fim)->format('d/m/Y')
                ) ?>
            </strong><br>
            Profissional:
            <strong><?= $profNomeFiltro ? htmlspecialchars($profNomeFiltro) : 'Todos' ?></strong><br>
            Sala:
            <strong><?= $salaNomeFiltro ? htmlspecialchars($salaNomeFiltro) : 'Todas' ?></strong>
        </p>

        <p class="info" style="margin-top: 4px;">
            Deseja um relatório em PDF? Use a
            <a class="btn-link" href="relatorio.php?<?= htmlspecialchars($queryStringRelatorio) ?>">
                versão para impressão / PDF
            </a>.
        </p>
    </div>

    <div class="card">
        <h2>Resumo geral</h2>
        <p class="info">
            Total de horas utilizadas no período filtrado
            (considerando apenas registros com check-out concluído).
        </p>
        <h3>
            <?= htmlspecialchars(number_format($totalGeral, 2, ',', '')) ?> horas
        </h3>
    </div>

    <div class="card">
        <h2>Resumo por dia</h2>
        <?php if (empty($resumoDia)): ?>
            <p>Não há registros finalizados dentro dos filtros selecionados.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Data</th>
                    <th>Total de horas</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($resumoDia as $linha): ?>
                    <tr>
                        <td>
                            <?php
                            $dataBR = DateTime::createFromFormat('Y-m-d', $linha['data'])->format('d/m/Y');
                            echo htmlspecialchars($dataBR);
                            ?>
                        </td>
                        <td><?= htmlspecialchars(number_format((float)$linha['horas_total'], 2, ',', '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Resumo por profissional</h2>
        <?php if (empty($resumoProf)): ?>
            <p>Não há registros para os filtros selecionados.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Profissional</th>
                    <th>Total de horas</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($resumoProf as $linha): ?>
                    <tr>
                        <td><?= htmlspecialchars($linha['profissional']) ?></td>
                        <td><?= htmlspecialchars(number_format((float)$linha['horas_total'], 2, ',', '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Resumo por sala</h2>
        <?php if (empty($resumoSala)): ?>
            <p>Não há registros para os filtros selecionados.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Sala</th>
                    <th>Total de horas</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($resumoSala as $linha): ?>
                    <tr>
                        <td><?= htmlspecialchars($linha['sala']) ?></td>
                        <td><?= htmlspecialchars(number_format((float)$linha['horas_total'], 2, ',', '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>

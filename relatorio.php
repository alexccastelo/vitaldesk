<?php
// relatorio.php - Relatório para impressão / PDF
require_once 'config.php';
require_once 'auth.php';

// Mesma lógica de filtros do dashboard
$stmtProf = $pdo->query("SELECT * FROM profissionais WHERE ativo = 1 ORDER BY nome ASC");
$profissionais = $stmtProf->fetchAll(PDO::FETCH_ASSOC);

$stmtSalas = $pdo->query("SELECT * FROM salas WHERE ativo = 1 ORDER BY nome ASC");
$salas = $stmtSalas->fetchAll(PDO::FETCH_ASSOC);

$data_inicio     = isset($_GET['data_inicio']) ? trim($_GET['data_inicio']) : '';
$data_fim        = isset($_GET['data_fim']) ? trim($_GET['data_fim']) : '';
$profissional_id = isset($_GET['profissional_id']) ? (int)$_GET['profissional_id'] : 0;
$sala_id         = isset($_GET['sala_id']) ? (int)$_GET['sala_id'] : 0;

if ($data_inicio === '' && $data_fim === '') {
    $data_fim    = date('Y-m-d');
    $data_inicio = date('Y-m-d', strtotime('-6 days'));
} elseif ($data_inicio === '' && $data_fim !== '') {
    $data_inicio = $data_fim;
} elseif ($data_inicio !== '' && $data_fim === '') {
    $data_fim = $data_inicio;
}

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

$where  = "hora_checkout IS NOT NULL";
$param  = [];

$where                 .= " AND data BETWEEN :data_inicio AND :data_fim";
$param[':data_inicio']  = $data_inicio;
$param[':data_fim']     = $data_fim;

if ($profNomeFiltro !== null) {
    $where .= " AND profissional = :profissional";
    $param[':profissional'] = $profNomeFiltro;
}

if ($salaNomeFiltro !== null) {
    $where .= " AND sala = :sala";
    $param[':sala'] = $salaNomeFiltro;
}

// Resumo por dia
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

// Resumo por profissional
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

// Resumo por sala
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

// Total geral
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

// Lista detalhada (para anexar ao final do relatório)
$sqlDetalhes = "
    SELECT data, profissional, sala, hora_checkin, hora_checkout, total_horas
    FROM registros
    WHERE $where
    ORDER BY data, profissional, sala, hora_checkin
";
$stmtDet = $pdo->prepare($sqlDetalhes);
$stmtDet->execute($param);
$detalhes = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Uso de Salas</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container">
    <div class="card">
        <div class="topbar">
            <h1>Relatório de Uso de Salas</h1>
            <button type="button" onclick="window.print();">Imprimir / Salvar em PDF</button>
        </div>
        <?php include 'user_info.php'; ?>
        <p class="info">
            Este relatório está formatado para impressão. No navegador, escolha
            <strong>Imprimir &gt; Salvar como PDF</strong> para gerar o arquivo em PDF.
        </p>
        <p class="info">
            Período:
            <strong>
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
            <strong><?= $salaNomeFiltro ? htmlspecialchars($salaNomeFiltro) : 'Todas' ?></strong><br>
        </p>
        <p><strong>Total geral de horas no período:</strong>
            <?= htmlspecialchars(number_format($totalGeral, 2, ',', '')) ?> horas
        </p>
    </div>

    <div class="card">
        <h2>Resumo por dia</h2>
        <?php if (empty($resumoDia)): ?>
            <p>Não há registros finalizados para os filtros selecionados.</p>
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

    <div class="card">
        <h2>Registros detalhados</h2>
        <?php if (empty($detalhes)): ?>
            <p>Não há registros para os filtros selecionados.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Data</th>
                    <th>Profissional</th>
                    <th>Sala</th>
                    <th>Check-in</th>
                    <th>Check-out</th>
                    <th>Horas (decimais)</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($detalhes as $row): ?>
                    <tr>
                        <td>
                            <?php
                            $dataBR = DateTime::createFromFormat('Y-m-d', $row['data'])->format('d/m/Y');
                            echo htmlspecialchars($dataBR);
                            ?>
                        </td>
                        <td><?= htmlspecialchars($row['profissional']) ?></td>
                        <td><?= htmlspecialchars($row['sala']) ?></td>
                        <td><?= htmlspecialchars($row['hora_checkin']) ?></td>
                        <td><?= htmlspecialchars($row['hora_checkout']) ?></td>
                        <td>
                            <?php
                            if ($row['total_horas'] !== null) {
                                echo htmlspecialchars(number_format((float)$row['total_horas'], 2, ',', ''));
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <p class="info">
        Para retornar ao dashboard, acesse
        <a class="btn-link" href="index.php?<?= htmlspecialchars(http_build_query([
            'data_inicio'     => $data_inicio,
            'data_fim'        => $data_fim,
            'profissional_id' => $profissional_id,
            'sala_id'         => $sala_id,
        ])) ?>">Dashboard</a>.
    </p>
</div>
</body>
</html>

<?php
// checkin.php
require_once 'config.php';
require_once 'auth.php';

$mensagemWhatsApp = null;  // Mensagem para WhatsApp
$mensagemSistema  = null;  // Alertas gerais

// PROCESSAMENTO DOS FORMULÁRIOS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // CHECK-IN
    if ($action === 'checkin') {
        $profissional_id = (int)(isset($_POST['profissional_id']) ? $_POST['profissional_id'] : 0);
        $sala_id         = (int)(isset($_POST['sala_id']) ? $_POST['sala_id'] : 0);
        $data            = trim(isset($_POST['data']) ? $_POST['data'] : '');
        $hora_checkin    = trim(isset($_POST['hora_checkin']) ? $_POST['hora_checkin'] : '');

        if ($data === '') {
            $data = date('Y-m-d');
        }

        if ($profissional_id <= 0 || $sala_id <= 0 || $hora_checkin === '') {
            $mensagemSistema = "Erro: selecione o profissional, a sala e informe o horário de check-in.";
        } else {
            // Buscar nome do profissional
            $stmtP = $pdo->prepare("SELECT nome FROM profissionais WHERE id = :id AND ativo = 1");
            $stmtP->execute([':id' => $profissional_id]);
            $profRow = $stmtP->fetch(PDO::FETCH_ASSOC);

            // Buscar nome da sala
            $stmtS = $pdo->prepare("SELECT nome FROM salas WHERE id = :id AND ativo = 1");
            $stmtS->execute([':id' => $sala_id]);
            $salaRow = $stmtS->fetch(PDO::FETCH_ASSOC);

            if (!$profRow || !$salaRow) {
                $mensagemSistema = "Erro: profissional ou sala inválidos.";
            } else {
                $profissional = $profRow['nome'];
                $sala         = $salaRow['nome'];

                $stmt = $pdo->prepare("
                    INSERT INTO registros (profissional, sala, data, hora_checkin)
                    VALUES (:profissional, :sala, :data, :hora_checkin)
                ");
                $stmt->execute([
                    ':profissional' => $profissional,
                    ':sala'         => $sala,
                    ':data'         => $data,
                    ':hora_checkin' => $hora_checkin
                ]);

                $mensagemSistema = "Check-in registrado com sucesso para {$profissional} na sala {$sala}.";
            }
        }

    // CHECK-OUT
    } elseif ($action === 'checkout') {
        $id            = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
        $hora_checkout = trim(isset($_POST['hora_checkout']) ? $_POST['hora_checkout'] : '');

        if ($hora_checkout === '') {
            $hora_checkout = date('H:i'); // horário atual
        }

        $stmt = $pdo->prepare("SELECT * FROM registros WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $registro = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$registro) {
            $mensagemSistema = "Registro não encontrado.";
        } elseif (!empty($registro['hora_checkout'])) {
            $mensagemSistema = "Este registro já possui check-out.";
        } else {
            $data         = $registro['data'];
            $hora_checkin = $registro['hora_checkin'];
            $profissional = $registro['profissional'];
            $sala         = $registro['sala'];

            $horasDecimais   = calcularHorasDecimais($data, $hora_checkin, $hora_checkout);
            $horasFormatadas = number_format($horasDecimais, 2, ',', '');
            $dataBR          = DateTime::createFromFormat('Y-m-d', $data)->format('d/m/Y');

            $mensagemWhatsApp = "Olá, {$profissional}! Segue o registro de uso da sala {$sala}:\n"
                . "Data: {$dataBR}\n"
                . "Check-in: {$hora_checkin}\n"
                . "Check-out: {$hora_checkout}\n"
                . "Tempo total utilizado: {$horasFormatadas} horas.";

            $stmtUpdate = $pdo->prepare("
                UPDATE registros
                SET hora_checkout = :hora_checkout,
                    total_horas   = :total_horas,
                    mensagem      = :mensagem
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':hora_checkout' => $hora_checkout,
                ':total_horas'   => $horasDecimais,
                ':mensagem'      => $mensagemWhatsApp,
                ':id'            => $id
            ]);

            $mensagemSistema = "Check-out registrado com sucesso para {$profissional}.";
        }
    }
}

// BUSCAS PARA EXIBIÇÃO

// Dropdowns
$stmtProf = $pdo->query("SELECT * FROM profissionais WHERE ativo = 1 ORDER BY nome ASC");
$profissionais = $stmtProf->fetchAll(PDO::FETCH_ASSOC);

$stmtSalas = $pdo->query("SELECT * FROM salas WHERE ativo = 1 ORDER BY nome ASC");
$salas = $stmtSalas->fetchAll(PDO::FETCH_ASSOC);

// Check-ins em aberto
$stmtAbertos = $pdo->query("
    SELECT * FROM registros
    WHERE hora_checkout IS NULL
    ORDER BY data DESC, hora_checkin DESC
");
$registrosAbertos = $stmtAbertos->fetchAll(PDO::FETCH_ASSOC);

// Paginação dos registros finalizados (grupos de 5)
$itensPorPagina = 5;
$paginaAtual = isset($_GET['page_finalizados']) ? (int)$_GET['page_finalizados'] : 1;
if ($paginaAtual < 1) {
    $paginaAtual = 1;
}
$offset = ($paginaAtual - 1) * $itensPorPagina;

// Total de registros finalizados
$stmtFinalizadosCount = $pdo->query("SELECT COUNT(*) FROM registros WHERE hora_checkout IS NOT NULL");
$totalFinalizados = (int)$stmtFinalizadosCount->fetchColumn();
$totalPaginas = $totalFinalizados > 0 ? (int)ceil($totalFinalizados / $itensPorPagina) : 1;

$temPaginaAnterior = $paginaAtual > 1;
$temProximaPagina  = $paginaAtual < $totalPaginas;

// Garantir que limit/offset são inteiros na query
$limit     = (int)$itensPorPagina;
$offsetInt = (int)$offset;

// Buscar o grupo atual
$sqlFinalizados = "
    SELECT * FROM registros
    WHERE hora_checkout IS NOT NULL
    ORDER BY data DESC, hora_checkin DESC
    LIMIT $limit OFFSET $offsetInt
";
$stmtFinalizados = $pdo->query($sqlFinalizados);
$registrosFinalizados = $stmtFinalizados->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-in de Salas - Espaço Vital Clínica</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container">
    <h1>Controle de Check-in / Check-out de Salas</h1>
    <?php include 'user_info.php'; ?>

    <?php if ($mensagemSistema !== null): ?>
        <div class="msg-alerta">
            <?= htmlspecialchars($mensagemSistema) ?>
        </div>
    <?php endif; ?>

    <?php if ($mensagemWhatsApp !== null): ?>
        <div class="card">
            <h2>Mensagem para enviar ao profissional</h2>
            <p class="info">Copie e cole essa mensagem no WhatsApp do profissional:</p>
            <textarea rows="5" readonly onclick="this.select();"><?= htmlspecialchars($mensagemWhatsApp) ?></textarea>
        </div>
    <?php endif; ?>

    <!-- NOVO CHECK-IN -->
    <div class="card">
        <h2>Novo Check-in</h2>

        <?php if (empty($profissionais) || empty($salas)): ?>
            <p class="info">
                Para registrar um check-in, primeiro cadastre pelo menos um <strong>profissional</strong> e uma <strong>sala</strong> na área de
                <a class="btn-link" href="registros.php">Admin &gt; Registros</a>.
            </p>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="action" value="checkin">

                <label for="profissional_id">Profissional</label>
                <select id="profissional_id" name="profissional_id" required>
                    <option value="">Selecione um profissional...</option>
                    <?php foreach ($profissionais as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="sala_id">Sala</label>
                <select id="sala_id" name="sala_id" required>
                    <option value="">Selecione uma sala...</option>
                    <?php foreach ($salas as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nome']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="data">Data</label>
                <input type="date" id="data" name="data" value="<?= date('Y-m-d'); ?>">

                <label for="hora_checkin">Horário de Check-in</label>
                <input type="time" id="hora_checkin" name="hora_checkin" required value="<?= date('H:i'); ?>">

                <button type="submit">Registrar Check-in</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- CHECK-INS EM ABERTO -->
    <div class="card">
        <h2>Check-ins em aberto</h2>
        <?php if (empty($registrosAbertos)): ?>
            <p>Não há check-ins em aberto.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Profissional</th>
                    <th>Sala</th>
                    <th>Data</th>
                    <th>Check-in</th>
                    <th>Check-out</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($registrosAbertos as $reg): ?>
                    <tr>
                        <td><?= htmlspecialchars($reg['id']) ?></td>
                        <td><?= htmlspecialchars($reg['profissional']) ?></td>
                        <td><?= htmlspecialchars($reg['sala']) ?></td>
                        <td>
                            <?php
                            $dataBR = DateTime::createFromFormat('Y-m-d', $reg['data'])->format('d/m/Y');
                            echo htmlspecialchars($dataBR);
                            ?>
                        </td>
                        <td><?= htmlspecialchars($reg['hora_checkin']) ?></td>
                        <td>
                            <form method="post" class="actions-form">
                                <input type="hidden" name="action" value="checkout">
                                <input type="hidden" name="id" value="<?= (int)$reg['id'] ?>">
                                <input class="small-input" type="time" name="hora_checkout" value="<?= date('H:i'); ?>">
                                <button type="submit">Fazer Check-out</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- REGISTROS FINALIZADOS EM GRUPOS DE 5 -->
    <div class="card">
        <h2>Registros finalizados (em grupos de 5)</h2>
        <p class="info">
            Esta listagem mostra os registros finalizados em grupos de 5. Use os comandos abaixo para navegar para os próximos ou anteriores 5 registros.
        </p>
        <?php if ($totalFinalizados === 0): ?>
            <p>Não há registros finalizados ainda.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Profissional</th>
                    <th>Sala</th>
                    <th>Data</th>
                    <th>Check-in</th>
                    <th>Check-out</th>
                    <th>Horas (decimais)</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($registrosFinalizados as $reg): ?>
                    <tr>
                        <td><?= htmlspecialchars($reg['id']) ?></td>
                        <td><?= htmlspecialchars($reg['profissional']) ?></td>
                        <td><?= htmlspecialchars($reg['sala']) ?></td>
                        <td>
                            <?php
                            $dataBR = DateTime::createFromFormat('Y-m-d', $reg['data'])->format('d/m/Y');
                            echo htmlspecialchars($dataBR);
                            ?>
                        </td>
                        <td><?= htmlspecialchars($reg['hora_checkin']) ?></td>
                        <td><?= htmlspecialchars($reg['hora_checkout']) ?></td>
                        <td>
                            <?php
                            if ($reg['total_horas'] !== null) {
                                echo htmlspecialchars(number_format((float)$reg['total_horas'], 2, ',', ''));
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="info" style="margin-top: 8px;">
                Página <?= htmlspecialchars($paginaAtual) ?> de <?= htmlspecialchars($totalPaginas) ?>.
            </p>

            <div style="margin-top: 8px;">
                <?php if ($temPaginaAnterior): ?>
                    <a class="btn-link" href="checkin.php?page_finalizados=<?= $paginaAtual - 1 ?>">&laquo; Anteriores 5</a>
                <?php endif; ?>

                <?php if ($temPaginaAnterior && $temProximaPagina): ?>
                    &nbsp;|&nbsp;
                <?php endif; ?>

                <?php if ($temProximaPagina): ?>
                    <a class="btn-link" href="checkin.php?page_finalizados=<?= $paginaAtual + 1 ?>">Próximos 5 &raquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>

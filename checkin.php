<?php
require_once 'config.php';
require_once 'auth.php';

$mensagemSistema  = '';
$mensagemWhatsApp = '';
$erro             = '';

/*
 |---------------------------------------------------------
 | AÇÕES: NOVO CHECK-IN / CHECK-OUT
 |---------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // NOVO CHECK-IN
    if ($action === 'novo_checkin') {
        $profissionalId = (int)($_POST['profissional_id'] ?? 0);
        $salaId         = (int)($_POST['sala_id'] ?? 0);
        $data           = trim($_POST['data'] ?? '');
        $horaCheckin    = trim($_POST['hora_checkin'] ?? '');

        if ($profissionalId <= 0 || $salaId <= 0) {
            $erro = 'Selecione o profissional e a sala.';
        } else {
            if ($data === '') {
                $data = date('Y-m-d');
            }
            if ($horaCheckin === '') {
                $horaCheckin = date('H:i');
            }

            // Busca nome do profissional
            $stmt = $pdo->prepare("SELECT nome FROM profissionais WHERE id = :id");
            $stmt->execute([':id' => $profissionalId]);
            $rowProf = $stmt->fetch(PDO::FETCH_ASSOC);
            $nomeProfissional = $rowProf['nome'] ?? '';

            // Busca nome da sala
            $stmt = $pdo->prepare("SELECT nome FROM salas WHERE id = :id");
            $stmt->execute([':id' => $salaId]);
            $rowSala = $stmt->fetch(PDO::FETCH_ASSOC);
            $nomeSala = $rowSala['nome'] ?? '';

            if ($nomeProfissional === '' || $nomeSala === '') {
                $erro = 'Erro ao localizar o profissional ou a sala.';
            } else {
                // Insere o registro
                $stmt = $pdo->prepare("
                    INSERT INTO registros (profissional, sala, data, hora_checkin)
                    VALUES (:profissional, :sala, :data, :hora_checkin)
                ");
                $stmt->execute([
                    ':profissional' => $nomeProfissional,
                    ':sala'         => $nomeSala,
                    ':data'         => $data,
                    ':hora_checkin' => $horaCheckin,
                ]);

                $mensagemSistema = "Check-in registrado com sucesso para {$nomeProfissional} na sala {$nomeSala}.";

                // Monta mensagem para WhatsApp (CHECK-IN)
                $dataBR = DateTime::createFromFormat('Y-m-d', $data);
                $dataFormatada = $dataBR ? $dataBR->format('d/m/Y') : $data;

                $mensagemWhatsApp  = "Olá, {$nomeProfissional}! Seu check-in para uso da sala {$nomeSala} foi registrado:\n";
                $mensagemWhatsApp .= "Data: {$dataFormatada}\n";
                $mensagemWhatsApp .= "Check-in: {$horaCheckin}.";
            }
        }
    }

    // CHECK-OUT
    if ($action === 'checkout') {
        $registroId   = (int)($_POST['registro_id'] ?? 0);
        $horaCheckout = trim($_POST['hora_checkout'] ?? '');

        if ($registroId <= 0) {
            $erro = 'Registro inválido para check-out.';
        } else {
            if ($horaCheckout === '') {
                $horaCheckout = date('H:i');
            }

            // Busca o registro
            $stmt = $pdo->prepare("
                SELECT id, profissional, sala, data, hora_checkin, hora_checkout, total_horas
                FROM registros
                WHERE id = :id
            ");
            $stmt->execute([':id' => $registroId]);
            $registro = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$registro) {
                $erro = 'Registro não encontrado.';
            } elseif (!empty($registro['hora_checkout'])) {
                $erro = 'Este registro já possui check-out.';
            } else {
                // Calcula horas decimais usando função do config.php
                $totalHoras = calcularHorasDecimais(
                    $registro['data'],
                    $registro['hora_checkin'],
                    $horaCheckout
                );

                $stmt = $pdo->prepare("
                    UPDATE registros
                    SET hora_checkout = :hora_checkout,
                        total_horas   = :total_horas
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':hora_checkout' => $horaCheckout,
                    ':total_horas'   => $totalHoras,
                    ':id'            => $registroId,
                ]);

                $mensagemSistema = "Check-out registrado com sucesso para {$registro['profissional']}.";

                // Monta mensagem para WhatsApp (CHECK-OUT)
                $dataBR = DateTime::createFromFormat('Y-m-d', $registro['data']);
                $dataFormatada = $dataBR ? $dataBR->format('d/m/Y') : $registro['data'];

                $mensagemWhatsApp  = "Olá, {$registro['profissional']}! Segue o registro de uso da sala {$registro['sala']}:\n";
                $mensagemWhatsApp .= "Data: {$dataFormatada}\n";
                $mensagemWhatsApp .= "Check-in: {$registro['hora_checkin']}\n";
                $mensagemWhatsApp .= "Check-out: {$horaCheckout}\n";
                $mensagemWhatsApp .= "Tempo total utilizado: " . number_format((float)$totalHoras, 2, ',', '') . " horas.";
            }
        }
    }
}

/*
 |---------------------------------------------------------
 | BUSCAS: PROFISSIONAIS, SALAS, ABERTOS, ÚLTIMOS FINALIZADOS
 |---------------------------------------------------------
*/

// Profissionais e salas para o dropdown
$stmt = $pdo->query("SELECT id, nome FROM profissionais WHERE ativo = 1 ORDER BY nome ASC");
$profissionais = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT id, nome FROM salas WHERE ativo = 1 ORDER BY nome ASC");
$salas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check-ins em aberto (sem check-out)
$stmt = $pdo->query("
    SELECT id, profissional, sala, data, hora_checkin
    FROM registros
    WHERE hora_checkout IS NULL
    ORDER BY data DESC, hora_checkin DESC
");
$abertos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Últimos registros finalizados (pagina de 5 em 5)
$porPagina = 5;
$pagina = max(1, (int)($_GET['page'] ?? 1));
$offset = ($pagina - 1) * $porPagina;

// total para paginação
$stmt = $pdo->query("SELECT COUNT(*) FROM registros WHERE hora_checkout IS NOT NULL");
$totalFinalizados = (int)$stmt->fetchColumn();
$totalPaginas = max(1, (int)ceil($totalFinalizados / $porPagina));

$stmt = $pdo->prepare("
    SELECT id, profissional, sala, data, hora_checkin, hora_checkout, total_horas
    FROM registros
    WHERE hora_checkout IS NOT NULL
    ORDER BY data DESC, hora_checkin DESC
    LIMIT :limite OFFSET :offset
");
$stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$finalizados = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-in - Espaço Vital Clínica</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container">
    <h1>Controle de Check-in / Check-out de Salas</h1>

    <?php include 'user_info.php'; ?>

    <?php if ($mensagemSistema): ?>
        <div class="msg-alerta" style="background-color:#fffbeb;border-color:#fbbf24;color:#92400e;">
            <?= htmlspecialchars($mensagemSistema) ?>
        </div>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="msg-alerta">
            <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <?php if ($mensagemWhatsApp): ?>
        <div class="card">
            <h2>Mensagem para enviar ao profissional</h2>
            <p>Copie e cole essa mensagem no WhatsApp do profissional:</p>
            <textarea readonly rows="5"><?= htmlspecialchars($mensagemWhatsApp) ?></textarea>
        </div>
    <?php endif; ?>

    <!-- NOVO CHECK-IN -->
    <div class="card">
        <h2>Novo check-in</h2>
        <form method="post" class="flex-row">
            <input type="hidden" name="action" value="novo_checkin">

            <div class="flex-col">
                <label for="profissional_id">Profissional</label>
                <select id="profissional_id" name="profissional_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($profissionais as $p): ?>
                        <option value="<?= (int)$p['id'] ?>">
                            <?= htmlspecialchars($p['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="sala_id">Sala</label>
                <select id="sala_id" name="sala_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($salas as $s): ?>
                        <option value="<?= (int)$s['id'] ?>">
                            <?= htmlspecialchars($s['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex-col">
                <label for="data">Data</label>
                <input type="date" id="data" name="data" value="<?= date('Y-m-d'); ?>">

                <label for="hora_checkin">Horário de check-in</label>
                <input type="time" id="hora_checkin" name="hora_checkin" value="<?= date('H:i'); ?>">

                <button type="submit" style="margin-top:16px;">Registrar check-in</button>
            </div>
        </form>
    </div>

    <!-- CHECK-INS EM ABERTO -->
    <div class="card">
        <h2>Check-ins em aberto</h2>
        <?php if (empty($abertos)): ?>
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
                <?php foreach ($abertos as $a): ?>
                    <tr>
                        <td><?= (int)$a['id'] ?></td>
                        <td><?= htmlspecialchars($a['profissional']) ?></td>
                        <td><?= htmlspecialchars($a['sala']) ?></td>
                        <td>
                            <?php
                            $dataBR = DateTime::createFromFormat('Y-m-d', $a['data']);
                            echo $dataBR ? htmlspecialchars($dataBR->format('d/m/Y')) : htmlspecialchars($a['data']);
                            ?>
                        </td>
                        <td><?= htmlspecialchars($a['hora_checkin']) ?></td>
                        <td>
                            <form method="post" class="actions-form">
                                <input type="hidden" name="action" value="checkout">
                                <input type="hidden" name="registro_id" value="<?= (int)$a['id'] ?>">
                                <input type="time" name="hora_checkout" value="<?= date('H:i'); ?>">
                                <button type="submit">Registrar check-out</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ÚLTIMOS REGISTROS FINALIZADOS -->
    <div class="card">
        <h2>Últimos registros finalizados</h2>
        <p class="info">
            Exibindo <strong><?= $porPagina ?></strong> registros por página.
            Use os links abaixo para navegar entre os grupos de registros.
        </p>
        <?php if (empty($finalizados)): ?>
            <p>Não há registros finalizados.</p>
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
                <?php foreach ($finalizados as $r): ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= htmlspecialchars($r['profissional']) ?></td>
                        <td><?= htmlspecialchars($r['sala']) ?></td>
                        <td>
                            <?php
                            $dataBR = DateTime::createFromFormat('Y-m-d', $r['data']);
                            echo $dataBR ? htmlspecialchars($dataBR->format('d/m/Y')) : htmlspecialchars($r['data']);
                            ?>
                        </td>
                        <td><?= htmlspecialchars($r['hora_checkin']) ?></td>
                        <td><?= htmlspecialchars($r['hora_checkout']) ?></td>
                        <td>
                            <?= $r['total_horas'] !== null
                                ? htmlspecialchars(number_format((float)$r['total_horas'], 2, ',', ''))
                                : '' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPaginas > 1): ?>
                <div class="pagination">
                    <?php if ($pagina > 1): ?>
                        <a href="checkin.php?page=<?= $pagina - 1 ?>">&laquo; Anteriores</a>
                    <?php endif; ?>

                    <span>Página <?= $pagina ?> de <?= $totalPaginas ?></span>

                    <?php if ($pagina < $totalPaginas): ?>
                        <a href="checkin.php?page=<?= $pagina + 1 ?>">Próximos &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>

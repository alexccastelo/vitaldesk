<?php

require_once 'config.php';
require_once 'auth.php';

$mensagem = '';
$erro     = '';

// Função para recalcular horas decimais quando editar um registro


/*
 |---------------------------------------------------------
 | PROCESSAMENTO DOS POSTS
 |---------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // NOVO PROFISSIONAL
    if ($action === 'create_profissional') {
        $nome = trim($_POST['nome'] ?? '');

        if ($nome === '') {
            $erro = 'Informe o nome do profissional.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO profissionais (nome, ativo) VALUES (:nome, 1)");
            $stmt->execute([':nome' => $nome]);
            $mensagem = 'Profissional cadastrado com sucesso.';
        }

    // EXCLUIR PROFISSIONAL
    } elseif ($action === 'delete_profissional') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $erro = 'ID de profissional inválido.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM profissionais WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $mensagem = 'Profissional removido.';
        }

    // NOVA SALA
    } elseif ($action === 'create_sala') {
        $nome = trim($_POST['nome'] ?? '');

        if ($nome === '') {
            $erro = 'Informe o nome da sala.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO salas (nome, ativo) VALUES (:nome, 1)");
            $stmt->execute([':nome' => $nome]);
            $mensagem = 'Sala cadastrada com sucesso.';
        }

    // EXCLUIR SALA
    } elseif ($action === 'delete_sala') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $erro = 'ID de sala inválido.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM salas WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $mensagem = 'Sala removida.';
        }

    // ATUALIZAR REGISTRO
    } elseif ($action === 'update_registro') {
        $id              = (int)($_POST['id'] ?? 0);
        $profissional    = trim($_POST['profissional'] ?? '');
        $sala            = trim($_POST['sala'] ?? '');
        $data            = trim($_POST['data'] ?? '');
        $horaCheckin     = trim($_POST['hora_checkin'] ?? '');
        $horaCheckout    = trim($_POST['hora_checkout'] ?? '');

        if ($id <= 0) {
            $erro = 'ID de registro inválido.';
        } elseif ($profissional === '' || $sala === '' || $data === '' || $horaCheckin === '') {
            $erro = 'Preencha profissional, sala, data e horário de check-in.';
        } else {
            $totalHoras = null;
            if ($horaCheckout !== '') {
                $totalHoras = calcularHorasDecimais($data, $horaCheckin, $horaCheckout);
            }

            $stmt = $pdo->prepare("
                UPDATE registros
                SET profissional   = :profissional,
                    sala           = :sala,
                    data           = :data,
                    hora_checkin   = :hora_checkin,
                    hora_checkout  = :hora_checkout,
                    total_horas    = :total_horas
                WHERE id = :id
            ");
            $stmt->execute([
                ':profissional'  => $profissional,
                ':sala'          => $sala,
                ':data'          => $data,
                ':hora_checkin'  => $horaCheckin,
                ':hora_checkout' => $horaCheckout !== '' ? $horaCheckout : null,
                ':total_horas'   => $totalHoras,
                ':id'            => $id,
            ]);

            $mensagem = 'Registro atualizado com sucesso.';
        }

    // EXCLUIR REGISTRO
    } elseif ($action === 'delete_registro') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $erro = 'ID de registro inválido.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM registros WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $mensagem = 'Registro excluído com sucesso.';
        }
    }
}

/*
 |---------------------------------------------------------
 | BUSCAS PARA POPULAR A TELA
 |---------------------------------------------------------
*/
$stmtProf  = $pdo->query("SELECT id, nome FROM profissionais ORDER BY nome ASC");
$profissionais = $stmtProf->fetchAll(PDO::FETCH_ASSOC);

$stmtSalas = $pdo->query("SELECT id, nome FROM salas ORDER BY nome ASC");
$salas     = $stmtSalas->fetchAll(PDO::FETCH_ASSOC);

$stmtReg = $pdo->query("
    SELECT id, profissional, sala, data, hora_checkin, hora_checkout, total_horas
    FROM registros
    ORDER BY data DESC, hora_checkin DESC
");
$registros = $stmtReg->fetchAll(PDO::FETCH_ASSOC);

// Registro em edição (se houver ?edit=ID)
$registroEditando = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
if ($editId > 0) {
    $stmt = $pdo->prepare("
        SELECT id, profissional, sala, data, hora_checkin, hora_checkout, total_horas
        FROM registros
        WHERE id = :id
    ");
    $stmt->execute([':id' => $editId]);
    $registroEditando = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registros - Espaço Vital Clínica</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container">
    <h1>Administração - Registros de Uso de Salas</h1>

    <?php include 'user_info.php'; ?>

    <?php if ($mensagem): ?>
        <div class="msg-alerta" style="background-color:#ecfdf3;border-color:#22c55e;color:#166534;">
            <?= htmlspecialchars($mensagem) ?>
        </div>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="msg-alerta">
            <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <!-- CADASTRO DE PROFISSIONAIS E SALAS -->
    <div class="card">
        <h2>Cadastro de Profissionais e Salas</h2>
        <div class="flex-row">
            <div class="flex-col">
                <h3>Novo profissional</h3>
                <form method="post">
                    <input type="hidden" name="action" value="create_profissional">
                    <label for="nome_profissional">Nome do profissional</label>
                    <input type="text" id="nome_profissional" name="nome" required>
                    <button type="submit">Adicionar profissional</button>
                </form>

                <h3 style="margin-top:12px;">Profissionais cadastrados</h3>
                <?php if (empty($profissionais)): ?>
                    <p class="info">Nenhum profissional cadastrado.</p>
                <?php else: ?>
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>Ações</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($profissionais as $p): ?>
                            <tr>
                                <td><?= (int)$p['id'] ?></td>
                                <td><?= htmlspecialchars($p['nome']) ?></td>
                                <td>
                                    <form method="post" class="actions-form"
                                          onsubmit="return confirm('Tem certeza que deseja excluir este profissional?');">
                                        <input type="hidden" name="action" value="delete_profissional">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit">Excluir</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="flex-col">
                <h3>Nova sala</h3>
                <form method="post">
                    <input type="hidden" name="action" value="create_sala">
                    <label for="nome_sala">Nome da sala</label>
                    <input type="text" id="nome_sala" name="nome" required>
                    <button type="submit">Adicionar sala</button>
                </form>

                <h3 style="margin-top:12px;">Salas cadastradas</h3>
                <?php if (empty($salas)): ?>
                    <p class="info">Nenhuma sala cadastrada.</p>
                <?php else: ?>
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>Ações</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($salas as $s): ?>
                            <tr>
                                <td><?= (int)$s['id'] ?></td>
                                <td><?= htmlspecialchars($s['nome']) ?></td>
                                <td>
                                    <form method="post" class="actions-form"
                                          onsubmit="return confirm('Tem certeza que deseja excluir esta sala?');">
                                        <input type="hidden" name="action" value="delete_sala">
                                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                        <button type="submit">Excluir</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- EDIÇÃO DE REGISTRO (opcional) -->
    <?php if ($registroEditando): ?>
        <div class="card">
            <h2>Editar registro #<?= (int)$registroEditando['id'] ?></h2>
            <form method="post" class="flex-row">
                <input type="hidden" name="action" value="update_registro">
                <input type="hidden" name="id" value="<?= (int)$registroEditando['id'] ?>">

                <div class="flex-col">
                    <label for="profissional_edit">Profissional</label>
                    <select id="profissional_edit" name="profissional" required>
                        <option value="">Selecione</option>
                        <?php foreach ($profissionais as $p): ?>
                            <option value="<?= htmlspecialchars($p['nome']) ?>"
                                <?= $registroEditando['profissional'] === $p['nome'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="sala_edit">Sala</label>
                    <select id="sala_edit" name="sala" required>
                        <option value="">Selecione</option>
                        <?php foreach ($salas as $s): ?>
                            <option value="<?= htmlspecialchars($s['nome']) ?>"
                                <?= $registroEditando['sala'] === $s['nome'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex-col">
                    <label for="data_edit">Data</label>
                    <input type="date" id="data_edit" name="data"
                           value="<?= htmlspecialchars($registroEditando['data']) ?>" required>

                    <label for="hora_checkin_edit">Check-in</label>
                    <input type="time" id="hora_checkin_edit" name="hora_checkin"
                           value="<?= htmlspecialchars($registroEditando['hora_checkin']) ?>" required>

                    <label for="hora_checkout_edit">Check-out</label>
                    <input type="time" id="hora_checkout_edit" name="hora_checkout"
                           value="<?= htmlspecialchars($registroEditando['hora_checkout'] ?? '') ?>">
                </div>

                <div class="flex-col" style="align-self:flex-end;">
                    <button type="submit">Salvar alterações</button>
                    <a href="registros.php" class="btn-secondary" style="margin-left:8px;">Cancelar</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- TODOS OS REGISTROS -->
    <div class="card">
        <h2>Todos os registros</h2>
        <?php if (empty($registros)): ?>
            <p>Não há registros cadastrados.</p>
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
                    <th>Ações</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($registros as $r): ?>
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
                        <td><?= htmlspecialchars($r['hora_checkout'] ?? '') ?></td>
                        <td>
                            <?= $r['total_horas'] !== null
                                ? htmlspecialchars(number_format((float)$r['total_horas'], 2, ',', ''))
                                : '' ?>
                        </td>
                        <td>
                            <a class="btn-link" href="registros.php?edit=<?= (int)$r['id'] ?>">Editar</a>
                            |
                            <form method="post" class="actions-form"
                                  onsubmit="return confirm('Tem certeza que deseja excluir este registro?');">
                                <input type="hidden" name="action" value="delete_registro">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>

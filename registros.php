<?php
// registros.php
session_start();
require_once 'config.php';
require_once 'auth.php';

$mensagemSistema = null;

// PROCESSAMENTO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // LOGIN
    if ($action === 'login') {
        $email = trim(isset($_POST['email']) ? $_POST['email'] : '');
        $senha = trim(isset($_POST['senha']) ? $_POST['senha'] : '');

        if ($email === '' || $senha === '') {
            $mensagemSistema = "Informe e-mail e senha.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = :email");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($senha, $user['senha_hash'])) {
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['user_email'] = $user['email'];
                $mensagemSistema = "Login realizado com sucesso.";
            } else {
                $mensagemSistema = "E-mail ou senha inválidos.";
            }
        }

    // LOGOUT
    } elseif ($action === 'logout') {
        session_unset();
        session_destroy();
        header("Location: registros.php");
        exit;

    // OUTRAS AÇÕES (precisam de login)
    } else {
        if (!isset($_SESSION['user_id'])) {
            $mensagemSistema = "Acesso não autorizado.";
        } else {
            // CADASTRAR PROFISSIONAL
            if ($action === 'add_profissional') {
                $nome = trim(isset($_POST['nome_profissional']) ? $_POST['nome_profissional'] : '');

                if ($nome === '') {
                    $mensagemSistema = "Erro: informe o nome do profissional.";
                } else {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO profissionais (nome) VALUES (:nome)");
                        $stmt->execute([':nome' => $nome]);
                        $mensagemSistema = "Profissional '{$nome}' cadastrado com sucesso.";
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'UNIQUE') !== false) {
                            $mensagemSistema = "Já existe um profissional cadastrado com esse nome.";
                        } else {
                            $mensagemSistema = "Erro ao cadastrar profissional: " . $e->getMessage();
                        }
                    }
                }

            // CADASTRAR SALA
            } elseif ($action === 'add_sala') {
                $nome = trim(isset($_POST['nome_sala']) ? $_POST['nome_sala'] : '');

                if ($nome === '') {
                    $mensagemSistema = "Erro: informe o nome da sala.";
                } else {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO salas (nome) VALUES (:nome)");
                        $stmt->execute([':nome' => $nome]);
                        $mensagemSistema = "Sala '{$nome}' cadastrada com sucesso.";
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'UNIQUE') !== false) {
                            $mensagemSistema = "Já existe uma sala cadastrada com esse nome.";
                        } else {
                            $mensagemSistema = "Erro ao cadastrar sala: " . $e->getMessage();
                        }
                    }
                }

            // ATUALIZAR REGISTRO
            } elseif ($action === 'update_registro') {
                $id            = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
                $profissional  = trim(isset($_POST['profissional']) ? $_POST['profissional'] : '');
                $sala          = trim(isset($_POST['sala']) ? $_POST['sala'] : '');
                $data          = trim(isset($_POST['data']) ? $_POST['data'] : '');
                $hora_checkin  = trim(isset($_POST['hora_checkin']) ? $_POST['hora_checkin'] : '');
                $hora_checkout = trim(isset($_POST['hora_checkout']) ? $_POST['hora_checkout'] : '');

                if ($id <= 0 || $profissional === '' || $sala === '' || $data === '' || $hora_checkin === '') {
                    $mensagemSistema = "Preencha todos os campos obrigatórios (exceto check-out, que pode ficar em branco).";
                } else {
                    $total_horas = null;
                    $mensagem    = null;

                    if ($hora_checkout !== '') {
                        $total_horas    = calcularHorasDecimais($data, $hora_checkin, $hora_checkout);
                        $horasFormatadas = number_format($total_horas, 2, ',', '');
                        $dataBR          = DateTime::createFromFormat('Y-m-d', $data)->format('d/m/Y');

                        $mensagem = "Olá, {$profissional}! Segue o registro de uso da sala {$sala}:\n"
                            . "Data: {$dataBR}\n"
                            . "Check-in: {$hora_checkin}\n"
                            . "Check-out: {$hora_checkout}\n"
                            . "Tempo total utilizado: {$horasFormatadas} horas.";
                    }

                    $stmt = $pdo->prepare("
                        UPDATE registros
                        SET profissional = :profissional,
                            sala         = :sala,
                            data         = :data,
                            hora_checkin = :hora_checkin,
                            hora_checkout= :hora_checkout,
                            total_horas  = :total_horas,
                            mensagem     = :mensagem
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':profissional' => $profissional,
                        ':sala'         => $sala,
                        ':data'         => $data,
                        ':hora_checkin' => $hora_checkin,
                        ':hora_checkout'=> $hora_checkout !== '' ? $hora_checkout : null,
                        ':total_horas'  => $total_horas,
                        ':mensagem'     => $mensagem,
                        ':id'           => $id
                    ]);

                    $mensagemSistema = "Registro #{$id} atualizado com sucesso.";
                }

            // EXCLUIR REGISTRO
            } elseif ($action === 'delete_registro') {
                $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
                if ($id > 0) {
                    $stmt = $pdo->prepare("DELETE FROM registros WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                    $mensagemSistema = "Registro #{$id} excluído com sucesso.";
                } else {
                    $mensagemSistema = "Registro inválido para exclusão.";
                }
            }
        }
    }
}

// VARIÁVEIS DE LISTAGEM
$registros      = [];
$registroEdicao = null;
$profissionais  = [];
$salas          = [];

if (isset($_SESSION['user_id'])) {
    // Registros
    $stmt = $pdo->query("SELECT * FROM registros ORDER BY data DESC, hora_checkin DESC");
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
    if ($editId > 0) {
        $stmtE = $pdo->prepare("SELECT * FROM registros WHERE id = :id");
        $stmtE->execute([':id' => $editId]);
        $registroEdicao = $stmtE->fetch(PDO::FETCH_ASSOC);
    }

    // Profissionais e salas (para o card de cadastro)
    $stmtProf = $pdo->query("SELECT * FROM profissionais WHERE ativo = 1 ORDER BY nome ASC");
    $profissionais = $stmtProf->fetchAll(PDO::FETCH_ASSOC);

    $stmtSalas = $pdo->query("SELECT * FROM salas WHERE ativo = 1 ORDER BY nome ASC");
    $salas = $stmtSalas->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Registros de Salas</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container">
    <div class="topbar">
        <h1>Administração - Registros de Uso de Salas</h1>
    </div>
    <?php include 'user_info.php'; ?>

    <?php if ($mensagemSistema !== null): ?>
        <div class="msg-alerta">
            <?= htmlspecialchars($mensagemSistema) ?>
        </div>
    <?php endif; ?>

    <?php if (!isset($_SESSION['user_id'])): ?>
        <!-- LOGIN -->
        <div class="card">
            <h2>Login</h2>
            <p class="info">
                Usuário padrão (apenas na primeira vez):<br>
                <strong>E-mail:</strong> admin@clinica.local<br>
                <strong>Senha:</strong> Clinica@2024!
            </p>
            <form method="post">
                <input type="hidden" name="action" value="login">

                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" required>

                <label for="senha">Senha</label>
                <input type="password" id="senha" name="senha" required>

                <button type="submit">Entrar</button>
            </form>
        </div>
    <?php else: ?>

        <div class="card">
            <div class="topbar">
                <div>
                    <strong>Usuário:</strong> <?= htmlspecialchars($_SESSION['user_email']) ?>
                </div>
                <form method="post" class="actions-form">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit">Sair</button>
                </form>
            </div>
        </div>

        <!-- NOVO: CADASTRO DE PROFISSIONAIS E SALAS -->
        <div class="card">
            <h2>Cadastro de Profissionais e Salas</h2>
            <div class="flex-row">
                <div class="flex-col">
                    <h3>Profissionais</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="add_profissional">
                        <label for="nome_profissional">Nome do profissional</label>
                        <input type="text" id="nome_profissional" name="nome_profissional" placeholder="Ex.: Dra. Maria Silva">
                        <button type="submit">Cadastrar profissional</button>
                    </form>
                    <?php if (!empty($profissionais)): ?>
                        <p class="info" style="margin-top: 12px;">Profissionais cadastrados (ativos):</p>
                        <ul class="info">
                            <?php foreach ($profissionais as $p): ?>
                                <li><?= htmlspecialchars($p['nome']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="info" style="margin-top: 12px;">Nenhum profissional cadastrado ainda.</p>
                    <?php endif; ?>
                </div>

                <div class="flex-col">
                    <h3>Salas</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="add_sala">
                        <label for="nome_sala">Nome da sala</label>
                        <input type="text" id="nome_sala" name="nome_sala" placeholder="Ex.: Sala 01, Sala Azul, etc.">
                        <button type="submit">Cadastrar sala</button>
                    </form>
                    <?php if (!empty($salas)): ?>
                        <p class="info" style="margin-top: 12px;">Salas cadastradas (ativas):</p>
                        <ul class="info">
                            <?php foreach ($salas as $s): ?>
                                <li><?= htmlspecialchars($s['nome']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="info" style="margin-top: 12px;">Nenhuma sala cadastrada ainda.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($registroEdicao): ?>
            <div class="card">
                <h2>Editar registro #<?= htmlspecialchars($registroEdicao['id']) ?></h2>
                <p class="info small">Ao alterar horários com check-out preenchido, o sistema recalcula o tempo total.</p>
                <form method="post">
                    <input type="hidden" name="action" value="update_registro">
                    <input type="hidden" name="id" value="<?= (int)$registroEdicao['id'] ?>">

                    <label for="profissional">Profissional</label>
                    <input type="text" id="profissional" name="profissional"
                           value="<?= htmlspecialchars($registroEdicao['profissional']) ?>" required>

                    <label for="sala">Sala</label>
                    <input type="text" id="sala" name="sala"
                           value="<?= htmlspecialchars($registroEdicao['sala']) ?>" required>

                    <label for="data">Data</label>
                    <input type="date" id="data" name="data"
                           value="<?= htmlspecialchars($registroEdicao['data']) ?>" required>

                    <label for="hora_checkin">Horário de Check-in</label>
                    <input type="time" id="hora_checkin" name="hora_checkin"
                           value="<?= htmlspecialchars($registroEdicao['hora_checkin']) ?>" required>

                    <label for="hora_checkout">Horário de Check-out (pode ficar em branco)</label>
                    <input type="time" id="hora_checkout" name="hora_checkout"
                           value="<?= htmlspecialchars($registroEdicao['hora_checkout'] ?? '') ?>">

                    <button type="submit">Salvar alterações</button>
                    <a class="btn-link" href="registros.php">Cancelar edição</a>
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
                    <?php foreach ($registros as $reg): ?>
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
                            <td><?= htmlspecialchars($reg['hora_checkout'] ?? '') ?></td>
                            <td>
                                <?php
                                if ($reg['total_horas'] !== null) {
                                    echo htmlspecialchars(number_format((float)$reg['total_horas'], 2, ',', ''));
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td>
                                <a class="btn-link" href="registros.php?edit=<?= (int)$reg['id'] ?>">Editar</a>
                                &nbsp;|&nbsp;
                                <form method="post" class="actions-form"
                                      onsubmit="return confirm('Confirma excluir o registro #<?= (int)$reg['id'] ?>?');">
                                    <input type="hidden" name="action" value="delete_registro">
                                    <input type="hidden" name="id" value="<?= (int)$reg['id'] ?>">
                                    <button type="submit" class="btn-link btn-danger small">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>

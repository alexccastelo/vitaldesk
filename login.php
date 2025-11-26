<?php
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Se já estiver logado, manda para o dashboard
if (!empty($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$mensagemErro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = trim($_POST['senha'] ?? '');

    if ($email === '' || $senha === '') {
        $mensagemErro = 'Informe e-mail e senha.';
    } else {
        $stmt = $pdo->prepare("SELECT id, email, senha_hash FROM usuarios WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($usuario && password_verify($senha, $usuario['senha_hash'])) {
            $_SESSION['usuario_id']    = (int)$usuario['id'];
            $_SESSION['usuario_email'] = $usuario['email'];

            header('Location: index.php');
            exit;
        } else {
            $mensagemErro = 'E-mail ou senha inválidos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Espaço Vital Clínica</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'header.php'; ?>

<div class="container" style="max-width: 420px; margin-top: 24px;">
    <div class="card">
        <h1 style="margin-bottom: 12px;">Acesso ao sistema</h1>

        <?php if ($mensagemErro): ?>
            <div class="msg-alerta">
                <?= htmlspecialchars($mensagemErro) ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required>

            <label for="senha">Senha</label>
            <input type="password" id="senha" name="senha" required>

            <button type="submit">Entrar</button>
        </form>
    </div>

    <p class="info small" style="text-align:center; margin-top: 12px;">
        Espaço Vital Clínica · Controle de Salas · v0.1<br>
        &copy; <?= date('Y'); ?> Espaço Vital Clínica. Todos os direitos reservados.
    </p>
</div>
</body>
</html>

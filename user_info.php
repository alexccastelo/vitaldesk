<?php
// user_info.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['usuario_email'])): ?>
    <div class="card user-info-card">
        <div class="topbar">
            <span><strong>Usuário:</strong>
                <?= htmlspecialchars($_SESSION['usuario_email']) ?>
            </span>
            <form method="post" action="logout.php" class="actions-form">
                <button type="submit">Sair</button>
            </form>
        </div>
    </div>
<?php endif; ?>

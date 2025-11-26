<?php
// header.php
$current = basename($_SERVER['SCRIPT_NAME']);
?>
<header class="site-header">
    <div class="header-inner">
        <div class="logo-area">
            <img src="img/logo.png" alt="Espaço Vital Clínica" class="logo-img">
            <span class="logo-text">Espaço Vital Clínica · Controle de Salas</span>
        </div>
        <nav class="main-nav">
            <a href="index.php" class="<?= $current === 'index.php' ? 'active' : '' ?>">Dashboard</a>
            <a href="checkin.php" class="<?= $current === 'checkin.php' ? 'active' : '' ?>">Check-in</a>
            <a href="registros.php" class="<?= $current === 'registros.php' ? 'active' : '' ?>">Registros</a>
            <a href="usuarios.php" class="<?= $current === 'usuarios.php' ? 'active' : '' ?>">Usuários</a>
        </nav>
    </div>
</header>

<?php
// auth.php
// Verifica se o usuário está logado; se não, redireciona para login.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

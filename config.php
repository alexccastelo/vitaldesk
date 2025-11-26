<?php
// config.php
// Configurações e recursos compartilhados da aplicação

// Fuso horário
date_default_timezone_set('America/Fortaleza');

// Caminho do banco SQLite
$dbFile = __DIR__ . '/clinica_salas.db';

// Conexão PDO com SQLite
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Erro ao conectar ao banco de dados: ' . htmlspecialchars($e->getMessage()));
}

/*
 |---------------------------------------------------------
 | Criação de tabelas (se não existirem)
 |---------------------------------------------------------
*/

// Tabela de registros de uso das salas
$pdo->exec("
CREATE TABLE IF NOT EXISTS registros (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    profissional TEXT NOT NULL,
    sala TEXT NOT NULL,
    data TEXT NOT NULL,            -- YYYY-MM-DD
    hora_checkin TEXT NOT NULL,    -- HH:MM
    hora_checkout TEXT,            -- HH:MM
    total_horas REAL,              -- horas em decimal
    mensagem TEXT                  -- mensagem gerada para WhatsApp
);
");

// Tabela de profissionais
$pdo->exec("
CREATE TABLE IF NOT EXISTS profissionais (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL UNIQUE,
    ativo INTEGER NOT NULL DEFAULT 1
);
");

// Tabela de salas
$pdo->exec("
CREATE TABLE IF NOT EXISTS salas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL UNIQUE,
    ativo INTEGER NOT NULL DEFAULT 1
);
");

// Tabela de usuários (acesso ao admin)
$pdo->exec("
CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    senha_hash TEXT NOT NULL
);
");

/*
 |---------------------------------------------------------
 | Usuário administrador padrão
 |---------------------------------------------------------
 | Criado apenas se ainda não existir nenhum usuário.
 | E-mail: admin@clinica.local
 | Senha:  Clinica@2024!
*/
$checkUsuarios = $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
if ((int)$checkUsuarios === 0) {
    $emailDefault = 'admin@clinica.local';
    $senhaDefault = 'Clinica@2024!';
    $hashDefault  = password_hash($senhaDefault, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha_hash) VALUES (:email, :senha_hash)");
    $stmt->execute([
        ':email'      => $emailDefault,
        ':senha_hash' => $hashDefault
    ]);
}

/*
 |---------------------------------------------------------
 | Funções auxiliares compartilhadas
 |---------------------------------------------------------
*/

// Calcula diferença entre dois horários em horas decimais
function calcularHorasDecimais($data, $horaInicio, $horaFim)
{
    $inicio = new DateTime($data . ' ' . $horaInicio);
    $fim    = new DateTime($data . ' ' . $horaFim);

    // Se o fim for menor que o início, assume que virou o dia
    if ($fim < $inicio) {
        $fim->modify('+1 day');
    }

    $intervalo = $inicio->diff($fim);
    $minutosTotais = ($intervalo->days * 24 * 60) + ($intervalo->h * 60) + $intervalo->i;

    return $minutosTotais / 60;
}

// Valida se uma senha é "forte"
function senhaForte($senha)
{
    if (strlen($senha) < 8) return false;
    if (!preg_match('/[A-Z]/', $senha)) return false;
    if (!preg_match('/[a-z]/', $senha)) return false;
    if (!preg_match('/\d/', $senha))    return false;
    if (!preg_match('/[^a-zA-Z0-9]/', $senha)) return false;

    return true;
}

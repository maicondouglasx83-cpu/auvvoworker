<?php
// backend/logout.php
// Destrói a sessão do usuário com segurança e redireciona para o login.
session_start();

// 1. Limpa todos os dados da sessão
$_SESSION = [];

// 2. Destrói o cookie de sessão no navegador do cliente
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destrói a sessão no servidor
session_destroy();

// 4. Redireciona para o login
header("Location: ../login");
exit;
?>

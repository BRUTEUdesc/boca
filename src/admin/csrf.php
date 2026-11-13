<?php
// Token anti-CSRF por sessao para os paineis BRUTE (scores.php, animeitor.php).
// Uso: echo csrfField() dentro do <form>; csrfValid() antes de executar a acao do POST.
// Requer session_start() (feito em header.php).

function csrfToken()
{
    if (empty($_SESSION['csrftoken']) || !is_string($_SESSION['csrftoken'])) {
        try {
            $_SESSION['csrftoken'] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $_SESSION['csrftoken'] = hash('sha256', uniqid('', true) . mt_rand() . session_id());
        }
    }
    return $_SESSION['csrftoken'];
}

function csrfField()
{
    return '<input type="hidden" name="csrftoken" value="' . htmlspecialchars(csrfToken()) . '" />';
}

function csrfValid()
{
    return isset($_SESSION['csrftoken'], $_POST['csrftoken']) &&
        is_string($_SESSION['csrftoken']) && is_string($_POST['csrftoken']) &&
        $_SESSION['csrftoken'] !== '' &&
        hash_equals($_SESSION['csrftoken'], $_POST['csrftoken']);
}

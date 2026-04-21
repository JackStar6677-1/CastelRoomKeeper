<?php

function castel_mailer_config()
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/mail_config.php';
    if (!file_exists($path)) {
        return null;
    }

    $loaded = require $path;
    $config = is_array($loaded) ? $loaded : null;
    return $config;
}

function castel_mailer_read_response($socket)
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }
    return $response;
}

function castel_mailer_expect($response, array $expectedCodes)
{
    foreach ($expectedCodes as $code) {
        if (strpos($response, (string) $code) === 0) {
            return true;
        }
    }
    return false;
}

function castel_mailer_write($socket, $command)
{
    fwrite($socket, $command . "\r\n");
}

function castel_mailer_send($to, $subject, $body, &$error = null)
{
    $config = castel_mailer_config();
    if (!$config) {
        $error = 'No existe configuración SMTP.';
        return false;
    }

    $host = isset($config['host']) ? (string) $config['host'] : '';
    $port = isset($config['port']) ? (int) $config['port'] : 465;
    $secure = isset($config['secure']) ? (string) $config['secure'] : 'ssl';
    $username = isset($config['username']) ? (string) $config['username'] : '';
    $password = isset($config['password']) ? (string) $config['password'] : '';
    $fromEmail = isset($config['from_email']) ? (string) $config['from_email'] : $username;
    $fromName = isset($config['from_name']) ? (string) $config['from_name'] : 'Colegio Castelgandolfo';
    $replyTo = isset($config['reply_to']) ? (string) $config['reply_to'] : $fromEmail;

    if ($host === '' || $username === '' || $password === '' || $fromEmail === '') {
        $error = 'La configuración SMTP está incompleta.';
        return false;
    }

    $transport = $secure === 'ssl' ? 'ssl://' . $host : $host;
    $socket = @stream_socket_client($transport . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        $error = 'No se pudo conectar al servidor SMTP: ' . $errstr;
        return false;
    }

    stream_set_timeout($socket, 20);

    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(220))) {
        fclose($socket);
        $error = 'Respuesta SMTP inválida al conectar: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, 'EHLO colegiocastelgandolfo.cl');
    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(250))) {
        fclose($socket);
        $error = 'EHLO falló: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, 'AUTH LOGIN');
    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(334))) {
        fclose($socket);
        $error = 'AUTH LOGIN falló: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, base64_encode($username));
    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(334))) {
        fclose($socket);
        $error = 'Usuario SMTP rechazado: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, base64_encode($password));
    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(235))) {
        fclose($socket);
        $error = 'Contraseña SMTP rechazada: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, 'MAIL FROM:<' . $fromEmail . '>');
    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(250))) {
        fclose($socket);
        $error = 'MAIL FROM rechazado: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, 'RCPT TO:<' . $to . '>');
    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(250, 251))) {
        fclose($socket);
        $error = 'RCPT TO rechazado: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, 'DATA');
    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(354))) {
        fclose($socket);
        $error = 'DATA rechazado: ' . trim($response);
        return false;
    }

    $headers = array(
        'Date: ' . date('r'),
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $replyTo,
        'To: ' . $to,
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: Castelgandolfo SMTP Mailer',
    );

    $normalizedBody = preg_replace("/(?<!\r)\n/", "\r\n", $body);
    $normalizedBody = preg_replace('/^\./m', '..', $normalizedBody);
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $normalizedBody . "\r\n.";
    fwrite($socket, $message . "\r\n");

    $response = castel_mailer_read_response($socket);
    if (!castel_mailer_expect($response, array(250))) {
        fclose($socket);
        $error = 'Envío DATA falló: ' . trim($response);
        return false;
    }

    castel_mailer_write($socket, 'QUIT');
    fclose($socket);
    return true;
}

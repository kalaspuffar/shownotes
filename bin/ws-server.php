#!/usr/bin/env php
<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Bootstrap and configuration (tasks 1.1 – 1.4)
// ---------------------------------------------------------------------------

$config = require_once __DIR__ . '/../etc/config.php';

// ext-sockets is required; bail early with a clear error if it's missing
if (!function_exists('socket_create')) {
    fwrite(STDERR, "[ws-server] ERROR: PHP ext-sockets is not available.\n");
    fwrite(STDERR, "            Enable the 'sockets' extension in your php.ini and try again.\n");
    exit(1);
}

$host = $config['ws_host'];
$port = (int) $config['ws_port'];

echo "[ws-server] Cozy News Corner WebSocket Server\n";
echo "[ws-server] Listening on ws://{$host}:{$port}\n";
echo "[ws-server] Press Ctrl+C to stop.\n";

// ---------------------------------------------------------------------------
// SIGINT handling (tasks 2.1 – 2.3)
// ---------------------------------------------------------------------------

$shutdown = false;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () use (&$shutdown) {
        $shutdown = true;
    });
}

// ---------------------------------------------------------------------------
// Server socket setup (tasks 3.1 – 3.5)
// ---------------------------------------------------------------------------

$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($server === false) {
    fwrite(STDERR, "[ws-server] ERROR: socket_create() failed: " . socket_strerror(socket_last_error()) . "\n");
    exit(1);
}

// Allow quick restart without "Address already in use" errors
socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);

if (!socket_bind($server, $host, $port)) {
    fwrite(STDERR, "[ws-server] ERROR: socket_bind() failed: " . socket_strerror(socket_last_error($server)) . "\n");
    exit(1);
}

socket_listen($server, 5);
socket_set_nonblock($server);

// ---------------------------------------------------------------------------
// RFC 6455 handshake (tasks 4.1 – 4.5)
// ---------------------------------------------------------------------------

/**
 * Perform the HTTP→WebSocket upgrade handshake on a freshly accepted socket.
 *
 * Returns true when the 101 response has been sent, false on any error.
 */
function performHandshake($socket): bool
{
    $request = socket_read($socket, 4096);
    if ($request === false || $request === '') {
        return false;
    }

    // Extract the Sec-WebSocket-Key header (required by RFC 6455)
    if (!preg_match('/Sec-WebSocket-Key:\s*(.+)\r\n/i', $request, $matches)) {
        return false;
    }

    $clientKey  = trim($matches[1]);
    $acceptKey  = base64_encode(sha1($clientKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

    $response = "HTTP/1.1 101 Switching Protocols\r\n"
              . "Upgrade: websocket\r\n"
              . "Connection: Upgrade\r\n"
              . "Sec-WebSocket-Accept: {$acceptKey}\r\n"
              . "\r\n";

    socket_write($socket, $response);
    return true;
}

// ---------------------------------------------------------------------------
// Frame parsing (tasks 5.1 – 5.7)
// ---------------------------------------------------------------------------

/**
 * Read one WebSocket frame from a client socket.
 *
 * Returns an array with 'opcode' and 'payload' on success,
 * or null when the client has disconnected.
 */
function parseFrame($socket): ?array
{
    $data = socket_read($socket, 4096);
    if ($data === false || $data === '') {
        return null;
    }

    $bytes  = array_values(unpack('C*', $data));
    $index  = 0;

    $byte0  = $bytes[$index++];
    $byte1  = $bytes[$index++];

    $opcode = $byte0 & 0x0F;
    $masked = ($byte1 & 0x80) !== 0;
    $len    = $byte1 & 0x7F;

    // 16-bit extended length
    if ($len === 126) {
        $len    = unpack('n', chr($bytes[$index]) . chr($bytes[$index + 1]))[1];
        $index += 2;
    // 64-bit extended length
    } elseif ($len === 127) {
        $len    = unpack('J', chr($bytes[$index])   . chr($bytes[$index + 1])
                             . chr($bytes[$index + 2]) . chr($bytes[$index + 3])
                             . chr($bytes[$index + 4]) . chr($bytes[$index + 5])
                             . chr($bytes[$index + 6]) . chr($bytes[$index + 7]))[1];
        $index += 8;
    }

    // 4-byte mask key (browsers always mask frames they send)
    $mask = [];
    if ($masked) {
        $mask   = [$bytes[$index], $bytes[$index + 1], $bytes[$index + 2], $bytes[$index + 3]];
        $index += 4;
    }

    // Read and unmask the payload
    $payload = '';
    for ($i = 0; $i < $len; $i++) {
        $byte     = $bytes[$index + $i] ?? 0;
        $payload .= $masked ? chr($byte ^ $mask[$i % 4]) : chr($byte);
    }

    return ['opcode' => $opcode, 'payload' => $payload];
}

// ---------------------------------------------------------------------------
// Frame encoding (tasks 6.1 – 6.4)
// ---------------------------------------------------------------------------

/**
 * Encode a string as an unmasked RFC 6455 text frame (opcode 0x1, FIN set).
 */
function encodeFrame(string $payload): string
{
    $len = strlen($payload);

    if ($len <= 125) {
        return chr(0x81) . chr($len) . $payload;
    }

    if ($len <= 65535) {
        return chr(0x81) . chr(126) . pack('n', $len) . $payload;
    }

    return chr(0x81) . chr(127) . pack('J', $len) . $payload;
}

// ---------------------------------------------------------------------------
// Message dispatch (tasks 7.1 – 7.5)
// ---------------------------------------------------------------------------

/**
 * Decode a JSON payload and act on it.
 *
 * navigate → broadcast to every client except the sender
 * hello    → log role, reply with ack
 * other    → log and ignore
 */
function handleMessage($sender, string $rawPayload, array &$clients): void
{
    $msg = json_decode($rawPayload, true);

    if (!is_array($msg) || !isset($msg['action'])) {
        echo "[ws-server] Received non-JSON or missing 'action': " . substr($rawPayload, 0, 80) . "\n";
        return;
    }

    switch ($msg['action']) {
        case 'navigate':
            $frame = encodeFrame($rawPayload);
            foreach ($clients as $client) {
                if ($client !== $sender) {
                    socket_write($client, $frame);
                }
            }
            break;

        case 'hello':
            $role = $msg['role'] ?? 'unknown';
            echo "[ws-server] Client said hello as role: {$role}\n";
            $ack = json_encode(['action' => 'ack', 'clients' => count($clients)]);
            socket_write($sender, encodeFrame($ack));
            break;

        default:
            echo "[ws-server] Unknown action '{$msg['action']}' — ignoring.\n";
            break;
    }
}

// ---------------------------------------------------------------------------
// Event loop (tasks 8.1 – 8.10)
// ---------------------------------------------------------------------------

$clients = [];   // fully handshaked WebSocket connections
$pending = [];   // accepted TCP connections awaiting the HTTP upgrade

while (!$shutdown) {
    // Build the read-set: server socket + all tracked sockets
    $read   = array_merge([$server], $pending, $clients);
    $write  = null;
    $except = null;

    // 100 ms timeout so the SIGINT flag is checked at most every 100 ms
    $changed = socket_select($read, $write, $except, 0, 100000);

    if ($changed === false || $changed === 0) {
        continue;
    }

    // New incoming TCP connection
    if (in_array($server, $read, true)) {
        $newSocket = socket_accept($server);
        if ($newSocket !== false) {
            socket_getpeername($newSocket, $peerAddr, $peerPort);
            echo "[ws-server] New connection from {$peerAddr}:{$peerPort}\n";
            $pending[] = $newSocket;
        }
        // Remove server from the list so we don't process it again below
        $read = array_filter($read, fn($s) => $s !== $server);
    }

    // Attempt handshake on pending sockets that have data
    foreach ($pending as $pendingKey => $pendingSocket) {
        if (!in_array($pendingSocket, $read, true)) {
            continue;
        }

        if (performHandshake($pendingSocket)) {
            $clients[] = $pendingSocket;
            echo "[ws-server] Handshake complete — " . count($clients) . " client(s) connected.\n";
        } else {
            socket_close($pendingSocket);
            echo "[ws-server] Handshake failed — connection dropped.\n";
        }

        unset($pending[$pendingKey]);
    }

    // Process frames from established clients
    foreach ($clients as $clientKey => $clientSocket) {
        if (!in_array($clientSocket, $read, true)) {
            continue;
        }

        $frame = parseFrame($clientSocket);

        if ($frame === null) {
            // Client disconnected unexpectedly
            socket_close($clientSocket);
            unset($clients[$clientKey]);
            $clients = array_values($clients);
            echo "[ws-server] Client disconnected. " . count($clients) . " client(s) remaining.\n";
            continue;
        }

        $opcode  = $frame['opcode'];
        $payload = $frame['payload'];

        if ($opcode === 0x1) {
            // Text frame — dispatch to message handler
            handleMessage($clientSocket, $payload, $clients);
        } elseif ($opcode === 0x9) {
            // Ping — respond with pong (opcode 0x8A), same payload, unmasked
            socket_write($clientSocket, chr(0x8A) . chr(strlen($payload)) . $payload);
        } elseif ($opcode === 0x8) {
            // Close frame — close socket and remove from list
            socket_close($clientSocket);
            unset($clients[$clientKey]);
            $clients = array_values($clients);
            echo "[ws-server] Client sent close frame. " . count($clients) . " client(s) remaining.\n";
        }
    }
}

// ---------------------------------------------------------------------------
// Clean shutdown (task 8.10)
// ---------------------------------------------------------------------------

echo "\n[ws-server] Shutting down…\n";

foreach ($clients as $client) {
    socket_close($client);
}

foreach ($pending as $pendingSocket) {
    socket_close($pendingSocket);
}

socket_close($server);

echo "[ws-server] All sockets closed. Goodbye.\n";

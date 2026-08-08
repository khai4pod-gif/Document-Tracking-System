<?php
/**
 * classes/Mailer.php
 * Minimal SMTP client used to deliver transactional mail (login OTPs).
 *
 * The project deliberately ships without Composer, so rather than pull in
 * PHPMailer this speaks just enough SMTP to send a single message:
 * optional STARTTLS/implicit TLS and optional AUTH LOGIN.
 *
 * Configuration lives in config/config.php (MAIL_* constants). The
 * defaults point at Mailpit, which Laragon runs on 127.0.0.1:1025.
 */

declare(strict_types=1);

class Mailer
{
    /** @var resource|null */
    private $socket = null;

    /**
     * Sends a UTF-8 HTML email with a plain-text alternative.
     *
     * @throws RuntimeException if the message could not be handed to the server.
     */
    public function send(string $toAddress, string $toName, string $subject, string $htmlBody, string $textBody): void
    {
        if (!filter_var($toAddress, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The recipient address is not a valid email address.');
        }

        $this->connect();

        try {
            $this->handshake();
            $this->authenticate();

            $this->command('MAIL FROM:<' . MAIL_FROM_ADDRESS . '>', [250]);
            $this->command('RCPT TO:<' . $toAddress . '>', [250, 251]);
            $this->command('DATA', [354]);

            $this->write($this->buildMessage($toAddress, $toName, $subject, $htmlBody, $textBody));
            $this->command('.', [250]);

            // Politeness only — a failure here does not undo a delivered message.
            try {
                $this->command('QUIT', [221]);
            } catch (RuntimeException $e) {
                // ignore
            }
        } finally {
            $this->disconnect();
        }
    }

    // -----------------------------------------------------------------
    // Connection handling
    // -----------------------------------------------------------------

    private function connect(): void
    {
        $encryption = strtolower((string)MAIL_ENCRYPTION);
        $host = ($encryption === 'ssl' ? 'ssl://' : '') . MAIL_HOST;

        $context = stream_context_create([
            'ssl' => ['SNI_enabled' => true],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $host . ':' . MAIL_PORT,
            $errno,
            $errstr,
            (float)MAIL_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new RuntimeException(
                'Could not reach the mail server at ' . MAIL_HOST . ':' . MAIL_PORT
                . ($errstr !== '' ? ' (' . $errstr . ')' : '') . '.'
            );
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, (int)MAIL_TIMEOUT);

        $this->expect([220]);
    }

    private function handshake(): void
    {
        $hostname = $this->clientHostname();
        $this->command('EHLO ' . $hostname, [250]);

        if (strtolower((string)MAIL_ENCRYPTION) === 'tls') {
            $this->command('STARTTLS', [220]);

            $ok = @stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            );
            if ($ok !== true) {
                throw new RuntimeException('Failed to start TLS with the mail server.');
            }

            // The session resets after STARTTLS, so greet again.
            $this->command('EHLO ' . $hostname, [250]);
        }
    }

    private function authenticate(): void
    {
        if (MAIL_USERNAME === '') {
            return;
        }

        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode(MAIL_USERNAME), [334]);
        $this->command(base64_encode(MAIL_PASSWORD), [235]);
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    // -----------------------------------------------------------------
    // Protocol plumbing
    // -----------------------------------------------------------------

    private function command(string $command, array $expectedCodes): string
    {
        $this->write($command . "\r\n");
        return $this->expect($expectedCodes, $command);
    }

    private function write(string $data): void
    {
        if (!is_resource($this->socket) || @fwrite($this->socket, $data) === false) {
            throw new RuntimeException('Lost the connection to the mail server while sending.');
        }
    }

    /**
     * Reads a (possibly multi-line) SMTP reply and asserts its status code.
     */
    private function expect(array $expectedCodes, string $context = 'connection'): string
    {
        $response = '';

        while (is_resource($this->socket)) {
            $line = fgets($this->socket, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                throw new RuntimeException(
                    !empty($meta['timed_out'])
                        ? 'The mail server stopped responding.'
                        : 'The mail server closed the connection unexpectedly.'
                );
            }
            $response .= $line;

            // Continuation lines look like "250-..."; the final one "250 ...".
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException(
                'The mail server rejected "' . $context . '": ' . trim($response)
            );
        }

        return $response;
    }

    // -----------------------------------------------------------------
    // Message construction
    // -----------------------------------------------------------------

    private function buildMessage(string $toAddress, string $toName, string $subject, string $htmlBody, string $textBody): string
    {
        $boundary = 'bnd_' . bin2hex(random_bytes(12));
        $eol = "\r\n";

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->encodeHeaderName(MAIL_FROM_NAME) . ' <' . MAIL_FROM_ADDRESS . '>',
            'To: ' . $this->encodeHeaderName($toName) . ' <' . $toAddress . '>',
            'Subject: ' . $this->encodeHeaderValue($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->clientHostname() . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'Auto-Submitted: auto-generated',
        ];

        $body = '--' . $boundary . $eol
            . 'Content-Type: text/plain; charset=UTF-8' . $eol
            . 'Content-Transfer-Encoding: base64' . $eol . $eol
            . chunk_split(base64_encode($textBody), 76, $eol)
            . '--' . $boundary . $eol
            . 'Content-Type: text/html; charset=UTF-8' . $eol
            . 'Content-Transfer-Encoding: base64' . $eol . $eol
            . chunk_split(base64_encode($htmlBody), 76, $eol)
            . '--' . $boundary . '--' . $eol;

        return implode($eol, $headers) . $eol . $eol . $this->stuffDots($body);
    }

    /** RFC 5321: a leading '.' on a line must be doubled inside DATA. */
    private function stuffDots(string $body): string
    {
        $body = str_replace("\r\n.", "\r\n..", $body);
        return $body[0] === '.' ? '.' . $body : $body;
    }

    private function encodeHeaderValue(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) === 1
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    /** Display names containing specials must be quoted, or encoded if non-ASCII. */
    private function encodeHeaderName(string $name): string
    {
        if (preg_match('/[^\x20-\x7E]/', $name) === 1) {
            return '=?UTF-8?B?' . base64_encode($name) . '?=';
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
    }

    private function clientHostname(): string
    {
        $host = $_SERVER['SERVER_NAME'] ?? php_uname('n');
        // EHLO wants a domain-ish token; fall back to a literal if it looks odd.
        return preg_match('/^[A-Za-z0-9.\-]+$/', (string)$host) === 1 ? (string)$host : 'localhost';
    }
}

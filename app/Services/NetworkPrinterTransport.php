<?php

namespace App\Services;

use App\Models\Printer;
use RuntimeException;

class NetworkPrinterTransport
{
    public function validate(Printer $printer): array
    {
        $config = json_decode($printer->connection_config ?? '', true);
        if (is_array($config)) {
            $ip = $config['ip'] ?? '';
            $port = $config['port'] ?? 9100;
        } else {
            [$ip, $port] = array_pad(explode(':', $printer->connection_config ?? '', 2), 2, 9100);
        }
        if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP) || ! filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])) {
            throw new RuntimeException('Invalid printer IP/port configuration.');
        }

        return [$ip, $port];
    }

    public function send(Printer $printer, string $payload): void
    {
        [$ip, $port] = $this->validate($printer);
        $host = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $socket = @stream_socket_client('tcp://'.$host.':'.$port, $code, $error, 2);
        if ($socket === false) {
            throw new RuntimeException('Printer connection failed: '.$error);
        }
        try {
            stream_set_timeout($socket, 2);
            $offset = 0;
            $deadline = microtime(true) + 3;
            while ($offset < strlen($payload)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Printer write deadline exceeded.');
                }
                $written = @fwrite($socket, substr($payload, $offset));
                if ($written === false || $written === 0 || stream_get_meta_data($socket)['timed_out']) {
                    throw new RuntimeException('Printer write failed or timed out.');
                }
                $offset += $written;
            }
        } finally {
            fclose($socket);
        }
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WhatsappGateway
{
    /**
     * Ambil client berikutnya secara round-robin dari daftar config.
     */
    public static function nextClientId(): string
    {
        $clients = config('whatsapp.client_ids', []);
        if (!is_array($clients) || $clients === []) {
            $fallback = trim((string) config('whatsapp.client_id', 'al_syukro_96240'));
            $clients = [$fallback !== '' ? $fallback : 'al_syukro_96240'];
        }

        $clients = array_values(array_filter(array_map(
            static fn ($id) => substr(trim((string) $id), 0, 20),
            $clients
        )));

        if ($clients === []) {
            $clients = ['al_syukro_96240'];
        }

        if (count($clients) === 1) {
            return $clients[0];
        }

        $index = (int) Cache::increment('whatsapp.roundrobin.index');
        if ($index < 1) {
            $index = 1;
            Cache::forever('whatsapp.roundrobin.index', 1);
        }

        return $clients[($index - 1) % count($clients)];
    }

    public static function enqueue(string $phone, string $message, int|string $logId, ?string $clientId = null): mixed
    {
        $client = substr(trim((string) ($clientId ?: self::nextClientId())), 0, 20);
        if ($client === '') {
            $client = 'al_syukro_96240';
        }

        try {
            $result = DB::connection('mysql_wa')->select(
                'CALL new_whatsapp_queue(:client, :logId, :phone, :message)',
                [
                    'client' => $client,
                    'logId' => $logId,
                    'phone' => $phone,
                    'message' => $message,
                ]
            );
        } catch (Throwable $e) {
            Log::channel('whatsapp')->error('queue enqueue failed', [
                'client' => $client,
                'log_id' => $logId,
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                'Gagal mengantrekan pesan WhatsApp: ' . $e->getMessage(),
                0,
                $e
            );
        }

        Log::channel('whatsapp')->info('queue enqueue', [
            'client' => $client,
            'log_id' => $logId,
            'phone' => $phone,
            'result' => $result,
        ]);

        return $result;
    }
}

<?php

namespace App\Http;

use App\Notifications\Resource;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class MetricsController
{
    private const STATUSES = ['NEW', 'READY', 'QUEUED', 'SENT', 'UNSENT', 'UNDELIVERED', 'EXPIRED', 'DELIVERED', 'ARCHIVED'];

    public function __invoke(): Response
    {
        $sendings = DB::select(<<<'SQL'
            SELECT channels.code AS channel, sendings.status, count(sendings.id) AS total
            FROM channels
            LEFT JOIN sendings ON sendings.channel = channels.code
            GROUP BY GROUPING SETS ((channels.code), (sendings.status))
            ORDER BY channels.code, sendings.status
            SQL);
        $channels = [];
        $statuses = [];
        foreach ($sendings as $row) {
            if ($row->channel !== null) {
                $channels[$row->channel] = $row->total;
            } elseif ($row->status !== null) {
                $statuses[$row->status] = $row->total;
            }
        }
        $messages = Resource::Message->query()->select('status')->selectRaw('count(*) as total')
            ->groupBy('status')->pluck('total', 'status')->all();

        return response(
            $this->gauge('homer_messages_statistics', 'Statistics of messages by statuses', 'Status', $this->statusCounts($messages))
            .$this->gauge('homer_sendings_statistics_status', 'Statistics of sendings by statuses and type of channels', 'Status', $this->statusCounts($statuses))
            .$this->gauge('homer_sendings_statistics_channel', 'Statistics of sendings by statuses and type of channels', 'Channel', $channels),
            200,
            ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'],
        );
    }

    private function statusCounts(array $counts): array
    {
        $result = [];
        foreach (self::STATUSES as $code => $label) {
            $result[$label] = $counts[$code] ?? 0;
        }

        return $result;
    }

    private function gauge(string $name, string $help, string $label, iterable $counts): string
    {
        $output = "# HELP $name $help\n# TYPE $name gauge\n";
        foreach ($counts as $value => $count) {
            $escaped = strtr((string) $value, ["\\" => "\\\\", '"' => '\\"', "\n" => '\\n']);
            $output .= sprintf("%s{%s=\"%s\"} %d\n", $name, $label, $escaped, $count);
        }

        return $output;
    }
}

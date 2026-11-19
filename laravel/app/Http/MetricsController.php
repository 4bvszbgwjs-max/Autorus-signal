<?php

namespace App\Http;

use App\Notifications\Resource;
use Symfony\Component\HttpFoundation\Response;

final class MetricsController
{
    private const STATUSES = ['NEW', 'READY', 'QUEUED', 'SENT', 'UNSENT', 'UNDELIVERED', 'EXPIRED', 'DELIVERED', 'ARCHIVED'];

    public function __invoke(): Response
    {
        $channels = Resource::Channel->query()->leftJoin('sendings', 'sendings.channel', '=', 'channels.code')
            ->select('channels.code')->selectRaw('count(sendings.id) as total')
            ->groupBy('channels.code')->orderBy('channels.code')->pluck('total', 'code');

        return response(
            $this->gauge('homer_messages_statistics', 'Statistics of messages by statuses', 'Status', $this->statusCounts(Resource::Message))
            .$this->gauge('homer_sendings_statistics_status', 'Statistics of sendings by statuses and type of channels', 'Status', $this->statusCounts(Resource::Sending))
            .$this->gauge('homer_sendings_statistics_channel', 'Statistics of sendings by statuses and type of channels', 'Channel', $channels),
            200,
            ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'],
        );
    }

    private function statusCounts(Resource $resource): array
    {
        $counts = $resource->query()->select('status')->selectRaw('count(*) as total')->groupBy('status')->pluck('total', 'status');
        $result = [];
        foreach (self::STATUSES as $code => $label) {
            $result[$label] = $counts->get($code, 0);
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

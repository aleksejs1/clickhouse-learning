<?php

namespace App;

use Symfony\Component\HttpFoundation\Request;

/**
 * Фильтр периода по event_time (query-параметры from/to).
 *
 * Сортировочный ключ таблицы начинается с event_time, поэтому эти условия
 * позволяют ClickHouse отсекать гранулы по первичному индексу — на десятках
 * миллионов строк это главный способ ускорить графики.
 */
final class TimeFilter
{
    private function __construct(
        public readonly ?string $from, // 'Y-m-d H:i:s' или null
        public readonly ?string $to,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            self::parse((string) $request->query->get('from', '')),
            self::parse((string) $request->query->get('to', '')),
        );
    }

    private static function parse(string $value): ?string
    {
        // '2026-07-14T12:30' из <input type="datetime-local">, опционально с секундами
        $value = str_replace('T', ' ', trim($value));
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if (false !== $dt) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    /** @return list<string> условия для WHERE */
    public function conditions(): array
    {
        $where = [];
        if (null !== $this->from) {
            $where[] = 'event_time >= {from:DateTime}';
        }
        if (null !== $this->to) {
            $where[] = 'event_time <= {to:DateTime}';
        }

        return $where;
    }

    /** @return array<string, string> значения для параметров запроса ClickHouse */
    public function params(): array
    {
        return array_filter(['from' => $this->from, 'to' => $this->to]);
    }

    /** @return array<string, string> значения для ссылок и <input type="datetime-local"> */
    public function queryParams(): array
    {
        return array_map(
            static fn (string $v): string => str_replace(' ', 'T', $v),
            $this->params(),
        );
    }
}

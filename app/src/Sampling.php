<?php

namespace App;

use Symfony\Component\HttpFoundation\Request;

/**
 * Сэмплирование (query-параметр sample: 0.1 или 0.01).
 *
 * Таблица создана с SAMPLE BY xxHash32(event_id), поэтому «SAMPLE 0.1» читает
 * ~10% строк по диапазону ключа сэмплирования — быстро на любом объёме, но
 * приблизительно. Счётчики надо домножать обратно на scale(); средние (avg)
 * масштабирования не требуют.
 */
final class Sampling
{
    private const RATES = ['0.1', '0.01'];

    /** @param string $rate '' — сэмплирование выключено */
    private function __construct(public readonly string $rate)
    {
    }

    public static function fromRequest(Request $request): self
    {
        $rate = (string) $request->query->get('sample', '');

        return new self(\in_array($rate, self::RATES, true) ? $rate : '');
    }

    /** SQL-клауза после FROM: '' или 'SAMPLE 0.1' (значение из белого списка) */
    public function sql(): string
    {
        return '' === $this->rate ? '' : 'SAMPLE '.$this->rate;
    }

    /** Во сколько раз домножать счётчики, чтобы получить оценку полных чисел */
    public function scale(): float
    {
        return '' === $this->rate ? 1.0 : 1 / (float) $this->rate;
    }

    public function isActive(): bool
    {
        return '' !== $this->rate;
    }
}

<?php

namespace App\Command;

use App\Service\ClickHouse;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Генерирует тестовые события телеметрии с заложенными аномалиями A1-A4
 * (см. docs/DATA_MODEL.md §2-3).
 */
#[AsCommand('app:generate-events', 'Generate test telemetry events')]
class GenerateEventsCommand extends Command
{
    private const BATCH_SIZE = 10_000;
    private const PERIOD_DAYS = 30;

    private const EVENT_TYPES = [
        'gps_ping' => 60, 'trip_start' => 8, 'trip_end' => 8, 'refuel' => 6,
        'harsh_braking' => 5, 'speeding' => 5, 'idle' => 4, 'engine_fault' => 4,
    ];
    private const VEHICLE_TYPES = ['truck', 'van', 'refrigerated_truck', 'tanker'];
    private const VEHICLE_MAKES = ['volvo', 'scania', 'man', 'mercedes', 'daf'];
    private const REGIONS = ['riga', 'kurzeme', 'latgale', 'vidzeme', 'zemgale'];
    private const ROAD_TYPES = ['highway', 'city', 'rural'];
    private const WEATHER = ['clear' => 55, 'rain' => 25, 'snow' => 12, 'fog' => 8];
    private const HARSH_CNT = [0 => 70, 1 => 20, 2 => 7, 3 => 1.5, 4 => 1, 5 => 0.5];

    public function __construct(private ClickHouse $clickHouse)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('count', InputArgument::REQUIRED, 'Number of events to generate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = (int) $input->getArgument('count');
        if ($count < 1) {
            $output->writeln('<error>count must be a positive integer</error>');

            return Command::INVALID;
        }

        $progress = new ProgressBar($output, $count);
        $progress->start();

        $batch = [];
        for ($i = 0; $i < $count; ++$i) {
            $batch[] = $this->event();
            if (self::BATCH_SIZE === \count($batch)) {
                $this->clickHouse->insertBatch('events', $batch);
                $progress->advance(\count($batch));
                $batch = [];
            }
        }
        $this->clickHouse->insertBatch('events', $batch);
        $progress->advance(\count($batch));

        $progress->finish();
        $output->writeln('');
        $output->writeln(sprintf('<info>Inserted %d events.</info>', $count));

        return Command::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function event(): array
    {
        $vehicleId = sprintf('V-%03d', mt_rand(1, 50));
        $vehicleType = self::VEHICLE_TYPES[crc32($vehicleId) % \count(self::VEHICLE_TYPES)];
        $vehicleMake = self::VEHICLE_MAKES[crc32('make'.$vehicleId) % \count(self::VEHICLE_MAKES)];
        $driverId = sprintf('D-%02d', mt_rand(1, 30));
        $routeId = sprintf('R-%02d', mt_rand(1, 15));
        $region = self::REGIONS[crc32($routeId) % \count(self::REGIONS)];
        $roadType = $this->pick(self::ROAD_TYPES);
        $weather = $this->weighted(self::WEATHER);
        $eventType = $this->weighted(self::EVENT_TYPES);
        $station = sprintf('FS-%02d', mt_rand(1, 10));

        // A2 (часть): D-13 агрессивно водит — у него чаще harsh_braking/speeding
        if ('D-13' === $driverId && 'gps_ping' === $eventType && mt_rand(1, 100) <= 20) {
            $eventType = $this->pick(['harsh_braking', 'speeding']);
        }

        $stopped = \in_array($eventType, ['idle', 'refuel'], true);
        $speed = $stopped ? 0.0 : max(0.0, match ($roadType) {
            'highway' => $this->normal(78, 10),
            'city' => $this->normal(35, 9),
            default => $this->normal(55, 10),
        });
        $consumption = max(5.0, match ($vehicleType) {
            'van' => $this->normal(14, 2),
            'refrigerated_truck' => $this->normal(33, 3),
            default => $this->normal(28, 3),
        });
        $ambientTemp = 'snow' === $weather ? $this->normal(-4, 3) : $this->normal(12, 8);
        $engineTemp = $this->normal(90, 4);
        $rpm = (int) max(600, $this->normal($stopped ? 800 : 1400, $stopped ? 60 : 250));
        $cargo = mt_rand(0, 'van' === $vehicleType ? 1_500 : 24_000);
        $odometer = 50_000 + crc32('odo'.$vehicleId) % 390_000 + mt_rand(0, 10_000);
        $fuelLevel = 'refuel' === $eventType ? mt_rand(850, 1000) / 10 : mt_rand(50, 1000) / 10;
        $tirePressure = $this->normal(8.5, 0.4);
        $tripDuration = 'trip_end' === $eventType ? (int) min(600, max(10, $this->normal(180, 60))) : 0;
        $harshCnt = $this->weighted(self::HARSH_CNT);

        // Аномалии (docs/DATA_MODEL.md §3)
        if ('FS-07' === $station) {                                // A1: плохое топливо
            $consumption *= 1.2;
        }
        if ('D-13' === $driverId) {                                // A2: агрессивный водитель
            $harshCnt += 2;
            $speed *= 1.15;
        }
        if ('R-04' === $routeId) {                                 // A3: разбитая дорога
            $speed *= 0.7;
            $tripDuration = (int) ($tripDuration * 1.5);
        }
        if ('daf' === $vehicleMake && $ambientTemp > 20) {         // A4: слабое охлаждение
            $engineTemp += 8;
        }

        return [
            'event_id' => Uuid::v4()->toRfc4122(),
            'event_time' => date('Y-m-d H:i:s', time() - mt_rand(0, self::PERIOD_DAYS * 86_400)),
            'event_type' => $eventType,
            'vehicle_id' => $vehicleId,
            'vehicle_type' => $vehicleType,
            'vehicle_make' => $vehicleMake,
            'driver_id' => $driverId,
            'route_id' => $routeId,
            'region' => $region,
            'road_type' => $roadType,
            'weather' => $weather,
            'last_fuel_station_id' => $station,
            'speed_kmh' => round($speed, 1),
            'fuel_level_pct' => $fuelLevel,
            'fuel_consumption_l100' => round($consumption, 1),
            'engine_temp_c' => round($engineTemp, 1),
            'engine_rpm' => $rpm,
            'cargo_weight_kg' => $cargo,
            'odometer_km' => $odometer,
            'ambient_temp_c' => round($ambientTemp, 1),
            'tire_pressure_bar' => round($tirePressure, 2),
            'trip_duration_min' => $tripDuration,
            'harsh_events_cnt' => min(255, $harshCnt),
        ];
    }

    private function normal(float $mu, float $sigma): float
    {
        // Бокс-Мюллер
        $u1 = mt_rand(1, mt_getrandmax()) / mt_getrandmax();
        $u2 = mt_rand(1, mt_getrandmax()) / mt_getrandmax();

        return $mu + $sigma * sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }

    private function pick(array $values): mixed
    {
        return $values[array_rand($values)];
    }

    /** @param array<int|string, int|float> $weights значение => вес */
    private function weighted(array $weights): int|string
    {
        $roll = mt_rand(1, 1000) / 1000 * array_sum($weights);
        foreach ($weights as $value => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $value;
            }
        }

        return array_key_last($weights);
    }
}

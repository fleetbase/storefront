<?php

/*
 * The order activity observer queues its work as closures with dispatch(). The test harness has
 * no queue, so its global dispatch() drops closures; this namespaced dispatch(), which the
 * observer resolves first, records them so a test can run them as the queue would.
 */

namespace Fleetbase\Storefront\Observers;

if (!class_exists(OrderActivityDispatchRecorder::class, false)) {
    class OrderActivityDispatchRecorder
    {
        /** @var array<int, \Closure> */
        public static array $jobs = [];

        public static function runAll(): void
        {
            $jobs         = static::$jobs;
            static::$jobs = [];
            foreach ($jobs as $job) {
                $job();
            }
        }
    }
}

if (!function_exists(__NAMESPACE__ . '\dispatch')) {
    function dispatch(mixed $job): object
    {
        if ($job instanceof \Closure) {
            OrderActivityDispatchRecorder::$jobs[] = $job;
        }

        return new class {
            public bool $afterCommit = false;

            public function afterCommit(): static
            {
                $this->afterCommit = true;

                return $this;
            }
        };
    }
}

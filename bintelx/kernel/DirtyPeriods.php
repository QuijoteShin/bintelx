<?php
# bintelx/kernel/DirtyPeriods.php
# Period Invalidation and Recalculation Queue Management
#
# Features:
#   - Track periods needing recalculation after retroactive changes
#   - Cascade propagation (if Jan changes, Feb-Dec may need recalc due to YTD)
#   - Priority queue ordered by date (oldest first)
#   - Duplicate prevention
#   - Reason tracking for audit
#   - Batch processing support
#
# Use Cases:
#   - Retroactive salary increase
#   - AFP/Isapre change backdated
#   - Correction of previous period
#   - New hire with start date in past
#   - Finiquito affecting previous periods
#
# @version 1.0.0

namespace bX;

require_once __DIR__ . '/Math.php';

class DirtyPeriods
{
    public const VERSION = '1.0.0';

    # Period states
    public const STATE_CLEAN = 'CLEAN';
    public const STATE_DIRTY = 'DIRTY';
    public const STATE_QUEUED = 'QUEUED';
    public const STATE_PROCESSING = 'PROCESSING';
    public const STATE_ERROR = 'ERROR';

    # Cascade modes
    public const CASCADE_NONE = 'NONE';           # Only mark specified period
    public const CASCADE_FORWARD = 'FORWARD';     # Mark all periods after (YTD impact)
    public const CASCADE_BACKWARD = 'BACKWARD';   # Mark all periods before
    public const CASCADE_YEAR = 'YEAR';           # Mark all periods in same year
    public const CASCADE_ALL = 'ALL';             # Mark all periods for employee

    # Reason types
    public const REASON_SALARY_CHANGE = 'SALARY_CHANGE';
    public const REASON_AFP_CHANGE = 'AFP_CHANGE';
    public const REASON_HEALTH_CHANGE = 'HEALTH_CHANGE';
    public const REASON_CONTRACT_CHANGE = 'CONTRACT_CHANGE';
    public const REASON_RETROACTIVE = 'RETROACTIVE';
    public const REASON_CORRECTION = 'CORRECTION';
    public const REASON_TERMINATION = 'TERMINATION';
    public const REASON_NEW_HIRE = 'NEW_HIRE';
    public const REASON_MANUAL = 'MANUAL';

    # Instance state
    private array $dirtyPeriods = [];
    private array $processedPeriods = [];
    private $persistCallback = null;  # callable
    private $loadCallback = null;      # callable
    private bool $cascadeEnabled = true;

    public function __construct()
    {
    }

    # ========================================================================
    # CONFIGURATION
    # ========================================================================

    /**
     * Set callback for persisting dirty periods to database
     *
     * @param callable $callback fn(array $period): bool
     */
    public function setPersistCallback(callable $callback): self
    {
        $this->persistCallback = $callback;
        return $this;
    }

    /**
     * Set callback for loading dirty periods from database
     *
     * @param callable $callback fn(int $employeeId, ?string $fromPeriod): array
     */
    public function setLoadCallback(callable $callback): self
    {
        $this->loadCallback = $callback;
        return $this;
    }

    /**
     * Enable/disable cascade propagation
     */
    public function setCascadeEnabled(bool $enabled): self
    {
        $this->cascadeEnabled = $enabled;
        return $this;
    }

    # ========================================================================
    # MARKING PERIODS DIRTY
    # ========================================================================

    /**
     * Mark a period as dirty (needing recalculation)
     *
     * @param int $employeeId Employee ID
     * @param string $period Period (YYYY-MM format)
     * @param string $reason Reason for marking dirty
     * @param string $cascade Cascade mode
     * @param array $metadata Additional metadata
     * @return array List of periods marked dirty
     */
    public function markDirty(
        int $employeeId,
        string $period,
        string $reason = self::REASON_MANUAL,
        string $cascade = self::CASCADE_FORWARD,
        array $metadata = []
    ): array {
        $markedPeriods = [];

        # Get periods to mark based on cascade mode
        $periodsToMark = $this->getPeriodsForCascade($employeeId, $period, $cascade);

        foreach ($periodsToMark as $p) {
            $key = $this->buildKey($employeeId, $p);

            # Skip if already dirty and not updating reason
            if (isset($this->dirtyPeriods[$key]) &&
                $this->dirtyPeriods[$key]['state'] === self::STATE_DIRTY) {
                # Add reason if different
                if (!in_array($reason, $this->dirtyPeriods[$key]['reasons'])) {
                    $this->dirtyPeriods[$key]['reasons'][] = $reason;
                }
                continue;
            }

            $entry = [
                'employee_id' => $employeeId,
                'period' => $p,
                'state' => self::STATE_DIRTY,
                'reasons' => [$reason],
                'cascade_from' => $period,
                'metadata' => $metadata,
                'marked_at' => date('Y-m-d H:i:s'),
                'processed_at' => null,
            ];

            $this->dirtyPeriods[$key] = $entry;
            $markedPeriods[] = $p;

            # Persist if callback set
            if ($this->persistCallback) {
                call_user_func($this->persistCallback, $entry);
            }
        }

        return $markedPeriods;
    }

    /**
     * Mark period dirty due to effective-dated parameter change
     *
     * @param int $employeeId Employee ID
     * @param string $effectiveFrom Effective from date (YYYY-MM-DD)
     * @param string $effectiveTo Effective to date (YYYY-MM-DD)
     * @param string $reason Reason
     * @return array List of periods marked dirty
     */
    public function markDirtyForEffectiveDates(
        int $employeeId,
        string $effectiveFrom,
        string $effectiveTo,
        string $reason = self::REASON_RETROACTIVE
    ): array {
        $markedPeriods = [];

        # Get all periods in range
        $periods = $this->getPeriodsInRange($effectiveFrom, $effectiveTo);

        foreach ($periods as $period) {
            $marked = $this->markDirty($employeeId, $period, $reason, self::CASCADE_NONE);
            $markedPeriods = array_merge($markedPeriods, $marked);
        }

        # If cascade enabled, mark forward from last period
        if ($this->cascadeEnabled && !empty($periods)) {
            $lastPeriod = end($periods);
            $currentPeriod = date('Y-m');
            if ($lastPeriod < $currentPeriod) {
                $forwardPeriods = $this->getPeriodsInRange(
                    $lastPeriod . '-01',
                    $currentPeriod . '-31'
                );
                foreach (array_slice($forwardPeriods, 1) as $period) {
                    $this->markDirty($employeeId, $period, $reason, self::CASCADE_NONE, [
                        'propagated_from' => $lastPeriod,
                    ]);
                    $markedPeriods[] = $period;
                }
            }
        }

        return array_unique($markedPeriods);
    }

    /**
     * Mark dirty for termination (finiquito)
     * Marks current month and potentially previous if termination is mid-month
     */
    public function markDirtyForTermination(
        int $employeeId,
        string $terminationDate,
        array $metadata = []
    ): array {
        $period = substr($terminationDate, 0, 7); # YYYY-MM

        return $this->markDirty(
            $employeeId,
            $period,
            self::REASON_TERMINATION,
            self::CASCADE_NONE,
            array_merge($metadata, [
                'termination_date' => $terminationDate,
            ])
        );
    }

    # ========================================================================
    # QUEUE MANAGEMENT
    # ========================================================================

    /**
     * Get next period to process from queue
     *
     * @param int|null $employeeId Filter by employee (null = any)
     * @return array|null Period entry or null if queue empty
     */
    public function getNext(?int $employeeId = null): ?array
    {
        # Sort by period (oldest first)
        $candidates = array_filter($this->dirtyPeriods, function ($entry) use ($employeeId) {
            if ($entry['state'] !== self::STATE_DIRTY) {
                return false;
            }
            if ($employeeId !== null && $entry['employee_id'] !== $employeeId) {
                return false;
            }
            return true;
        });

        if (empty($candidates)) {
            return null;
        }

        # Sort by period ascending
        usort($candidates, fn($a, $b) => strcmp($a['period'], $b['period']));

        $next = $candidates[0];
        $key = $this->buildKey($next['employee_id'], $next['period']);

        # Mark as processing
        $this->dirtyPeriods[$key]['state'] = self::STATE_QUEUED;

        return $next;
    }

    /**
     * Get all pending periods for an employee
     *
     * @param int $employeeId Employee ID
     * @return array List of period entries
     */
    public function getPending(int $employeeId): array
    {
        $pending = array_filter($this->dirtyPeriods, function ($entry) use ($employeeId) {
            return $entry['employee_id'] === $employeeId &&
                   in_array($entry['state'], [self::STATE_DIRTY, self::STATE_QUEUED]);
        });

        # Sort by period ascending
        usort($pending, fn($a, $b) => strcmp($a['period'], $b['period']));

        return $pending;
    }

    /**
     * Get count of pending recalculations
     */
    public function getPendingCount(?int $employeeId = null): int
    {
        return count(array_filter($this->dirtyPeriods, function ($entry) use ($employeeId) {
            if (!in_array($entry['state'], [self::STATE_DIRTY, self::STATE_QUEUED])) {
                return false;
            }
            if ($employeeId !== null && $entry['employee_id'] !== $employeeId) {
                return false;
            }
            return true;
        }));
    }

    /**
     * Mark period as processing (being calculated)
     */
    public function markProcessing(int $employeeId, string $period): void
    {
        $key = $this->buildKey($employeeId, $period);
        if (isset($this->dirtyPeriods[$key])) {
            $this->dirtyPeriods[$key]['state'] = self::STATE_PROCESSING;
        }
    }

    /**
     * Mark period as clean (successfully recalculated)
     */
    public function markClean(int $employeeId, string $period, array $result = []): void
    {
        $key = $this->buildKey($employeeId, $period);

        if (isset($this->dirtyPeriods[$key])) {
            $entry = $this->dirtyPeriods[$key];
            $entry['state'] = self::STATE_CLEAN;
            $entry['processed_at'] = date('Y-m-d H:i:s');
            $entry['result'] = $result;

            $this->processedPeriods[$key] = $entry;
            unset($this->dirtyPeriods[$key]);

            # Persist if callback set
            if ($this->persistCallback) {
                call_user_func($this->persistCallback, $entry);
            }
        }
    }

    /**
     * Mark period as error (calculation failed)
     */
    public function markError(int $employeeId, string $period, string $error): void
    {
        $key = $this->buildKey($employeeId, $period);

        if (isset($this->dirtyPeriods[$key])) {
            $this->dirtyPeriods[$key]['state'] = self::STATE_ERROR;
            $this->dirtyPeriods[$key]['error'] = $error;
            $this->dirtyPeriods[$key]['error_at'] = date('Y-m-d H:i:s');

            if ($this->persistCallback) {
                call_user_func($this->persistCallback, $this->dirtyPeriods[$key]);
            }
        }
    }

    /**
     * Reset error state to dirty for retry
     */
    public function retryErrors(int $employeeId): int
    {
        $count = 0;
        foreach ($this->dirtyPeriods as $key => &$entry) {
            if ($entry['employee_id'] === $employeeId && $entry['state'] === self::STATE_ERROR) {
                $entry['state'] = self::STATE_DIRTY;
                $entry['retry_count'] = ($entry['retry_count'] ?? 0) + 1;
                unset($entry['error']);
                unset($entry['error_at']);
                $count++;
            }
        }
        return $count;
    }

    # ========================================================================
    # BATCH PROCESSING
    # ========================================================================

    /**
     * Process all pending periods for an employee
     *
     * @param int $employeeId Employee ID
     * @param callable $calculator fn(int $employeeId, string $period): array
     * @return array Results indexed by period
     */
    public function processAll(int $employeeId, callable $calculator): array
    {
        $results = [];

        while ($entry = $this->getNext($employeeId)) {
            $period = $entry['period'];
            $this->markProcessing($employeeId, $period);

            try {
                $result = call_user_func($calculator, $employeeId, $period);
                $this->markClean($employeeId, $period, $result);
                $results[$period] = [
                    'success' => true,
                    'result' => $result,
                ];
            } catch (\Exception $e) {
                $this->markError($employeeId, $period, $e->getMessage());
                $results[$period] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    # ========================================================================
    # HELPERS
    # ========================================================================

    /**
     * Build unique key for period
     */
    private function buildKey(int $employeeId, string $period): string
    {
        return "{$employeeId}:{$period}";
    }

    /**
     * Get periods to mark based on cascade mode
     */
    private function getPeriodsForCascade(int $employeeId, string $period, string $cascade): array
    {
        if (!$this->cascadeEnabled || $cascade === self::CASCADE_NONE) {
            return [$period];
        }

        $year = substr($period, 0, 4);
        $month = (int)substr($period, 5, 2);
        $currentPeriod = date('Y-m');
        $periods = [$period];

        switch ($cascade) {
            case self::CASCADE_FORWARD:
                # All periods from this one to current
                $p = $period;
                while ($p <= $currentPeriod) {
                    if (!in_array($p, $periods)) {
                        $periods[] = $p;
                    }
                    $p = $this->addMonths($p, 1);
                }
                break;

            case self::CASCADE_BACKWARD:
                # All periods from start of year to this one
                for ($m = 1; $m <= $month; $m++) {
                    $p = sprintf('%s-%02d', $year, $m);
                    if (!in_array($p, $periods)) {
                        $periods[] = $p;
                    }
                }
                break;

            case self::CASCADE_YEAR:
                # All periods in the year
                for ($m = 1; $m <= 12; $m++) {
                    $p = sprintf('%s-%02d', $year, $m);
                    if ($p <= $currentPeriod && !in_array($p, $periods)) {
                        $periods[] = $p;
                    }
                }
                break;

            case self::CASCADE_ALL:
                # All periods up to current (would need employee hire date)
                # For now, cascade forward from given period
                return $this->getPeriodsForCascade($employeeId, $period, self::CASCADE_FORWARD);
        }

        sort($periods);
        return $periods;
    }

    /**
     * Get periods in date range
     */
    private function getPeriodsInRange(string $fromDate, string $toDate): array
    {
        $periods = [];
        $from = substr($fromDate, 0, 7);
        $to = substr($toDate, 0, 7);
        $current = $from;

        while ($current <= $to) {
            $periods[] = $current;
            $current = $this->addMonths($current, 1);
        }

        return $periods;
    }

    /**
     * Add months to period
     */
    private function addMonths(string $period, int $months): string
    {
        $date = new \DateTime($period . '-01');
        $date->modify("+{$months} months");
        return $date->format('Y-m');
    }

    /**
     * Check if period is dirty
     */
    public function isDirty(int $employeeId, string $period): bool
    {
        $key = $this->buildKey($employeeId, $period);
        return isset($this->dirtyPeriods[$key]) &&
               in_array($this->dirtyPeriods[$key]['state'], [self::STATE_DIRTY, self::STATE_QUEUED]);
    }

    /**
     * Get state of a period
     */
    public function getState(int $employeeId, string $period): string
    {
        $key = $this->buildKey($employeeId, $period);
        return $this->dirtyPeriods[$key]['state'] ?? self::STATE_CLEAN;
    }

    /**
     * Get all dirty periods (for debugging/admin)
     */
    public function getAllDirty(): array
    {
        return array_values($this->dirtyPeriods);
    }

    /**
     * Clear all (for testing)
     */
    public function clear(): void
    {
        $this->dirtyPeriods = [];
        $this->processedPeriods = [];
    }

    /**
     * Load from database
     */
    public function loadFromDb(int $employeeId, ?string $fromPeriod = null): void
    {
        if ($this->loadCallback) {
            $periods = call_user_func($this->loadCallback, $employeeId, $fromPeriod);
            foreach ($periods as $entry) {
                $key = $this->buildKey($entry['employee_id'], $entry['period']);
                $this->dirtyPeriods[$key] = $entry;
            }
        }
    }
}

# ============================================================================
# RECALC QUEUE SERVICE
# ============================================================================

/**
 * RecalcQueue - Higher-level service for managing recalculation queues
 * Works with DirtyPeriods to process pending recalculations
 */
class RecalcQueue
{
    public const VERSION = '1.0.0';

    # Queue status
    public const STATUS_IDLE = 'IDLE';
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_PAUSED = 'PAUSED';
    public const STATUS_ERROR = 'ERROR';

    private DirtyPeriods $dirtyPeriods;
    private string $status = self::STATUS_IDLE;
    private array $stats = [];
    private $calculator = null;     # callable
    private $beforeProcess = null;  # callable
    private $afterProcess = null;   # callable
    private int $batchSize = 10;
    private int $delayMs = 0;

    public function __construct(?DirtyPeriods $dirtyPeriods = null)
    {
        $this->dirtyPeriods = $dirtyPeriods ?? new DirtyPeriods();
        $this->resetStats();
    }

    /**
     * Get DirtyPeriods instance
     */
    public function getDirtyPeriods(): DirtyPeriods
    {
        return $this->dirtyPeriods;
    }

    /**
     * Set calculator callback
     *
     * @param callable $callback fn(int $employeeId, string $period): array
     */
    public function setCalculator(callable $callback): self
    {
        $this->calculator = $callback;
        return $this;
    }

    /**
     * Set before process hook
     *
     * @param callable $callback fn(int $employeeId, string $period): void
     */
    public function setBeforeProcess(callable $callback): self
    {
        $this->beforeProcess = $callback;
        return $this;
    }

    /**
     * Set after process hook
     *
     * @param callable $callback fn(int $employeeId, string $period, array $result): void
     */
    public function setAfterProcess(callable $callback): self
    {
        $this->afterProcess = $callback;
        return $this;
    }

    /**
     * Set batch size
     */
    public function setBatchSize(int $size): self
    {
        $this->batchSize = max(1, $size);
        return $this;
    }

    /**
     * Set delay between batches in milliseconds
     */
    public function setDelayMs(int $ms): self
    {
        $this->delayMs = max(0, $ms);
        return $this;
    }

    /**
     * Queue a period for recalculation
     */
    public function queue(
        int $employeeId,
        string $period,
        string $reason = DirtyPeriods::REASON_MANUAL,
        string $cascade = DirtyPeriods::CASCADE_FORWARD
    ): array {
        return $this->dirtyPeriods->markDirty($employeeId, $period, $reason, $cascade);
    }

    /**
     * Queue for termination (finiquito)
     */
    public function queueTermination(int $employeeId, string $terminationDate): array
    {
        return $this->dirtyPeriods->markDirtyForTermination($employeeId, $terminationDate);
    }

    /**
     * Process queue for a single employee
     */
    public function processEmployee(int $employeeId): array
    {
        if (!$this->calculator) {
            throw new \RuntimeException('Calculator not set');
        }

        $this->status = self::STATUS_RUNNING;
        $results = [];
        $processed = 0;

        while ($entry = $this->dirtyPeriods->getNext($employeeId)) {
            $period = $entry['period'];

            # Before hook
            if ($this->beforeProcess) {
                call_user_func($this->beforeProcess, $employeeId, $period);
            }

            $this->dirtyPeriods->markProcessing($employeeId, $period);
            $this->stats['processing']++;

            try {
                $result = call_user_func($this->calculator, $employeeId, $period);
                $this->dirtyPeriods->markClean($employeeId, $period, $result);
                $results[$period] = ['success' => true, 'result' => $result];
                $this->stats['succeeded']++;
            } catch (\Exception $e) {
                $this->dirtyPeriods->markError($employeeId, $period, $e->getMessage());
                $results[$period] = ['success' => false, 'error' => $e->getMessage()];
                $this->stats['failed']++;
            }

            # After hook
            if ($this->afterProcess) {
                call_user_func($this->afterProcess, $employeeId, $period, $results[$period]);
            }

            $processed++;
            $this->stats['total_processed']++;

            # Batch limit
            if ($processed >= $this->batchSize) {
                if ($this->delayMs > 0) {
                    # Channel-safe: usleep bloquea el event loop; Coroutine::sleep cede el control
                    if (class_exists('Swoole\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
                        \Swoole\Coroutine::sleep($this->delayMs / 1000);
                    } else {
                        usleep($this->delayMs * 1000);
                    }
                }
                $processed = 0;
            }
        }

        $this->status = self::STATUS_IDLE;
        return $results;
    }

    /**
     * Process all pending across all employees
     */
    public function processAll(): array
    {
        if (!$this->calculator) {
            throw new \RuntimeException('Calculator not set');
        }

        $this->status = self::STATUS_RUNNING;
        $allResults = [];

        while ($entry = $this->dirtyPeriods->getNext()) {
            $employeeId = $entry['employee_id'];
            $period = $entry['period'];

            if (!isset($allResults[$employeeId])) {
                $allResults[$employeeId] = [];
            }

            $this->dirtyPeriods->markProcessing($employeeId, $period);

            try {
                $result = call_user_func($this->calculator, $employeeId, $period);
                $this->dirtyPeriods->markClean($employeeId, $period, $result);
                $allResults[$employeeId][$period] = ['success' => true];
                $this->stats['succeeded']++;
            } catch (\Exception $e) {
                $this->dirtyPeriods->markError($employeeId, $period, $e->getMessage());
                $allResults[$employeeId][$period] = ['success' => false, 'error' => $e->getMessage()];
                $this->stats['failed']++;
            }

            $this->stats['total_processed']++;
        }

        $this->status = self::STATUS_IDLE;
        return $allResults;
    }

    /**
     * Pause queue processing
     */
    public function pause(): void
    {
        $this->status = self::STATUS_PAUSED;
    }

    /**
     * Resume queue processing
     */
    public function resume(): void
    {
        if ($this->status === self::STATUS_PAUSED) {
            $this->status = self::STATUS_IDLE;
        }
    }

    /**
     * Get current status
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Get processing stats
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'pending' => $this->dirtyPeriods->getPendingCount(),
        ]);
    }

    /**
     * Reset stats
     */
    public function resetStats(): void
    {
        $this->stats = [
            'total_processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'processing' => 0,
        ];
    }
}

<?php
# bintelx/kernel/HolidayProvider.php
# Holiday Calendar Provider for Payroll Calculations
# Supports: Brazil DSR, Chile irrenunciables, multi-country
#
# @version 1.0.1 - Fixed I3: isMandatoryHoliday now supports region parameter

namespace bX;

class HolidayProvider
{
    public const VERSION = '1.0.1';

    # Holiday types
    public const TYPE_NATIONAL = 'NATIONAL';
    public const TYPE_REGIONAL = 'REGIONAL';
    public const TYPE_MUNICIPAL = 'MUNICIPAL';
    public const TYPE_OPTIONAL = 'OPTIONAL';

    # Cache namespace (transparente: Swoole\Table o static array via bX\Cache)
    private const CACHE_NS = 'geo:holidays';
    private const CACHE_TTL = 86400; # 24h
    private static bool $useDb = true;

    # Fallback holidays si no hay DB (para tests)
    private static array $fallbackHolidays = [];

    /**
     * Get holidays for a country in a date range
     *
     * @param string $countryCode ISO 3166-1 alpha-2
     * @param string $fromDate Y-m-d
     * @param string $toDate Y-m-d
     * @param string|null $regionCode State/province filter
     * @return array List of holiday records
     */
    public static function getHolidays(
        string $countryCode,
        string $fromDate,
        string $toDate,
        ?string $regionCode = null
    ): array {
        $cacheKey = "{$countryCode}_{$fromDate}_{$toDate}_{$regionCode}";

        return Cache::getOrSet(self::CACHE_NS, $cacheKey, self::CACHE_TTL, function() use ($countryCode, $fromDate, $toDate, $regionCode) {
            if (self::$useDb) {
                try {
                    return self::loadFromDb($countryCode, $fromDate, $toDate, $regionCode);
                } catch (\Exception $e) {
                    return self::loadFromFallback($countryCode, $fromDate, $toDate);
                }
            }
            return self::loadFromFallback($countryCode, $fromDate, $toDate);
        });
    }

    /**
     * Load holidays from database
     */
    private static function loadFromDb(
        string $countryCode,
        string $fromDate,
        string $toDate,
        ?string $regionCode
    ): array {
        $sql = "
            SELECT
                holiday_date,
                holiday_name,
                holiday_type,
                is_mandatory,
                affects_dsr,
                region_code
            FROM pay_holiday
            WHERE country_code = :country
              AND holiday_date >= :from_date
              AND holiday_date <= :to_date
        ";

        $params = [
            ':country' => $countryCode,
            ':from_date' => $fromDate,
            ':to_date' => $toDate,
        ];

        if ($regionCode !== null) {
            $sql .= " AND (region_code IS NULL OR region_code = :region)";
            $params[':region'] = $regionCode;
        }

        $sql .= " ORDER BY holiday_date ASC";

        return CONN::dml($sql, $params);
    }

    /**
     * Load from fallback array (for tests)
     */
    private static function loadFromFallback(
        string $countryCode,
        string $fromDate,
        string $toDate
    ): array {
        $results = [];

        foreach (self::$fallbackHolidays as $holiday) {
            if ($holiday['country_code'] !== $countryCode) {
                continue;
            }
            if ($holiday['holiday_date'] < $fromDate || $holiday['holiday_date'] > $toDate) {
                continue;
            }
            $results[] = $holiday;
        }

        return $results;
    }

    /**
     * Count holidays in a period
     *
     * @param string $countryCode ISO country code
     * @param string $fromDate Start date
     * @param string $toDate End date
     * @param bool $onlyMandatory Only count mandatory holidays
     * @return int Number of holidays
     */
    public static function countHolidays(
        string $countryCode,
        string $fromDate,
        string $toDate,
        bool $onlyMandatory = false
    ): int {
        $holidays = self::getHolidays($countryCode, $fromDate, $toDate);

        if (!$onlyMandatory) {
            return count($holidays);
        }

        return count(array_filter($holidays, fn($h) => ($h['is_mandatory'] ?? 1) == 1));
    }

    /**
     * Count DSR-affecting holidays (Brazil)
     * DSR = Descanso Semanal Remunerado
     *
     * @param string $fromDate Start date
     * @param string $toDate End date
     * @param string|null $regionCode Estado brasileiro
     * @return int Number of holidays that affect DSR
     */
    public static function countDsrHolidays(
        string $fromDate,
        string $toDate,
        ?string $regionCode = null
    ): int {
        $holidays = self::getHolidays('BR', $fromDate, $toDate, $regionCode);

        return count(array_filter($holidays, fn($h) => ($h['affects_dsr'] ?? 1) == 1));
    }

    /**
     * Calculate Brazil DSR (Descanso Semanal Remunerado)
     *
     * DSR = (Comissões + Horas Extra) / Dias Úteis Trabalhados × (Domingos + Feriados)
     *
     * @param string $variableEarnings Sum of commissions, overtime, etc.
     * @param int $workingDays Days worked in the month (excluding Sundays/holidays)
     * @param int $restDays Sundays + holidays in the month
     * @return array ['amount' => DSR value, 'breakdown' => calculation details]
     */
    public static function calculateDsr(
        string $variableEarnings,
        int $workingDays,
        int $restDays
    ): array {
        if ($workingDays <= 0 || Math::comp($variableEarnings, '0') <= 0) {
            return [
                'amount' => '0',
                'variable_earnings' => $variableEarnings,
                'working_days' => $workingDays,
                'rest_days' => $restDays,
                'breakdown' => ['reason' => 'NO_VARIABLE_EARNINGS_OR_NO_WORKING_DAYS']
            ];
        }

        # DSR = Variable / WorkingDays × RestDays
        $dailyAverage = Math::div($variableEarnings, (string)$workingDays, 4);
        $dsr = Math::mul($dailyAverage, (string)$restDays, 2);

        return [
            'amount' => $dsr,
            'variable_earnings' => $variableEarnings,
            'working_days' => $workingDays,
            'rest_days' => $restDays,
            'daily_average' => $dailyAverage,
            'breakdown' => [
                'formula' => "$variableEarnings / $workingDays × $restDays",
                'daily_average' => $dailyAverage,
                'dsr' => $dsr
            ]
        ];
    }

    /**
     * Get working days info for a month (Brazil)
     * Calculates working days, Sundays, and holidays
     *
     * @param int $year Year
     * @param int $month Month
     * @param string|null $regionCode Estado brasileiro
     * @return array ['total_days', 'sundays', 'holidays', 'working_days', 'rest_days']
     */
    public static function getMonthWorkingDays(
        int $year,
        int $month,
        ?string $regionCode = null
    ): array {
        $firstDay = sprintf('%04d-%02d-01', $year, $month);
        $lastDay = date('Y-m-t', strtotime($firstDay));
        $totalDays = (int)date('t', strtotime($firstDay));

        # Count Sundays
        $sundays = 0;
        $current = new \DateTime($firstDay);
        $end = new \DateTime($lastDay);

        while ($current <= $end) {
            if ($current->format('w') == 0) { # Sunday
                $sundays++;
            }
            $current->modify('+1 day');
        }

        # Count holidays (excluding those that fall on Sunday)
        $holidays = self::getHolidays('BR', $firstDay, $lastDay, $regionCode);
        $holidayCount = 0;
        $sundayHolidays = 0;

        foreach ($holidays as $holiday) {
            $dow = date('w', strtotime($holiday['holiday_date']));
            if ($dow == 0) {
                $sundayHolidays++; # Holiday on Sunday (don't double count)
            } else {
                $holidayCount++;
            }
        }

        # Working days = Total - Sundays - Holidays (not on Sunday)
        $workingDays = $totalDays - $sundays - $holidayCount;

        # Rest days = Sundays + Holidays (for DSR)
        $restDays = $sundays + $holidayCount;

        return [
            'year' => $year,
            'month' => $month,
            'total_days' => $totalDays,
            'sundays' => $sundays,
            'holidays' => $holidayCount,
            'sunday_holidays' => $sundayHolidays,
            'working_days' => $workingDays,
            'rest_days' => $restDays
        ];
    }

    /**
     * Check if a date is a holiday
     *
     * @param string $date Y-m-d
     * @param string $countryCode ISO country code
     * @param string|null $regionCode State/province
     * @return array|null Holiday info or null if not a holiday
     */
    public static function isHoliday(
        string $date,
        string $countryCode,
        ?string $regionCode = null
    ): ?array {
        $holidays = self::getHolidays($countryCode, $date, $date, $regionCode);
        return !empty($holidays) ? $holidays[0] : null;
    }

    /**
     * Check if a date is a mandatory/irrenunciable holiday
     *
     * FIX I3: Now supports region parameter for regional mandatory holidays
     *
     * @param string $date Y-m-d
     * @param string $countryCode ISO country code
     * @param string|null $regionCode State/province code
     * @return bool
     */
    public static function isMandatoryHoliday(
        string $date,
        string $countryCode,
        ?string $regionCode = null
    ): bool {
        $holiday = self::isHoliday($date, $countryCode, $regionCode);
        return $holiday !== null && ($holiday['is_mandatory'] ?? 0) == 1;
    }

    # =========================================================================
    # CONFIGURATION
    # =========================================================================

    /**
     * Disable database queries (for testing)
     */
    public static function useMemoryOnly(): void
    {
        self::$useDb = false;
    }

    /**
     * Enable database queries
     */
    public static function useDatabase(): void
    {
        self::$useDb = true;
    }

    /**
     * Add fallback holidays (for testing)
     */
    public static function addFallbackHolidays(array $holidays): void
    {
        foreach ($holidays as $h) {
            self::$fallbackHolidays[] = $h;
        }
    }

    /**
     * Clear all caches
     */
    public static function clearCache(): void
    {
        Cache::flush(self::CACHE_NS);
        Cache::notifyChannel(self::CACHE_NS);
        self::$fallbackHolidays = [];
    }

    /**
     * Create RulesEngine DSL functions
     */
    public static function createDslFunctions(): array
    {
        return [
            'BR_DSR' => function(string $variableEarnings, string $yearMonth, ?string $region = null) {
                $year = (int)substr($yearMonth, 0, 4);
                $month = (int)substr($yearMonth, 5, 2);
                $info = self::getMonthWorkingDays($year, $month, $region);
                $result = self::calculateDsr($variableEarnings, $info['working_days'], $info['rest_days']);
                return $result['amount'];
            },

            'HOLIDAYS_COUNT' => function(string $countryCode, string $fromDate, string $toDate) {
                return (string)self::countHolidays($countryCode, $fromDate, $toDate);
            },

            'WORKING_DAYS' => function(string $countryCode, string $yearMonth) {
                $year = (int)substr($yearMonth, 0, 4);
                $month = (int)substr($yearMonth, 5, 2);
                if ($countryCode === 'BR') {
                    $info = self::getMonthWorkingDays($year, $month);
                    return (string)$info['working_days'];
                }
                # For other countries, simple 30-day commercial base
                return '30';
            },

            'REST_DAYS' => function(string $countryCode, string $yearMonth) {
                if ($countryCode === 'BR') {
                    $year = (int)substr($yearMonth, 0, 4);
                    $month = (int)substr($yearMonth, 5, 2);
                    $info = self::getMonthWorkingDays($year, $month);
                    return (string)$info['rest_days'];
                }
                return '4'; # Default ~4 Sundays
            },
        ];
    }
}

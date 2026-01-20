<?php
# bintelx/kernel/GeoService.php
# Centralized service for geographic and currency configuration
#
# Features:
#   - Country/Region configuration (timezone, locale, phone codes)
#   - Currency management (CLP, UF, UTM, USD, EUR)
#   - Exchange rates with effective dating
#   - Tax rates by country with effective dating
#   - Labor policies by country with effective dating
#   - Multi-tenant support via scope_entity_id
#   - ALCOA+ audit logging
#
# Usage:
#   GeoService::getUFValue('2026-01-20');              // '38123.45'
#   GeoService::clpToUF('1000000', '2026-01-20');      // '26.22'
#   GeoService::getTaxRatesMap('CL');                  // ['VAT19'=>'19.00',...]
#   GeoService::getLaborPolicyValue('CL','workday_hours','2026-05-01'); // '40'
#
# Currency Display Pattern (main_currency):
#   Los valores monetarios se almacenan SIEMPRE en CLP en la base de datos.
#   El campo `main_currency` indica cómo MOSTRAR los valores al usuario.
#
#   Backend: Guarda/calcula todo en CLP
#   Frontend: Si main_currency === 'UF', convierte CLP→UF para mostrar
#
#   Ejemplo en frontend (JS):
#     const ufRate = await api.get('/geo/uf.json');  // { value: 38123.45 }
#     const displayValue = main_currency === 'UF'
#         ? `UF ${(clpAmount / ufRate).toFixed(2)}`
#         : formatCLP(clpAmount);
#
#   Endpoint: GET /api/geo/uf.json → { success: true, value: '38123.45' }
#
# @version 1.0.0

namespace bX;

class GeoService
{
    public const VERSION = '1.0.0';

    # Cache for performance
    private static array $rateCache = [];
    private static array $taxCache = [];
    private static array $policyCache = [];
    private static array $countryCache = [];
    private static array $currencyCache = [];

    # =========================================================================
    # EXCHANGE RATES
    # =========================================================================

    /**
     * Get exchange rate between two currencies for a specific date
     *
     * @param string $base Base currency code (CLP)
     * @param string $target Target currency code (UF)
     * @param string|null $date Effective date (default: today)
     * @param int|null $scopeEntityId Tenant scope (default: Profile scope)
     * @return array|null Rate info or null if not found
     */
    public static function getExchangeRate(
        string $base,
        string $target,
        ?string $date = null,
        ?int $scopeEntityId = null
    ): ?array {
        $date = $date ?? date('Y-m-d');
        $scopeEntityId = $scopeEntityId ?? (Profile::$scope_entity_id ?? null);

        $cacheKey = "{$base}|{$target}|{$date}|{$scopeEntityId}";
        if (isset(self::$rateCache[$cacheKey])) {
            return self::$rateCache[$cacheKey];
        }

        # Try tenant-specific rate first, then global
        $sql = "SELECT * FROM geo_exchange_rates
                WHERE base_currency_code = :base
                  AND target_currency_code = :target
                  AND effective_from <= :date
                  AND effective_to >= :date
                  AND (scope_entity_id = :scope OR scope_entity_id IS NULL)
                ORDER BY scope_entity_id DESC, effective_from DESC
                LIMIT 1";

        $params = [
            ':base' => strtoupper($base),
            ':target' => strtoupper($target),
            ':date' => $date,
            ':scope' => $scopeEntityId
        ];

        $rows = CONN::dml($sql, $params);
        $result = !empty($rows) ? $rows[0] : null;

        self::$rateCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Convert amount from one currency to another
     *
     * @param string $amount Amount to convert
     * @param string $from Source currency code
     * @param string $to Target currency code
     * @param string|null $date Effective date
     * @return array Result with converted amount and metadata
     */
    public static function convert(
        string $amount,
        string $from,
        string $to,
        ?string $date = null
    ): array {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return [
                'success' => true,
                'original' => $amount,
                'converted' => $amount,
                'rate' => '1',
                'from' => $from,
                'to' => $to,
                'date' => $date ?? date('Y-m-d')
            ];
        }

        # Try direct rate (from → to)
        $rateInfo = self::getExchangeRate($from, $to, $date);

        if ($rateInfo) {
            # Direct rate: from * (1 / rate) = to
            # Example: CLP to UF -> CLP / rate = UF
            $converted = Math::div($amount, $rateInfo['rate'], 8);
            return [
                'success' => true,
                'original' => $amount,
                'converted' => $converted,
                'rate' => $rateInfo['rate'],
                'rate_type' => 'direct',
                'from' => $from,
                'to' => $to,
                'date' => $date ?? date('Y-m-d'),
                'source_reference' => $rateInfo['source_reference']
            ];
        }

        # Try inverse rate (to → from)
        $inverseInfo = self::getExchangeRate($to, $from, $date);

        if ($inverseInfo) {
            # Inverse rate: amount * rate = converted
            $converted = Math::mul($amount, $inverseInfo['rate'], 8);
            return [
                'success' => true,
                'original' => $amount,
                'converted' => $converted,
                'rate' => $inverseInfo['rate'],
                'rate_type' => 'inverse',
                'from' => $from,
                'to' => $to,
                'date' => $date ?? date('Y-m-d'),
                'source_reference' => $inverseInfo['source_reference']
            ];
        }

        return [
            'success' => false,
            'error' => 'RATE_NOT_FOUND',
            'message' => "No exchange rate found for {$from} to {$to} on " . ($date ?? date('Y-m-d'))
        ];
    }

    /**
     * Get UF value in CLP for a specific date
     *
     * @param string|null $date Effective date
     * @return string|null UF value in CLP or null if not found
     */
    public static function getUFValue(?string $date = null): ?string
    {
        $rate = self::getExchangeRate('CLP', 'UF', $date);
        return $rate ? $rate['rate'] : null;
    }

    /**
     * Get UTM value in CLP for a specific date
     *
     * @param string|null $date Effective date
     * @return string|null UTM value in CLP or null if not found
     */
    public static function getUTMValue(?string $date = null): ?string
    {
        $rate = self::getExchangeRate('CLP', 'UTM', $date);
        return $rate ? $rate['rate'] : null;
    }

    /**
     * Convert CLP to UF
     *
     * @param string $clpAmount Amount in CLP
     * @param string|null $date Effective date
     * @return string|null Amount in UF or null if rate not found
     */
    public static function clpToUF(string $clpAmount, ?string $date = null): ?string
    {
        $result = self::convert($clpAmount, 'CLP', 'UF', $date);
        return $result['success'] ? Math::round($result['converted'], 2) : null;
    }

    /**
     * Convert UF to CLP
     *
     * @param string $ufAmount Amount in UF
     * @param string|null $date Effective date
     * @return string|null Amount in CLP or null if rate not found
     */
    public static function ufToCLP(string $ufAmount, ?string $date = null): ?string
    {
        $result = self::convert($ufAmount, 'UF', 'CLP', $date);
        return $result['success'] ? Math::round($result['converted'], 0) : null;
    }

    /**
     * Get exchange rates map for multiple currencies (batch - avoids N+1 queries)
     * Useful for Units Calculator where multiple conversions are needed
     *
     * @param array $currencies List of currency codes to fetch rates for
     * @param string $baseCurrency Base currency (default: CLP)
     * @param string|null $date Effective date
     * @return array Map of currency_code => rate (relative to base)
     */
    public static function getExchangeRatesMap(
        array $currencies,
        string $baseCurrency = 'CLP',
        ?string $date = null
    ): array {
        $date = $date ?? date('Y-m-d');
        $scopeEntityId = Profile::$scope_entity_id ?? null;
        $baseCurrency = strtoupper($baseCurrency);

        if (empty($currencies)) {
            return [];
        }

        # Normalize and dedupe
        $currencies = array_unique(array_map('strtoupper', $currencies));

        # Build placeholders for IN clause
        $placeholders = [];
        $params = [':base' => $baseCurrency, ':date' => $date, ':scope' => $scopeEntityId];
        foreach ($currencies as $i => $code) {
            $key = ":curr{$i}";
            $placeholders[] = $key;
            $params[$key] = $code;
        }
        $inClause = implode(',', $placeholders);

        # Fetch all rates in one query (both direct and inverse)
        $sql = "SELECT base_currency_code, target_currency_code, rate, scope_entity_id
                FROM geo_exchange_rates
                WHERE ((base_currency_code = :base AND target_currency_code IN ({$inClause}))
                    OR (target_currency_code = :base AND base_currency_code IN ({$inClause})))
                  AND effective_from <= :date
                  AND effective_to >= :date
                  AND (scope_entity_id = :scope OR scope_entity_id IS NULL)
                ORDER BY scope_entity_id DESC, effective_from DESC";

        $rows = CONN::dml($sql, $params);

        # Build map with priority: tenant-specific > global, direct > inverse
        $map = [$baseCurrency => '1']; # Base currency = 1
        $seen = [];

        foreach ($rows as $row) {
            $base = $row['base_currency_code'];
            $target = $row['target_currency_code'];
            $rate = $row['rate'];
            $isScoped = $row['scope_entity_id'] !== null;

            if ($base === $baseCurrency) {
                # Direct rate: CLP -> UF means 1 UF = rate CLP
                $key = "{$target}|direct";
                if (!isset($seen[$key]) || $isScoped) {
                    $map[$target] = $rate;
                    $seen[$key] = true;
                }
            } else {
                # Inverse rate: UF -> CLP means 1 CLP = 1/rate UF
                $key = "{$base}|inverse";
                if (!isset($seen[$key]) || $isScoped) {
                    # Only set if no direct rate exists
                    if (!isset($map[$base])) {
                        $map[$base] = Math::div('1', $rate, 8);
                    }
                    $seen[$key] = true;
                }
            }
        }

        return $map;
    }

    /**
     * Save exchange rate with ALCOA+ audit
     *
     * @param string $base Base currency code
     * @param string $target Target currency code
     * @param string $rate Rate value
     * @param string $effectiveFrom Effective from date
     * @param string|null $effectiveTo Effective to date (default: 9999-12-31)
     * @param string|null $sourceReference Source reference (SII, BCCH, manual)
     * @param int|null $scopeEntityId Tenant scope (null = global)
     * @return array Result with success status
     */
    public static function saveExchangeRate(
        string $base,
        string $target,
        string $rate,
        string $effectiveFrom,
        ?string $effectiveTo = null,
        ?string $sourceReference = null,
        ?int $scopeEntityId = null
    ): array {
        $profileId = Profile::$profile_id ?? 0;

        # Validate rate
        if (Math::lte($rate, '0')) {
            return ['success' => false, 'error' => 'INVALID_RATE', 'message' => 'Rate must be positive'];
        }

        # Close previous period if exists
        $sqlUpdate = "UPDATE geo_exchange_rates
                      SET effective_to = DATE_SUB(:effective_from, INTERVAL 1 DAY)
                      WHERE base_currency_code = :base
                        AND target_currency_code = :target
                        AND effective_to = '9999-12-31'
                        AND (scope_entity_id = :scope OR (scope_entity_id IS NULL AND :scope IS NULL))";

        CONN::nodml($sqlUpdate, [
            ':base' => strtoupper($base),
            ':target' => strtoupper($target),
            ':effective_from' => $effectiveFrom,
            ':scope' => $scopeEntityId
        ]);

        # Calculate hash for ALCOA+
        $hashData = json_encode([
            'base' => strtoupper($base),
            'target' => strtoupper($target),
            'rate' => $rate,
            'effective_from' => $effectiveFrom,
            'timestamp' => microtime(true)
        ]);
        $rateHash = hash('sha256', $hashData);

        # Insert new rate
        $sqlInsert = "INSERT INTO geo_exchange_rates
                      (base_currency_code, target_currency_code, rate, effective_from, effective_to,
                       source_reference, scope_entity_id, rate_hash, created_by)
                      VALUES (:base, :target, :rate, :effective_from, :effective_to,
                              :source, :scope, :hash, :created_by)";

        $result = CONN::nodml($sqlInsert, [
            ':base' => strtoupper($base),
            ':target' => strtoupper($target),
            ':rate' => $rate,
            ':effective_from' => $effectiveFrom,
            ':effective_to' => $effectiveTo ?? '9999-12-31',
            ':source' => $sourceReference,
            ':scope' => $scopeEntityId,
            ':hash' => $rateHash,
            ':created_by' => $profileId
        ]);

        if (!$result['success']) {
            return ['success' => false, 'error' => 'INSERT_FAILED', 'message' => $result['error']];
        }

        # Audit log
        self::auditLog('geo_exchange_rates', (int)$result['last_id'], 'INSERT', null, [
            'base' => strtoupper($base),
            'target' => strtoupper($target),
            'rate' => $rate,
            'effective_from' => $effectiveFrom
        ]);

        # Clear cache
        self::$rateCache = [];

        return [
            'success' => true,
            'exchange_rate_id' => $result['last_id'],
            'rate_hash' => $rateHash
        ];
    }

    # =========================================================================
    # TAX RATES
    # =========================================================================

    /**
     * Get a specific tax rate
     *
     * @param string $country Country code (CL)
     * @param string $code Tax code (VAT19)
     * @param string|null $date Effective date
     * @return array|null Tax rate info or null
     */
    public static function getTaxRate(
        string $country,
        string $code,
        ?string $date = null
    ): ?array {
        $date = $date ?? date('Y-m-d');
        $scopeEntityId = Profile::$scope_entity_id ?? null;

        $cacheKey = "{$country}|{$code}|{$date}|{$scopeEntityId}";
        if (isset(self::$taxCache[$cacheKey])) {
            return self::$taxCache[$cacheKey];
        }

        $sql = "SELECT * FROM geo_tax_rates
                WHERE country_code = :country
                  AND tax_code = :code
                  AND effective_from <= :date
                  AND effective_to >= :date
                  AND (scope_entity_id = :scope OR scope_entity_id IS NULL)
                ORDER BY scope_entity_id DESC, effective_from DESC
                LIMIT 1";

        $rows = CONN::dml($sql, [
            ':country' => strtoupper($country),
            ':code' => $code,
            ':date' => $date,
            ':scope' => $scopeEntityId
        ]);

        $result = !empty($rows) ? $rows[0] : null;
        self::$taxCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Get tax rates map for a country (code => rate)
     *
     * @param string $country Country code
     * @param string|null $date Effective date
     * @return array Map of tax_code => rate
     */
    public static function getTaxRatesMap(string $country, ?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $scopeEntityId = Profile::$scope_entity_id ?? null;

        $sql = "SELECT tax_code, rate FROM geo_tax_rates
                WHERE country_code = :country
                  AND effective_from <= :date
                  AND effective_to >= :date
                  AND (scope_entity_id = :scope OR scope_entity_id IS NULL)
                ORDER BY scope_entity_id DESC, effective_from DESC";

        $rows = CONN::dml($sql, [
            ':country' => strtoupper($country),
            ':date' => $date,
            ':scope' => $scopeEntityId
        ]);

        $map = [];
        foreach ($rows as $row) {
            if (!isset($map[$row['tax_code']])) {
                $map[$row['tax_code']] = Math::round($row['rate'], 2);
            }
        }

        return $map;
    }

    /**
     * Get default tax rate for a country
     *
     * @param string $country Country code
     * @param string|null $date Effective date
     * @return array|null Default tax rate info
     */
    public static function getDefaultTaxRate(string $country, ?string $date = null): ?array
    {
        $date = $date ?? date('Y-m-d');
        $scopeEntityId = Profile::$scope_entity_id ?? null;

        $sql = "SELECT * FROM geo_tax_rates
                WHERE country_code = :country
                  AND is_default = 1
                  AND effective_from <= :date
                  AND effective_to >= :date
                  AND (scope_entity_id = :scope OR scope_entity_id IS NULL)
                ORDER BY scope_entity_id DESC, effective_from DESC
                LIMIT 1";

        $rows = CONN::dml($sql, [
            ':country' => strtoupper($country),
            ':date' => $date,
            ':scope' => $scopeEntityId
        ]);

        return !empty($rows) ? $rows[0] : null;
    }

    # =========================================================================
    # LABOR POLICIES
    # =========================================================================

    /**
     * Get a labor policy
     *
     * @param string $country Country code
     * @param string $key Policy key (workday_hours, vacation_days)
     * @param string|null $date Effective date
     * @return array|null Policy info or null
     */
    public static function getLaborPolicy(
        string $country,
        string $key,
        ?string $date = null
    ): ?array {
        $date = $date ?? date('Y-m-d');
        $scopeEntityId = Profile::$scope_entity_id ?? null;

        $cacheKey = "{$country}|{$key}|{$date}|{$scopeEntityId}";
        if (isset(self::$policyCache[$cacheKey])) {
            return self::$policyCache[$cacheKey];
        }

        $sql = "SELECT * FROM geo_labor_policies
                WHERE country_code = :country
                  AND policy_key = :key
                  AND effective_from <= :date
                  AND effective_to >= :date
                  AND (scope_entity_id = :scope OR scope_entity_id IS NULL)
                ORDER BY scope_entity_id DESC, effective_from DESC
                LIMIT 1";

        $rows = CONN::dml($sql, [
            ':country' => strtoupper($country),
            ':key' => $key,
            ':date' => $date,
            ':scope' => $scopeEntityId
        ]);

        $result = !empty($rows) ? $rows[0] : null;
        self::$policyCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Get labor policy value (extracted from JSON)
     *
     * @param string $country Country code
     * @param string $key Policy key
     * @param string|null $date Effective date
     * @return string|null Policy value or null
     */
    public static function getLaborPolicyValue(
        string $country,
        string $key,
        ?string $date = null
    ): ?string {
        $policy = self::getLaborPolicy($country, $key, $date);

        if (!$policy) {
            return null;
        }

        $valueJson = is_string($policy['value_json'])
            ? json_decode($policy['value_json'], true)
            : $policy['value_json'];

        return isset($valueJson['value']) ? (string)$valueJson['value'] : null;
    }

    /**
     * Get all labor policies for a country
     *
     * @param string $country Country code
     * @param string|null $date Effective date
     * @return array List of policies
     */
    public static function getAllLaborPolicies(string $country, ?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $scopeEntityId = Profile::$scope_entity_id ?? null;

        $sql = "SELECT * FROM geo_labor_policies
                WHERE country_code = :country
                  AND effective_from <= :date
                  AND effective_to >= :date
                  AND (scope_entity_id = :scope OR scope_entity_id IS NULL)
                ORDER BY policy_key, scope_entity_id DESC, effective_from DESC";

        $rows = CONN::dml($sql, [
            ':country' => strtoupper($country),
            ':date' => $date,
            ':scope' => $scopeEntityId
        ]);

        # Dedupe by policy_key (first = most specific)
        $policies = [];
        foreach ($rows as $row) {
            if (!isset($policies[$row['policy_key']])) {
                $policies[$row['policy_key']] = $row;
            }
        }

        return array_values($policies);
    }

    # =========================================================================
    # COUNTRIES & CURRENCIES
    # =========================================================================

    /**
     * Get country by code
     *
     * @param string $code Country code (CL)
     * @return array|null Country info or null
     */
    public static function getCountry(string $code): ?array
    {
        $code = strtoupper($code);

        if (isset(self::$countryCache[$code])) {
            return self::$countryCache[$code];
        }

        $sql = "SELECT * FROM geo_countries WHERE country_code = :code AND is_active = 1";
        $rows = CONN::dml($sql, [':code' => $code]);

        $result = !empty($rows) ? $rows[0] : null;
        self::$countryCache[$code] = $result;
        return $result;
    }

    /**
     * List all active countries
     *
     * @return array List of countries
     */
    public static function listCountries(): array
    {
        $sql = "SELECT * FROM geo_countries WHERE is_active = 1 ORDER BY name_en";
        return CONN::dml($sql, []);
    }

    /**
     * Get currency by code
     *
     * @param string $code Currency code (CLP, UF)
     * @return array|null Currency info or null
     */
    public static function getCurrency(string $code): ?array
    {
        $code = strtoupper($code);

        if (isset(self::$currencyCache[$code])) {
            return self::$currencyCache[$code];
        }

        $sql = "SELECT * FROM geo_currencies WHERE currency_code = :code AND is_active = 1";
        $rows = CONN::dml($sql, [':code' => $code]);

        $result = !empty($rows) ? $rows[0] : null;
        self::$currencyCache[$code] = $result;
        return $result;
    }

    /**
     * List currencies (optionally filtered by country)
     *
     * @param string|null $country Country code filter
     * @return array List of currencies
     */
    public static function listCurrencies(?string $country = null): array
    {
        $sql = "SELECT * FROM geo_currencies WHERE is_active = 1";
        $params = [];

        if ($country !== null) {
            $sql .= " AND (country_code = :country OR country_code IS NULL)";
            $params[':country'] = strtoupper($country);
        }

        $sql .= " ORDER BY currency_code";
        return CONN::dml($sql, $params);
    }

    # =========================================================================
    # REGIONS & COMMUNES
    # =========================================================================

    /**
     * Get regions for a country
     *
     * @param string $country Country code
     * @return array List of regions
     */
    public static function getRegions(string $country): array
    {
        $sql = "SELECT * FROM geo_regions
                WHERE country_code = :country AND is_active = 1
                ORDER BY region_ordinal, region_name";
        return CONN::dml($sql, [':country' => strtoupper($country)]);
    }

    /**
     * Get communes for a region
     *
     * @param int $regionId Region ID
     * @return array List of communes
     */
    public static function getCommunes(int $regionId): array
    {
        $sql = "SELECT * FROM geo_communes
                WHERE region_id = :region_id AND is_active = 1
                ORDER BY commune_name";
        return CONN::dml($sql, [':region_id' => $regionId]);
    }

    /**
     * Get commune by code
     *
     * @param string $country Country code
     * @param string $code Commune code
     * @return array|null Commune info or null
     */
    public static function getCommuneByCode(string $country, string $code): ?array
    {
        $sql = "SELECT c.*, r.region_name, r.region_code
                FROM geo_communes c
                JOIN geo_regions r ON c.region_id = r.region_id
                WHERE c.country_code = :country AND c.commune_code = :code AND c.is_active = 1";

        $rows = CONN::dml($sql, [
            ':country' => strtoupper($country),
            ':code' => $code
        ]);

        return !empty($rows) ? $rows[0] : null;
    }

    # =========================================================================
    # FORMATTING HELPERS
    # =========================================================================

    /**
     * Format currency amount according to currency rules
     *
     * @param string $amount Amount to format
     * @param string $currencyCode Currency code
     * @param bool $includeSymbol Include currency symbol
     * @return string Formatted amount
     */
    public static function formatCurrency(
        string $amount,
        string $currencyCode,
        bool $includeSymbol = true
    ): string {
        $currency = self::getCurrency($currencyCode);
        $decimals = $currency ? (int)$currency['decimal_digits'] : 2;
        $symbol = $currency ? $currency['currency_symbol'] : '';

        $formatted = Math::format($amount, $decimals, ',', '.');

        if ($includeSymbol && $symbol) {
            return "{$symbol} {$formatted}";
        }

        return $formatted;
    }

    # =========================================================================
    # AUDIT LOG
    # =========================================================================

    /**
     * Record audit log entry
     *
     * @param string $tableName Table name
     * @param int $recordId Record ID
     * @param string $action Action (INSERT, UPDATE, DELETE)
     * @param array|null $oldValue Old value
     * @param array|null $newValue New value
     */
    private static function auditLog(
        string $tableName,
        int $recordId,
        string $action,
        ?array $oldValue,
        ?array $newValue
    ): void {
        $sql = "INSERT INTO geo_audit_log
                (table_name, record_id, action, old_value, new_value, profile_id, ip_address, user_agent)
                VALUES (:table, :record, :action, :old, :new, :profile, :ip, :ua)";

        CONN::nodml($sql, [
            ':table' => $tableName,
            ':record' => $recordId,
            ':action' => $action,
            ':old' => $oldValue ? json_encode($oldValue) : null,
            ':new' => $newValue ? json_encode($newValue) : null,
            ':profile' => Profile::$profile_id ?? 0,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null
        ]);
    }

    /**
     * Clear all caches
     */
    public static function clearCache(): void
    {
        self::$rateCache = [];
        self::$taxCache = [];
        self::$policyCache = [];
        self::$countryCache = [];
        self::$currencyCache = [];
    }
}

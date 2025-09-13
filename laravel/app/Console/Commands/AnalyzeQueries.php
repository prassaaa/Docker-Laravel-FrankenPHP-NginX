<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;

class AnalyzeQueries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:analyze-queries 
                            {--table= : Analyze specific table}
                            {--missing-indexes : Find missing indexes}
                            {--slow-queries : Analyze slow queries}
                            {--unused-indexes : Find unused indexes}
                            {--duplicates : Find duplicate indexes}
                            {--stats : Show table statistics}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze database queries and suggest optimizations';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Database Query Analyzer');
        $this->info('=======================');
        
        $table = $this->option('table');
        
        if ($this->option('missing-indexes')) {
            $this->analyzeMissingIndexes($table);
        }
        
        if ($this->option('slow-queries')) {
            $this->analyzeSlowQueries();
        }
        
        if ($this->option('unused-indexes')) {
            $this->analyzeUnusedIndexes($table);
        }
        
        if ($this->option('duplicates')) {
            $this->analyzeDuplicateIndexes($table);
        }
        
        if ($this->option('stats') || !$this->hasAnyOption()) {
            $this->showTableStatistics($table);
        }
        
        return 0;
    }

    /**
     * Analyze missing indexes.
     *
     * @param string|null $table
     */
    protected function analyzeMissingIndexes(?string $table = null)
    {
        $this->info("\nAnalyzing Missing Indexes...");
        
        if (config('database.default') === 'mysql') {
            $this->analyzeMissingIndexesMySQL($table);
        } elseif (config('database.default') === 'pgsql') {
            $this->analyzeMissingIndexesPostgreSQL($table);
        }
    }

    /**
     * Analyze missing indexes for MySQL.
     *
     * @param string|null $table
     */
    protected function analyzeMissingIndexesMySQL(?string $table = null)
    {
        // Check for foreign keys without indexes
        $database = config('database.connections.mysql.database');
        
        $query = "
            SELECT 
                kcu.TABLE_NAME,
                kcu.COLUMN_NAME,
                kcu.CONSTRAINT_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE kcu
            WHERE kcu.TABLE_SCHEMA = ?
            AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 
                FROM information_schema.STATISTICS s
                WHERE s.TABLE_SCHEMA = kcu.TABLE_SCHEMA
                AND s.TABLE_NAME = kcu.TABLE_NAME
                AND s.COLUMN_NAME = kcu.COLUMN_NAME
            )
        ";
        
        $params = [$database];
        if ($table) {
            $query .= " AND kcu.TABLE_NAME = ?";
            $params[] = $table;
        }
        
        $missingIndexes = DB::select($query, $params);
        
        if (empty($missingIndexes)) {
            $this->info("✓ No missing indexes on foreign keys found.");
        } else {
            $this->warn("Missing indexes on foreign keys:");
            $this->table(
                ['Table', 'Column', 'Constraint', 'References'],
                array_map(function ($idx) {
                    return [
                        $idx->TABLE_NAME,
                        $idx->COLUMN_NAME,
                        $idx->CONSTRAINT_NAME,
                        $idx->REFERENCED_TABLE_NAME . '.' . $idx->REFERENCED_COLUMN_NAME
                    ];
                }, $missingIndexes)
            );
        }
    }

    /**
     * Analyze missing indexes for PostgreSQL.
     *
     * @param string|null $table
     */
    protected function analyzeMissingIndexesPostgreSQL(?string $table = null)
    {
        // Check for foreign keys without indexes
        $query = "
            SELECT
                tc.table_name,
                kcu.column_name,
                tc.constraint_name,
                ccu.table_name AS foreign_table_name,
                ccu.column_name AS foreign_column_name
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage AS ccu
                ON ccu.constraint_name = tc.constraint_name
                AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
            AND tc.table_schema = 'public'
            AND NOT EXISTS (
                SELECT 1
                FROM pg_indexes
                WHERE schemaname = 'public'
                AND tablename = tc.table_name
                AND indexdef LIKE '%' || kcu.column_name || '%'
            )
        ";
        
        if ($table) {
            $query .= " AND tc.table_name = '{$table}'";
        }
        
        $missingIndexes = DB::select($query);
        
        if (empty($missingIndexes)) {
            $this->info("✓ No missing indexes on foreign keys found.");
        } else {
            $this->warn("Missing indexes on foreign keys:");
            $this->table(
                ['Table', 'Column', 'Constraint', 'References'],
                array_map(function ($idx) {
                    return [
                        $idx->table_name,
                        $idx->column_name,
                        $idx->constraint_name,
                        $idx->foreign_table_name . '.' . $idx->foreign_column_name
                    ];
                }, $missingIndexes)
            );
        }
    }

    /**
     * Analyze slow queries.
     */
    protected function analyzeSlowQueries()
    {
        $this->info("\nAnalyzing Slow Queries...");
        
        if (config('database.default') === 'mysql') {
            $this->analyzeSlowQueriesMySQL();
        } elseif (config('database.default') === 'pgsql') {
            $this->analyzeSlowQueriesPostgreSQL();
        }
    }

    /**
     * Analyze slow queries for MySQL.
     */
    protected function analyzeSlowQueriesMySQL()
    {
        // Enable slow query log temporarily
        DB::statement("SET GLOBAL slow_query_log = 'ON'");
        DB::statement("SET GLOBAL long_query_time = 1");
        
        // Get recent slow queries from performance schema
        $slowQueries = DB::select("
            SELECT 
                DIGEST_TEXT as query,
                COUNT_STAR as exec_count,
                AVG_TIMER_WAIT/1000000000 as avg_time_ms,
                SUM_TIMER_WAIT/1000000000 as total_time_ms,
                FIRST_SEEN,
                LAST_SEEN
            FROM performance_schema.events_statements_summary_by_digest
            WHERE DIGEST_TEXT IS NOT NULL
            AND AVG_TIMER_WAIT/1000000000 > 1000
            ORDER BY AVG_TIMER_WAIT DESC
            LIMIT 10
        ");
        
        if (empty($slowQueries)) {
            $this->info("✓ No slow queries found.");
        } else {
            $this->warn("Top 10 Slow Queries:");
            $this->table(
                ['Query', 'Exec Count', 'Avg Time (ms)', 'Total Time (ms)', 'First Seen', 'Last Seen'],
                array_map(function ($query) {
                    return [
                        substr($query->query, 0, 50) . '...',
                        $query->exec_count,
                        round($query->avg_time_ms, 2),
                        round($query->total_time_ms, 2),
                        $query->FIRST_SEEN,
                        $query->LAST_SEEN
                    ];
                }, $slowQueries)
            );
        }
    }

    /**
     * Analyze slow queries for PostgreSQL.
     */
    protected function analyzeSlowQueriesPostgreSQL()
    {
        // Ensure pg_stat_statements extension is available
        $extensions = DB::select("SELECT * FROM pg_extension WHERE extname = 'pg_stat_statements'");
        
        if (empty($extensions)) {
            $this->warn("pg_stat_statements extension not installed. Cannot analyze slow queries.");
            return;
        }
        
        $slowQueries = DB::select("
            SELECT 
                query,
                calls,
                mean_exec_time as avg_time_ms,
                total_exec_time as total_time_ms,
                min_exec_time as min_time_ms,
                max_exec_time as max_time_ms
            FROM pg_stat_statements
            WHERE mean_exec_time > 1000
            ORDER BY mean_exec_time DESC
            LIMIT 10
        ");
        
        if (empty($slowQueries)) {
            $this->info("✓ No slow queries found.");
        } else {
            $this->warn("Top 10 Slow Queries:");
            $this->table(
                ['Query', 'Calls', 'Avg (ms)', 'Total (ms)', 'Min (ms)', 'Max (ms)'],
                array_map(function ($query) {
                    return [
                        substr($query->query, 0, 50) . '...',
                        $query->calls,
                        round($query->avg_time_ms, 2),
                        round($query->total_time_ms, 2),
                        round($query->min_time_ms, 2),
                        round($query->max_time_ms, 2)
                    ];
                }, $slowQueries)
            );
        }
    }

    /**
     * Analyze unused indexes.
     *
     * @param string|null $table
     */
    protected function analyzeUnusedIndexes(?string $table = null)
    {
        $this->info("\nAnalyzing Unused Indexes...");
        
        if (config('database.default') === 'mysql') {
            $this->analyzeUnusedIndexesMySQL($table);
        } elseif (config('database.default') === 'pgsql') {
            $this->analyzeUnusedIndexesPostgreSQL($table);
        }
    }

    /**
     * Analyze unused indexes for MySQL.
     *
     * @param string|null $table
     */
    protected function analyzeUnusedIndexesMySQL(?string $table = null)
    {
        $database = config('database.connections.mysql.database');
        
        $query = "
            SELECT 
                t.TABLE_NAME,
                s.INDEX_NAME,
                s.COLUMN_NAME,
                t.TABLE_ROWS
            FROM information_schema.STATISTICS s
            JOIN information_schema.TABLES t 
                ON s.TABLE_SCHEMA = t.TABLE_SCHEMA 
                AND s.TABLE_NAME = t.TABLE_NAME
            WHERE s.TABLE_SCHEMA = ?
            AND s.INDEX_NAME != 'PRIMARY'
            AND s.NON_UNIQUE = 1
        ";
        
        $params = [$database];
        if ($table) {
            $query .= " AND s.TABLE_NAME = ?";
            $params[] = $table;
        }
        
        $indexes = DB::select($query, $params);
        
        if (empty($indexes)) {
            $this->info("✓ No potentially unused indexes found.");
        } else {
            $this->warn("Potentially unused indexes (verify before removing):");
            $this->table(
                ['Table', 'Index', 'Column', 'Table Rows'],
                array_map(function ($idx) {
                    return [
                        $idx->TABLE_NAME,
                        $idx->INDEX_NAME,
                        $idx->COLUMN_NAME,
                        number_format($idx->TABLE_ROWS)
                    ];
                }, $indexes)
            );
        }
    }

    /**
     * Analyze unused indexes for PostgreSQL.
     *
     * @param string|null $table
     */
    protected function analyzeUnusedIndexesPostgreSQL(?string $table = null)
    {
        $query = "
            SELECT 
                schemaname,
                tablename,
                indexname,
                idx_scan,
                idx_tup_read,
                idx_tup_fetch,
                pg_size_pretty(pg_relation_size(indexrelid)) as index_size
            FROM pg_stat_user_indexes
            WHERE idx_scan = 0
            AND schemaname = 'public'
        ";
        
        if ($table) {
            $query .= " AND tablename = '{$table}'";
        }
        
        $unusedIndexes = DB::select($query);
        
        if (empty($unusedIndexes)) {
            $this->info("✓ No unused indexes found.");
        } else {
            $this->warn("Unused indexes:");
            $this->table(
                ['Schema', 'Table', 'Index', 'Scans', 'Size'],
                array_map(function ($idx) {
                    return [
                        $idx->schemaname,
                        $idx->tablename,
                        $idx->indexname,
                        $idx->idx_scan,
                        $idx->index_size
                    ];
                }, $unusedIndexes)
            );
        }
    }

    /**
     * Analyze duplicate indexes.
     *
     * @param string|null $table
     */
    protected function analyzeDuplicateIndexes(?string $table = null)
    {
        $this->info("\nAnalyzing Duplicate Indexes...");
        
        if (config('database.default') === 'mysql') {
            $this->analyzeDuplicateIndexesMySQL($table);
        } elseif (config('database.default') === 'pgsql') {
            $this->analyzeDuplicateIndexesPostgreSQL($table);
        }
    }

    /**
     * Analyze duplicate indexes for MySQL.
     *
     * @param string|null $table
     */
    protected function analyzeDuplicateIndexesMySQL(?string $table = null)
    {
        $database = config('database.connections.mysql.database');
        
        $query = "
            SELECT 
                s1.TABLE_NAME,
                s1.INDEX_NAME as INDEX1,
                s2.INDEX_NAME as INDEX2,
                GROUP_CONCAT(s1.COLUMN_NAME ORDER BY s1.SEQ_IN_INDEX) as COLUMNS
            FROM information_schema.STATISTICS s1
            JOIN information_schema.STATISTICS s2
                ON s1.TABLE_SCHEMA = s2.TABLE_SCHEMA
                AND s1.TABLE_NAME = s2.TABLE_NAME
                AND s1.COLUMN_NAME = s2.COLUMN_NAME
                AND s1.SEQ_IN_INDEX = s2.SEQ_IN_INDEX
                AND s1.INDEX_NAME != s2.INDEX_NAME
            WHERE s1.TABLE_SCHEMA = ?
            AND s1.INDEX_NAME < s2.INDEX_NAME
        ";
        
        $params = [$database];
        if ($table) {
            $query .= " AND s1.TABLE_NAME = ?";
            $params[] = $table;
        }
        
        $query .= " GROUP BY s1.TABLE_NAME, s1.INDEX_NAME, s2.INDEX_NAME";
        
        $duplicates = DB::select($query, $params);
        
        if (empty($duplicates)) {
            $this->info("✓ No duplicate indexes found.");
        } else {
            $this->warn("Duplicate indexes:");
            $this->table(
                ['Table', 'Index 1', 'Index 2', 'Columns'],
                array_map(function ($dup) {
                    return [
                        $dup->TABLE_NAME,
                        $dup->INDEX1,
                        $dup->INDEX2,
                        $dup->COLUMNS
                    ];
                }, $duplicates)
            );
        }
    }

    /**
     * Analyze duplicate indexes for PostgreSQL.
     *
     * @param string|null $table
     */
    protected function analyzeDuplicateIndexesPostgreSQL(?string $table = null)
    {
        $query = "
            WITH index_info AS (
                SELECT
                    n.nspname AS schema_name,
                    t.relname AS table_name,
                    i.relname AS index_name,
                    array_agg(a.attname ORDER BY a.attnum) AS column_names
                FROM pg_index idx
                JOIN pg_class i ON i.oid = idx.indexrelid
                JOIN pg_class t ON t.oid = idx.indrelid
                JOIN pg_namespace n ON n.oid = t.relnamespace
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(idx.indkey)
                WHERE n.nspname = 'public'
                GROUP BY n.nspname, t.relname, i.relname
            )
            SELECT 
                a.table_name,
                a.index_name AS index1,
                b.index_name AS index2,
                array_to_string(a.column_names, ', ') AS columns
            FROM index_info a
            JOIN index_info b ON a.table_name = b.table_name
                AND a.column_names = b.column_names
                AND a.index_name < b.index_name
        ";
        
        if ($table) {
            $query .= " WHERE a.table_name = '{$table}'";
        }
        
        $duplicates = DB::select($query);
        
        if (empty($duplicates)) {
            $this->info("✓ No duplicate indexes found.");
        } else {
            $this->warn("Duplicate indexes:");
            $this->table(
                ['Table', 'Index 1', 'Index 2', 'Columns'],
                array_map(function ($dup) {
                    return [
                        $dup->table_name,
                        $dup->index1,
                        $dup->index2,
                        $dup->columns
                    ];
                }, $duplicates)
            );
        }
    }

    /**
     * Show table statistics.
     *
     * @param string|null $table
     */
    protected function showTableStatistics(?string $table = null)
    {
        $this->info("\nTable Statistics:");
        
        if (config('database.default') === 'mysql') {
            $this->showTableStatisticsMySQL($table);
        } elseif (config('database.default') === 'pgsql') {
            $this->showTableStatisticsPostgreSQL($table);
        }
    }

    /**
     * Show table statistics for MySQL.
     *
     * @param string|null $table
     */
    protected function showTableStatisticsMySQL(?string $table = null)
    {
        $database = config('database.connections.mysql.database');
        
        $query = "
            SELECT 
                TABLE_NAME,
                TABLE_ROWS,
                ROUND(DATA_LENGTH / 1024 / 1024, 2) AS data_size_mb,
                ROUND(INDEX_LENGTH / 1024 / 1024, 2) AS index_size_mb,
                ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS total_size_mb,
                TABLE_COLLATION,
                ENGINE
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
            AND TABLE_TYPE = 'BASE TABLE'
        ";
        
        $params = [$database];
        if ($table) {
            $query .= " AND TABLE_NAME = ?";
            $params[] = $table;
        }
        
        $query .= " ORDER BY total_size_mb DESC";
        
        $stats = DB::select($query, $params);
        
        $this->table(
            ['Table', 'Rows', 'Data (MB)', 'Index (MB)', 'Total (MB)', 'Engine', 'Collation'],
            array_map(function ($stat) {
                return [
                    $stat->TABLE_NAME,
                    number_format($stat->TABLE_ROWS),
                    $stat->data_size_mb,
                    $stat->index_size_mb,
                    $stat->total_size_mb,
                    $stat->ENGINE,
                    $stat->TABLE_COLLATION
                ];
            }, $stats)
        );
    }

    /**
     * Show table statistics for PostgreSQL.
     *
     * @param string|null $table
     */
    protected function showTableStatisticsPostgreSQL(?string $table = null)
    {
        $query = "
            SELECT 
                schemaname,
                tablename,
                pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) AS total_size,
                pg_size_pretty(pg_relation_size(schemaname||'.'||tablename)) AS table_size,
                pg_size_pretty(pg_indexes_size(schemaname||'.'||tablename)) AS indexes_size,
                n_live_tup AS row_count,
                n_dead_tup AS dead_rows,
                last_vacuum,
                last_autovacuum
            FROM pg_stat_user_tables
            WHERE schemaname = 'public'
        ";
        
        if ($table) {
            $query .= " AND tablename = '{$table}'";
        }
        
        $query .= " ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC";
        
        $stats = DB::select($query);
        
        $this->table(
            ['Table', 'Total Size', 'Table Size', 'Index Size', 'Live Rows', 'Dead Rows', 'Last Vacuum'],
            array_map(function ($stat) {
                return [
                    $stat->tablename,
                    $stat->total_size,
                    $stat->table_size,
                    $stat->indexes_size,
                    number_format($stat->row_count),
                    number_format($stat->dead_rows),
                    $stat->last_vacuum ?: 'Never'
                ];
            }, $stats)
        );
    }

    /**
     * Check if any option is set.
     *
     * @return bool
     */
    protected function hasAnyOption(): bool
    {
        return $this->option('missing-indexes') ||
               $this->option('slow-queries') ||
               $this->option('unused-indexes') ||
               $this->option('duplicates');
    }
}
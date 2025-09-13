<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PDOException;

class DatabaseRetry
{
    /**
     * Handle an incoming request with database retry logic.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $retryTimes = config('database.retry.times', 3);
        $retrySleep = config('database.retry.sleep', 1);
        $attempt = 0;

        while ($attempt < $retryTimes) {
            try {
                return $next($request);
            } catch (QueryException $e) {
                $attempt++;
                
                // Check if it's a connection error
                if ($this->isConnectionError($e) && $attempt < $retryTimes) {
                    Log::warning("Database connection error, retrying... Attempt {$attempt}/{$retryTimes}", [
                        'error' => $e->getMessage(),
                        'code' => $e->getCode(),
                    ]);
                    
                    sleep($retrySleep);
                    continue;
                }
                
                // If not a connection error or max retries reached, throw the exception
                throw $e;
            }
        }
        
        // This should never be reached, but just in case
        return $next($request);
    }

    /**
     * Determine if the exception is a connection error.
     *
     * @param  \Illuminate\Database\QueryException  $e
     * @return bool
     */
    protected function isConnectionError(QueryException $e): bool
    {
        $connectionErrors = [
            'SQLSTATE[08006]', // PostgreSQL connection failure
            'SQLSTATE[HY000] [2002]', // MySQL connection refused
            'SQLSTATE[HY000] [2003]', // MySQL can't connect
            'SQLSTATE[HY000] [2006]', // MySQL server has gone away
            'SQLSTATE[HY000] [2013]', // Lost connection to MySQL
            'Connection refused',
            'Connection reset by peer',
            'Connection timed out',
            'server has gone away',
            'no connection to the server',
            'Lost connection',
            'is dead or not enabled',
            'Error while sending',
            'decryption failed or bad record mac',
            'server closed the connection unexpectedly',
            'SSL connection has been closed unexpectedly',
            'Error writing data to the connection',
            'Resource deadlock avoided',
            'Transaction() on null',
            'child connection forced to terminate due to client_idle_limit',
        ];

        $message = $e->getMessage();
        $previousMessage = $e->getPrevious() ? $e->getPrevious()->getMessage() : '';

        foreach ($connectionErrors as $error) {
            if (stripos($message, $error) !== false || stripos($previousMessage, $error) !== false) {
                return true;
            }
        }

        // Check error codes
        if ($e->getPrevious() instanceof PDOException) {
            $errorCode = $e->getPrevious()->getCode();
            $connectionErrorCodes = [
                '08006', // PostgreSQL connection failure
                '57P01', // PostgreSQL admin shutdown
                '57P02', // PostgreSQL crash shutdown
                '57P03', // PostgreSQL cannot connect now
                '58000', // PostgreSQL system error
                '58P01', // PostgreSQL undefined file
                '2002',  // MySQL connection refused
                '2003',  // MySQL can't connect
                '2006',  // MySQL server has gone away
                '2013',  // Lost connection to MySQL
            ];

            if (in_array($errorCode, $connectionErrorCodes)) {
                return true;
            }
        }

        return false;
    }
}
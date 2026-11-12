<?php

namespace App\Support;

use Closure;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final class SqlTransactions
{
    public static function run(Closure $operation): mixed
    {
        $connection = DB::connection();
        // Only the owner of the outer transaction can safely retry a whole unit of work.
        if ($connection->transactionLevel() > 0) {
            return $connection->transaction($operation);
        }
        $attempts = max(1, (int) config('database.transactions.attempts', 5));
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                return $connection->transaction($operation);
            } catch (Throwable $error) {
                if ($error instanceof UniqueConstraintViolationException) {
                    throw new ConflictHttpException('Dữ liệu hoặc mã giao dịch vừa được sử dụng. Vui lòng tải lại để kiểm tra.', $error);
                }
                if (! (new ConcurrencyErrorDetector)->causedByConcurrencyError($error)) {
                    throw $error;
                }
                if ($attempt === $attempts - 1) {
                    throw new ConflictHttpException('Dữ liệu đang được cập nhật. Vui lòng tải lại và thử lại.', $error);
                }
                $delay = min((int) config('database.transactions.max_delay_ms', 500), (int) config('database.transactions.base_delay_ms', 75) * (2 ** $attempt));
                usleep($delay * 1000);
            }
        }
        throw new \LogicException('Transaction attempt limit must be positive.');
    }
}

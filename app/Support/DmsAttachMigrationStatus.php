<?php

namespace App\Support;

class DmsAttachMigrationStatus
{
    public const PENDING = 'PENDING';

    public const QUEUED = 'QUEUED';

    public const PROCESSING = 'PROCESSING';

    public const DONE = 'DONE';

    public const FAILED = 'FAILED';

    /**
     * @return list<string>
     */
    public static function dispatchable(): array
    {
        return [
            self::PENDING,
            self::FAILED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function stale(): array
    {
        return [
            self::QUEUED,
            self::PROCESSING,
        ];
    }
}

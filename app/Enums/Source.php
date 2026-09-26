<?php

namespace App\Enums;

/**
 * Where a business record came from. Paired with `external_key` for idempotent upserts.
 */
enum Source: string
{
    case Manual = 'manual';
    case Redmine = 'redmine';
    case Vault = 'vault';
    case Claude = 'claude';
    case Bank = 'bank';
    case System = 'system';
}

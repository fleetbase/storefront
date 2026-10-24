<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Traits\HasUuid;
use Illuminate\Support\Facades\Hash;

/**
 * A store's or network's App Review access: whether app store reviewers may sign in to its
 * app with the fixed review number (Support\AppReview::PHONE) and the code its owner
 * generated. The code is only ever stored hashed.
 *
 * @property string                          $uuid
 * @property string|null                     $company_uuid
 * @property string                          $owner_uuid
 * @property string|null                     $owner_type
 * @property bool                            $enabled
 * @property string|null                     $code
 * @property \Illuminate\Support\Carbon|null $code_generated_at
 * @property \Illuminate\Support\Carbon|null $enabled_at
 * @property string|null                     $updated_by_uuid
 */
class AppReviewAccess extends StorefrontModel
{
    use HasUuid;

    protected $table = 'app_review_accesses';

    protected $fillable = ['company_uuid', 'owner_uuid', 'owner_type', 'enabled', 'code', 'code_generated_at', 'enabled_at', 'updated_by_uuid'];

    protected $hidden = ['code'];

    protected $casts = [
        'enabled'           => 'boolean',
        'code_generated_at' => 'datetime',
        'enabled_at'        => 'datetime',
    ];

    public function owner()
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_uuid');
    }

    /**
     * Turned on and holding a code: the only state in which a reviewer can sign in.
     */
    public function isUsable(): bool
    {
        return $this->enabled && filled($this->code);
    }

    public function hasCode(): bool
    {
        return filled($this->code);
    }

    /**
     * Replace the code with the given one, stored hashed.
     */
    public function setPlainCode(string $code): void
    {
        $this->code              = Hash::make($code);
        $this->code_generated_at = now();
    }

    public function checkCode(string $code): bool
    {
        return $this->hasCode() && $code !== '' && Hash::check($code, $this->code);
    }
}

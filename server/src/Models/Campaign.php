<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\File;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A notification sent to a store's or network's customers, now or at a scheduled time.
 */
class Campaign extends StorefrontModel
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use SoftDeletes;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING   = 'sending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_CANCELED  = 'canceled';

    public const CHANNEL_PUSH  = 'push';
    public const CHANNEL_INBOX = 'inbox';

    protected $publicIdType = 'campaign';

    protected $table = 'campaigns';

    protected $searchableColumns = ['name', 'title', 'body'];

    protected $fillable = [
        'company_uuid', 'created_by_uuid', 'owner_uuid', 'owner_type', 'segment_uuid', 'promotion_uuid', 'recipients', 'name', 'status',
        'channels', 'title', 'body', 'image_uuid', 'action', 'send_at', 'started_at', 'sent_at', 'stats', 'meta',
    ];

    protected $casts = [
        'owner_type' => PolymorphicType::class,
        'recipients' => Json::class,
        'channels'   => Json::class,
        'action'     => Json::class,
        'stats'      => Json::class,
        'meta'       => Json::class,
        'send_at'    => 'datetime',
        'started_at' => 'datetime',
        'sent_at'    => 'datetime',
    ];

    public function owner()
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_uuid');
    }

    public function segment()
    {
        return $this->belongsTo(CustomerSegment::class, 'segment_uuid', 'uuid');
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class, 'promotion_uuid', 'uuid');
    }

    public function image()
    {
        return $this->setConnection(config('fleetbase.connection.db'))->belongsTo(File::class, 'image_uuid', 'uuid');
    }

    public function setOwnerTypeAttribute($type)
    {
        $this->attributes['owner_type'] = Utils::getMutationType($type);
    }

    /**
     * The store or network the campaign is sent for.
     */
    public function resolveOwner(): Store|Network|null
    {
        return Store::where('uuid', $this->owner_uuid)->first() ?? Network::where('uuid', $this->owner_uuid)->first();
    }

    /**
     * @return string[] enabled channels, defaulting to push and inbox
     */
    public function enabledChannels(): array
    {
        $channels = array_values(array_intersect((array) ($this->channels ?? []), [self::CHANNEL_PUSH, self::CHANNEL_INBOX]));

        return $channels ?: [self::CHANNEL_PUSH, self::CHANNEL_INBOX];
    }
}

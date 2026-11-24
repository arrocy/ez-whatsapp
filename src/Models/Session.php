<?php

namespace Arrocy\Whatsapp\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'user_id', 'chatbot'])]
class Session extends Model
{
    protected $table = 'ez_sessions';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }
}

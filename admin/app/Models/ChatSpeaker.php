<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A user allowed to speak in group chats (see the chat_speakers migration).
class ChatSpeaker extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'chat_speakers';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

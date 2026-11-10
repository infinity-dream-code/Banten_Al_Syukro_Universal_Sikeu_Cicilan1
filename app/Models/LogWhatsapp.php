<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogWhatsapp extends Model
{
    protected $table = 'log_whatsapps';

    protected $fillable = [
        'status',
        'log_id',
        'user_id',
        'custid',
        'nama',
        'no_wa',
        'pesan',
        'response',
        'client',
    ];

    protected $casts = [
        'status' => 'integer',
        'log_id' => 'integer',
        'user_id' => 'integer',
    ];
}

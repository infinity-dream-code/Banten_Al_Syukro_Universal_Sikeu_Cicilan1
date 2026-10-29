<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class sccttran extends Model
{
    protected $connection = "DATA_MYSQL";

    protected $table = "sccttran";

    protected $primaryKey = "urut";

    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = [
        "CUSTID",
        "METODE",
        "TRXDATE",
        "NOREFF",
        "FIDBANK",
        "KDCHANNEL",
        "DEBET",
        "KREDIT",
        "REFFBANK",
        "TRANSNO",
        "BILLID",
        "BILLTARGET",
        "INSTALLMENT",
        "isreversal",
    ];

    public function scopeNotReversed($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('isreversal')
                ->orWhere('isreversal', 0)
                ->orWhere('isreversal', '0');
        });
    }

    public function scopeHasNominal($query)
    {
        return $query->where(function ($q) {
            $q->whereRaw('CAST(COALESCE(DEBET, 0) AS SIGNED) > 0')
                ->orWhereRaw('CAST(COALESCE(KREDIT, 0) AS SIGNED) > 0');
        });
    }
}

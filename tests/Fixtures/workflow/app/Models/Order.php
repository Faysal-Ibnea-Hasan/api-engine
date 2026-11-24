<?php

namespace WorkflowFixture\App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['total', 'notes'];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}

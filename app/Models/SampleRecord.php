<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A record that belongs to a sample dataset (see App\Services\SampleRegistry). */
class SampleRecord extends Model
{
    protected $fillable = ['dataset', 'model_type', 'model_id'];
}

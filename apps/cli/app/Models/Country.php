<?php

namespace App\Models;

/**
 * @property-read \App\Models\City $cities
 */
class Country extends Model
{
    public $country_id;
    public $country;
    public $last_update;
}
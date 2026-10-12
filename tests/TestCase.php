<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed roles and permissions whenever a test refreshes the database.
     *
     * @var class-string
     */
    protected $seeder = RolesAndPermissionsSeeder::class;
}

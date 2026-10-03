<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.app_name', 'Cockpit');
        $this->migrator->add('general.support_email', 'support@example.test');
    }
};

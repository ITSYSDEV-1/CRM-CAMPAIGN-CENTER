<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PepipostAccount;
use App\Models\CrmUnit;

class CampaignSystemSeeder extends Seeder
{
    public function run(): void
    {
        // Buat akun Pepipost
        $account1 = PepipostAccount::create([
            'name' => 'Akun 1',
            'daily_quota' => 3000,
            'monthly_quota' => 150000,
            'is_active' => true,
            'settings' => [
                'timezone' => 'Asia/Jakarta',
                'auto_approve_limit' => 5000
            ]
        ]);

        $account2 = PepipostAccount::create([
            'name' => 'Akun 2',
            'daily_quota' => 5000,
            'monthly_quota' => 150000,
            'is_active' => true,
            'settings' => [
                'timezone' => 'Asia/Jakarta',
                'auto_approve_limit' => 5000
            ]
        ]);

        $account3 = PepipostAccount::create([
            'name' => 'Akun 3',
            'daily_quota' => 3000,
            'monthly_quota' => 150000,
            'is_active' => true,
            'settings' => [
                'timezone' => 'Asia/Jakarta',
                'auto_approve_limit' => 5000
            ]
        ]);

        // Buat CRM Units sesuai grouping
        // Grup 1: RCD - RMS (Akun 1)
        CrmUnit::create([
            'app_code' => 'RCD',
            'name' => 'Royal City Hotel',
            'pepipost_account_id' => $account1->id,
            'daily_quota' => 1500,
            'monthly_quota' => 75000,
            'mandatory_daily_quota' => 200,
            'max_sync_per_day' => 5,
            'is_active' => true,
            'settings' => [
                'priority' => 'high',
                'auto_approve' => true
            ]
        ]);

        CrmUnit::create([
            'app_code' => 'RMS',
            'name' => 'Royal Mountain Suite',
            'pepipost_account_id' => $account1->id,
            'daily_quota' => 1500,
            'monthly_quota' => 75000,
            'mandatory_daily_quota' => 150,
            'max_sync_per_day' => 5,
            'is_active' => true,
            'settings' => [
                'priority' => 'medium',
                'auto_approve' => true
            ]
        ]);

        // Grup 2: KSV - RGH (Akun 2)
        CrmUnit::create([
            'app_code' => 'KSV',
            'name' => 'King Suite Villa',
            'pepipost_account_id' => $account2->id,
            'daily_quota' => 2500,
            'monthly_quota' => 75000,
            'mandatory_daily_quota' => 300,
            'max_sync_per_day' => 5,
            'is_active' => true,
            'settings' => [
                'priority' => 'high',
                'auto_approve' => true
            ]
        ]);

        CrmUnit::create([
            'app_code' => 'RGH',
            'name' => 'Royal Garden Hotel',
            'pepipost_account_id' => $account2->id,
            'daily_quota' => 2500,
            'monthly_quota' => 75000,
            'mandatory_daily_quota' => 250,
            'max_sync_per_day' => 5,
            'is_active' => true,
            'settings' => [
                'priority' => 'medium',
                'auto_approve' => true
            ]
        ]);

        // Grup 3: RRP - RRPTG - PS (Akun 3)
        CrmUnit::create([
            'app_code' => 'RRP',
            'name' => 'Royal Resort & Pool',
            'pepipost_account_id' => $account3->id,
            'daily_quota' => 1000,
            'monthly_quota' => 50000,
            'mandatory_daily_quota' => 100,
            'max_sync_per_day' => 5,
            'is_active' => true,
            'settings' => [
                'priority' => 'medium',
                'auto_approve' => true
            ]
        ]);

        CrmUnit::create([
            'app_code' => 'RRPTG',
            'name' => 'Royal Resort Pool Tugu',
            'pepipost_account_id' => $account3->id,
            'daily_quota' => 1000,
            'monthly_quota' => 50000,
            'mandatory_daily_quota' => 80,
            'max_sync_per_day' => 5,
            'is_active' => true,
            'settings' => [
                'priority' => 'low',
                'auto_approve' => true
            ]
        ]);

        CrmUnit::create([
            'app_code' => 'PS',
            'name' => 'Premium Suite',
            'pepipost_account_id' => $account3->id,
            'daily_quota' => 1000,
            'monthly_quota' => 50000,
            'mandatory_daily_quota' => 120,
            'max_sync_per_day' => 5,
            'is_active' => true,
            'settings' => [
                'priority' => 'medium',
                'auto_approve' => false
            ]
        ]);
    }
}
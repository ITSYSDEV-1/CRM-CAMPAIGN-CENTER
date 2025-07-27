<?php

namespace App\Services;

use Carbon\Carbon;

class BillingCycleService
{
    /**
     * Menentukan tanggal akhir billing berdasarkan bulan
     */
    public static function getEndDay($month)
    {
        // Bulan dengan 31 hari biasanya berakhir di 20
        if (in_array($month, [1,3,5,7,8,10,12])) {
            return 20;
        }
        // Februari special case
        else if ($month == 2) {
            return 19;
        }
        // Bulan dengan 30 hari berakhir di 19
        else {
            return 19;
        }
    }
    
    /**
     * Menentukan tanggal mulai billing berdasarkan bulan
     */
    public static function getStartDay($month)
    {
        // Bulan sebelum Februari mulai tanggal 20
        if ($month == 1) {
            return 20;
        }
        // Bulan setelah Februari mulai tanggal 22
        else if ($month == 3) {
            return 22;
        }
        // Default mulai tanggal 21
        else {
            return 21;
        }
    }
    
    /**
     * Mendapatkan periode billing cycle untuk tanggal tertentu
     */
    public static function getBillingPeriod($date = null)
    {
        $now = $date ? Carbon::parse($date) : Carbon::now();
        $currentDay = $now->day;
        $currentMonth = $now->month;
        
        // Tentukan periode billing cycle
        if ($currentDay < self::getStartDay($currentMonth)) {
            // Masih dalam cycle bulan sebelumnya
            $prevMonth = $now->copy()->subMonth();
            $startDate = $prevMonth->copy()->setDay(self::getStartDay($prevMonth->month));
            $endDate = $now->copy()->setDay(self::getEndDay($currentMonth));
        } else {
            // Cycle baru dimulai
            $nextMonth = $now->copy()->addMonth();
            $startDate = $now->copy()->setDay(self::getStartDay($currentMonth));
            $endDate = $nextMonth->copy()->setDay(self::getEndDay($nextMonth->month));
        }
        
        return [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'start_day' => self::getStartDay($currentMonth),
            'end_day' => self::getEndDay($currentMonth),
            'current_month' => $currentMonth
        ];
    }
    
    /**
     * Cek apakah tanggal berada dalam periode billing yang sama
     */
    public static function isInSameBillingPeriod($date1, $date2)
    {
        $period1 = self::getBillingPeriod($date1);
        $period2 = self::getBillingPeriod($date2);
        
        return $period1['start_date'] === $period2['start_date'] && 
               $period1['end_date'] === $period2['end_date'];
    }
}
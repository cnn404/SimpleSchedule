#!/usr/bin/env php
<?php

class CronSchedule extends \Lib\Schedule
{
    public function schedule(\Lib\Schedule $schedule)
    {
        #示例：
        //$schedule->add('*/5 * * * *', 'Admin.DelAccount.autoDelAccount')->outPutLog('/var/www/html/runtime/autoDelAccount.log');
        //$schedule->add('*/3 * * * *', 'Admin.DelAccount.autoDelAccount')->outPutLog('/var/www/html/runtime/autoDelAccount2.log');
        //$schedule->add('0 10 * * *', 'Admin.DelAccount.autoDelAccount')->outPutLog('/var/www/html/runtime/autoDelAccount3.log');
    }
}

$cron = new CronSchedule();
$cron->run();

<?php

namespace App\Support;

/** Only studio access is exposed today. Financial permissions define independent future capabilities. */
enum Permission: string
{
    case StudioAccess = 'studio.access';
    case AttendanceView = 'attendance.view';
    case PaymentsView = 'payments.view';
    case PaymentsRecord = 'payments.record';
    case PaymentsCorrect = 'payments.correct';
    case ReportsView = 'reports.view';
}

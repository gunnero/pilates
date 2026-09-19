<?php

namespace App\Support;

enum Permission: string
{
    case StudioAccess = 'studio.access';
    case OrganizationsView = 'organizations.view';
    case OrganizationsManage = 'organizations.manage';
    case LocationsView = 'locations.view';
    case LocationsManage = 'locations.manage';
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
    case PermissionsView = 'permissions.view';
    case PermissionsManage = 'permissions.manage';
    case TrainersView = 'trainers.view';
    case TrainersManage = 'trainers.manage';
    case MembersView = 'members.view';
    case MembersCreate = 'members.create';
    case MembersUpdate = 'members.update';
    case MembersStatusManage = 'members.status.manage';
    case MembersNotesView = 'members.notes.view';
    case MembersNotesManage = 'members.notes.manage';
    case AttendanceView = 'attendance.view';
    case PaymentsView = 'payments.view';
    case PaymentsRecord = 'payments.record';
    case PaymentsCorrect = 'payments.correct';
    case ReportsView = 'reports.view';
}

<?php

namespace App\Support;

enum Permission: string
{
    case ReservationsView = 'reservations.view';
    case ReservationsManage = 'reservations.manage';
    case ReservationsCancel = 'reservations.cancel';
    case WaitingListView = 'waiting_list.view';
    case WaitingListManage = 'waiting_list.manage';
    case PermanentSpotsView = 'permanent_spots.view';
    case PermanentSpotsManage = 'permanent_spots.manage';
    case StudioAccess = 'studio.access';
    case OrganizationsView = 'organizations.view';
    case OrganizationsManage = 'organizations.manage';
    case LocationsView = 'locations.view';
    case LocationsManage = 'locations.manage';
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
    case PermissionsView = 'permissions.view';
    case PermissionsManage = 'permissions.manage';
    case SessionsView = 'sessions.view';
    case SessionsManage = 'sessions.manage';
    case SessionsCancel = 'sessions.cancel';
    case GroupsView = 'groups.view';
    case GroupsManage = 'groups.manage';
    case ScheduleView = 'schedule.view';
    case ScheduleManage = 'schedule.manage';
    case TrainersView = 'trainers.view';
    case TrainersManage = 'trainers.manage';
    case MembersView = 'members.view';
    case MembersCreate = 'members.create';
    case MembersUpdate = 'members.update';
    case MembersStatusManage = 'members.status.manage';
    case MembersNotesView = 'members.notes.view';
    case MembersNotesManage = 'members.notes.manage';
    case MembershipsView = 'memberships.view';
    case MembershipsManage = 'memberships.manage';
    case AttendanceManage = 'attendance.manage';
    case AttendanceCorrect = 'attendance.correct';
    case AttendanceView = 'attendance.view';
    case PaymentsView = 'payments.view';
    case PaymentsRecord = 'payments.record';
    case PaymentsCorrect = 'payments.correct';
    case ReportsView = 'reports.view';
}

export type Routes = {
    CheckIn: undefined;
    Home: undefined;
    Sessions: { group?: string; relationship?: 'all' | 'reserved' } | undefined;
    Groups: undefined;
    Group: { id: string };
    Session: { id: string };
    Activity: undefined;
    Waiting: undefined;
    Attendance: undefined;
    Memberships: undefined;
    Payments: undefined;
    Notifications: undefined;
    Notice: { id: string };
    Profile: undefined;
};

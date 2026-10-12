type Value = string | number | boolean | null;
export type ReportData = {
    meta: {
        report: string;
        from: string;
        until: string;
        as_of: string;
        today: string;
        timezone: string;
        basis: string;
        view: string;
    };
    totals: Record<string, Value>;
    rows: Record<string, Value>[];
    columns: string[];
    options: Record<
        string,
        { rows: { id: string; label: string }[]; truncated: boolean }
    >;
    pagination?: { total: number; page: number; last_page: number };
    dimensions?: Record<
        string,
        { rows: Record<string, Value>[]; truncated: boolean }
    >;
    comparison: null | {
        from: string;
        until: string;
        totals: Record<string, Value>;
        rows: Record<string, Value>[];
        changes: Record<
            string,
            {
                current: Value;
                previous: Value;
                absolute: Value;
                relative_percent: Value;
                percentage_points: Value;
            }
        >;
    };
};

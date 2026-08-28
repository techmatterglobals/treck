namespace Treck.Admin.Application.Models;

public sealed record DesktopOverview(
    DateOnly Date,
    EmployeeOverview Employees,
    PresenceOverview Presence,
    ActivityOverview Activity,
    string Scope,
    DateTimeOffset GeneratedAt);

public sealed record EmployeeOverview(int Total, int Present, double AttendancePercent);

public sealed record PresenceOverview(
    int Total,
    int Online,
    int Offline,
    int Active,
    int Idle,
    int Locked,
    int LoggedOut);

public sealed record ActivityOverview(
    long ActiveSeconds,
    long IdleSeconds,
    long TrackedSeconds,
    double ActivePercent);

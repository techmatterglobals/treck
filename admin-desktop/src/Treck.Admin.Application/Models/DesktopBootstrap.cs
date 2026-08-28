namespace Treck.Admin.Application.Models;

public sealed record DesktopBootstrap(
    DesktopUser User,
    IReadOnlyList<string> Roles,
    IReadOnlyList<string> Permissions,
    DesktopFeatures Features,
    ServerInformation Server);

public sealed record DesktopUser(long Id, string Name, string Email);

public sealed record DesktopFeatures(
    bool Presence,
    bool Attendance,
    bool Reports,
    bool ApplicationUsage,
    bool Screenshots,
    bool Downloads,
    bool AgentHealth);

public sealed record ServerInformation(
    string Version,
    string Timezone,
    string DisplayTimezone,
    DateTimeOffset Time);

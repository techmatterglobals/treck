namespace Treck.Admin.Application.Models;

public sealed record DesktopBootstrap(
    string ContractVersion,
    DesktopUser User,
    IReadOnlyList<string> Roles,
    IReadOnlyList<string> Permissions,
    IReadOnlyList<DesktopOrganization> Organizations,
    bool OrganizationSelectionRequired,
    DesktopOrganization? RecommendedOrganization,
    DesktopFeatures Features,
    ServerInformation Server);

public sealed record DesktopUser(long Id, string Name, string Email);

public sealed record DesktopOrganization(
    long Id,
    string Name,
    string? Slug,
    string Status,
    string Role,
    IReadOnlyList<string> Permissions,
    DesktopFeatures Features);

public sealed record DesktopFeatures(
    bool Presence,
    bool Attendance,
    bool Reports,
    bool ApplicationUsage,
    bool Screenshots,
    bool Downloads,
    bool AgentHealth,
    bool EmployeeDetail = true,
    bool OrganizationWide = false,
    bool ManagerLimited = false);

public sealed record ServerInformation(
    string Version,
    string Timezone,
    string DisplayTimezone,
    DateTimeOffset Time);

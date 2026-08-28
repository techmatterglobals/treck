namespace Treck.Admin.Application.Models;

public sealed record LoginSession(
    string Token,
    string TokenType,
    DesktopUser User,
    IReadOnlyList<string> Roles,
    IReadOnlyList<string> Abilities);

public sealed record NavigationItem(string Key, string Label);

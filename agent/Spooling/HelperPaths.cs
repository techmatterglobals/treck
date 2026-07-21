using Treck.Agent.Storage;

namespace Treck.Agent.Spooling;

/// <summary>
/// Resolves the shared directory tree the interactive capture/collection helper
/// writes to and the Session-0 service reads from. Everything the helper needs
/// write access to lives under a single <see cref="Root"/> so the service can
/// grant the interactive user access to just that subtree — the offline queue
/// (offline.db) and the DPAPI-encrypted device token at the data-dir root stay
/// off-limits.
///
///   {ProgramData}\TreckAgent\helper              ← Root (ACL-granted to the user)
///   {ProgramData}\TreckAgent\helper\screenshots  ← compressed image temp files
///   {ProgramData}\TreckAgent\helper\spool        ← one .json sidecar per event
/// </summary>
public static class HelperPaths
{
    public static string Root(IStoragePathProvider paths)
        => Path.Combine(paths.BaseDirectory, "helper");

    public static string Screenshots(IStoragePathProvider paths)
        => Path.Combine(Root(paths), "screenshots");

    public static string Spool(IStoragePathProvider paths)
        => Path.Combine(Root(paths), "spool");
}

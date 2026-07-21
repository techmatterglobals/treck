using Treck.Agent.Storage;

namespace Treck.Agent.Screenshots;

/// <summary>
/// Resolves the on-disk spool location shared between the interactive capture
/// helper (writer) and the Session-0 service (reader). Both derive it from the
/// same <see cref="IStoragePathProvider"/>, so they always agree:
///
///   {ProgramData}\TreckAgent\screenshots        ← compressed image temp files
///   {ProgramData}\TreckAgent\screenshots\spool  ← one .json sidecar per capture
///
/// Keeping the spool under the screenshots directory (never at the data-dir root)
/// lets the service grant the interactive user access to *only* this directory,
/// leaving the offline queue and the encrypted device token untouched.
/// </summary>
public static class ScreenshotSpool
{
    public static string ImageDirectory(IStoragePathProvider paths)
        => Path.Combine(paths.BaseDirectory, "screenshots");

    public static string SpoolDirectory(IStoragePathProvider paths)
        => Path.Combine(ImageDirectory(paths), "spool");
}

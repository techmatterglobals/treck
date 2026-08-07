using System.Text.Json;
using Microsoft.Extensions.Logging;
using Treck.Agent.Offline;
using Treck.Agent.Storage;

namespace Treck.Agent.Spooling;

/// <summary>
/// File-backed <see cref="IAgentEventSpool"/> used by the interactive helper.
/// Each event is written as a JSON sidecar (temp file + atomic rename) so the
/// service never reads a half-written file. A one-time write probe at startup
/// surfaces an ACL/permission problem immediately (the #1 helper failure mode)
/// rather than silently on the first capture.
/// </summary>
public sealed class FileAgentEventSpool : IAgentEventSpool
{
    private readonly ILogger<FileAgentEventSpool> _logger;
    private readonly string _spoolDirectory;

    public FileAgentEventSpool(ILogger<FileAgentEventSpool> logger, IStoragePathProvider paths)
    {
        _logger = logger;
        _spoolDirectory = HelperPaths.Spool(paths);
        Directory.CreateDirectory(_spoolDirectory);
        VerifyWritable();
    }

    public void Submit(OfflineEvent evt)
    {
        var finalPath = Path.Combine(_spoolDirectory, $"{evt.IdempotencyKey}.json");
        var tempPath = finalPath + ".tmp";

        var json = JsonSerializer.Serialize(SpooledEvent.From(evt));
        File.WriteAllText(tempPath, json);
        File.Move(tempPath, finalPath, overwrite: true);

        _logger.LogDebug("Spooled {Kind} event {Key}.", evt.Kind, evt.IdempotencyKey);
    }

    /// <summary>Write + delete a probe file so a permission problem is loud and early.</summary>
    private void VerifyWritable()
    {
        var probe = Path.Combine(_spoolDirectory, $".writetest-{Environment.ProcessId}");

        try
        {
            File.WriteAllText(probe, "ok");
            File.Delete(probe);
            _logger.LogInformation("Spool directory is writable: {Dir}.", _spoolDirectory);
        }
        catch (Exception ex)
        {
            _logger.LogError(
                ex,
                "Spool directory is NOT writable by this user: {Dir}. Captures cannot be handed to the service — " +
                "grant the interactive user Modify on the helper directory (the service normally does this via icacls).",
                _spoolDirectory);
        }
    }
}

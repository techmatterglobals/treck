using System.Collections.Concurrent;
using System.Text.Json;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Treck.Agent.Applications;
using Treck.Agent.Configuration;
using Treck.Agent.Offline;
using Treck.Agent.Spooling;

namespace Treck.Agent.Downloads;

/// <summary>
/// Detects files saved into user download locations and reports metadata to the
/// backend (Phase 12). Runs inside the interactive helper so it sees the logged-in
/// user's Downloads folder and foreground application; the resolved Windows
/// identity (folded in by <see cref="SourceStamp"/>) lets the server attribute
/// each download to the right employee (Phase 11).
///
/// Detection uses a <see cref="FileSystemWatcher"/> (OS change notifications — no
/// busy polling) plus a lightweight debounce timer so in-progress downloads
/// (.crdownload/.part) are reported only once the file is complete and its size
/// has stabilized. Ignored folders/extensions/applications, hashing and the max
/// hash size are all configurable. Metadata ONLY — file contents are never read
/// except to compute an optional SHA-256, and those bytes are never transmitted.
/// </summary>
public sealed class FileDownloadMonitor : IHostedService, IDisposable
{
    private readonly FileDownloadOptions _options;
    private readonly IFileHashService _hashService;
    private readonly IActiveWindowService _activeWindow;
    private readonly IAgentEventSpool _spool;
    private readonly EventSource _source;
    private readonly ILogger<FileDownloadMonitor> _logger;

    private readonly List<FileSystemWatcher> _watchers = [];
    private readonly ConcurrentDictionary<string, FileDownloadSession> _pending = new(StringComparer.OrdinalIgnoreCase);
    private Timer? _sweep;

    public FileDownloadMonitor(
        IOptions<FileDownloadOptions> options,
        IFileHashService hashService,
        IActiveWindowService activeWindow,
        IAgentEventSpool spool,
        EventSource source,
        ILogger<FileDownloadMonitor> logger)
    {
        _options = options.Value;
        _hashService = hashService;
        _activeWindow = activeWindow;
        _spool = spool;
        _source = source;
        _logger = logger;
    }

    public Task StartAsync(CancellationToken cancellationToken)
    {
        if (!_options.Enabled)
        {
            _logger.LogInformation("File download monitoring is disabled.");
            return Task.CompletedTask;
        }

        foreach (var folder in ResolveFolders())
        {
            try
            {
                var watcher = new FileSystemWatcher(folder)
                {
                    IncludeSubdirectories = _options.IncludeSubdirectories,
                    NotifyFilter = NotifyFilters.FileName | NotifyFilters.Size | NotifyFilters.LastWrite,
                    EnableRaisingEvents = true,
                };
                watcher.Created += OnChanged;
                watcher.Renamed += OnRenamed;
                watcher.Changed += OnChanged;
                _watchers.Add(watcher);
                _logger.LogInformation("Watching downloads in {Folder}.", folder);
            }
            catch (Exception ex)
            {
                _logger.LogWarning(ex, "Could not watch {Folder}.", folder);
            }
        }

        // Debounce sweep: promotes stable files to reported downloads. The period
        // is a fraction of the stabilization window; it is idle work (no I/O)
        // unless files are actually pending.
        var period = Math.Max(250, _options.StabilizationMilliseconds / 2);
        _sweep = new Timer(_ => SweepPending(), null, period, period);

        return Task.CompletedTask;
    }

    public Task StopAsync(CancellationToken cancellationToken)
    {
        Dispose();
        return Task.CompletedTask;
    }

    private void OnChanged(object sender, FileSystemEventArgs e) => Track(e.FullPath);

    private void OnRenamed(object sender, RenamedEventArgs e) => Track(e.FullPath);

    /// <summary>Note a path as pending (or update its size); the sweep reports it once stable.</summary>
    private void Track(string path)
    {
        try
        {
            if (Directory.Exists(path))
            {
                return; // directories are not downloads
            }

            var info = new FileInfo(path);
            var now = DateTimeOffset.UtcNow;

            // Skip partial-download temp files by extension (they'll be renamed
            // to the final name, which fires another event we do track).
            if (IsIgnoredExtension(info.Extension))
            {
                return;
            }

            _pending.AddOrUpdate(
                path,
                _ => new FileDownloadSession(path, SafeLength(info), now),
                (_, session) =>
                {
                    session.Observe(SafeLength(info), now);
                    return session;
                });
        }
        catch (Exception ex)
        {
            _logger.LogDebug(ex, "Track failed for {Path}.", path);
        }
    }

    private void SweepPending()
    {
        var now = DateTimeOffset.UtcNow;

        foreach (var (path, session) in _pending)
        {
            if (!session.IsStable(now, _options.StabilizationMilliseconds))
            {
                continue;
            }

            session.MarkReported();
            _pending.TryRemove(path, out _);

            try
            {
                Report(path);
            }
            catch (Exception ex)
            {
                _logger.LogWarning(ex, "Failed to report download {Path}.", path);
            }
        }
    }

    private void Report(string path)
    {
        if (!File.Exists(path))
        {
            return; // moved/deleted before it stabilized
        }

        var info = new FileInfo(path);
        var folder = info.DirectoryName ?? string.Empty;

        if (IsIgnoredFolder(folder))
        {
            return;
        }

        var app = _activeWindow.GetActiveApplication();
        if (app is not null && IsIgnoredApplication(app.ProcessName, app.ExecutableName))
        {
            return;
        }

        var size = SafeLength(info);
        var extension = info.Extension.TrimStart('.').ToLowerInvariant();

        var download = new DownloadedFile(
            FileName: info.Name,
            FileExtension: extension,
            FileSize: size,
            LocalPath: path,
            DownloadFolder: folder,
            Sha256Hash: _hashService.TryHash(path, size),
            DownloadedAt: DateTimeOffset.UtcNow,
            ApplicationName: app?.ProcessName,
            ProcessName: app?.ExecutableName,
            WindowTitle: app?.WindowTitle);

        var json = SourceStamp.Apply(JsonSerializer.Serialize(download), _source);
        _spool.Submit(OfflineEvent.Create(OfflineEventKind.FileDownload, json, download.DownloadedAt));

        _logger.LogInformation("Download recorded: {Name} ({Size} bytes).", info.Name, size);
    }

    /// <summary>Watched folders, defaulting to the user's Downloads folder.</summary>
    private IEnumerable<string> ResolveFolders()
    {
        var folders = _options.WatchedFolders
            .Select(f => Environment.ExpandEnvironmentVariables(f))
            .Where(f => !string.IsNullOrWhiteSpace(f))
            .ToList();

        if (folders.Count == 0)
        {
            var profile = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile);
            folders.Add(Path.Combine(profile, "Downloads"));
        }

        return folders.Where(Directory.Exists).Distinct(StringComparer.OrdinalIgnoreCase);
    }

    private static long SafeLength(FileInfo info)
    {
        try
        {
            info.Refresh();
            return info.Exists ? info.Length : 0;
        }
        catch
        {
            return 0;
        }
    }

    private bool IsIgnoredExtension(string extension)
        => _options.IgnoredExtensions.Contains(extension.TrimStart('.'), StringComparer.OrdinalIgnoreCase);

    private bool IsIgnoredFolder(string folder)
        => _options.IgnoredFolders.Any(f => folder.Contains(f, StringComparison.OrdinalIgnoreCase));

    private bool IsIgnoredApplication(string? processName, string? executable)
        => _options.IgnoredApplications.Any(a =>
            string.Equals(a, processName, StringComparison.OrdinalIgnoreCase) ||
            string.Equals(a, executable, StringComparison.OrdinalIgnoreCase));

    public void Dispose()
    {
        _sweep?.Dispose();
        _sweep = null;

        foreach (var watcher in _watchers)
        {
            watcher.EnableRaisingEvents = false;
            watcher.Dispose();
        }

        _watchers.Clear();
    }
}

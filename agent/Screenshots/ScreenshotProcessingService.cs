using System.Collections.Concurrent;
using System.Drawing.Imaging;
using System.Runtime.Versioning;
using System.Security.Cryptography;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Treck.Agent.Spooling;
using Treck.Agent.Storage;

namespace Treck.Agent.Screenshots;

/// <summary>
/// Compresses captures to JPEG (quality-configurable) or PNG, computes the
/// SHA-256 of the compressed bytes, deduplicates against the previous frame per
/// monitor, and writes the survivor to a temp file under
/// <c>{storage}/screenshots</c>. The temp file is deleted by the sync pipeline
/// after a successful upload.
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class ScreenshotProcessingService : IScreenshotProcessingService
{
    // Foreground context columns on the server (active_process /
    // active_window_title) are varchar(255); clamp before upload so a long
    // window title never turns into a rejected screenshot.
    private const int MaxMetadataLength = 255;

    private readonly ILogger<ScreenshotProcessingService> _logger;
    private readonly ScreenshotOptions _options;
    private readonly string _tempDirectory;

    // Last uploaded hash per monitor, so an unchanged screen is skipped.
    private readonly ConcurrentDictionary<int, string> _lastHashes = new();

    public ScreenshotProcessingService(
        ILogger<ScreenshotProcessingService> logger,
        IOptions<ScreenshotOptions> options,
        IStoragePathProvider paths)
    {
        _logger = logger;
        _options = options.Value;
        _tempDirectory = HelperPaths.Screenshots(paths);
        Directory.CreateDirectory(_tempDirectory);
    }

    public ScreenshotMetadata? Process(
        MonitorCapture capture,
        string? activeProcess,
        string? activeWindowTitle,
        string sessionId,
        DateTimeOffset capturedAt)
    {
        var isPng = string.Equals(_options.Format, "png", StringComparison.OrdinalIgnoreCase);
        var bytes = Encode(capture, isPng);
        var hash = Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();

        // Duplicate detection: identical frame as the previous cycle → skip.
        if (_lastHashes.TryGetValue(capture.MonitorNumber, out var previous) && previous == hash)
        {
            _logger.LogDebug("Skipping unchanged monitor {Monitor} (hash {Hash}).", capture.MonitorNumber, hash[..8]);
            return null;
        }

        var extension = isPng ? "png" : "jpg";
        var path = Path.Combine(_tempDirectory, $"{hash}.{extension}");
        File.WriteAllBytes(path, bytes);

        _lastHashes[capture.MonitorNumber] = hash;

        return new ScreenshotMetadata(
            CapturedAt: capturedAt,
            MonitorNumber: capture.MonitorNumber,
            Width: capture.Image.Width,
            Height: capture.Image.Height,
            FileSize: bytes.LongLength,
            ImageHash: hash,
            ActiveProcess: Truncate(activeProcess, MaxMetadataLength),
            ActiveWindowTitle: Truncate(activeWindowTitle, MaxMetadataLength),
            SessionId: sessionId,
            LocalPath: path,
            Format: isPng ? "png" : "jpeg");
    }

    private byte[] Encode(MonitorCapture capture, bool isPng)
    {
        using var stream = new MemoryStream();

        if (isPng)
        {
            capture.Image.Save(stream, ImageFormat.Png);
        }
        else
        {
            using var parameters = new EncoderParameters(1);
            parameters.Param[0] = new EncoderParameter(Encoder.Quality, (long) _options.JpegQuality);
            capture.Image.Save(stream, JpegCodec(), parameters);
        }

        return stream.ToArray();
    }

    /// <summary>
    /// Clamp a metadata string to <paramref name="max"/> chars, without splitting
    /// a surrogate pair at the boundary (which would corrupt the JSON payload).
    /// </summary>
    private static string? Truncate(string? value, int max)
    {
        if (value is null || value.Length <= max)
        {
            return value;
        }

        if (char.IsHighSurrogate(value[max - 1]))
        {
            max--;
        }

        return value[..max];
    }

    private static ImageCodecInfo JpegCodec()
    {
        foreach (var codec in ImageCodecInfo.GetImageEncoders())
        {
            if (codec.FormatID == ImageFormat.Jpeg.Guid)
            {
                return codec;
            }
        }

        throw new InvalidOperationException("No JPEG encoder is available on this system.");
    }
}

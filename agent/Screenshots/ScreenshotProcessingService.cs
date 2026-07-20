using System.Collections.Concurrent;
using System.Drawing.Imaging;
using System.Runtime.Versioning;
using System.Security.Cryptography;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
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
        _tempDirectory = Path.Combine(paths.BaseDirectory, "screenshots");
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
            ActiveProcess: activeProcess,
            ActiveWindowTitle: activeWindowTitle,
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

using System.ComponentModel.DataAnnotations;

namespace Treck.Agent.Screenshots;

/// <summary>
/// Configuration for the screenshot module (Phase 8), bound from the
/// "Screenshots" section of appsettings.json. Capture is opt-in and disabled by
/// default. The policy is deliberately simple and easy to extend.
/// </summary>
public sealed class ScreenshotOptions
{
    public const string SectionName = "Screenshots";

    /// <summary>Master switch. When false the capture worker never starts.</summary>
    public bool Enabled { get; set; }

    /// <summary>Base interval between capture cycles, in seconds.</summary>
    [Range(30, 86400)]
    public int IntervalSeconds { get; set; } = 600;

    /// <summary>
    /// When &gt; 0, each cycle's delay is randomized in
    /// [IntervalSeconds, IntervalSeconds + RandomJitterSeconds] so captures are
    /// not perfectly periodic. 0 = fixed interval.
    /// </summary>
    [Range(0, 86400)]
    public int RandomJitterSeconds { get; set; }

    /// <summary>Capture only when the user has been active within the idle threshold.</summary>
    public bool CaptureOnlyWhenActive { get; set; } = true;

    /// <summary>Capture every monitor separately (else only the primary).</summary>
    public bool MultiMonitor { get; set; } = true;

    /// <summary>Image format: "jpeg" (smaller) or "png" (lossless).</summary>
    public string Format { get; set; } = "jpeg";

    /// <summary>JPEG quality 1-100 (ignored for PNG).</summary>
    [Range(1, 100)]
    public int JpegQuality { get; set; } = 60;

    /// <summary>
    /// Skip capture entirely while any of these foreground processes is active
    /// (case-insensitive, without extension), e.g. a password manager.
    /// </summary>
    public string[] IgnoredProcesses { get; set; } = [];

    /// <summary>
    /// Skip capture when the foreground window title contains any of these
    /// substrings (case-insensitive) — e.g. "Private", "Incognito".
    /// </summary>
    public string[] IgnoredWindowTitles { get; set; } = [];
}

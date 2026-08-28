namespace Treck.Agent.Screenshots;

/// <summary>
/// Captures the interactive user's desktop. Implementations must never capture
/// the Windows secure desktop (UAC / Ctrl+Alt+Del / login / lock screen) — see
/// <see cref="CanCapture"/>.
/// </summary>
public interface IScreenshotCaptureService
{
    /// <summary>
    /// True only when the current input desktop is the normal interactive
    /// desktop ("Default"). False for the secure desktop, the lock screen, or
    /// when no interactive desktop is attached (service in session 0).
    /// </summary>
    bool CanCapture();

    /// <summary>
    /// Capture each monitor (or just the primary, per options). Returns disposable
    /// bitmaps; the caller owns and disposes them. Empty if capture is not
    /// currently possible.
    /// </summary>
    IReadOnlyList<MonitorCapture> CaptureAll();
}

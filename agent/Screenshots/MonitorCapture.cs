using System.Drawing;
using System.Runtime.Versioning;

namespace Treck.Agent.Screenshots;

/// <summary>
/// A single monitor's raw capture — the in-memory bitmap plus its 0-based
/// monitor index. Disposable so the (large) bitmap is released promptly after
/// processing.
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class MonitorCapture : IDisposable
{
    public MonitorCapture(int monitorNumber, Bitmap image)
    {
        MonitorNumber = monitorNumber;
        Image = image;
    }

    public int MonitorNumber { get; }

    public Bitmap Image { get; }

    public void Dispose() => Image.Dispose();
}

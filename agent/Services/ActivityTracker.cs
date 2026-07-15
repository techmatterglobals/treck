using Treck.Agent.Models;

namespace Treck.Agent.Services;

/// <summary>
/// Pure classification logic (no I/O), so it is trivially unit-testable:
/// given an elapsed interval, the current idle seconds, and lock state, decide
/// how the interval splits into active vs idle and what status to report.
/// </summary>
public sealed class ActivityTracker
{
    public ActivitySample Classify(int elapsedSeconds, int idleSeconds, bool isLocked, int idleThresholdSeconds)
    {
        elapsedSeconds = Math.Max(0, elapsedSeconds);

        if (isLocked)
        {
            return new ActivitySample(0, elapsedSeconds, AgentStatus.Locked);
        }

        if (idleSeconds >= idleThresholdSeconds)
        {
            return new ActivitySample(0, elapsedSeconds, AgentStatus.Idle);
        }

        return new ActivitySample(elapsedSeconds, 0, AgentStatus.Online);
    }
}

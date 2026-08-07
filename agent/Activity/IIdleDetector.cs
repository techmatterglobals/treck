namespace Treck.Agent.Activity;

/// <summary>Measures how long the workstation has been idle (no keyboard/mouse input).</summary>
public interface IIdleDetector
{
    TimeSpan GetIdleTime();
}
